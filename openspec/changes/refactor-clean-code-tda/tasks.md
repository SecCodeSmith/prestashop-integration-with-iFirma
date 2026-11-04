# Tasks

Every refactor task (groups 2 to 13) follows design D1:
1. Characterise: confirm coverage of the touched methods, and add pinning tests for any uncovered line.
2. Refactor.
3. The full PHPUnit suite passes.
4. `diff-cover` reports 100% of changed lines covered.

No task starts on a red tree.

## 1. Safety net

- [ ] 1.1 Add a `<coverage>` section (classes/, controllers/, ifirma.php) to `phpunit.xml.dist` and a `composer coverage` script that writes `build/coverage.xml` (Cobertura). Add `build/` to `.gitignore`. Verify that `composer coverage` produces the XML in an environment with pcov (CI or the WSL container), and record the per-class line coverage baseline in the PR description.
- [ ] 1.2 Add a CI `coverage` job (setup-php `coverage: pcov`, `pip install diff-cover`, `diff-cover build/coverage.xml --compare-branch=origin/master --fail-under=100`) for pull requests. Verify that it passes on a no-op PR and fails on a scratch PR that adds an untested branch.
- [ ] 1.3 Add a CI `lint-php72` job (setup-php `7.2`, `php -l` on all tracked PHP). Verify that it passes on the current tree and fails on a scratch file using a typed property.

## 2. API-client factory (Tier 1)

- [ ] 2.1 Add a characterisation test for `IfirmaApiClient::fromConfiguration()`, written against the intended behaviour: it reads the user and three keys, uses the explicit base URL when given, and falls back to `IFIRMA_BASE_URL`. Then implement it. Verify that the new test and the full suite pass.
- [ ] 2.2 Replace the inline client construction in the 8 `build()` methods (ReceiptService, IssuancePipeline, DeliveryService, StatusSyncService, KsefService, DuplicateGuard, ContractorService, CustomerContractorService) with the factory. Characterise first: add a `build()` smoke test per class asserting that the built client is configured from `Configuration`. Verify that `git grep -n "new IfirmaApiClient(" classes` shows only the factory, the suite is green, and diff-cover is at 100%.

## 3. AdminActions error guard (Tier 1)

- [ ] 3.1 Characterise the failure path of each of the 15 guarded actions: each needs a test where the service throws and the action returns the `failure()` shape. Add any that AdminActionsTest lacks. Verify that they pass on unchanged code.
- [ ] 3.2 Introduce `guard(callable)` with a single `Throwable` catch, apply it to all 15 actions and to the pair at `ifirma.php:~1455`. Verify that `grep -c "catch (Exception" classes/IfirmaAdminActions.php` returns 0, the suite is green, and diff-cover is at 100%.

## 4. Match labels (Tier 1)

- [ ] 4.1 List every code where `ifirma.php::matchedByLabel` and `IfirmaAdminActions::matchedByLabel` word labels differently, and get the user's choice for each. Verify that the decisions are recorded in this task's PR description.
- [ ] 4.2 Characterise both label paths, plus `contractorActionLabel`, which has no direct test today: one test per code. Verify that they pass on unchanged code.
- [ ] 4.3 Introduce `IfirmaMatchLabels` (design D6), keyed by `IfirmaContractorMap::BY_*`. Replace both methods and the hard-coded match-code lists (`ifirma.php:~984`, `IfirmaAdminActions.php:~376`). Verify that the suite is green, `TranslationCoverageTest` is green, and diff-cover is at 100%.

## 5. Outcome and status constants (Tier 1)

- [ ] 5.1 Add `IfirmaPipelineResult::OUTCOME_*` constants and `toArray()`. Replace the outcome literals in the Pipeline and the branches in AdminActions (`~111-120`, `~773`), and the result re-assembly. Verify that IssuancePipelineTest and AdminActionsTest are green and diff-cover is at 100%.
- [ ] 5.2 Replace the raw status literals in IfirmaCustomerView and IfirmaCustomerContractorService with `IfirmaReceipt::STATUS_*` / `IfirmaDataRequest::STATUS_*` constants. Verify that `git grep -nE "'(held|pending|duplicate_suspected)'" classes/IfirmaCustomerView.php classes/IfirmaCustomerContractorService.php` is empty, the suite is green, and diff-cover is at 100%.

## 6. HTTP status line (Tier 1)

