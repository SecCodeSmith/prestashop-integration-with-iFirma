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

- [x] 3.1 Characterise the failure path of each of the 15 guarded actions: each needs a test where the service throws and the action returns the `failure()` shape. Add any that AdminActionsTest lacks. Verify that they pass on unchanged code. Evidence, characterisation commit `4b3cff0`, which passes on base:
  - `ErrorGuardTest` adds 44 tests. Every action answers the exact failure envelope for an Exception and for an Error. The order-status hook logs its failure instead of throwing.
  - It also pins every other line of the 15 action bodies that no test reached, as D1 requires.
  - Added at integration: `rejectRequest`'s own `reason_required` answer as an exact envelope. A mutant that routed it through the guard used to survive and now fails.
  - Added at integration: the settings page's API limit box when the limit request throws (an Exception and an Error).
- [x] 3.2 Introduce `guard(callable)` with a single `Throwable` catch, apply it to all 15 actions and to the pair at `ifirma.php:~1455`. Verify that `grep -c "catch (Exception" classes/IfirmaAdminActions.php` returns 0, the suite is green, and diff-cover is at 100%. Evidence, refactor commit `fcbf782`:
  - Each action body now sits in `return $this->guard(function () use (...) { ... });`. The opener replaces the `try {` line at the same indentation, so the bodies keep their indent.
  - `rejectRequest` keeps an inner catch for its own `reason_required` answer and rethrows any other refusal to the guard.
  - Both remaining dual pairs in `ifirma.php` are now a single `Throwable` catch: the order-status hook, as the task names, and the limit box in `renderLimitBox()`, the same duplication, added at integration.
  - `grep -c "catch (Exception" classes/IfirmaAdminActions.php` returns 0, and `ifirma.php` has no dual pair left.
  - Gate against `bb9fc16`: `OK (1280 tests)`, 47 changed lines, 100%.
- [x] 3.3 Added with the user's approval on 2026-11-05. Collapse the seven remaining duplicated `catch (Exception $e) {…} catch (Throwable $e) {…}` pairs into a single `catch (Throwable $e)` with the same body. Each pair's two branches have identical bodies, so behaviour is unchanged. The sites:
  - `IfirmaIssuancePipeline`: the run log, the resume log, the run's `fail()`, the KSeF step's result and `fail()`'s best-effort recording;
  - `IfirmaKsefService`: the submit error;
  - `IfirmaStatusSyncService`: a `return false`.

  Single catches stay as they are. For example, the deliver step deliberately catches only `Exception`, so an `Error` fails the whole run.

  Characterise first: at each site, an `Exception` and an `Error` must each reach the catch, with the observable effect asserted: the log line, the failure outcome, the KSeF step message or the returned `false`.

  Verify that `git grep -n -A2 "catch (Exception" -- classes ifirma.php controllers | grep "catch (Throwable"` prints nothing, the suite is green, and diff-cover is at 100%.

  Evidence:
  - Characterisation `d1f4ac9` adds 13 tests and passes on base. They cover:
    - the status hook and the resume, which log and swallow;
    - an `Error` from a step, which is logged, recorded and not thrown;
    - the KSeF step, which fails only itself;
    - a failure while recording a failure, which keeps the original;
    - `KsefService::submit()`, which records a throwable as the error;
    - `opportunisticSync()`, which returns `false`.

    Every catch line had 0 hits at base for sites 1, 2, 4, 6 and 7, and is now reached by both kinds.
  - Refactor `b192e51` only deletes 14 lines. All seven pairs were byte-identical, so diff-cover has nothing to measure.
  - The real safety net: a mutation run narrowing each site to `Exception` or to `Error` killed 14 of 14. I re-checked the KSeF and status-sync sites myself; both mutants fail.
  - The task grep prints nothing. Gate against `f566c86`: `OK (1462 tests)`.
  - Found while doing this, already present before the change and unchanged by it: `IfirmaKsefService::submit()` treats a throwable with an empty message as success (`ok` true, `ksef_status` `WYSLANA`). It needs its own behaviour-changing fix.

