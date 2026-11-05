# Tasks

Every refactor task follows the D1 protocol of `refactor-clean-code-tda`:
1. Characterise: pin every uncovered line of the methods to be touched, with tests that pass on unchanged code.
2. Refactor.
3. The whole suite passes.
4. diff-cover reports 100% of changed lines covered against the task's base commit.

Each task is one commit straight on master; a characterisation commit may come first.

## 1. Receipt intent methods for the paid and suspected states

- [x] 1.1 Characterise the paid writes in `IfirmaReceiptService::updateStatusPaid()`, `IfirmaStatusSyncService::applyRow()` and the paid branch of `IfirmaDuplicateGuard`, and the suspected write in `IfirmaDuplicateGuard`. Pin the fields set and the number of writes on each path (the ObjectModel stub logs writes). Verify that the new tests pass on unchanged code. Evidence: characterisation `fa6d0a6` passes on base and pins every field and write count on those paths.
- [x] 1.2 Add `IfirmaReceipt::markPaid()` and `markSuspected($reason)` (design D1) with direct tests in `IfirmaReceiptTest`: the fields set, nothing else, no save. Switch the callers. Verify that the suite is green, the write counts are unchanged, and diff-cover is at 100%. Evidence, refactor `f68120e`:
  - `markPaid()` sets only the status. That is the part the three paid callers share; each keeps its own extra assignments, such as `paid_amount`, `last_error` or `remote_paid_amount`.
  - `IfirmaDuplicateGuard` keeps its paid-or-created choice at the call site.
  - `markSuspected($reason)` sets the four fields its single caller sets: the status, an empty `ifirma_id`, `source` module, and `last_error` stored as given.
  - Gate against `a16a476`: `OK (1614 tests)`, 11 changed lines, 100%.

## 2. One contractor identifier length

- [x] 2.1 Characterise the identifier limits: `IfirmaContractorMap`'s field size, `link()`'s truncation, and the limits that `IfirmaContractorImport` and `IfirmaCustomerContractorService` apply. A 64-character identifier passes and a 65-character one is cut or refused, exactly as today. Verify on unchanged code.
- [ ] 2.2 Reference `IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH` in the four places, each file requiring `IfirmaSchema.php` (design D2). Verify:
  - `git grep -nwE "64" classes/IfirmaContractorMap.php classes/IfirmaContractorImport.php classes/IfirmaCustomerContractorService.php` shows no identifier-length literal;
  - the suite is green and diff-cover is at 100%;
  - the PHP 7.2 lint passes;
  - the end-to-end smoke passes in the container.

  Evidence:
  - Characterisation `fe0fc6e` passes on base: a 64-character identifier passes and a 65-character one is cut or refused.
  - Refactor `7d9f4b9`: the four places reference the constant, and the three files require `IfirmaSchema.php` themselves. The grep finds no `64` left.
  - Each class file loads on its own on PHP 7.2 and 8.2.
  - Gate against `f68120e`: `OK (1626 tests)`, 100%.
  - The end-to-end smoke on `dd6dc23` matched the pre-refactor run line for line.
  - Those `require_once` lines are redundant in today's load chain, so no test can kill their removal. They are insurance for when `refactor-srp-splits` cuts that chain.

## 3. Named fallback constructor

- [x] 3.1 Add `IfirmaContractorResolution::fallback()` (design D3) with unit tests. Use it for every identifier-less fallback in `IfirmaContractorService` and `IfirmaReceiptService`. Verify that the existing pins of `ensure()` results and of the stored `_contractor` records are unchanged and green, that `git grep -n "new IfirmaContractorResolution(null" -- classes | grep -v ACTION_DISABLED` prints nothing, and that diff-cover is at 100%.

  The disabled resolution deliberately keeps the plain constructor; design D3 keeps it for every outcome other than the fallback. The first wording, an empty grep with no exclusion, could therefore never pass.

  Evidence:
  - Characterisation `5e24209` passes on base.
  - Refactor `dd6dc23`: `fallback()` builds the six identifier-less fallbacks. The resolution owns `ACTION_FALLBACK`, and the service's constant aliases it, so `'fallback'` is written once.
  - The pins of `ensure()` results and of stored `_contractor` records are unchanged and green, and the scoped grep is empty.
  - Gate against `7d9f4b9`: `OK (1638 tests)`, 7 changed lines, 100%.

## 4. Test-only cleanups

- [x] 4.1 Pin the customer panel's JSON escaping: a match label containing `<`, `>`, `&`, `'` and `"` is encoded with the `JSON_HEX_*` escapes. Verify that the test fails on a mutant that drops the flags. Evidence, commit `adf0f72`:
  - `testCustomerPanelHexEscapesTheMarkupAndQuotesOfAMatchLabel` asserts the five hex escapes, the absence of the raw characters, and a lossless round trip.
  - Dropping all four flags, or any single one, fails it on that flag's own character. I re-checked the all-flags mutant myself.
- [x] 4.2 Move the repeated `throwables()` and `failures()` providers into a shared trait in `tests/Support/` (design D4), and build `brokenHoldPipeline()` on `pipelineWhoseHoldCheckRaises()`. Verify that the suite keeps the same number of tests and assertions, and that the licence check passes. Evidence, commit `369909e`:
  - The new `IfirmaThrowableProviders` trait replaces the three identical `throwables()` copies.
  - `ErrorGuardTest::failures()` keeps its own messages through the trait's `throwablesRaising()`.
  - The suite stays at `OK (1639 tests, 6946 assertions)`, and `phpunit --list-tests` is identical before and after. The licence check passes.
  - Left alone, because they have their own keys or messages: three more specialised providers (`throwablesWithoutAMessage()` twice, `paddedThrowables()`) and `ApiClientFactoryTest::limitRequestFailures()`.

## 5. Integration check

- [ ] 5.1 Run the gate over the whole change against its base, the PHP 7.2 lint, the licence check, the end-to-end smoke and `openspec validate refactor-tda-followups --strict`. Then run `graphify update .`, commit, push, and confirm that all six CI jobs pass with the coverage job at 100%.

## Workflow follow-up

- Archive the change once CI is green. There is no spec sync, because `skip_specs` is set.
