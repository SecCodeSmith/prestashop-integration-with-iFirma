# Design

## Context

- This change builds on `refactor-clean-code-tda`. It assumes the following exist:
  - `IfirmaApiClient::fromConfiguration()`
  - the `IfirmaReceipt` and `IfirmaDataRequest` intent methods
  - `IfirmaDeliveryResult`, `IfirmaContractorResolution` and `IfirmaPipelineResult::OUTCOME_*`
  - the CI coverage and diff-cover gate, and the PHP 7.2 lint
- PrestaShop constraints:
  - Hooks must stay on the `Ifirma` module class.
  - The translation scanner only picks up `$this->l('literal')` calls in the module file, and in controllers through their own `l()`.
  - Classes are loaded through `require_once` / autoload in `ifirma.php`, so every new file must be registered where the existing classes are.
- Existing coverage by area:

| Area | Tests |
|---|---|
| Receipt create and payload | CreateReceiptTest 20, PayloadBuilderTest 31 |
| Pipeline | 40 |
| Duplicate guard | 38 |
| Contractor service | 57 |
| Customer contractor | ~10 for `remoteCount` |
| Contractor import | 22 |
| Settings POST | ~34 `getContent()` calls in ModuleHooksTest |
| Admin panel | ModuleHooksTest `testAdminPanel*` |
| Admin actions | AdminActionsTest, AdminControllerCustomerTest |

## Goals / Non-Goals

**Goals:**
- No method in the touched classes is longer than about 40 lines. Each extracted unit has one reason to change.
- Pure decision logic (tally, classify, match, validate) can be unit-tested without the Db or Configuration stubs.
- External behaviour is the same, byte for byte, in API payloads, saved fields and Smarty assignments.

**Non-Goals:**
- New features, new document types or new channels. The `DocumentType` descriptor stays deferred until a second type exists.
- Interfaces for `Configuration`, `Db` or `Context`.
- Changing `IfirmaApiClient::execute`, which the review judged linear and well tested.

## Decisions

### D1. Same test-first protocol as `refactor-clean-code-tda` D1
Each task follows the same four steps:
1. Characterise the touched lines.
2. Split.
3. The full suite passes.
4. `diff-cover` reports 100%.

Each split also gets a **golden comparison** where the output is data:
- For `createReceipt` and the payload builder, captured request payloads from the existing tests are asserted equal before and after.
- For `renderAdminOrderPanel`, the full Smarty assignment array is asserted equal.
- For `postProcessConfiguration`, the full set of Configuration writes is asserted equal for valid and invalid inputs.

### D2. Steps return explicit continue or stop, not shared flags
The `Pipeline::execute` steps and the `createReceipt` phases each return `null` (continue) or a terminal `IfirmaPipelineResult`. `execute` becomes a short sequence of `if ($stop = $this->stepX(...)) { return $stop; }`. This replaces the `$adopted` flag and the outcome switch. Result mutation goes through the `IfirmaPipelineResult` intent methods (`markIssued`, `markAdopted`, `stopAt`).
- *Alternative:* a chain-of-responsibility list of step objects. Rejected as over-engineering for six fixed steps.

### D3. Split ReceiptService along existing static seams
- `IfirmaPayloadBuilder` takes `buildInvoicePayload`, `buildContractor`, `orderProductLines`, `buyerNames`, `fieldLimits`, `truncate` and `buildOrderReference`.
- `IfirmaDocumentSettings` takes `normalizeDocumentType`, `normalizeSignatureType`, `signatureTypes` and `enabledDocumentTypes`.

During the change, ReceiptService keeps thin delegating `public static` wrappers for any method that DuplicateGuard, DeliveryService or a template calls. Once a grep shows none remain, the wrappers are removed in the last task. Tests are moved to target the new classes, with the assertions kept verbatim.

### D4. AdminActions split behind a facade
`IfirmaAdminActions` stays as the class the controller instantiates. It delegates to `IfirmaReceiptActions`, `IfirmaCustomerActions` and `IfirmaDataRequestActions`, which share the `guard()` and `failure()` helpers through a small `IfirmaActionSupport` base class. The `setCustomerService` test seam stays on the facade and is forwarded.
- *Alternative:* have the controller dispatch by area. This was deferred so that `AdminIfirmaController` and its tests stay unchanged.

### D5. Module-class extractions keep `l()` in `ifirma.php`
`IfirmaAdminOrderPanelView`, `IfirmaAdminPanelPresenter` and `IfirmaSettings` receive a `labels` array built in `ifirma.php` from literal `$this->l()` calls. `IfirmaSettings` is a table of `key => (type, min, max, default)`. Its `fromInput(array)` returns the normalised values and the errors, and `ifirma.php` persists them by looping over the table. The error messages stay as `l()` literals in the module, keyed by setting.

### D6. Pure cores, thin I/O shells
- `remoteCount`: `IfirmaRemoteDocumentCollector` does the date windows, paging and call budget, and returns the documents plus coverage. `tally(array $docs)` is a pure function.
- `ContractorImport::match`: a `CustomerIndex` loader does the only Db reads, and the matching and ranking is a pure function over the indexes.
- `DuplicateGuard::check`: a `GuardSettings` value is resolved once, followed by `scanRemote()` and a pure `classify()`.
- `ContractorService::ensure`: `resolveFromMapping`, `resolveByIdentifier`, `resolveBySearch` and `createModuleContractor`, each returning an `IfirmaContractorResolution` or null.

### D7. Removing runtime schema checks is a gated, last step
The runtime `ensureContractorMapTable()` and `ensureReceiptKsefColumns()` calls are removed only after `UpgradeTest` proves that every path a supported shop can take creates the table and the columns:
- fresh install
- 1.0.x to 1.1.0 to 1.2.0
- 1.1.0 to 1.2.0

If any path cannot be proven, the runtime check stays, but it is cached once per request at the entry point instead of in each model method.

## Risks / Trade-offs

- [A subtle payload change reaches the iFirma API] → Golden payload equality (D1) plus a smoke run against the mock API, which validates the HMAC signature and the payload shape.
- [A string moving out of `ifirma.php` drops from translations] → `TranslationCoverageTest` must stay green. D5 keeps the literals in place.
- [A new class file is not loaded in production] → Each task adds the file to the module's loader. The integration task installs the built zip on the test-stack shop and exercises every hook.
- [The change is large and drawn out] → Each task is independently mergeable and reverts cleanly. The order goes from lowest to highest coupling.
- [Removing the runtime ensure calls breaks a shop whose upgrade failed half-way] → D7 gating, plus the fallback of caching per request.

## Migration Plan

The tasks are applied in order, each as its own commit or PR with green CI. There is no data migration. The D7 step is the only one that touches install and upgrade behaviour, and it lands last. Rollback is a revert of the task commit.