## 4. Match labels (Tier 1)

- [x] 4.1 List every code where `ifirma.php::matchedByLabel` and `IfirmaAdminActions::matchedByLabel` word labels differently, and get the user's choice for each. Verify that the decisions are recorded in this task's notes. Decision, 2026-11-05: the user chose the long, module-file wording everywhere, as design D6 proposes.

  Codes worded the same in both maps: `nip` (NIP), `name` (name / nazwa), `email` (e-mail) and `manual` (manual link / powiązanie ręczne).

  Codes that differ:

  | Code | Module file (panels) | AdminActions (note) |
  |---|---|---|
  | `import-nip` | "import, NIP" | "NIP" |
  | `import-email` | "import, e-mail" | "e-mail" |
  | `import-name` | "import, name and postcode" (PL "import, nazwa i kod pocztowy") | "name" |
  | `document` | "attached by iFirma to an issued document" (PL "przypisany przez iFirma do wystawionego dokumentu") | "issued document" (PL "wystawiony dokument") |
  | `module` | "module contractor" | its default, but never reached there, because the note's code list excludes `module` |
  | unknown | the raw code | "manual link" |

  Every variant already has a Polish translation. The order panel already prints the long forms after "matched by:".

  The only visible change is in the AdminActions note "linked to existing contractor %s (matched by %s)" (PL "… (dopasowanie: %s)"): it now reads, for example, "dopasowanie: import, NIP" instead of "dopasowanie: NIP". The panels do not change.
- [x] 4.2 Characterise both label paths, plus `contractorActionLabel`, which has no direct test today: one test per code. Verify that they pass on unchanged code. Evidence, characterisation commit `909f253`, which passes on base:
  - `ContractorLabelWordingTest` drives the real hooks and actions with a stored contractor link, one test per code:
    - the order panel and customer panel labels for all 9 codes and for an unknown code;
    - the customer panel's JSON of codes and labels, whose membership and order are pinned;
    - the AdminActions link note for the 8 linked codes. `module` is excluded from that list, as are an unknown code and a link that points at the module identifier;
    - `contractorActionLabel` for every action, including the read-back entries that are not records.
- [x] 4.3 Introduce `IfirmaMatchLabels` (design D6), keyed by `IfirmaContractorMap::BY_*`. Replace both methods and the hard-coded match-code lists (`ifirma.php:~984`, `IfirmaAdminActions.php:~376`). Verify that the suite is green, `TranslationCoverageTest` is green, and diff-cover is at 100%. Evidence, refactor commit `5e600ad`:
  - `Ifirma::matchLabels()` builds an `IfirmaMatchLabels` from the nine `$this->l()` literals, which stay in `ifirma.php`, keyed by the `BY_*` constants in the old display order.
  - `label()` returns an unknown code unchanged. `labels()` replaces the customer-panel loop. `linkCodes()` keeps AdminActions' list: every code except `module`.
  - Both `matchedByLabel()` copies and both hard-coded lists are gone; `git grep matchedByLabel` finds 0 hits.
  - The only changed output is the 4.1-approved one: the four link-note expectations (import-nip, import-email, import-name, document) now carry the long wording.
  - `TranslationCoverageTest` is green. `translations/pl.php` is untouched; the five AdminActions-domain label entries are now unused.
  - Gate against `3c926eb`: `OK (1419 tests)`, 23 changed lines, 100%.
  - Left for later: the customer-panel JSON's `JSON_HEX_*` flags were never pinned and still aren't.

## 5. Outcome and status constants (Tier 1)

