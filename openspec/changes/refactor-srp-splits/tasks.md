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

- [x] 1.1 Move the two sync queries out of `IfirmaStatusSyncService`, with tests against the Db stub:
  - the receipts query of `selectForCron()` into the static finder `IfirmaReceipt::findDueForSync($staleBefore, $limit)`;
  - the count in `countHeldOrders()` into `IfirmaDataRequest::countOrdersOnHold()`, because it reads the data request table. The plan first named it `IfirmaReceipt::countDueForSync()`, but it counts orders held by pending data requests, not receipts.

  Verify that StatusSyncServiceTest is green and diff-cover is at 100%.

  Evidence:
  - Characterisation `0d482ca` pins, through the service, the exact receipts SQL (indentation included), the 24-hour cut-off from the service's clock, the LIMIT clamping, the row order, the skipping of a receipt that does not load, and the exact held-orders count. It kills 29 of 29 mutants of the old code.
  - Refactor `809f191`: both queries moved byte for byte, and the service methods stay public and delegate. The service now requires `IfirmaDataRequest.php` itself.
  - 39 of 40 mutants of the new code are killed. The survivor drops an `(int)` cast that the ObjectModel applies anyway.
  - Gate against `3f6dfd2`: `OK (1668 tests)`, 17 changed lines, 100%.
  - Review follow-ups `11ada5e` and `c5ec5f1`: the three copies of the SQL capture helper, which turns CRLF into LF, are now one trait, `IfirmaSentQueries`.
- [x] 1.2 Extract `IfirmaCustomerInvoiceProfile` (company, phone and NIP fallbacks loaded once) from `IfirmaCustomerContractorService::defaultCriteria` / `customerNip` / `invoiceAddresses`, with unit tests for each fallback. Verify that CustomerContractorServiceTest is green, `invoiceAddresses` is called once per request (asserted with a counting stub), and diff-cover is at 100%.

  Evidence:
  - Characterisation `9555ce3` and `65e8171` pin the company, phone and NIP fallbacks, the address order and the number of loads.
  - Refactor `f7ac84b`: `IfirmaCustomerInvoiceProfile::load()` reads the customer and the invoice addresses once. `defaultCriteria()` used to read them twice when the first address had no NIP; it now reads them once, and `customerNip()` reads them once.
  - Additive counting hooks assert the number of reads: `Order::$customerOrdersHook`, `Customer::$addressesHook` and `IfirmaTestRegistryTrait::$loads`.
  - The reviewer compared the old and new answers over 3000 random fixtures and found no difference.
  - Gate against `03ed8e6`: `OK (1785 tests)`, 54 changed lines, 100%.
  - Review follow-up `30a2355`:
    - The profile test no longer loads the profile class itself, so the suite now fails if the service loses its `require_once` line.
    - `customerNip()`'s customer argument is pinned.
- [x] 1.3 Move the register-availability decision from `ifirma.php::hookDisplayAdminCustomers` into `IfirmaCustomerContractorService::registerAvailability($idCustomer)`, and the order-detail options assembly into `IfirmaCustomerView`. Verify that the ModuleHooksTest panel and order-detail tests are green and diff-cover is at 100%.

  Evidence:
  - Characterisation `1d02a68` and `9de3b31` pin `can_register` and `register_reason` for a switched-off registration, a customer without orders and an available one, every order-detail option, and a language with an empty state name.
  - Refactor `2c9d23a`:
    - `IfirmaCustomerContractorService::registerAvailability()` (11 lines) returns a reason code. `ifirma.php` maps that code to its two `l()` literals, which stay in the module file.
    - `IfirmaCustomerView::orderOptions()` (12 lines) reads the configuration and names the trigger state in the shop's language. It goes through `Context::getContext()` because PrestaShop's `Module::$context` is protected. `Ifirma::orderStateName()` is gone.
  - A differential run compared old and new over 194,040 option combinations and 576 register cases: no difference. 51 of 52 mutants are killed; the survivor drops a redundant cast.
  - Gate against `d708c46`: `OK (2296 tests)`, 27 changed lines, 100%.

