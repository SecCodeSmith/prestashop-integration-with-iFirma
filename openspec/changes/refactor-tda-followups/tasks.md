# Tasks

Every refactor task follows the D1 protocol of `refactor-clean-code-tda`:
1. Characterise: pin every uncovered line of the methods to be touched, with tests that pass on unchanged code.
2. Refactor.
3. The whole suite passes.
4. diff-cover reports 100% of changed lines covered against the task's base commit.

Each task is one commit straight on master; a characterisation commit may come first.

## 1. Receipt intent methods for the paid and suspected states

- [ ] 1.1 Characterise the paid writes in `IfirmaReceiptService::updateStatusPaid()`, `IfirmaStatusSyncService::applyRow()` and the paid branch of `IfirmaDuplicateGuard`, and the suspected write in `IfirmaDuplicateGuard`. Pin the fields set and the number of writes on each path (the ObjectModel stub logs writes). Verify that the new tests pass on unchanged code.
- [ ] 1.2 Add `IfirmaReceipt::markPaid()` and `markSuspected($reason)` (design D1) with direct tests in `IfirmaReceiptTest`: the fields set, nothing else, no save. Switch the callers. Verify that the suite is green, the write counts are unchanged, and diff-cover is at 100%.

## 2. One contractor identifier length

- [ ] 2.1 Characterise the identifier limits: `IfirmaContractorMap`'s field size, `link()`'s truncation, and the limits that `IfirmaContractorImport` and `IfirmaCustomerContractorService` apply. A 64-character identifier passes and a 65-character one is cut or refused, exactly as today. Verify on unchanged code.
- [ ] 2.2 Reference `IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH` in the four places, each file requiring `IfirmaSchema.php` (design D2). Verify:
  - `git grep -nwE "64" classes/IfirmaContractorMap.php classes/IfirmaContractorImport.php classes/IfirmaCustomerContractorService.php` shows no identifier-length literal;
  - the suite is green and diff-cover is at 100%;
  - the PHP 7.2 lint passes;
  - the end-to-end smoke passes in the container.

## 3. Named fallback constructor

- [ ] 3.1 Add `IfirmaContractorResolution::fallback()` (design D3) with unit tests. Use it for every identifier-less fallback in `IfirmaContractorService` and `IfirmaReceiptService`. Verify that the existing pins of `ensure()` results and of the stored `_contractor` records are unchanged and green, `git grep -n "new IfirmaContractorResolution(null"` is empty, and diff-cover is at 100%.

## 4. Test-only cleanups

- [ ] 4.1 Pin the customer panel's JSON escaping: a match label containing `<`, `>`, `&`, `'` and `"` is encoded with the `JSON_HEX_*` escapes. Verify that the test fails on a mutant that drops the flags.
- [ ] 4.2 Move the repeated `throwables()` and `failures()` providers into a shared trait in `tests/Support/` (design D4), and build `brokenHoldPipeline()` on `pipelineWhoseHoldCheckRaises()`. Verify that the suite keeps the same number of tests and assertions, and that the licence check passes.

## 5. Integration check

- [ ] 5.1 Run the gate over the whole change against its base, the PHP 7.2 lint, the licence check, the end-to-end smoke and `openspec validate refactor-tda-followups --strict`. Then run `graphify update .`, commit, push, and confirm that all six CI jobs pass with the coverage job at 100%.

## Workflow follow-up

- Archive the change once CI is green. There is no spec sync, because `skip_specs` is set.
