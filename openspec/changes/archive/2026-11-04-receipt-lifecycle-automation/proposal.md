# Proposal

## Why

The module can issue an iFirma document from the back office and expose the PDF to the customer, but the end-to-end receipt lifecycle is still manual and unsafe: it never checks whether a document for the order already exists in iFirma (merchants who also run iFirma's own PrestaShop integrator, or who issue documents by hand, end up with duplicates), it never sends the document to the customer, it does not observe what happens to the document after issuance, and a customer who notices wrong billing data has no way to stop the document from being issued with it. The module also has no automated tests beyond `php -l`, so none of this can be verified before release.

## What Changes

- **Remote duplicate guard before issuance.** Before creating a document (manually or automatically) the module queries iFirma's invoice list for the order's window and contractor, confirms candidates by the order reference the module stamps into every document, and either adopts an already-existing document as the order's receipt or blocks issuance as a suspected duplicate for admin review. Interpretation of the request "check that the iFirma-side integration did not create a receipt": any document that another source (iFirma's e-commerce integrator, manual entry) already created in the same iFirma account for this order.
- **Status-triggered issue-and-send.** The existing single trigger status now runs a full pipeline: duplicate check → issue → fetch PDF → deliver to the customer by e-mail. Delivery channel is configurable: PrestaShop mail with the PDF attached (default, works for both document types), or iFirma's own `send` endpoint (documented only for `fakturakraj`, so used only when the document type supports it). Issuance is held while a customer data-change request is open.
- **Document monitoring.** The module polls iFirma for the document's remote state (paid amount, sent flag, draft flag, KSeF status, linked correction, disappearance) on admin demand, opportunistically when the customer opens the order, and in bulk through a token-protected cron endpoint. Polling respects the documented 100 requests/minute and 15,000/day limits.
- **Customer-facing lifecycle on the order page and account block.** Instead of appearing only once a PDF exists, the order detail block always states where the receipt is in its lifecycle (not yet issued and why, held pending a data request, issued with number/date/paid/sent status and download link, unavailable with reason) and offers the data-change request entry point.
- **Customer data-change request before issuance.** A logged-in customer can request corrections to billing data (private person vs company, company name, NIP, address) for an order that has no issued document. One open request per order; it pauses auto-issuance, notifies the merchant, and is approved (data applied as an override for the document payload) or rejected (with reason) from the admin order panel. After resolution, issuance resumes automatically if the order already reached the trigger status.
- **iFirma API compliance fixes.** Contractor identifier capped at 15 characters and made deterministic per customer (currently 40 characters of e-mail, over the documented limit); order reference stamped in `Uwagi` and `DodatkoweUwagi` (`NUMER_ZAMOWIENIA`); `dataDo` always passed to the list endpoint; documented `Kod` error codes mapped to actionable messages; create requests are never blindly retried after a timeout (the duplicate guard runs first).
- **Verification.** PHPUnit unit tests with lightweight PrestaShop stubs for signing, payload building, duplicate matching, status mapping and the request state machine; the iFirma mock in `test-stack` gains the list filters, single-document detail, send endpoint, limit endpoint and a seeding hook that simulates documents created by iFirma's integrator; a smoke script runs the full pipeline inside the Docker PrestaShop against the mock; CI runs all of it.
- **BREAKING for merchants (data, not API):** `ps_ifirma_receipt` gains columns and a new table `ps_ifirma_data_request` is added via a 1.1.0 upgrade script. Documents created by earlier versions keep working; only the duplicate guard cannot confirm them by order reference (they carry none) and falls back to amount/contractor matching.

## Capabilities

### New Capabilities
- `receipt-duplicate-guard`: detecting documents already present in iFirma for an order, adopting confirmed matches, blocking suspected ones and the admin resolution actions (link / issue anyway).
- `receipt-auto-issuance`: issuing a document when an order reaches the configured status, including hold conditions, order-reference stamping, failure recording and the no-blind-retry rule.
- `receipt-delivery`: delivering an issued document to the customer by e-mail through the configured channel, recording delivery state, and manual resend.
- `receipt-status-sync`: refreshing a document's remote state from iFirma (paid, sent, draft, KSeF, correction, missing) on demand, opportunistically and via cron, within rate limits.
- `customer-receipt-visibility`: what a logged-in customer sees about their receipt on the order detail page and in the account block at every lifecycle stage.
- `invoice-data-change-request`: customer-submitted billing-data correction requests, their lifecycle, merchant notification, admin approval/rejection and the resulting payload override.
- `ifirma-api-compliance`: constraints the module honours when talking to iFirma: request signing, payload field limits, error-code mapping, rate limiting and request accounting.

### Modified Capabilities
<!-- No main specs exist yet; every capability above is new. -->

## Impact

- **Code:** `ifirma.php` (hooks, config form, new config keys, upgrade), `classes/IfirmaApiClient.php` (detail GET, send, limit, error mapping, rate guard), `classes/IfirmaReceiptService.php` (pipeline, payload fixes, override), `classes/IfirmaReceipt.php` (new fields), new classes for duplicate matching, delivery, sync and data requests, `controllers/admin/AdminIfirmaController.php` (new ajax actions), new front controllers `datarequest` and `cron`, templates for admin panel / order detail / account block / request form, new `mails/` templates, `upgrade/upgrade-1.1.0.php`, `sql/`.
- **Data:** new columns on `ps_ifirma_receipt`; new table `ps_ifirma_data_request`; new `ps_configuration` keys (send channel, duplicate window, sync interval, cron token, merchant notification e-mail, data-request switch).
- **External API:** new iFirma calls: `GET faktury.json` with filters, `GET {type}/{id}.json`, `POST {type}/send/{id}.json`, `GET abonent/limit.json` (optional, needs the `abonent` key).
- **Test stack (separate git repo under `test-stack/`):** mock endpoints and pytest cases; docker smoke flow.
- **CI:** new PHPUnit job; mock job extended.
- **Docs:** README sections for the pipeline, duplicate guard, delivery, cron scheduling, data requests and the iFirma-integrator caveat.
