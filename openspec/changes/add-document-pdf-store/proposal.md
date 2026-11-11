# Proposal

## Why

Every PDF download of a customer or an employee costs one iFirma request, and the same document is fetched again for each click. The module keeps a copy of each document PDF on the shop server for 30 days, so repeated downloads send no request and still work while iFirma is down or the request limit is reached.

This reverses a recorded decision: the `customer-receipt-visibility` spec says the PDF is streamed "without storing it", and the design of `2026-11-07-admin-receipt-ops-and-hardening` rejected a cache because it "would need storage and invalidation rules". This change supplies those rules.

## What Changes

- A copy of the original PDF is stored whenever the module fetches it from iFirma: customer download, back-office download, back-office refresh and the shop-mail delivery right after issuance. One copy per document, replaced by a newer fetch.
- Customer and back-office downloads are served from the copy while it is usable; such a download sends no iFirma request. The delivery e-mail always fetches from iFirma (and replaces the copy), so a mailed document is always the current one.
- A copy is usable for a configurable number of days counted from the moment it was stored: new setting "Keep PDF copies (days)", `IFIRMA_PDF_COPY_DAYS`, 0 to 365, default 30, also after an upgrade. 0 turns the store off and removes the existing copies.
- A copy is not served when its document changed since it was stored (payment registered, KSeF status or number, document id or type, found missing in iFirma) or when its bytes are damaged; the next download fetches and replaces it.
- The duplicate variant (`duplicate=1`, `?typ=dup`) is never stored and never served from the copy.
- **A download served from the copy uses no download limit**: the per-document and per-customer throttle, the customer's day cap, the shared customer budget and the employee budget apply only to a download that is fetched from iFirma. A copy is returned even when one of them is used up, always after the access checks. (Owner's decision of 2026-11-11; it replaces "keep every limit" of 2026-11-10.)
- New back-office action "Refresh PDF copy" (manage level, POST): discards the copy and fetches the current document from iFirma. The order panel shows when the copy was stored.
- Expired copies are deleted by the cron run (a new job that sends no iFirma request) and, for shops without cron, by later store writes. Copies of a deleted customer are deleted with the customer; uninstall drops the table and the setting.
- Copies live in a database table, never in a file under the web root.

## Capabilities

### New Capabilities
- `document-pdf-store`: keeping a copy of each document PDF on the shop server: when a copy is stored, how long it is kept, what makes it unusable, who is served from it, how it is refreshed and how it is deleted.

### Modified Capabilities
- `customer-receipt-visibility`: "Download access control" no longer forbids storing (the stored copy is served after the same access checks); "Download throttle" and "A customer's downloads are capped per day" apply only to a download fetched from iFirma, so a stored copy is neither refused nor counted; new requirement that a served copy uses no customer download limit.
- `receipt-access-control`: the back-office download reads the copy after the view-level check and without using the employee budget; the new "Refresh PDF copy" action needs the manage level and POST.
- `receipt-status-sync`: "Cron jobs and their order" gains the purge job, which runs first and is not stopped by the rate limit.

## Impact

- **Depends on the two changes in progress.** `IfirmaDownloadBudget`, `IfirmaDownloadDayCap`, the requirement "A customer's downloads are capped per day" and the current wording of "Cron jobs and their order" exist only in `add-api-usage-delivery-retry-status-filter-and-ci-fix`; `add-rate-limit-queue-and-ksef-confirmed-delivery` still carries a MODIFIED "Download access control" with the old "without storing it" text. Both must be archived before this change, otherwise the archive of this change fails (`openspec validate` already reports it for the day cap) or its wording is overwritten.
- New classes: `IfirmaPdfCopy` (value object), `IfirmaPdfCopyStore` (table access), `IfirmaPdfCopyFingerprint`, `IfirmaPdfCopyRetention`, `IfirmaDocumentPdfService` (copy or fetch), `IfirmaPdfCopyPurgeJob` (cron), `IfirmaPdfCopyActions` (refresh).
- Changed callers of `IfirmaReceiptService::fetchPdf()`: `controllers/front/receipt.php`, `controllers/admin/AdminIfirmaController.php` (`DownloadPdf`, new `RefreshPdfCopy`), `classes/IfirmaDeliveryService.php`. `fetchPdf()` itself is unchanged.
- Storage: new table `ifirma_pdf_copy` (`IfirmaSchema`, `sql/install.php`, `sql/uninstall.php`, upgrade script of the version the Release workflow will produce); new configuration key in `IfirmaSettings`, `IfirmaSchema::configurationDefaults()` and the settings golden fixture.
- New hook `actionObjectCustomerDeleteAfter` (registered on install and by the upgrade script); cron summary gains one block.
- Views: configuration form field, order panel line and button (`views/templates/hook/admin_order_panel.tpl`, admin JS), order-panel golden fixture, translations.
- Personal data: document PDFs (name, address, NIP, items) are now at rest in the shop database for up to the configured days and are included in database backups. Documented in `docs/configuration.md`, `docs/architecture.md`, `docs/usage.md`, `docs/upgrading.md`.
- No new dependency. No change to the iFirma API calls themselves.
- Tests: unit tests per class, front and back-office download tests for copy hit, miss, expiry and mismatch and for each limit a served copy must not use, smoke steps in scenarios M and Q (binary round trip in real MySQL, second download sends no request). Red-team pass required: new admin action, new table, new hook.
