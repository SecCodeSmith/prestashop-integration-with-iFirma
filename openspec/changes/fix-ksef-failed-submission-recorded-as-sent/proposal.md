# Proposal

## Why

`IfirmaKsefService::submit()` decides success by checking that no error text was collected. When the call to iFirma throws an exception whose message is empty or only whitespace, there is no text to collect, so the module records the invoice as sent to KSeF: status `WYSLANA`, `ksef_sent_at` stamped, result `ok`. In fact the submission failed. The merchant then believes that a legally required e-invoice reached KSeF when it did not.

The `ksef-submission` spec already requires a failed submission to record `BLAD` with its error, and to record `WYSLANA` only when iFirma answers `Kod` 0. This edge case breaks both rules, and no test covered it. The adversarial review of `refactor-clean-code-tda` (task 3.3) found it, and the user chose to fix it on 2026-11-05.

## What Changes

- `submit()` tracks failure explicitly. A thrown `Throwable`, or an answer without `Kod` 0, is always a failure, whatever text came with it.
- When the failure carries no usable text (an empty or whitespace-only message), `ksef_error` and the result message hold the existing message "iFirma did not confirm the KSeF submission". That message is already translated. This is the fallback the module already uses when iFirma itself answers with no text.
- Both callers get the fix:
  - The pipeline's `ksef` step is recorded as failed with "KSeF: <message>", while delivery still runs and the outcome stays `issued`.
  - The admin **Send to KSeF** action reports the failure.
- Nothing else changes: no new strings, settings, schema columns or API calls.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `ksef-submission`: adds the requirement that a submission is recorded as sent only when iFirma confirms it, with scenarios for a failure without a usable message.

## Impact

- `classes/IfirmaKsefService.php`: `submit()`.
- Tests: `tests/Unit/KsefServiceTest.php`, plus a pin through the pipeline's `ksef` step in `tests/Unit/IssuancePipelineTest.php`.
- Behaviour changes only in the broken edge case. As a side effect, a whitespace-only exception message now stores the readable fallback text instead of the whitespace.
- The change lands straight on master behind the same local gate as the refactor (PHPUnit with pcov, then diff-cover at 100% of changed lines), and CI repeats the gate after the push.