- [x] 5.1 Add `IfirmaPipelineResult::OUTCOME_*` constants and `toArray()`. Replace the outcome literals in the Pipeline and the branches in AdminActions (`~111-120`, `~773`), and the result re-assembly. Verify that IssuancePipelineTest and AdminActionsTest are green and diff-cover is at 100%. Evidence:
  - Characterisation `1d50a1f` pins the pipeline outcomes and the create action's payload, and passes on base.
  - Refactor `6dbbc89`:
    - one `OUTCOME_*` constant per documented outcome, with the same strings; the result's default uses the constant;
    - the pipeline's literals and the AdminActions outcome checks use the constants;
    - the create action reads `toArray()` (outcome and stopped_at) instead of re-assembling them, and its returned array is byte-identical.
  - `resolved()`'s `$data['outcome'] = $result->outcome` (old ~773) is a plain field copy with no literal, so it stays.
  - Added at integration: a PHP `Error` from delivery escapes the deliver step, and `fail()` turns the run into outcome `error`. That is today's behaviour, now pinned; deleting `fail()`'s outcome line used to survive and now fails.
  - Gate against `5e600ad`: `OK (1430 tests)`, 17 changed lines, 100%.
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
  - End to end on the local test-stack shop (master `bb9fc16`, port 8099):
    - The cron URL with a wrong token answers `HTTP/1.1 403 Forbidden` and `{"error":"Forbidden"}`.
    - With the real `IFIRMA_CRON_TOKEN` it answers `HTTP/1.1 200 OK` and the JSON sync summary.
    - This is the proof for the gate-exempt `respond()`. The smoke script's own HTTP check skips here, because `localhost` inside the container redirects.

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
- [x] 9.2 Replace the field-by-field status mutations in ReceiptService (`~253-265`), Pipeline (`ensureRow`/`fail`), DeliveryService and StatusSyncService (`applyRow`) with the intent methods, keeping the existing `save()` calls. Verify that the suite is green, the number of `save()` calls per path is unchanged (asserted in the existing Db stub tests where available), and diff-cover is at 100%.

  Scope, decided by the lead:
  - Only design D8's four methods are used.
  - `StatusSyncService::applyRow` stays unchanged, because it only writes the paid status, which none of the four covers. The same goes for `ensureRow()`, which sets no status.
  - The pipeline's error sites set the error status only for a new row, so they became `if ($isNew) markError() else last_error = …`.
  - `delivered_at` stays an explicit assignment next to `recordDelivery()`.
  - `DuplicateGuard::adopt()`'s delivery triple was included as well.
  - Left for later: `markPaid`, or a `markSuspected` for the duplicate guard.

  Evidence:
  - Characterisation `98644a8` passes on base. It pins the fields and the number of writes on every path: the ObjectModel stub now logs each persisted add or update, and `IfirmaTestState::writes()` reads that log.
  - Added at integration: a failed shop-mail fallback records `shop_mail_fallback`. A mutant recording the configured channel used to survive and now fails.
  - Refactor `b7f281e`. Gate against `fcbf782`: `OK (1307 tests)`, 14 changed lines, 100%.

## 10. IfirmaDataRequest approve/reject (Tier 2)

- [x] 10.1 Add `approve()` and `reject()` to `IfirmaDataRequest`, with tests in IfirmaDataRequestTest (pending guard, fields set, same exception as today). Make the service delegate to them. Verify that DataRequestServiceTest and IfirmaDataRequestTest are green and diff-cover is at 100%. Evidence:
  - Characterisation `3db1313` covers the service's approve and reject paths and passes on base.
  - Refactor `6f25518`: the entity holds the pending guard and the reason check, and throws the same refusals as before. The service keeps `save()`, the side effects and the mail calls.
  - A reason of `'0'` stays valid, pinned at integration; an `empty()` mutant now fails.
  - Gate against `1dfd935`: `OK (1234 tests)`, 26 changed lines, 100%.
  - Accepted: on a refused reject, the service now reads its injected clock once, because the time is passed as an argument. The default clock is a plain `date()`, so production behaviour is unchanged.

## 11. Data-request notifier (Tier 2)