- [x] 1.4 Add `IfirmaDataRequestService::defaultsFromAddress(Address)` and `collectInput()`, with unit tests. Make `controllers/front/datarequest.php` use them, and cache the service locally. Verify that DataRequestServiceTest is green and diff-cover is at 100% on the service.

  `collectInput()` takes no argument, although the plan wrote `collectInput(array)`. It reads every field of `fields()` through `Tools::getValue()`, which sanitises each value; the raw request array would not.

  Evidence:
  - Characterisation `45cc33a`, then refactor `2503262`: `defaultsFromAddress()` returns the controller's former default values verbatim, and `collectInput()` holds the controller's former loop.
  - The controller's `service()` already memoised the service, so that part needed no change.
  - The Country stub gained `getCountries()`, so that the controller's countries line is pinned too.
  - Gate against `f7ac84b`: `OK (1821 tests)`, 20 changed lines, 100%, controller lines included.
  - Review follow-ups `1ff472c`, `188ca78` and `44e5966`:
    - Untrimmed input and a company named `0` are pinned.
    - The move had left the vat_number/dni fallback twice in the service. It is now one private helper (3 changed lines, 100%).
- [x] 1.5 Extract a shared `IfirmaHttpOutput::pdf()` used by `controllers/front/receipt.php` and `AdminIfirmaController::streamPdf`, with a unit test of the header list using a header-capturing seam. Verify that AdminControllerCustomerTest is green and the PDF downloads on the test-stack shop.

  Design: a line followed by `exit` cannot be covered by a test, and no new gate exemption was wanted. So:
  - `pdf()` discards one output buffer level, sends the four headers through a replaceable header sink (`useHeaderSink()`), and echoes the body. It does not exit.
  - Each controller keeps its own filename computation and its unchanged `exit;` line, so the diff adds only one call per controller.
  - HttpOutputTest drives both controllers end to end with a sink that throws after the fourth header.

  Evidence:
  - Refactor `03ed8e6`. Gate against `809f191`: `OK (1676 tests)`, 10 changed lines, 100%.
  - On the test-stack shop, a customer download from the front office and an employee download from the back office were each read to the end of the stream. The old and new controllers gave identical reports: HTTP 200, the same four headers, 897 bytes equal to Content-Length, and byte-identical PDFs.
  - Review follow-ups `5d8bf7d`, `a9e2c32` and `a654bff`:
    - The buffer discard before the headers is pinned.
    - `ControllerClassLoadingTest` loads one controller in a fresh PHP process and fails if the controller loses its `require_once` of `IfirmaHttpOutput`, or of `IfirmaHttpStatus` in `cron.php`. The shop check also reads each download to the end of the stream, so output after the PDF would show.

## 2. Contractor resolution steps

- [x] 2.1 Split `IfirmaContractorService::ensure` into per-strategy resolvers (design D6) plus a `lookupFailed()` helper. Verify that every ContractorServiceTest case is green and unchanged (the class grew well beyond 57 cases during `refactor-clean-code-tda`), `ensure` is 40 lines or fewer, and diff-cover is at 100%.

  Evidence:
  - Characterisation `28efbde`: 23 tests pin, for every path:
    - the ordered API calls and the contractor map writes;
    - the unlinking of a vanished mapping;
    - no map access without a customer or with the sync off;
    - the trimming of identifiers and the hints `ensure()` completes.

    They kill 10 of the 12 mutants of the old `ensure()` that the existing tests let survive. The other two are equivalent.
  - Refactor `29c54a4`: `ensure()` is 26 lines: `resolveFromMapping`, `resolveByIdentifier`, `resolveBySearch` and `createModuleContractor`, plus the helper. The helper is named `fallbackOnFailedLookup()`, after what it decides: the answer when a lookup fails.
  - All 101 earlier ContractorServiceTest cases are unchanged and green. The split kills 61 of 61 mutants.
  - Gate against `2503262`: `OK (1844 tests)`, 37 changed lines, 100%.
  - `createAndResolve()`, added by `refactor-clean-code-tda`, is still 66 lines. It is outside this task.
  - Review follow-ups `a802956` and `5cbd021`: customer 1, at the boundary of every customer guard, and a padded identifier are pinned.

## 3. Pure cores for counting and import

