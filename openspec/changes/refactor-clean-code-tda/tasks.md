# Tasks

Every refactor task (groups 2 to 13) follows design D1:
1. Characterise: confirm coverage of the touched methods, and add pinning tests for any uncovered line.
2. Refactor.
3. The full PHPUnit suite passes.
4. `diff-cover` reports 100% of changed lines covered.

No task starts on a red tree. Each task is one commit straight on master, with no branch and no pull request. The local gate runs before the commit (the whole suite in a PHP 8.2 image with pcov, then diff-cover against the task's base commit), and CI repeats it after the push.

## 1. Safety net

- [x] 1.1 Add a `<coverage>` section (classes/, controllers/, ifirma.php) to `phpunit.xml.dist` and a `composer coverage` script that writes `build/coverage.xml` (Cobertura). Add `build/` to `.gitignore`. Verify that `composer coverage` produces the XML in an environment with pcov (CI or the WSL container), and record the per-class line coverage baseline in these notes. Evidence:
  - The coverage filter leaves out the four directory-guard `index.php` files.
  - In a local PHP 8.2.33 image with pcov, the command behind `composer coverage` (`tests/vendor/bin/phpunit --coverage-cobertura build/coverage.xml`; the image has no composer) gives `OK (1080 tests, 3896 assertions)` and writes the report. File names in the report are repo-relative. CI runs `composer coverage` itself.
  - Baseline: 4466 of 4877 shipped lines are covered (91.6%). The weakest files a task touches are `controllers/front/cron.php` 0% (task 6), `IfirmaReceipt` 75% (task 9), `IfirmaAdminActions` 81.6% (tasks 3, 4, 5, 12, 13) and `IfirmaIssuancePipeline` 88.2% (tasks 5, 9, 12, 13).
  - Other low files no task touches: `IfirmaHttpTransport` 0%, `controllers/front/receipt.php` 0%, `AdminIfirmaController` 41%.
  - Every other class is at 93% or more, and six files are at 100%.
- [x] 1.2 Add a CI `coverage` job (setup-php `coverage: pcov`, `pip install diff-cover`, `diff-cover build/coverage.xml --fail-under=100`). It compares pull requests with their base, pushes with `github.event.before`, and manual runs with master, and the test build waits for it. The work lands on master without pull requests, so verify the gate on the machine and in CI:
  - Locally, the same diff-cover command must fail on an untested branch and pass once a test covers it.
  - In CI, the job must pass on the push that adds it.

  Evidence:
  - The local gate (whole suite in PHP 8.2 plus pcov, then diff-cover on the host) was probed in a scratch worktree. An untested new class failed (exit 1, 0%, lines 7-8 and 11 missing). One of two branches tested failed (66.7%, line 11). Both branches tested passed (100%). An untested method added to a tracked class failed. A red test failed the suite step (exit 2).
  - Two gaps were found and fixed while probing. Docker leaves an empty `tests/vendor` mount point behind, so the gate now checks for the phpunit binary instead. diff-cover's `--include-untracked` misses new files, so the gate now marks them intent-to-add and restores the index afterwards.
  - The `cron.php` exemption is proven too: a changed `cron.php` line fails without `--exclude` (exit 1) and is skipped with it (exit 0).
  - CI run #13 (41923647516) on `02bbbc8` passed all six jobs. The coverage job ran `composer coverage` (1080 tests), compared with `0acaa89` (the commit before the push), found no changed PHP lines and passed. The test build waited for it.
- [x] 1.3 Add a CI `lint-php72` job (setup-php `7.2`, `php -l` on every tracked PHP file outside `tests/` and `tools/`; the tests need PHPUnit 9, so PHP 7.3+). Verify that it passes on the current tree and fails on a scratch file using a typed property. Evidence:
  - With PHP 7.2.34, all 49 shipped PHP files lint clean, so the module's `php >=7.2` claim holds today.
  - A scratch class with a typed property fails with `syntax error, unexpected 'int'`, exit 255.
  - actionlint exits 0 on the new job, and the build job waits for it.

## 2. API-client factory (Tier 1)

- [x] 2.1 Add a characterisation test for `IfirmaApiClient::fromConfiguration()`, written against the intended behaviour: it reads the user and three keys, uses the explicit base URL when given, and falls back to `IFIRMA_BASE_URL`. Then implement it. Verify that the new test and the full suite pass. Evidence (commit `e7c3992`, test and factory squashed so the history bisects cleanly):
  - `ApiClientFactoryTest` (5 tests) was written first and failed on the missing method.
  - Its user differs from the stub's `PS_SHOP_EMAIL`, so a factory reading the wrong key fails it; checked: that mutant fails 4 of 5 tests.
  - Gate against `6c841c1`: `OK (1085 tests)`, 9 changed lines, 100%.
- [x] 2.2 Replace the inline client construction in the 8 `build()` methods (ReceiptService, IssuancePipeline, DeliveryService, StatusSyncService, KsefService, DuplicateGuard, ContractorService, CustomerContractorService) with the factory. Characterise first: add a `build()` smoke test per class asserting that the built client is configured from `Configuration`. Verify that `git grep -n "new IfirmaApiClient(" classes` shows only the factory, the suite is green, and diff-cover is at 100%. Evidence:
  - Characterisation `81d8fea` adds 22 tests, green on the unchanged inline code. Each `build()` client signs with the configured user and the right key (three distinct keys, so a swap shows) and falls back to the production URL. The Pipeline, Delivery and CustomerContractor builds share one client with their collaborators.
  - Refactor `27be949` also converts the module's own `buildApiClient()` (`ifirma.php`), a ninth site with identical semantics.
  - `git grep -n "new IfirmaApiClient(" classes ifirma.php controllers` prints only `classes/IfirmaApiClient.php:83`, the factory.
  - Gate against `e7c3992`: `OK (1107 tests)`, 10 changed lines, 100%.

## 3. AdminActions error guard (Tier 1)

- [ ] 3.1 Characterise the failure path of each of the 15 guarded actions: each needs a test where the service throws and the action returns the `failure()` shape. Add any that AdminActionsTest lacks. Verify that they pass on unchanged code.
- [ ] 3.2 Introduce `guard(callable)` with a single `Throwable` catch, apply it to all 15 actions and to the pair at `ifirma.php:~1455`. Verify that `grep -c "catch (Exception" classes/IfirmaAdminActions.php` returns 0, the suite is green, and diff-cover is at 100%.

## 4. Match labels (Tier 1)

- [ ] 4.1 List every code where `ifirma.php::matchedByLabel` and `IfirmaAdminActions::matchedByLabel` word labels differently, and get the user's choice for each. Verify that the decisions are recorded in this task's notes.
- [ ] 4.2 Characterise both label paths, plus `contractorActionLabel`, which has no direct test today: one test per code. Verify that they pass on unchanged code.
- [ ] 4.3 Introduce `IfirmaMatchLabels` (design D6), keyed by `IfirmaContractorMap::BY_*`. Replace both methods and the hard-coded match-code lists (`ifirma.php:~984`, `IfirmaAdminActions.php:~376`). Verify that the suite is green, `TranslationCoverageTest` is green, and diff-cover is at 100%.

## 5. Outcome and status constants (Tier 1)

- [ ] 5.1 Add `IfirmaPipelineResult::OUTCOME_*` constants and `toArray()`. Replace the outcome literals in the Pipeline and the branches in AdminActions (`~111-120`, `~773`), and the result re-assembly. Verify that IssuancePipelineTest and AdminActionsTest are green and diff-cover is at 100%.
- [x] 5.2 Replace the raw status literals in IfirmaCustomerView and IfirmaCustomerContractorService with `IfirmaReceipt::STATUS_*` / `IfirmaDataRequest::STATUS_*` constants. Verify that no status value is compared or listed as a literal in those two files, the suite is green, and diff-cover is at 100%. The check is `git grep -nE "(===|!==|==|!=) '(created|paid|held|error|unknown|duplicate_suspected|pending|approved|rejected|cancelled)'|array\([^)]*'(created|paid|held|error|unknown|duplicate_suspected|pending|approved|rejected|cancelled)'" -- classes/IfirmaCustomerView.php classes/IfirmaCustomerContractorService.php`, which must be empty.

  It replaced the first check, ``git grep -nE "'(held|pending|duplicate_suspected)'" …``, which cannot pass as written. Three of its hits are not statuses:
  - `IfirmaCustomerView::STATE_HELD`, the view state for a pending data request;
  - the `'held'` key of `localCounts()` (two hits);
  - both are consumed by templates and CSS, and tying them to `IfirmaReceipt::STATUS_HELD` would misstate them.

  Evidence:
  - Characterisation `28f27bf` passes on base: the view-state and `localCounts()` branches.
  - Refactor `8e565fb`.
  - The status-aware grep finds 0 hits, against 11 on `6c841c1`.
  - Gate against `27be949`: `OK (1112 tests)`, 8 changed lines, 100%.

## 6. HTTP status line (Tier 1)

- [x] 6.1 Extract the status-line construction from `controllers/front/cron.php::respond` into a small pure helper that maps the status code to its reason phrase, and test it with 200, 403, 404, 429 and 500. Use it in `cron.php`. Verify that a 403 still yields `HTTP/1.1 403 Forbidden`, the suite is green, and diff-cover is at 100% on the helper. `cron.php` itself is exempt from the gate (design D2), so change only the one status-line line there. Evidence (commit `685a213`):
  - The new `IfirmaHttpStatus::statusLine()` maps 200, 403, 404, 429 and 500 to their standard reason phrases. Any other code from 100 to 599 gets `Unknown Status`, and anything else becomes 500.
  - `HttpStatusTest` pins all of this; `HTTP/1.1 200 OK` and `HTTP/1.1 403 Forbidden` are unchanged.
  - The `cron.php` diff is exactly the new `require_once` and the `header()` line.
  - Gate against `8e565fb`: `OK (1129 tests)`, 5 changed lines, 100%.

## 7. Schema helper (Tier 1)

- [x] 7.1 Characterise `widenContractorMapIdentifier` and `ensureReceiptKsefColumns` against the Db stub (the column-present and column-absent paths). Then extract `columnExists($table, $column)` and a `CONTRACTOR_IDENTIFIER_LENGTH = 64` constant. Verify that SchemaTest and UpgradeTest are green and diff-cover is at 100%. Evidence:
  - Characterisation `708ff3f` passes on base. It covers the column-present and column-absent paths of both methods and of `upgrade-1.1.0.php`'s probe.
  - Refactor `fbdfcdb`. `columnExists()` serves `ensureReceiptKsefColumns()` and `upgrade-1.1.0.php`. Its SQL is byte-identical, and both replaced `$db` locals were `Db::getInstance()`. It returns `(int) … !== 0`, so it keeps the old truthiness even for a negative answer, as the review asked.
  - Gate against `685a213`: `OK (1159 tests)`, 8 changed lines, 100%.
  - Left for later: 64 is still written out in `IfirmaContractorMap` (`link()` and the ObjectModel size), `IfirmaContractorImport::MAX_IDENTIFIER` and `IfirmaCustomerContractorService::IDENTIFIER_MAX`.

## 8. Payment map serialiser (Tier 1)

- [x] 8.1 Add `IfirmaPaymentMethodResolver::formatMap(array)` with a round-trip test (`parseMap(formatMap(x)) == x` for representative maps, including empty and whitespace cases). Replace the hand-built `module=CODE` lines in `ifirma.php::postProcessConfiguration`. Verify that the ModuleHooksTest `getContent` tests are green and diff-cover is at 100%. Evidence:
  - Characterisation `11771d3` pins the settings POST's stored payment-map value and its validation, and passes on base.
  - Refactor `0d2a0ca`: `formatMap()` has round-trip tests, and the five hand-built lines in `ifirma.php` become one call.
  - Gate against `fbdfcdb`: `OK (1182 tests)`, 5 changed lines, 100%.
  - One older line of the method, `$tolerance = '0';`, is unreachable and untouched.

## 9. IfirmaReceipt intent methods (Tier 2)

- [x] 9.1 Add `tests/Unit/IfirmaReceiptTest.php` covering `markError`, `markUnknown`, `markHeld` and `recordDelivery` (fields set; no save inside). Implement the methods. Verify that the new tests pass. Evidence (commit `1dfd935`):
  - The methods only set fields and never save. They were derived from today's callers, which task 9.2 will switch over.
  - Messages are stored as given: null and an integer were added at integration, because ReceiptService stores iFirma's `Informacja` raw. A `(string)` cast mutant now fails.
  - Gate against `0d2a0ca`: `OK (1207 tests)`, 9 changed lines, 100%.
- [ ] 9.2 Replace the field-by-field status mutations in ReceiptService (`~253-265`), Pipeline (`ensureRow`/`fail`), DeliveryService and StatusSyncService (`applyRow`) with the intent methods, keeping the existing `save()` calls. Verify that the suite is green, the number of `save()` calls per path is unchanged (asserted in the existing Db stub tests where available), and diff-cover is at 100%.

## 10. IfirmaDataRequest approve/reject (Tier 2)

- [x] 10.1 Add `approve()` and `reject()` to `IfirmaDataRequest`, with tests in IfirmaDataRequestTest (pending guard, fields set, same exception as today). Make the service delegate to them. Verify that DataRequestServiceTest and IfirmaDataRequestTest are green and diff-cover is at 100%. Evidence:
  - Characterisation `3db1313` covers the service's approve and reject paths and passes on base.
  - Refactor `6f25518`: the entity holds the pending guard and the reason check, and throws the same refusals as before. The service keeps `save()`, the side effects and the mail calls.
  - A reason of `'0'` stays valid, pinned at integration; an `empty()` mutant now fails.
  - Gate against `1dfd935`: `OK (1234 tests)`, 26 changed lines, 100%.
  - Accepted: on a refused reject, the service now reads its injected clock once, because the time is passed as an argument. The default clock is a plain `date()`, so production behaviour is unchanged.

## 11. Data-request notifier (Tier 2)

- [ ] 11.1 Characterise `notifyMerchant` and `notifyCustomer` (template name, recipient, language fallback, and the return value when Mail fails) using the Mail stub. Verify that they pass on unchanged code.
- [ ] 11.2 Extract `IfirmaDataRequestNotifier` (design D10) and inject it into the service, defaulting in `build()`. Move the characterisation tests onto the notifier, and add service tests using a recording fake for approve, reject and notify calls. Verify that `git grep -n "Mail::Send" classes/IfirmaDataRequestService.php` is empty, the suite is green, and diff-cover is at 100%.

## 12. Delivery result object (Tier 2)

- [ ] 12.1 Add `IfirmaDeliveryResult` (immutable, `isSent()`) with unit tests. Make `IfirmaDeliveryService::deliver()` return it, and migrate the Pipeline and AdminActions callers in the same commit. Verify that `git grep -n "\['ok'\]\|\['status'\]" classes/IfirmaIssuancePipeline.php` shows no delivery-array reads, DeliveryServiceTest and IssuancePipelineTest are green (assertions retargeted, not weakened), and diff-cover is at 100%.

## 13. Contractor resolution object (Tier 2)

- [ ] 13.1 Add `IfirmaContractorResolution` with unit tests. Make `IfirmaContractorService::ensure()` return it, and migrate all callers (ReceiptService, Pipeline, AdminActions, CustomerContractorService) in the same commit. Verify that `git grep -n "getLastAction\|getLastReadback\|getAssignedIdentifier\|getLastSearchError"` returns no callers outside tests that are being retargeted, and the suite is green.
- [ ] 13.2 Remove the `getLast*()` accessors and their mutable fields. Verify that the grep from 13.1 returns nothing, ContractorServiceTest and AdminActionsTest are green, and diff-cover is at 100%.

## 14. Integration check

- [ ] 14.1 Run the full CI pipeline on master after the last task (lint, lint-php72, phpunit, coverage, mock, build). Install the build on the local test-stack shop, then issue a receipt, run a delivery and approve a data request. Verify that the behaviour matches master, and that `php tests/smoke/run.php` passes in the container.
- [ ] 14.2 Run `graphify update .` and `openspec validate refactor-clean-code-tda`. Verify that both succeed.

## Workflow follow-up

- Archive the change after review. `refactor-srp-splits` can then start.
