# Design

## Context

See proposal.md - Why. Relevant current state:

- Issuance runs as a pipeline of steps (guard, create, number, deliver) that records each outcome on the `ifirma_receipt` row. The receipt already carries `ksef_status` and `ksef_number` columns, filled by status sync from the list endpoint.
- The document list rows carry `KsefNumer` and `KsefStatus`; the module's status sync already writes both. No code submits to KSeF today.
- `IfirmaApiClient` signs and sends JSON calls and returns decoded JSON or `null`; business errors arrive as HTTP 200 with a non-zero `Kod`.
- The module creates only `fakturakraj` documents (`fakturaparagon` is disabled), and `fakturakraj` is a documented KSeF send type.
- The stored request payload of an issued document contains the buyer `Kontrahent` block, including `NIP` when the order's invoice address has one.

iFirma facts that constrain the design (from the iFirma API documentation and the merchant's request):

- `POST {type}/ksef/send/{id}.json` takes `{"DataWysylki": null}`: `null` means send now, `YYYY-MM-DD` means schedule. Only `Kod` 0 is documented as accepted for sending; the rest of the response is undocumented.
- The document list reports `KsefStatus` (for example `PRZYJETA`) and `KsefNumer`. The exact vocabulary of `KsefStatus` is not documented beyond the values seen so far.
- A KSeF submission cannot be withdrawn through the API.
- Polish law requires B2B invoices in KSeF from 2026; B2C invoices need not be sent.

Constraints: PHP 7.2 - 8.x syntax (`array()`, no typed properties), PrestaShop 1.7 - 9.x, explicit `require_once` (no runtime Composer), schema changes idempotent and also present in the 1.1.0 upgrade script.

## Goals / Non-Goals

**Goals:**
- Submit B2B invoices to KSeF automatically by default, with a setting that widens or disables automatic submission.
- Make the KSeF step a non-blocking pipeline step that records its outcome on the receipt row, so a KSeF failure never blocks issuance or delivery.
- Show the KSeF state in the admin panel and the KSeF number on the customer page without leaking errors to the customer.
- Keep the status marker and the list-sync values consistent, so an accepted submission is not reported as pending.

**Non-Goals:**
- Withdrawing or cancelling a KSeF submission (no API; not possible).
- Scheduling submissions for a later date (`DataWysylki` with a date). The module always sends with `null`.
- Submitting paragon or other document types; the module creates only `fakturakraj`.
- Fetching the KSeF reference number from KSeF itself; the module stores what iFirma's list reports.
- Showing KSeF errors on the customer page.

## Decisions

### D1. Default mode is `b2b`

**Choice.** `IFIRMA_KSEF_MODE` accepts `b2b` (default), `all` and `off`. `b2b` submits only invoices whose buyer has a NIP. `all` submits every invoice. `off` never submits automatically; the manual button stays available. Any stored value other than these three is normalised to `b2b` when read and when saved.

**Why.** The legal obligation covers B2B invoices only, so `b2b` matches the requirement without submitting B2C invoices the law does not require to be sent. `all` is available for merchants who prefer uniform handling, and `off` covers merchants who submit through iFirma's own tools. Normalising unknown values to the safe default avoids a typo silently disabling or widening submission.

**Alternative considered.** Defaulting to `all` was rejected: it sends B2C invoices, which the law does not require and which cannot be withdrawn.

### D2. The `ksef` step is non-blocking

**Choice.** The step sits between `number` and `deliver`. Its outcomes are `skipped` (with reason: disabled, private buyer in B2B mode, already in KSeF, unsupported type), `ok` (iFirma accepted the submission; receipt `ksef_status` = `WYSLANA`, `ksef_sent_at` recorded) and `failed` (receipt `ksef_status` = `BLAD`, `ksef_error` stored). On `failed`, the pipeline continues to delivery, the outcome stays `issued`, and the result message carries `KSeF: <error>`.

**Why.** The document already exists in iFirma when the step runs; blocking delivery on a KSeF failure would leave the customer without the invoice for a problem the customer cannot fix. The error stays visible to the merchant through the result message, the receipt and the admin panel.

**Alternative considered.** Making the step blocking was rejected for the reason above; retries are left to the admin button.

### D3. B2B detection uses the stored request payload, then the invoice address

**Choice.** A document is B2B when the stored request payload contains a non-empty buyer `Kontrahent.NIP`. If the payload has none, the module uses the NIP of the order's invoice address. An empty or whitespace-only value is treated as private.

**Why.** The payload is what was actually sent to iFirma, so the decision matches the document the merchant sees. The invoice address is the fallback for orders whose stored payload predates the NIP field.

**Trade-off.** The invoice address can change after issuance; the payload is authoritative because it is the record of what was issued.

### D4. `WYSLANA` marker is kept until iFirma reports a status

**Choice.** After an accepted submission the receipt holds `ksef_status` = `WYSLANA`. Status sync overwrites it only when iFirma returns a non-empty `KsefStatus`. `KsefNumer` is stored whenever iFirma returns a non-empty value, independently of the status.

**Why.** Right after submission the list may still show an empty `KsefStatus`; without the marker the admin panel would show the document as not sent and offer to submit it again. Keeping the marker until iFirma reports something keeps the state honest. Storing the number whenever present means a later sync can add it without waiting for a status change.

**Alternative considered.** Taking `KsefStatus` verbatim, including empty values, was rejected because it erases the record of the submission.

### D5. Lazy schema columns

**Choice.** `ksef_sent_at` (DATETIME NULL) and `ksef_error` (TEXT NULL) are added by `IfirmaSchema` on first use, after an `information_schema.COLUMNS` check, and also added by `upgrade/upgrade-1.1.0.php`. Both paths are idempotent.

**Why.** Merchants who update the files without running the upgrade still get working columns, and the upgrade path is safe to run after the lazy path has already added them.

### D6. Mock behaviour and what is unverified

**Choice.** The mock answers `POST {type}/ksef/send/{id}.json` with `Kod` 0 and marks the document as submitted. `/__mock/ksef/accept/{id}` sets `KsefStatus` = `PRZYJETA` and a `KsefNumer`; `/__mock/ksef/reject/{id}` makes the next send call return a non-zero `Kod`. The mock response shape is a guess based on the documented `Kod` convention.

**Unverified:** the real response body of the send call, the full `KsefStatus` vocabulary, whether the response carries a reference number, and whether iFirma submits automatically on its own for the account. Tests cover the module's handling of `Kod` 0, non-zero codes and unparseable responses; they do not prove iFirma's behaviour.

### D7. Admin state and customer visibility are derived, not stored

**Choice.** The admin row and the customer page derive one state from the receipt columns: not sent (with reason), sent at (awaiting iFirma), accepted (with number), or error (with message). The button **Send to KSeF** forces the submission regardless of mode, is hidden when the state is accepted, and is refused when the document has no iFirma id.

**Why.** One derived state avoids the admin and the customer seeing different answers for the same record.

## Risks / Trade-offs

- [Submission cannot be withdrawn] -> A mistaken submission stays in KSeF. Mitigation: `b2b` default, the warning in README before enabling `all`, and no button for accepted documents. Trade-off accepted: no undo.
- [iFirma account may auto-submit] -> A second submission or a document already in KSeF. Mitigation: skip when a KSeF number or accepted status is present; the open question below must be answered before release.
- [Response wording unknown] -> A success response may be read as a failure, or a failure as success. Mitigation: only `Kod` 0 counts as accepted; everything else is `failed` with the message and can be retried with the button.
- [Stale B2B decision] -> The NIP changed after issuance. Mitigation: the payload is authoritative (D3).
- [`WYSLANA` shown for a submission iFirma rejected later] -> The marker persists until iFirma reports a status (D4). Mitigation: status sync overwrites it on the next sync; the admin can refresh status.

## Open Questions

- Does iFirma submit invoices to KSeF on its own for this account (an account-level setting)? If yes, `all` or `b2b` could create a duplicate submission for documents the account already sent. The merchant must answer this before release.
- What is the exact `KsefStatus` vocabulary beyond `PRZYJETA`, and which values mean rejected? The module displays any non-empty value other than `PRZYJETA` and does not infer acceptance from it.
- Does the send response carry a reference number that should be stored? Until confirmed, the module stores only `ksef_sent_at` and the `Kod` outcome.
