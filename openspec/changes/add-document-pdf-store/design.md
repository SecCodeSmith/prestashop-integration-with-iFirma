# Design

## Context

Observed in the code (branch `feat/api-usage-delivery-retry-status-filter`):

- `IfirmaReceiptService::fetchPdf()` (`classes/IfirmaReceiptService.php:622`) is the only place that downloads a PDF. Three callers: `controllers/front/receipt.php:86`, `AdminIfirmaController::ajaxProcessDownloadPdf()` (`:1227`), `IfirmaDeliveryService::sendViaShopMail()` (`:500`, with `fetchPdfWithRetry()` at `:673`).
- The customer download runs: login, token, ownership, KSeF wait gate, day cap check, throttle claim, shared budget, day count, fetch. The back-office download runs: view level, employee budget, fetch.
- A document's row is never deleted and changes only through `IfirmaReceipt::save()`. What changes after issuance: `status` (`created` to `paid` when a payment is registered), `ksef_status` and `ksef_number` (`IfirmaKsefService`, `IfirmaStatusSyncService::applyRow()`), `remote_missing`, and `ifirma_id` / `document_type` when a not-issued row is reused or adopted.
- No template or script links `duplicate=1`; both controllers read it.
- The module has no customer-deletion or GDPR hook. Receipt rows outlive a deleted customer (tracked follow-up, not part of this change).
- Cron jobs are listed in `IfirmaCronRunner::build()`; once a job is rate limited the later jobs are skipped.

Decisions the owner made on 2026-11-10: customers and employees are served from the copy, with a back-office refresh action; a copy is stored on every fetch including the delivery e-mail; the lifetime is a setting, default 30, 0 turns it off. Decision of 2026-11-11, which replaces "every existing limit keeps counting a served copy" of the day before: a download served from the copy uses no download limit; the limits are for downloads fetched from iFirma.

## Goals / Non-Goals

**Goals:**
- A repeated download of a document sends no iFirma request for the configured days.
- A copy is never served to someone who fails an access check of the download (login, token, ownership and the KSeF wait for a customer; the view level and the order match in the back office), and never after the module knows the document changed.
- Bounded retention of the stored personal data, with deletion paths that do not depend on cron alone.

**Non-Goals:**
- No copy of the duplicate variant.
- No encryption of the stored bytes: the shop database already holds the same customer and order data; no home-made crypto.
- No psgdpr integration (`actionDeleteGDPRCustomer`, export, consent) and no deletion of receipt rows: the existing follow-up.
- No bulk refresh in the Documents list.
- No change to `fetchPdf()`, to the delivery retry rules or to the limits' numbers.

## Decisions

### D1. Database table, not files
Table `ifirma_pdf_copy`: `id_ifirma_receipt` INT UNSIGNED primary key, `fingerprint` CHAR(64), `byte_length` INT UNSIGNED, `content` MEDIUMBLOB, `stored_at` DATETIME with an index. One row per document, written with one `REPLACE`.
*Why:* a row cannot be reached by a URL, needs no path handling, works on several web servers and disappears with `DROP TABLE`. *Alternative:* files in the module or `download/` directory. Rejected: both are under the web root and protected only by `.htaccess`, which nginx ignores.

### D2. Bytes travel as hex
The write statement contains only an `(int)` id, a hex digest, an `(int)` length, `UNHEX('<bin2hex of the PDF>')` and a formatted date. No `pSQL()` on the bytes (it strips tags and would damage them) and no raw bytes inside a utf8 statement.
A PDF larger than 1 MiB is returned but not stored, so the statement stays under the 4 MiB default `max_allowed_packet`. On read the copy is used only when `strlen(content) === byte_length` and the content starts with `%PDF`; otherwise the row is deleted and the document is fetched.

### D3. The copy validates itself: fingerprint instead of invalidation hooks
`IfirmaPdfCopyFingerprint` is the SHA-256 of `document_type | ifirma_id | status | ksef_status | ksef_number | remote_missing`. It is stored with the copy and compared with the document's current values on every read. A mismatch is a miss.
*Why:* writers of those fields are spread over five classes; a check at read time cannot miss one and needs no change in them. *Alternative:* delete the copy in every writer. Rejected: one forgotten writer serves a stale invoice.
Not detectable: an edit made directly in the iFirma panel. Mitigated by the refresh action (D7), the lifetime and the off switch.

### D4. Lifetime is computed at read time
Only `stored_at` is stored. Usable means `stored_at >= now - days`. Lowering the setting therefore applies to existing copies at once; 0 means nothing is read or written. `IfirmaPdfCopyRetention` reads `IFIRMA_PDF_COPY_DAYS` (range 0 to 365 in `IfirmaSettings::fields()`, default 30 in `IfirmaSchema::configurationDefaults()`). Fixed lifetime, not sliding: a download does not extend it.

