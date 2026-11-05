# Tasks

Test-first, straight to master, behind the same local gate as `refactor-clean-code-tda`: the whole suite with pcov, then diff-cover at 100% of changed lines. CI repeats the gate after the push.

## 1. Pin the bug, then fix it

- [x] 1.1 Add tests to `tests/Unit/KsefServiceTest.php` for the spec scenarios "Error without a message" and "Error with a whitespace-only message": the send request throws with the message `''`, and with `'   '`, once for an `Exception` and once for an `Error`.
  - Assert that `submit()` returns `array('ok' => false, 'message' => 'iFirma did not confirm the KSeF submission')`, and that the receipt has `ksef_status` `BLAD`, that `ksef_error`, and an empty `ksef_sent_at`.
  - Verify that they FAIL on the current code, which today answers `ok` true and records `WYSLANA`. The existing `testSubmitRecordsAThrowableFromTheClientAsTheError`, the spec scenario "Error with a message", must still pass.
  - Commit them together with the fix in 1.2, so that no commit is red on its own.

  Evidence:
  - A characterisation commit, `926c471`, came first and passes on base. It pins the not-issued refusal, three accepted answer shapes and nine refused ones, and kills 12 of 12 mutants of the old code.
  - Run against the old `submit()`, the 6 new tests in `KsefServiceTest` and `IssuancePipelineTest` fail with the bug itself: `'ok' => true` and `ksef:ok` for a failed send. I re-checked this myself.
  - The existing "Error with a message" test still passes.
- [x] 1.2 Fix `IfirmaKsefService::submit()`:
  - track failure with a flag, set by the branch for an answer without `Kod` 0 and by the `catch`;
  - trim the caught message;
  - when a failure has no text, use the existing `l('iFirma did not confirm the KSeF submission', 'ifirmaksefservice')` literal;
  - keep the success path byte-identical (`WYSLANA`, `ksef_sent_at`, `ok` with iFirma's `Informacja`), and the path for a failure that has a message.

  Verify:
  - the 1.1 tests pass and `KsefServiceTest` is green;
  - `TranslationCoverageTest` is green, because there is no new string;
  - the gate against the change's base commit passes with 100% of changed lines covered;
  - the PHP 7.2 lint and the licence check pass.

  Evidence, fix commit `a16a476`:
  - A failure flag is set by the branch for an answer without `Kod` 0 and by the `catch`, and the caught message is trimmed.
  - The fallback literal appears once, applied to every failure without text. The same 13 `l()` calls remain, so there is no new string, and `TranslationCoverageTest` is green.
  - Gate against `6c29e1b`: `OK (1582 tests)`, 8 changed lines, 100%. The PHP 7.2 lint and the licence check pass.
  - 18 of 18 mutants of the fix are killed.
- [x] 1.3 Pin the spec scenario "Pipeline step with an unexplained failure" in `tests/Unit/IssuancePipelineTest.php`. Use the real `IfirmaKsefService`, so the test goes through `submit()`. Only the KSeF send request throws, with an empty message; creating and numbering the document succeed.
  - Assert that the `ksef` step is failed with "iFirma did not confirm the KSeF submission", and that the result message contains "KSeF: iFirma did not confirm the KSeF submission".
  - Assert that delivery ran and the outcome is `issued`, and that the receipt holds `ksef_status` `BLAD`.
  - Verify that it fails on the base code and passes after 1.2. It lands in the same commit as the fix.

  Evidence:
  - `testAKsefFailureWithoutAMessageFailsTheStepAndIsRecordedAsAnError` goes through the real `IfirmaKsefService`, for an Exception and for an Error. On the base the step was `ksef:ok`; with the fix it is `ksef:failed` with the fallback text, while delivery still runs and the outcome stays `issued`.
  - End-to-end smoke on master `dd6dc23`: 90 passed, and all 91 check lines are identical to the pre-refactor master. That includes scenario F (KSeF submission for a B2B order), whose success path is unchanged.

## 2. Integration check

- [ ] 2.1 Before pushing, run:
  - the whole suite and the gate against the base commit;
  - the PHP 7.2 lint and the licence check;
  - `openspec validate fix-ksef-failed-submission-recorded-as-sent --strict`;
  - the end-to-end smoke (`tests/smoke/run.php` in the PrestaShop container), whose KSeF scenario must still pass.

  Then run `graphify update .`, commit, push to master, and confirm that all six CI jobs pass with the coverage job at 100%. Verify by the smoke summary and by the CI run's job list and diff-cover summary.

## Workflow follow-up

- Archive the change once CI is green. The archive syncs the new requirement into `openspec/specs/ksef-submission/spec.md`.