- [x] 11.1 Characterise `notifyMerchant` and `notifyCustomer` (template name, recipient, language fallback, and the return value when Mail fails) using the Mail stub. Verify that they pass on unchanged code. Evidence, characterisation commit `5cc6b6a`, which passes on base:
  - It pins the five uncovered lines: a merchant with no address, a failing admin link, a customer without an address, and the context-language fallback.
  - It also pins everything around them: the template, recipient, shop and language of each mail (the order language, then English, then the context), every variable and its escaping, the subjects with their translator source, and the `false` returned when Mail fails.
- [x] 11.2 Extract `IfirmaDataRequestNotifier` (design D10) and inject it into the service, defaulting in `build()`. Move the characterisation tests onto the notifier, and add service tests using a recording fake for approve, reject and notify calls. Verify that `git grep -n "Mail::Send" classes/IfirmaDataRequestService.php` is empty, the suite is green, and diff-cover is at 100%. Evidence, refactor commit `a5f5d61`:
  - The notifier owns the templates, the merchant address, the language choice and the mail call.
  - The service still composes the subjects and variables, so every `l()` literal stays in place under the same source.
  - The notifier is an optional third constructor argument, so `build()` and the direct constructor calls are unchanged.
  - The delivery tests moved to `IfirmaDataRequestNotifierTest`. The service tests use a recording notifier.
  - The grep for `Mail::Send` in the service is empty.
  - Gate against `b7f281e`: `OK (1369 tests)`, 54 changed lines, 100%.

  Wave 2 as a whole (tasks 3, 9.2 and 11):
  - Gate against `bb9fc16`: 1369 tests, 115 changed lines, 100%. The PHP 7.2 lint is clean.
  - The end-to-end smoke (`tests/smoke/run.php` in the PrestaShop 8.1 container against the mock) gives `passed 90, failed 0, skipped 1`. That is the same list of checks with the same verdicts as the run on `bb9fc16` before the wave. The skip is the in-container cron HTTP check; it is covered under task 6.1.

## 12. Delivery result object (Tier 2)

- [x] 12.1 Add `IfirmaDeliveryResult` (immutable, `isSent()`) with unit tests. Make `IfirmaDeliveryService::deliver()` return it, and migrate the Pipeline and AdminActions callers in the same commit. Verify that `git grep -n "\['ok'\]\|\['status'\]" classes/IfirmaIssuancePipeline.php` shows no delivery-array reads, DeliveryServiceTest and IssuancePipelineTest are green (assertions retargeted, not weakened), and diff-cover is at 100%. Evidence:
  - Characterisation `d97f9e1` pins the deliver step and the resend envelope, and passes on base.
  - Refactor `05b77ee`:
    - `deliver()` and `resend()` return the immutable `IfirmaDeliveryResult`, which `IfirmaDeliveryService.php` loads itself;
    - the pipeline's deliver step and the AdminActions resend action read the getters, and the action's returned array is byte-identical;
    - the tests that asserted on the old array now assert the same values through the getters.
  - `isSent()` keeps the old `ok` flag exactly: true for sent and for skipped (nothing needed sending), false for failed and disabled. That is what both callers test; `getStatus()` tells sent from skipped.
  - The grep still prints two lines, `$k['ok']` (the KSeF result) and `$s['status']` (a pipeline step entry). Neither is a delivery read.
  - Integration fixes: the docblock now says what `channel` and `message` hold. The 5.1 `toArray()` test fills `delivery` with an `IfirmaDeliveryResult` instead of the obsolete array.
  - Gate against `6dbbc89`: `OK (1449 tests)`, 16 changed lines, 100%.

  Wave 3 as a whole (tasks 4, 5.1 and 12):
  - Gate against `3c926eb`: 1449 tests, 56 changed lines, 100%. The PHP 7.2 lint is clean.
  - The end-to-end smoke gives `passed 90, failed 0, skipped 1`, the same checks and verdicts as before the wave.

## 13. Contractor resolution object (Tier 2)

