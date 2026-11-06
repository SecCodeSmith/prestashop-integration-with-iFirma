# Proposal

## Why

`refactor-clean-code-tda` recorded follow-ups it deliberately left alone. They are listed under "Found during this change" in its archived `tasks.md`. Doing them finishes that change's Tell-Don't-Ask and single-definition moves, and closes small test gaps that its reviews found. The KSeF bug in that list is a behaviour change and has its own change, `fix-ksef-failed-submission-recorded-as-sent`. The user chose to do the rest on 2026-11-05.

## What Changes

- **Tell-Don't-Ask:** `IfirmaReceipt` gains an intent method for the paid status and one for the duplicate guard's suspected state. The remaining field-by-field writes in `IfirmaReceiptService::updateStatusPaid()`, `IfirmaStatusSyncService::applyRow()` and `IfirmaDuplicateGuard` use them.
- **One contractor identifier length:** four places reference `IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH` instead of the number 64:
  - `IfirmaContractorMap`'s ObjectModel field size;
  - the identifier truncation in `IfirmaContractorMap::link()`;
  - `IfirmaContractorImport::MAX_IDENTIFIER`;
  - `IfirmaCustomerContractorService::IDENTIFIER_MAX`.
- **Readability:** a named constructor for the identifier-less contractor fallback replaces the positional constructor calls in `IfirmaContractorService` and `IfirmaReceiptService`.
- **Tests:**
  - pin the escaping flags of the customer panel's JSON;
  - move the repeated `throwables()` and `failures()` data providers into a shared test trait;
  - build the older broken-hold pipeline helper on the newer one.
- No merchant-visible behaviour, stored data, configuration, translation or hook changes.

## Capabilities

### New Capabilities
<!-- none: pure refactor, skip_specs is set -->

### Modified Capabilities
<!-- none: no requirement changes -->

## Impact

- `classes/`: IfirmaReceipt, IfirmaReceiptService, IfirmaStatusSyncService, IfirmaDuplicateGuard, IfirmaContractorMap, IfirmaContractorImport, IfirmaCustomerContractorService, IfirmaContractorResolution and IfirmaContractorService.
- `tests/`: new characterisation and unit tests, and a shared provider trait under `tests/Support/`.
- Ordering: independent of `fix-ksef-failed-submission-recorded-as-sent`. It should land before `refactor-srp-splits`, which splits several of the same classes.
- Lands straight on master, test-first, behind the same local gate (PHPUnit with pcov, then diff-cover at 100% of changed lines). CI repeats the gate after the push.
