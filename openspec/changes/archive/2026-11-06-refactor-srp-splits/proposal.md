# Proposal

## Why

After `refactor-clean-code-tda` removes duplication and moves state transitions onto entities, the remaining Single Responsibility debt sits in a few long methods and large classes:

| Method | Lines |
|---|---|
| `IfirmaReceiptService::createReceipt` | 129 |
| `IfirmaIssuancePipeline::execute` | 149 |
| `IfirmaDuplicateGuard::check` | 155 |
| `IfirmaCustomerContractorService::remoteCount` | 125 |
| `IfirmaContractorImport::match` | 130 |
| `Ifirma::renderAdminOrderPanel` | 135 |
| `Ifirma::postProcessConfiguration` | 135 |
| `IfirmaContractorService::ensure` | ~90 |

| Class | Lines |
|---|---|
| `IfirmaReceiptService` | 946 |
| `IfirmaAdminActions` | 811 |

Each of these mixes several concerns, such as validation, I/O, parsing, decisions and presentation. That makes the next feature (another document type, another delivery channel) touch code it does not need. They are all well covered by tests, so they can be split safely, one at a time, under the same test-first gate.

## What Changes

- **Long methods become named steps:**
  - `createReceipt` becomes validate, build payload, post with retries, parse response, apply.
  - `Pipeline::execute` becomes per-step methods that each return continue or stop.
  - `DuplicateGuard::check` becomes settings resolution, remote scan and classification.
  - `remoteCount` becomes a window/page collector plus a pure tally.
  - `ContractorImport::match` becomes a DB-only customer index loader plus a pure matcher.
  - `ContractorService::ensure` becomes per-strategy resolvers.
- **Large classes are split by responsibility:**
  - `IfirmaReceiptService` becomes `IfirmaPayloadBuilder` (payload, contractor, product lines, truncation and order reference helpers), `IfirmaDocumentSettings` (document and signature type normalisers), and a thin API orchestrator.
  - `IfirmaAdminActions` becomes receipt, customer and data-request action classes behind the existing controller entry point. The unused `customerRegister` alias is removed.
- **The module class sheds view-model assembly:**
  - The admin order panel data is built by an `IfirmaAdminOrderPanelView`.
  - Settings POST validation and normalisation goes to an `IfirmaSettings` table of keys and bounds.
  - Presentation helpers go to an `IfirmaAdminPanelPresenter`, which receives pre-translated labels so `l()` literals stay in `ifirma.php`.
- **Smaller seams:**
  - The sync queries move to static finders on `IfirmaReceipt`.
  - The customer invoice profile (company, phone, NIP fallbacks) is loaded once by a dedicated source.
  - The register-availability decision moves into the customer contractor service.
  - Front-controller defaults move into the data-request service.
  - PDF streaming is shared between the front and admin controllers.
- **Runtime schema checks:** the `ensure*()` DDL checks leave hot paths and run only in install and upgrade. This step is gated on confirming that every supported upgrade path creates the columns.
- No merchant-visible behaviour, schema, configuration key, hook or translation string changes.

## Capabilities

### New Capabilities
<!-- none: pure refactor, skip_specs is set -->

### Modified Capabilities
<!-- none -->

## Impact

- `ifirma.php`; `classes/` (ReceiptService, IssuancePipeline, DuplicateGuard, CustomerContractorService, ContractorService, ContractorImport, StatusSyncService, AdminActions, IfirmaSchema, IfirmaContractorMap, IfirmaKsefService); `controllers/admin/AdminIfirmaController.php`, `controllers/front/receipt.php`, `datarequest.php`.
- New classes: PayloadBuilder, DocumentSettings, the three AdminActions classes, AdminOrderPanelView, AdminPanelPresenter, Settings, CustomerInvoiceProfile, the remote-document collector, CustomerIndex and an HTTP output helper.
- Tests: PayloadBuilderTest and CreateReceiptTest are retargeted to the new classes, keeping their assertions. New unit tests are added for each extracted pure function.
- Depends on `refactor-clean-code-tda` being applied and archived first (it needs the client factory, intent methods, result objects and the coverage gate).