- [x] 13.1 Add `IfirmaContractorResolution` with unit tests. Make `IfirmaContractorService::ensure()` return it, and migrate all callers (ReceiptService, Pipeline, AdminActions, CustomerContractorService) in the same commit. Verify that `git grep -n "getLastAction\|getLastReadback\|getAssignedIdentifier\|getLastSearchError"` returns no callers outside tests that are being retargeted, and the suite is green. Evidence:
  - Characterisation `2636d5f` passes on base. It pins:
    - the whole seven-key array of every `ensure()` outcome: disabled, kept, updated, created with and without an assigned identifier, and every fallback;
    - the stored `_contractor` record of the receipt payload, key for key and in order;
    - the `registerContractor` envelope and the exact customer-search messages;
    - a guest order's contractor;
    - every previously unreached line of the touched methods.
  - Refactor `6ff1ac0`:
    - `ensure()` returns the immutable `IfirmaContractorResolution`, which derives `matched_by`, `linked_identifier` and `assigned_identifier` exactly as the old `remember()` did, and offers `toArray()`;
    - `ReceiptService` keeps the resolution, and its Kod 201 `reference_rejected` fallback is a resolution too;
    - AdminActions reads the getters;
    - `CustomerContractorService` reads `search()->getError()`.
  - The pipeline never reads a contractor result, so it needed no change.
  - Gate against `b192e51`: `OK (1551 tests)`, 72 changed lines, 100%.
- [x] 13.2 Remove the `getLast*()` accessors and their mutable fields. Verify that the grep from 13.1 returns nothing, ContractorServiceTest and AdminActionsTest are green, and diff-cover is at 100%. Evidence:
  - Characterisation `3b17b06` pins the searches after an unreadable create, and is green on the 13.1 code.
  - Refactor `2daac57`:
    - `getLastAction()`, `getLastReadback()`, `getAssignedIdentifier()` and `getLastSearchError()` are gone, with their fields;
    - `search()` returns the immutable `IfirmaContractorSearchResult` (`getRows()`, `getError()`), as the lead decided;
    - the private `findByNip()` and `findByName()` hand back their row and error;
    - the stored record and every message and note stay byte-identical.
  - Accepted public-surface change: `createAndGetIdentifier()` (string or false), which depended on the removed state, became `createAndResolve()`, returning the resolution. Nothing outside the service called it.
  - The resolution's getter is `getAssignedId()`, so that the task's substring grep can come back empty.
  - The grep returns nothing outside the generated graph and the OpenSpec texts.
  - Gate against `6ff1ac0`: `OK (1555 tests)`, 42 changed lines, 100%.
  - The adversarial review compared about 65,000 differential receipts and found identical stored payloads. Its surviving mutants are pinned in `b35d81a` (tests only):
    - a stored mapping with an empty match code. `registerContractor` answers the mapped identifier and `learnDocumentContractor` makes no extra detail call; reading the linked identifier instead now fails both.
    - several exact-NIP rows carrying the buyer's name, narrowed by postcode. A `count >= 1` mutant now fails.
    - a 9-digit NIP, which is never searched. A `< 9` guard mutant now fails.
    - the stored `_contractor` record of the `update_failed` and `create_rejected` fallbacks, key for key.

    I re-checked two of the kills myself.
  - End-to-end smoke on `2daac57`: 90 passed, the same verdicts as the baseline. That includes scenario H (the contractor renamed by iFirma, with its `assigned_identifier`) and scenario I (customer search, link, issue and unlink).

## 14. Integration check

- [ ] 14.1 Run the full CI pipeline on master after the last task (lint, lint-php72, phpunit, coverage, mock, build). Install the build on the local test-stack shop, then issue a receipt, run a delivery and approve a data request. Verify that the behaviour matches master, and that `php tests/smoke/run.php` passes in the container.
- [ ] 14.2 Run `graphify update .` and `openspec validate refactor-clean-code-tda`. Verify that both succeed.

## Workflow follow-up

- Archive the change after review. `refactor-srp-splits` can then start.
