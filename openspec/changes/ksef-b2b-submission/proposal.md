# Proposal

## Why

Polish law requires B2B invoices to be sent to the National e-Invoicing System (KSeF) from 2026, while B2C invoices do not have to be. The module issues documents through iFirma but has no way to get them into KSeF, so a merchant who issues B2B invoices through the module has to submit each one by hand in iFirma. The merchant asked for this on 2026-11-02, and iFirma exposes a send endpoint for KSeF (`POST {type}/ksef/send/{id}.json`) for the document types the module uses, so the module can submit automatically. The iFirma response for this endpoint is not documented, and submissions cannot be withdrawn, so the feature has to be explicit about what it sends and what it records.

## What Changes

- **Submission mode setting.** `IFIRMA_KSEF_MODE` selects what is submitted automatically: `b2b` (default: invoices whose buyer has a NIP), `all` (every invoice), or `off` (never automatically; the manual button stays available). Unknown stored values are normalised to `b2b`.
- **Pipeline step `ksef`.** The issuing pipeline gets a `ksef` step between `number` and `deliver`. The step is skipped with a recorded reason when KSeF submission is disabled, when the buyer is private in B2B mode, when the document is already in KSeF, or when the document type is unsupported. It records `ok` when iFirma accepts the submission (receipt `ksef_status` = `WYSLANA`, `ksef_sent_at` stored) and `failed` otherwise. A failed step is non-blocking: delivery still runs, the outcome stays `issued`, the result message carries `KSeF: <error>`, and the receipt stores `ksef_status` = `BLAD` and `ksef_error`.
- **B2B detection.** A document counts as B2B when the stored request payload carries a buyer `Kontrahent.NIP`, falling back to the NIP of the invoice address.
- **Status synchronisation.** The module's `WYSLANA` marker is kept until iFirma reports a non-empty `KsefStatus` (for example `PRZYJETA`). `KsefNumer` is stored whenever iFirma returns it.
- **Admin panel.** A KSeF row in the order panel shows one readable state: not sent (with the reason), sent at (awaiting iFirma), accepted (with the KSeF number), or error (with the message). A **Send to KSeF** button forces submission regardless of the mode and is never offered when the document is already accepted.
- **Customer order page.** The order page shows the KSeF number when one is present.
- **Schema.** Columns `ksef_sent_at` (DATETIME NULL) and `ksef_error` (TEXT NULL) are added lazily (idempotent, checked against `information_schema`) and in the 1.1.0 upgrade script.
- **Mock.** The test-stack iFirma mock gains the KSeF send endpoint plus `/__mock/ksef/accept/{id}` and `/__mock/ksef/reject/{id}` helpers that drive the `KsefStatus` returned by the list and detail endpoints.
- **Documentation.** README gains a "KSeF (e-invoicing)" section, the `IFIRMA_KSEF_MODE` configuration entry and a troubleshooting entry for `KSeF: <error>`.

## Capabilities

### New Capabilities
- `ksef-submission`: automatic and manual submission of issued invoices to KSeF through iFirma, the submission mode setting, the pipeline step, the admin and customer visibility of the KSeF state, and the status marker rules.

### Modified Capabilities
<!-- No main specs are changed by this proposal. The following capabilities are affected by behaviour changes and are described here so reviewers can find them; their main specs are not edited:
- `receipt-auto-issuance`: the pipeline gains the `ksef` step between `number` and `deliver`; the step is non-blocking.
- `receipt-status-sync`: `WYSLANA` is kept until iFirma reports a non-empty `KsefStatus`; `KsefNumer` is stored whenever present. -->

## Impact

- **Code:** `classes/IfirmaKsefService.php` (new: mode normalisation, B2B detection, submission, state derivation), `classes/IfirmaApiClient.php` (KSeF send call), `classes/IfirmaIssuancePipeline.php` (new `ksef` step), `classes/IfirmaStatusSyncService.php` (marker rule and `KsefNumer` storage), `classes/IfirmaSchema.php` (lazy columns), `classes/IfirmaReceipt.php` (new fields), `classes/IfirmaAdminActions.php` (send action), `controllers/admin/AdminIfirmaController.php` (ajax action), `ifirma.php` (config keys, hooks, upgrade call), admin and order-detail templates, `upgrade/upgrade-1.1.0.php`, test-stack mock, README.
- **Data:** new columns `ksef_sent_at` and `ksef_error` on `ps_ifirma_receipt`; new configuration key `IFIRMA_KSEF_MODE`.
- **External API:** new iFirma call `POST {type}/ksef/send/{id}.json` with body `{"DataWysylki": null}` (null = now; `YYYY-MM-DD` = scheduled). Only `fakturakraj` is used by the module today.
- **Tests:** PHPUnit tests for mode normalisation, B2B detection, step outcomes and status marker rules; pytest cases for the mock endpoint and helpers; Docker smoke run of the pipeline with KSeF enabled.

## Risks

- **Submission cannot be withdrawn.** Once iFirma accepts a submission the invoice is in KSeF and cannot be cancelled through the module. Mitigation: the default mode submits only B2B invoices, the manual button is never offered for accepted documents, and the README warns merchants before they enable `all`.
- **iFirma account may auto-submit.** The iFirma account may already submit invoices to KSeF on its own, so the module would send a second submission or see a document that is already in KSeF. Mitigation: the step skips documents with a KSeF number or accepted status, and the open question on iFirma's own auto-submission setting must be answered by the merchant before release.
- **Response wording unknown.** The iFirma response for the send endpoint is undocumented. Mitigation: only `Kod` 0 counts as accepted; any other response, or a response without a parseable `Kod`, is recorded as `failed` with the message, and the admin can retry with the button.
