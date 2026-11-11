# Design

## Context

Observed in the code (branch `feat/api-usage-delivery-retry-status-filter`):

- Delivery states of a document (`classes/IfirmaReceipt.php`): `none` (column default, older documents), `sent`, `failed`, `skipped` (send channel "none", or a document adopted from iFirma), `awaiting_ksef`. `recordDelivery()` is the only writer besides `markAwaitingKsef()`.
- The customer download (`controllers/front/receipt.php`) checks login, token, that the document is issued, ownership and the KSeF wait, then the limits. It does not look at the delivery state.
- `IfirmaCustomerView` already has a rule for "sent" (`:297`): not waiting for KSeF, and delivery state `sent` or iFirma's flag `remote_sent`. It uses the rule only for the text "Sent on" / "Not yet sent". The download link is hidden only for a missing or waiting document; the account block hides it only for a waiting one. The guest order page shows no link.
- Back office: `ResendDocument` (manage, POST) calls `IfirmaReceiptActions::resend()` and `IfirmaDeliveryService::resend()` (named lock, quiet period, then a manual delivery). Bulk kinds of `IfirmaBulkRunner`: issue, retry, resend, refresh. The order panel's "Sent" row already shows iFirma's flag ("Marked as sent in iFirma") and the module's delivery text.
- iFirma's flag `CzyWyslano` is read into `remote_sent` by the status sync and on adoption.
- iFirma's API documentation (api.ifirma.pl, read on 2026-11-11): the operations on an issued invoice are sending it and registering a payment; there is no call that marks an invoice as sent and no edit of an issued invoice. `CzyWyslano` appears only in the example response of the invoice list, without a definition.

Decisions the owner made on 2026-11-11: a customer downloads only delivered documents, including older ones; "sent" is recorded in the module and iFirma's flag is shown, because the API cannot write it; a manual "Mark as sent" is added; this is a change of its own.

## Goals / Non-Goals

**Goals:**
- No customer-facing path gives the PDF of a document that was not sent to its customer.
- The order page, the account page and the download agree: a link is shown exactly when the download would succeed on the access checks.
- An employee can record a delivery made outside the module, one document or many, before the new rule takes downloads away.

**Non-Goals:**
- No write to iFirma: no extra send call, no e-mail to the shop's own address to set iFirma's flag.
- No change of stored delivery states by the upgrade.
- No change to how deliveries are sent or retried, and none to the guest order page.
- The status sync does not turn iFirma's flag into the module's "sent" state.

## Decisions

### D1. One definition, on stored fields
New class `IfirmaCustomerDeliveryGate` next to `IfirmaKsefDeliveryGate`: `isSentState($deliveryStatus, $remoteSent, $ksefStatus, $ksefNumber)` on plain values (for database rows) and `isSent(IfirmaReceipt)`. Sent means: not waiting for KSeF, and delivery state `sent` or `remote_sent` set. This is the rule `IfirmaCustomerView` has today; the view calls the new class.
*Why iFirma's flag counts:* the order page already tells the customer "Sent" for such a document, and iFirma did e-mail it. *Alternative:* the module's state only. Rejected: a page that says "Sent" without a link, and a customer who holds iFirma's e-mail but cannot download.

### D2. The download gets one more access check
`controllers/front/receipt.php` refuses with "not found" when the document is not sent. The check replaces the KSeF-wait check (the definition contains it) and stays where that one is: after ownership, before the day cap, the throttle, the budget and, once `add-document-pdf-store` is in, before the stored copy is read. The answer is the one of a wrong token, so it tells nothing about the document's state.

### D3. Links follow the same rule
`IfirmaCustomerView` sets `download_url` only when the document is sent, on the order page and in the account block. The templates do not change their conditions.

### D4. "Mark as sent" is a state change, not a delivery
`IfirmaDeliveryService::markSent(IfirmaReceipt)`: under the named lock `resend()` uses, it refuses a document that is not issued, is missing in iFirma or is already `sent`; otherwise it records `sent` with the new channel `manual`, sets `delivered_at` to now, clears the error and the retry time, and saves. No e-mail and no iFirma request. `describeDelivery()` gets the label "Marked as sent on <time> by an employee". One log line names the record id and the employee id.
*Alternative:* a flag on `resend()`. Rejected: `resend()` sends.

### D5. Admin action
`MarkSent` in `AdminIfirmaController::$levels` at the manage level, not in `$readOnlyActions`, through `IfirmaReceiptActions::markSent()` and the `IfirmaAdminActions` facade. The order panel shows the button for the manage level when the document is issued and its delivery state is not `sent`; it asks for confirmation and repeats the KSeF warning of "Send again" for a waiting document.

### D6. Bulk
`IfirmaBulkRunner::KIND_MARK_SENT`. It sends no request, so the request limit and the authentication pause do not stop it. Each document ends as "sent" or "skipped" with a reason (already sent, not issued, missing). Summary and audit entry as for the other kinds; manage level.

### D7. Warning at the send channel
The description of the send channel in the configuration form says that with "none" customers cannot download documents until an employee marks them as sent.

### D8. No migration
The upgrade changes no row. The rule applies to every document from the moment the code runs. "Mark as sent" lands first (pull requests 1 and 2), the customer rule last.

## Risks / Trade-offs

- [Customers lose the download of documents with a failed, skipped or missing delivery right after the upgrade] -> the owner's decision; "Mark as sent" and its bulk action land first; `docs/upgrading.md` and `docs/troubleshooting.md` say what to do.
- [A shop with the channel "none" gives no downloads at all] -> the warning of D7; the bulk action.
- ["Mark as sent" is an employee's statement, not proof of delivery] -> manage level, channel "manual" shown in the panel and the list, one log line with the employee id.
- [iFirma's flag is as old as the last sync] -> a document sent from the iFirma panel becomes downloadable after the next status sync or "Refresh from iFirma".
- [The documentation does not define `CzyWyslano`] -> task 1.2 checks it on a real account before the customer rule is built.
- [Both this change and `add-document-pdf-store` edit the customer download] -> the sent check is an access check and sits before the copy read and the limits; the second change to land keeps that order, and a test with a stored copy proves it.

## Migration Plan

1. Pull requests 1 and 2 (mark as sent, bulk) change no behaviour for customers.
2. Pull request 3 turns the rule on. Before it is released, the shop marks or sends the documents its customers should keep downloading.
3. Rollback: revert pull request 3; states written by "Mark as sent" stay valid.

## Open Questions

- Should the status sync record the module's "sent" state when iFirma reports a document as sent, so that the Documents list shows "Sent" too? Not in this change; for the customer the flag already counts.