- [ ] 6.1 Extract the status-line construction from `controllers/front/cron.php::respond` into a small pure helper that maps the status code to its reason phrase, and test it with 200, 403, 404, 429 and 500. Use it in `cron.php`. Verify that a 403 still yields `HTTP/1.1 403 Forbidden`, the suite is green, and diff-cover is at 100% on the helper.

## 7. Schema helper (Tier 1)

- [ ] 7.1 Characterise `widenContractorMapIdentifier` and `ensureReceiptKsefColumns` against the Db stub (the column-present and column-absent paths). Then extract `columnExists($table, $column)` and a `CONTRACTOR_IDENTIFIER_LENGTH = 64` constant. Verify that SchemaTest and UpgradeTest are green and diff-cover is at 100%.

## 8. Payment map serialiser (Tier 1)

- [ ] 8.1 Add `IfirmaPaymentMethodResolver::formatMap(array)` with a round-trip test (`parseMap(formatMap(x)) == x` for representative maps, including empty and whitespace cases). Replace the hand-built `module=CODE` lines in `ifirma.php::postProcessConfiguration`. Verify that the ModuleHooksTest `getContent` tests are green and diff-cover is at 100%.

## 9. IfirmaReceipt intent methods (Tier 2)

- [ ] 9.1 Add `tests/Unit/IfirmaReceiptTest.php` covering `markError`, `markUnknown`, `markHeld` and `recordDelivery` (fields set; no save inside). Implement the methods. Verify that the new tests pass.
- [ ] 9.2 Replace the field-by-field status mutations in ReceiptService (`~253-265`), Pipeline (`ensureRow`/`fail`), DeliveryService and StatusSyncService (`applyRow`) with the intent methods, keeping the existing `save()` calls. Verify that the suite is green, the number of `save()` calls per path is unchanged (asserted in the existing Db stub tests where available), and diff-cover is at 100%.

## 10. IfirmaDataRequest approve/reject (Tier 2)

- [ ] 10.1 Add `approve()` and `reject()` to `IfirmaDataRequest`, with tests in IfirmaDataRequestTest (pending guard, fields set, same exception as today). Make the service delegate to them. Verify that DataRequestServiceTest and IfirmaDataRequestTest are green and diff-cover is at 100%.

## 11. Data-request notifier (Tier 2)

- [ ] 11.1 Characterise `notifyMerchant` and `notifyCustomer` (template name, recipient, language fallback, and the return value when Mail fails) using the Mail stub. Verify that they pass on unchanged code.
- [ ] 11.2 Extract `IfirmaDataRequestNotifier` (design D10) and inject it into the service, defaulting in `build()`. Move the characterisation tests onto the notifier, and add service tests using a recording fake for approve, reject and notify calls. Verify that `git grep -n "Mail::Send" classes/IfirmaDataRequestService.php` is empty, the suite is green, and diff-cover is at 100%.

## 12. Delivery result object (Tier 2)

- [ ] 12.1 Add `IfirmaDeliveryResult` (immutable, `isSent()`) with unit tests. Make `IfirmaDeliveryService::deliver()` return it, and migrate the Pipeline and AdminActions callers in the same commit. Verify that `git grep -n "\['ok'\]\|\['status'\]" classes/IfirmaIssuancePipeline.php` shows no delivery-array reads, DeliveryServiceTest and IssuancePipelineTest are green (assertions retargeted, not weakened), and diff-cover is at 100%.

## 13. Contractor resolution object (Tier 2)

- [ ] 13.1 Add `IfirmaContractorResolution` with unit tests. Make `IfirmaContractorService::ensure()` return it, and migrate all callers (ReceiptService, Pipeline, AdminActions, CustomerContractorService) in the same commit. Verify that `git grep -n "getLastAction\|getLastReadback\|getAssignedIdentifier\|getLastSearchError"` returns no callers outside tests that are being retargeted, and the suite is green.
- [ ] 13.2 Remove the `getLast*()` accessors and their mutable fields. Verify that the grep from 13.1 returns nothing, ContractorServiceTest and AdminActionsTest are green, and diff-cover is at 100%.

## 14. Integration check

- [ ] 14.1 Run the full CI pipeline on the branch (lint, lint-php72, phpunit, coverage, mock, build). Install the build on the local test-stack shop, then issue a receipt, run a delivery and approve a data request. Verify that the behaviour matches master, and that `php tests/smoke/run.php` passes in the container.
- [ ] 14.2 Run `graphify update .` and `openspec validate refactor-clean-code-tda`. Verify that both succeed.

## Workflow follow-up

- Archive the change after review. `refactor-srp-splits` can then start.
