# Tasks

Every task follows design D1:
1. Characterise the touched lines.
2. Add a golden comparison where the output is data.
3. Split.
4. The full PHPUnit suite passes.
5. `diff-cover` reports 100% on changed lines.

No task starts on a red tree. Prerequisites: `refactor-clean-code-tda` is applied and its CI gates exist, and `refactor-tda-followups` has landed, because it touches several of the same classes.

Like `refactor-clean-code-tda`, the change lands straight on master: one commit per task (a characterisation commit may come first), no branch and no pull request (the user's choice, 2026-11-05). The local gate runs before each commit: the whole suite with pcov, then diff-cover against the task's base commit. CI repeats it after the push.

## 1. Small seams

- [ ] 1.1 Move the two sync queries from `IfirmaStatusSyncService` (`~322-414`) into static finders `IfirmaReceipt::findDueForSync()` / `countDueForSync()`, with tests against the Db stub. Verify that StatusSyncServiceTest is green and diff-cover is at 100%.
- [ ] 1.2 Extract `IfirmaCustomerInvoiceProfile` (company, phone and NIP fallbacks loaded once) from `IfirmaCustomerContractorService::defaultCriteria` / `customerNip` / `invoiceAddresses`, with unit tests for each fallback. Verify that CustomerContractorServiceTest is green, `invoiceAddresses` is called once per request (asserted with a counting stub), and diff-cover is at 100%.
- [ ] 1.3 Move the register-availability decision from `ifirma.php::hookDisplayAdminCustomers` into `IfirmaCustomerContractorService::registerAvailability($idCustomer)`, and the order-detail options assembly into `IfirmaCustomerView`. Verify that the ModuleHooksTest panel and order-detail tests are green and diff-cover is at 100%.
- [ ] 1.4 Add `IfirmaDataRequestService::defaultsFromAddress(Address)` and `collectInput(array)`, with unit tests. Make `controllers/front/datarequest.php` use them, and cache the service locally. Verify that DataRequestServiceTest is green and diff-cover is at 100% on the service.
- [ ] 1.5 Extract a shared `IfirmaHttpOutput::pdf()` used by `controllers/front/receipt.php` and `AdminIfirmaController::streamPdf`, with a unit test of the header list using a header-capturing seam. Verify that AdminControllerCustomerTest is green and the PDF downloads on the test-stack shop.

## 2. Contractor resolution steps

- [ ] 2.1 Split `IfirmaContractorService::ensure` into per-strategy resolvers (design D6) plus a `lookupFailed()` helper. Verify that every ContractorServiceTest case is green and unchanged (the class grew well beyond 57 cases during `refactor-clean-code-tda`), `ensure` is 40 lines or fewer, and diff-cover is at 100%.

## 3. Pure cores for counting and import

- [ ] 3.1 Extract `IfirmaRemoteDocumentCollector` and a pure `tally()` from `remoteCount`, and add direct tally tests (empty, mixed types, filter fallback). Verify that the ~10 `remoteCount` tests are green, unchanged, and diff-cover is at 100%.
- [ ] 3.2 Extract the `CustomerIndex` loader and a pure matcher from `IfirmaContractorImport::match`, and add matcher tests that need no Db stub. Verify that ContractorImportTest (22) is green, the import report output equals the golden output for the existing fixtures, and diff-cover is at 100%.

## 4. Duplicate guard

- [ ] 4.1 Introduce a `GuardSettings` value object (options plus config resolved once, named defaults replacing 0.01/60/10) and split `check()` into `resolveSettings` / `scanRemote` / `classify`. Add pure `classify` tests. Verify that DuplicateGuardTest (38) and DuplicateMatcherTest are green, `check` is 40 lines or fewer, and diff-cover is at 100%.

## 5. Receipt creation

- [ ] 5.1 Add golden payload tests: capture the encoded request payloads for every CreateReceiptTest and PayloadBuilderTest scenario on unchanged code, and store them as fixtures under `tests/fixtures/payloads/`. Verify that they pass on unchanged code.
- [ ] 5.2 Split `createReceipt` into `postWithRetries()` (returns the response and the final payload) and `parseCreateResponse()` (returns a small value object), then apply. Verify that the golden payloads are equal, CreateReceiptTest is green, `createReceipt` is 40 lines or fewer, and diff-cover is at 100%.
- [ ] 5.3 Extract `IfirmaPayloadBuilder` and `IfirmaDocumentSettings` (design D3), with temporary delegating static wrappers on ReceiptService. Retarget PayloadBuilderTest to the new class, keeping the assertions verbatim. Register the new files in the module loader. Verify that the golden payloads are equal, the suite is green, and diff-cover is at 100%.
- [ ] 5.4 Point the DuplicateGuard, DeliveryService and other static callers at the new classes, then remove the wrappers. Verify that `git grep -n "IfirmaReceiptService::\(truncate\|buildOrderReference\|normalize\)"` is empty and the suite is green.

## 6. Issuance pipeline

- [ ] 6.1 Add intent methods `markIssued`, `markAdopted`, `stopAt` and `alreadyIssued` to `IfirmaPipelineResult`, with unit tests. Verify that they pass.
- [ ] 6.2 Split `IfirmaIssuancePipeline::execute` into the step methods `stepHold`, `stepGuard`, `stepCreate`, `stepNumber`, `stepKsef` and `stepDeliver`, which return a stop or null (design D2). Remove the outcome switch and the `$adopted` flag. Verify that IssuancePipelineTest (40) is green, unchanged, step names and messages are identical, `execute` is 40 lines or fewer, and diff-cover is at 100%.

## 7. Module class extractions

- [ ] 7.1 Add a golden test of the full Configuration write set from `postProcessConfiguration` for one fully valid input and one input per validation error. Verify that it passes on unchanged code.
- [ ] 7.2 Introduce the `IfirmaSettings` table plus `fromInput()` (design D5), and make `postProcessConfiguration` loop over it. Error-message literals stay in `ifirma.php`. Verify that the golden write set is equal, the ModuleHooksTest `getContent` tests and TranslationCoverageTest are green, and diff-cover is at 100%.
- [ ] 7.3 Add a golden test of the full Smarty assignment array from `renderAdminOrderPanel` for the existing ModuleHooksTest admin-panel fixtures. Verify that it passes on unchanged code.
- [ ] 7.4 Extract `IfirmaAdminOrderPanelView` and `IfirmaAdminPanelPresenter`. Move the request-payload digging into `IfirmaReceipt::sentPaymentMethod()` / `contractorNote()` and the `canCreate()` / `canIssueAnyway()` decisions onto the receipt. Verify that the Smarty array is equal to golden, TranslationCoverageTest is green, `ifirma.php` loses those helpers, and diff-cover is at 100%.

## 8. Admin actions split

- [ ] 8.1 Split `IfirmaAdminActions` into receipt, customer and data-request action classes plus `IfirmaActionSupport`, behind the existing facade (design D4). Remove the `customerRegister` alias and its existence test after confirming that the controller and templates do not call it (`git grep -n customerRegister`). Verify that AdminActionsTest and AdminControllerCustomerTest are green and diff-cover is at 100%.

## 9. Runtime schema checks (gated)

- [ ] 9.1 Extend UpgradeTest to prove that fresh install, upgrade 1.1.0 to 1.2.0, and an install that predates the contractor map table all end with the contractor map table (identifier length 64) and the KSeF receipt columns. Verify that it is green.
- [ ] 9.2 If 9.1 proves every path, remove the runtime `ensure*()` calls from IfirmaContractorMap, IfirmaIssuancePipeline and IfirmaKsefService. Otherwise replace them with one cached check per request at the entry points, and record which path failed in this task's notes. Verify that the suite is green and diff-cover is at 100%.

## 10. Integration check

- [ ] 10.1 Run the full CI on master after the last task, install the build on the test-stack shop, and run `php tests/smoke/run.php` plus the upgrade smoke (`upgrade-phase1.php` then `upgrade-phase2.php`) in the container. Exercise issue, adopt, deliver, KSeF submit, data-request approve, and the contractor import. Verify that the behaviour matches the pre-change build and that the mock API reports no signature or payload errors.
- [ ] 10.2 Run `graphify update .` and `openspec validate refactor-srp-splits`. Verify that both succeed.

## Workflow follow-up

- Archive the change after review.