- [x] 3.1 Extract `IfirmaRemoteDocumentCollector` and a pure `tally()` from `remoteCount`, and add direct tally tests (empty, mixed types, filter fallback). Verify that the ~10 `remoteCount` tests are green, unchanged, and diff-cover is at 100%.

  Evidence:
  - Characterisation `7ab3482`, then refactor `8ec1c5a`:
    - `IfirmaRemoteDocumentCollector` makes every API call: the date windows, the paging and the call budget. It returns the documents with their coverage.
    - The pure static `tally()` (26 lines) counts them.
    - `remoteCount()` shrank from about 125 lines to 24.
    - The `REMOTE_*` constants moved to the collector, as `CALL_BUDGET`, `PAGE_SIZE`, `MAX_YEARS` and `WINDOW_DAYS`, with the same values.
  - The existing remoteCount tests are unchanged and green. The reviewer's seeded comparison of old and new over 3000 scenarios found no difference.
  - Gate against `2c9d23a`: `OK (2456 tests)`, 113 changed lines, 100%.

- [x] 3.2 Extract the `CustomerIndex` loader and a pure matcher from `IfirmaContractorImport::match`, and add matcher tests that need no Db stub. Verify that ContractorImportTest (22) is green, the import report output equals the golden output for the existing fixtures, and diff-cover is at 100%.

  Evidence:
  - Characterisation `7e843b5` passes on unchanged code. It adds a golden report for the existing fixtures (`tests/fixtures/contractor-import/golden.json`) and matching tests.
  - Refactor `7e1e6fc`:
    - `IfirmaCustomerIndex::load()` holds the only Db reads, the three queries byte for byte, and a pure `fromRows()`.
    - The static, pure `IfirmaContractorMatcher::match()` (23 lines) owns the ranking.
    - `IfirmaContractorImport::match()` now loads the index and delegates in 4 lines.
  - The golden report is equal. ContractorImportTest has 26 tests and 85 assertions, not the 22 the plan counted; it is unchanged and green. The matcher tests use no Db stub.
  - Gate against `29c54a4`: `OK (1981 tests)`, 111 changed lines, 100%.
  - Review follow-ups `1761d62`, `dc7d16e` and `5cbd021`:
    - The exact identifier comparison is pinned: a stored `007` is not the CSV's `7`.
    - The failing-read test helper is one trait.

## 4. Duplicate guard

- [x] 4.1 Introduce a `GuardSettings` value object (options plus config resolved once, named defaults replacing 0.01/60/10) and split `check()` into `resolveSettings` / `scanRemote` / `classify`. Add pure `classify` tests. Verify that DuplicateGuardTest (38) and DuplicateMatcherTest are green, `check` is 40 lines or fewer, and diff-cover is at 100%.

  Evidence:
  - Characterisation `105e460`, `27b6ad1` and `52aac99` pins `check()`'s outcomes, its read counts and when it reads the order and the clock, plus the search window across a clock change.
  - Refactor `a22a770`:
    - `IfirmaGuardSettings` resolves each option, then the configuration, then a named default, once. The defaults are `DEFAULT_AMOUNT_TOLERANCE` 0.01, `DEFAULT_WINDOW_DAYS` 60, `DEFAULT_MAX_DETAIL_CALLS` 10 and `DEFAULT_ENABLED`.
    - `check()` is 37 lines.
    - `scanRemote()` does all the I/O: the list queries, the pure `candidatesFrom()`, and the detail reads up to the cap. It stops at the first detail that carries the order reference, as before.
    - The pure `classify()` decides from the settings and the scan.
  - DuplicateGuardTest (51 tests) and DuplicateMatcherTest (23 tests) are unchanged and green.
  - Gate against `7e1e6fc`: `OK (2181 tests)`, 120 changed lines, 100%.
  - Review follow-ups `389a21d` and `19dbdc7`:
    - The undated candidate's order and an unreadable detail are pinned.
    - The settings docblock now says the `tolerance` option only selects candidates; `adopt()` decides paid with the configured value.

## 5. Receipt creation

- [x] 5.1 Add golden payload tests: capture the encoded request payloads for every CreateReceiptTest and PayloadBuilderTest scenario on unchanged code, and store them as fixtures under `tests/fixtures/payloads/`. Verify that they pass on unchanged code.

  Evidence: commit `bf196d8`, passing on unchanged code:
  - 56 fixtures under `tests/fixtures/payloads/` hold the encoded request bodies of every CreateReceiptTest and PayloadBuilderTest scenario.
  - CreateReceiptGoldenTest and PayloadBuilderGoldenTest compare each scenario with its fixture, through the `IfirmaGoldenPayloads` helper.
  - Gate against `a916d1c`: `OK (2665 tests)`.