### D5. One service owns the copy and the fetch
`IfirmaDocumentPdfService` (constructor: `IfirmaReceiptService`, `IfirmaPdfCopyStore`, `IfirmaPdfCopyRetention`, `IfirmaPdfCopyFingerprint`; `build(Module)` at the edge):
- `storedCopy($receipt)`: the bytes of the usable copy, or null. It sends no request and deletes a damaged copy. Used by both download controllers before their limits.
- `fresh($receipt)`: always fetch, then store. Used by both download controllers when there is no usable copy, by the delivery e-mail and by the refresh action.
- `duplicate($receipt)`: fetch only.
- `copyStoredAt($receipt)`: the stored time of the usable copy, from a read without the content, for the order panel.
A failed store write is logged (severity 2, no bytes, no personal data) and the fetched PDF is still returned. `IfirmaRateLimitedException` and other failures of the fetch pass through unchanged, so the controllers' give-back code keeps working.
*Alternative:* one call `forDownload()` that returns the copy or fetches. Dropped on 2026-11-11: the limits sit between the two halves, so the controller must know which half it is in.

### D6. The copy is read after the access checks and before the limits
Customer download: login, token, ownership, KSeF wait gate; then `storedCopy()`, and a usable copy is returned at once. Only without one the flow continues as today: day cap, throttle claim, shared budget, day count, `fresh()`; a fetch refused before sending gives its units back, as today. Back-office download: view level and the check that the record belongs to the order; then `storedCopy()`; only without a copy the employee budget and `fresh()`. The duplicate variant never reads the copy and passes every limit.
A served copy claims no throttle slot, raises no count and takes no budget unit, so nothing is given back for it.
Consequence: with a usable copy neither the module's request limit, a back-off, an authentication pause, an iFirma outage nor a used-up download limit fails the download, because no request is needed. The scenarios of "Download refused by the request limit", "Download throttle" and the day cap describe a document without a usable copy.
*Alternative:* count a served copy in every limit (the decision of 2026-11-10). Replaced by the owner on 2026-11-11: the limits exist to protect the iFirma request quota.

### D7. Refresh is a manage-level POST action
`RefreshPdfCopy` in `AdminIfirmaController::$levels` (manage), not in `$readOnlyActions`. `IfirmaPdfCopyActions::refresh()` deletes the copy first, takes one unit of the employee budget, calls `fresh()` and answers with the JSON envelope. When the fetch fails the copy stays deleted, so a copy an employee flagged is never served again.
*Alternative:* let "Refresh from iFirma" also drop the copy. Rejected: it adds a second effect to a working action.
The order panel shows "PDF copy stored on <date>" from a metadata read (no blob) and the button for the manage level.

### D8. Deletion paths
- Cron: `IfirmaPdfCopyPurgeJob`, first in `IfirmaCronRunner::build()` so a rate-limited run and a run that uses up its time budget still purge; it deletes copies older than the lifetime (all of them when the setting is 0) and reports the count in a summary block `pdf_copies`. It runs before the day-limit read because it needs no request; `skip()` keeps its contract and does nothing.
- Without cron: each store write also deletes up to 50 expired rows.
- Saving the setting as 0 deletes all copies in the same request.
- `actionObjectCustomerDeleteAfter`: deletes the copies of that customer's documents (`WHERE id_ifirma_receipt IN (SELECT ... WHERE id_customer = (int))`). psgdpr deletes a customer through the same object, so its erasure is covered.
- Uninstall: `DROP TABLE` and the configuration key.

### D9. Schema follows the existing pattern
`IfirmaSchema::pdfCopyColumns()`, `pdfCopyCreateSql()`, `ensurePdfCopyTable()` with an ensure flag; the store calls the ensure at its write and read paths. The table is also created by `sql/install.php` and by the upgrade script, which registers the new hook and sets the default.

## Risks / Trade-offs

- [A document edited in the iFirma panel is served stale for up to the lifetime] -> refresh action, panel shows the copy date, lifetime setting, off switch; documented in `docs/usage.md`.
- [Document PDFs at rest and in database backups] -> bounded lifetime, deletion paths of D8, off switch, documented.
- [Database growth: about 50 to 120 KB per document for 30 days] -> 1 MiB cap per copy, purge; documented with a sizing note.
- [A reader that bypasses the access checks would leak a document] -> the store is keyed by the id of an already authorised receipt, never by request input; negative tests for a foreign customer, a wrong token and a document waiting for KSeF with a copy present.
- [A logged-in customer can download their own stored copies in a loop, because no limit counts them] -> each such download is one primary-key read of at most 1 MiB behind login, token and ownership and sends no iFirma request; the red-team pass (abuse dimension) measures it; accepted by the owner on 2026-11-11.
- [Customer `duplicate=1` still reaches iFirma on every request] -> unchanged and still limited; removing the parameter from the front controller is a follow-up.
- [Archive order] -> see Migration Plan.

## Migration Plan

1. Archive `add-rate-limit-queue-and-ksef-confirmed-delivery` and `add-api-usage-delivery-retry-status-filter-and-ci-fix` first.
2. Upgrade script: if no `v1.10.0` tag exists when this merges, extend `upgrade/upgrade-1.10.0.php`; otherwise add the script of the next minor the Release workflow will produce. It creates the table, registers the hook and writes `IFIRMA_PDF_COPY_DAYS = 30` when the key is missing. Idempotent; a failure is reported so PrestaShop offers the upgrade again.
3. Rollback: set the lifetime to 0 (copies removed, downloads fetch as before). The table can stay.

## Open Questions

- Should the customer front controller stop honouring `duplicate=1`? No page links it. A follow-up either way: this change keeps the parameter and its limits as they are.
