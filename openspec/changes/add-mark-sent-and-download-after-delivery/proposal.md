# Proposal

## Why

A customer can download a document's PDF from the shop as soon as it is issued, even when its e-mail delivery failed or delivery is turned off. The owner wants the shop to hand a document to its customer only after it was sent, and wants employees to be able to record a delivery that happened outside the module.

Checked against iFirma's API documentation on 2026-11-11: iFirma has no call that only marks an invoice as sent. The operations on an issued invoice are sending it (iFirma's own e-mail) and registering a payment. iFirma's "sent" flag (`CzyWyslano` in the invoice list) can be read, and the module already reads it. So "sent" is recorded in the module, and iFirma's flag is shown and counted, not written.

## What Changes

- **BREAKING for customers:** a customer can download a document, and sees its download link, only when the document was sent to them. Sent means: the module delivered it (delivery state "sent"), an employee marked it as sent, or iFirma reports it as sent, and it is not waiting for KSeF acceptance. A failed delivery, a skipped delivery (send channel "none", adopted external document) and an older document that was never e-mailed give no download until an employee sends or marks it.
- One definition of "sent to the customer" is used by the order page, the account page and the download itself. It is the rule the order page already uses for its "Sent on" text.
- New back-office action "Mark as sent" (manage level, POST): records the document as sent with the channel "manual" and the current time. It sends no e-mail and no request to iFirma, ends a KSeF wait and cancels a pending automatic retry. Available in the order panel and as a bulk action in the Documents list.
- The order panel keeps showing iFirma's own flag ("Marked as sent in iFirma" / "Not marked as sent in iFirma"); nothing is written to iFirma.
- The configuration page warns, next to the send channel, that with "none" customers cannot download documents until an employee marks them as sent.
- No stored state is changed by the upgrade. `docs/upgrading.md` explains the new rule and how to restore the download for older documents (Send again, Mark as sent, bulk Mark as sent).

## Capabilities

### New Capabilities

None.

### Modified Capabilities
- `customer-receipt-visibility`: "Issued state" and "Account block" show a download link only for a document that was sent; new requirement that the download itself is refused until the document was sent.
- `receipt-delivery`: new requirement for the manual "Mark as sent"; new requirement for the warning about the channel "none".
- `receipt-documents-list`: new bulk action "Mark as sent".
- `receipt-access-control`: "Mark as sent" (single and bulk) needs the manage level and POST.

## Impact

- Code: `controllers/front/receipt.php` (one more access check), `classes/IfirmaCustomerView.php` and the two customer templates (link only when sent), a new small class that defines "sent to the customer" next to `IfirmaKsefDeliveryGate`, `classes/IfirmaDeliveryService.php` (`markSent()`, channel "manual", its label), `classes/IfirmaReceiptActions.php`, `classes/IfirmaAdminActions.php`, `controllers/admin/AdminIfirmaController.php` (action `MarkSent`, bulk kind), `classes/IfirmaBulkRunner.php`, `views/templates/hook/admin_order_panel.tpl` with its script, `ifirma.php` (warning text in the configuration form).
- No new table, column, setting or hook. No new iFirma request: marking as sent and the download check use stored fields only.
- Behaviour after the upgrade: shops whose channel is "none", and documents with a failed or skipped delivery, lose the customer download until an employee acts. This is the owner's decision of 2026-11-11.
- Order of work: "Mark as sent" (single and bulk) lands before the customer rule, so a shop can restore downloads at the moment the rule starts.
- Relation to `add-document-pdf-store`: both touch the customer download. The sent check is an access check, so it runs before the stored copy is read and before every limit, whichever change lands first.
- Archive order: after `add-rate-limit-queue-and-ksef-confirmed-delivery`, whose text of "Issued state" this change modifies.
- Tests: unit tests for the definition, the front controller, the customer view, the action, the permission and POST checks and the bulk kind; golden fixture of the order panel; smoke steps in scenarios E, Q, N, L and J. Red-team pass required: new admin action and a changed access rule on a customer endpoint.