- [x] 5.2 Split `createReceipt` into `postWithRetries()` (returns the response and the final payload) and `parseCreateResponse()` (returns a small value object), then apply. Verify that the golden payloads are equal, CreateReceiptTest is green, `createReceipt` is 40 lines or fewer, and diff-cover is at 100%.

  Evidence:
  - Characterisation `8f72950` and `2c9a01c` pin the receipt-creation outcomes and the reason of a rejection.
  - Refactor `87d3fd1`:
    - `postWithRetries()` (38 lines) posts the document with the contractor and DodatkoweUwagi retries, and returns the response and the final payload.
    - `parseCreateResponse()` (18 lines) returns the new value object `IfirmaCreateResponse`.
    - `createReceipt()` applies them in 40 lines.
  - The golden payloads are equal, and CreateReceiptTest is unchanged and green. The review approved it.
  - Gate against `bf196d8`: `OK (2714 tests)`, 41 changed lines, 100%.

- [ ] 5.3 Extract `IfirmaPayloadBuilder` and `IfirmaDocumentSettings` (design D3), with temporary delegating static wrappers on ReceiptService. Retarget PayloadBuilderTest to the new class, keeping the assertions verbatim. Register the new files in the module loader. Verify that the golden payloads are equal, the suite is green, and diff-cover is at 100%.
- [ ] 5.4 Point the DuplicateGuard, DeliveryService and other static callers at the new classes, then remove the wrappers. Verify that `git grep -n "IfirmaReceiptService::\(truncate\|buildOrderReference\|normalize\)"` is empty and the suite is green.

## 6. Issuance pipeline

- [x] 6.1 Add intent methods `markIssued`, `markAdopted`, `stopAt` and `alreadyIssued` to `IfirmaPipelineResult`, with unit tests. Verify that they pass.

  Evidence: refactor `11de148` adds `markIssued()`, `markAdopted()`, `alreadyIssued()` and `stopAt()`, plus `stopStep()` for a step that names where a run stopped without changing its outcome, each with unit tests. Gate against `8ec1c5a`: 16 changed lines, 100%.

- [x] 6.2 Split `IfirmaIssuancePipeline::execute` into the step methods `stepHold`, `stepGuard`, `stepCreate`, `stepNumber`, `stepKsef` and `stepDeliver`, which return a stop or null (design D2). Remove the outcome switch and the `$adopted` flag. Verify that IssuancePipelineTest (40) is green, unchanged, step names and messages are identical, `execute` is 40 lines or fewer, and diff-cover is at 100%.

  Evidence:
  - Characterisation `478eb8a`: 133 cases pin `ok`, `outcome`, `stoppedAt`, `message` and the step log of every outcome.
  - Refactor `a916d1c`: `execute()` is 17 lines, and the outcome switch and the `$adopted` flag are gone.
    - `stepHold`, `stepGuard` and `stepCreate` follow D2's `if ($stop = ...) { return $stop; }`.
    - `stepNumber`, `stepKsef` and `stepDeliver` are plain calls, because they never end a run: a failed KSeF send or delivery is logged, not terminal. A `return $stop` after them would be dead code that the 100% gate cannot cover.
    - Two direct writes remain on the result, because none of the four intents can express them without changing the step log: the KSeF error suffix on the message, and `fail()`.
  - IssuancePipelineTest has 63 methods (68 cases), not the 40 the plan counted. It is unchanged and green.
  - Gate against `11de148`: `OK (2609 tests)`, 107 changed lines, 100%.


## 7. Module class extractions

- [x] 7.1 Add a golden test of the full Configuration write set from `postProcessConfiguration` for one fully valid input and one input per validation error. Verify that it passes on unchanged code.

  Evidence: commit `b91c90c`, passing on unchanged code:
  - SettingsGoldenTest compares the full, ordered Configuration write set and the page's messages of 53 scenarios with `tests/fixtures/settings/golden.json`. The scenarios are one fully valid save and one input per validation branch.
  - The Configuration stub now also records the order of its writes.
  - Gate against `87d3fd1`: `OK (2771 tests)`.

- [ ] 7.2 Introduce the `IfirmaSettings` table plus `fromInput()` (design D5), and make `postProcessConfiguration` loop over it. Error-message literals stay in `ifirma.php`. Verify that the golden write set is equal, the ModuleHooksTest `getContent` tests and TranslationCoverageTest are green, and diff-cover is at 100%.
- [ ] 7.3 Add a golden test of the full Smarty assignment array from `renderAdminOrderPanel` for the existing ModuleHooksTest admin-panel fixtures. Verify that it passes on unchanged code.
- [ ] 7.4 Extract `IfirmaAdminOrderPanelView` and `IfirmaAdminPanelPresenter`. Move the request-payload digging into `IfirmaReceipt::sentPaymentMethod()` / `contractorNote()` and the `canCreate()` / `canIssueAnyway()` decisions onto the receipt. Verify that the Smarty array is equal to golden, TranslationCoverageTest is green, `ifirma.php` loses those helpers, and diff-cover is at 100%.

## 8. Admin actions split

- [ ] 8.1 Split `IfirmaAdminActions` into receipt, customer and data-request action classes plus `IfirmaActionSupport`, behind the existing facade (design D4). Remove the `customerRegister` alias and its existence test after confirming that the controller and templates do not call it (`git grep -n customerRegister`). Verify that AdminActionsTest and AdminControllerCustomerTest are green and diff-cover is at 100%.

## 9. Runtime schema checks (gated)

- [x] 9.1 Extend UpgradeTest to prove that fresh install, upgrade 1.1.0 to 1.2.0, and an install that predates the contractor map table all end with the contractor map table (identifier length 64) and the KSeF receipt columns. Verify that it is green.

  Evidence: commit `9f7c529`. Each path is checked without the runtime checks doing the work.
  - Proven: a fresh install, and 1.0.x to 1.1.0 to 1.2.0. Both end with the contractor map table (identifier VARCHAR(64)) and the four KSeF receipt columns.
  - Not proven: 1.1.0 to 1.2.0, and an install that predates the contractor map table. Development builds recorded as 1.1.0, made between 2026-11-02 and 2026-11-04 (before `882f929` and `4452d02`), lack the KSeF columns or the map table, and `upgrade-1.2.0.php` does not create them. Only the owner knows whether any shop ran such a build.
  - Gate against `b91c90c`: `OK (2786 tests)`.

- [ ] 9.2 If 9.1 proves every path, remove the runtime `ensure*()` calls from IfirmaContractorMap, IfirmaIssuancePipeline and IfirmaKsefService. Otherwise replace them with one cached check per request at the entry points, and record which path failed in this task's notes. Verify that the suite is green and diff-cover is at 100%.

## 10. Integration check

- [ ] 10.1 Run the full CI on master after the last task, install the build on the test-stack shop, and run `php tests/smoke/run.php` plus the upgrade smoke (`upgrade-phase1.php` then `upgrade-phase2.php`) in the container. Exercise issue, adopt, deliver, KSeF submit, data-request approve, and the contractor import. Verify that the behaviour matches the pre-change build and that the mock API reports no signature or payload errors.
- [ ] 10.2 Run `graphify update .` and `openspec validate refactor-srp-splits`. Verify that both succeed.

## Progress notes

- Wave 1 is on master: tasks 1.1, 1.2, 1.4, 1.5, 2.1, 3.2 and 4.1, plus their review follow-ups, applied in parallel.
  - Gate over the whole wave against `3f6dfd2`: `OK (2203 tests, 8334 assertions)`, 372 changed lines, 100%. The PHP 7.2 lint passes on 17 files, and the licence check on 167.
  - End-to-end smoke on the test-stack shop: 90 passed and 1 skipped. All 91 check lines are identical to the run before this change.
  - The PDF download check passes for the front office and the back office.
- Wave 2 and the first half of wave 3 are on master: tasks 1.3, 3.1, 5.1, 5.2, 6.1, 6.2, 7.1 and 9.1.
  - Gate over the range against `d708c46`: `OK (2786 tests, 10860 assertions)`, 304 changed lines, 100%. The PHP 7.2 lint passes on 8 files, and the licence check on 181.
  - End-to-end smoke: 90 passed and 1 skipped, and all 91 check lines are identical to the run before this change.
  - The PDF download check passes for the front office and the back office.
  - On 2026-11-05 the account's usage limit stopped the agents twice. The work was resumed from the worktrees, and every killed worker's leftovers were treated as an unverified draft. One stopped worker had left a mutated production line behind, uncommitted; it was restored before anything was integrated.

## Workflow follow-up

- Archive the change after review.
