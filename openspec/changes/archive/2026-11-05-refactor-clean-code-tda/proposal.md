# Proposal

## Why

A Clean Code, SOLID and Tell-Don't-Ask review of the module (about 11.5k lines) found duplication and "ask, then decide" call patterns that make changes risky. The clearest examples:
- The same API-client construction is copy-pasted into 8 service classes.
- 15 duplicated `Exception`/`Throwable` catch pairs sit in `IfirmaAdminActions`.
- Two `matchedByLabel()` methods give the same code different wording on different screens.
- Status and outcome strings are bare literals.
- Services set `status`, `last_error` and friends on entities field by field, instead of telling the entity what happened.

Behaviour is well covered (about 455 unit tests), so this is the right time to pay the debt down. Every touched line must be proven covered before it changes, and the full suite must pass after each step.

## What Changes

- **Coverage safety net (prerequisite):**
  - PHPUnit coverage reporting (pcov, Cobertura XML).
  - A CI gate that fails when changed PHP lines are not covered.
  - A PHP 7.2 syntax lint, because the module declares `php >=7.2`.
- **Tier 1, mechanical, no behaviour change:**
  - A single API-client factory replaces the 8 copy-pasted `build()` client blocks.
  - One error-guard helper in `IfirmaAdminActions` replaces the 15 dual catch blocks.
  - One shared match-label map, using the existing `IfirmaContractorMap::BY_*` constants.
  - Named constants for pipeline outcomes and receipt statuses where raw strings are used today.
  - The HTTP status line in the cron and front controllers is derived from the real status code.
  - One schema column-existence helper, plus a named identifier-length constant.
  - A payment-map serialiser that is the exact inverse of the existing parser.
- **Tier 2, Tell-Don't-Ask:**
  - Intent methods on `IfirmaReceipt` (mark error, unknown or held; record delivery) and on `IfirmaDataRequest` (approve, reject).
  - `IfirmaContractorService::ensure()` returns a resolution value object instead of exposing `getLast*()` state.
  - Delivery returns a result object instead of an untyped array.
  - Data-request mail moves into a notifier collaborator.
- No merchant-visible behaviour, database schema, configuration key, translation string or hook changes. One exception: the HTTP reason phrase for non-200, non-403 statuses, which no code path emits today.

## Capabilities

### New Capabilities
<!-- none: pure refactor, skip_specs is set -->

### Modified Capabilities
<!-- none: no requirement changes -->

## Impact

- `classes/`: IfirmaApiClient, IfirmaAdminActions, IfirmaReceipt, IfirmaDataRequest, IfirmaPipelineResult, IfirmaContractorService, IfirmaCustomerContractorService, IfirmaDeliveryService, IfirmaDataRequestService, IfirmaIssuancePipeline, IfirmaReceiptService, IfirmaStatusSyncService, IfirmaKsefService, IfirmaDuplicateGuard, IfirmaCustomerView, IfirmaSchema, IfirmaPaymentMethodResolver. There are also new small classes: the resolution, delivery-result and notifier classes, plus the label map.
- `ifirma.php` (label helpers, payment-map serialisation) and `controllers/front/cron.php`, `receipt.php`.
- `tests/Unit/*`: new characterisation tests where coverage is missing. Existing assertions are retargeted only where a public return type changes (ensure, deliver).
- `phpunit.xml.dist`, `composer.json` scripts, `.github/workflows/ci.yml` (coverage and PHP 7.2 lint jobs).
- Ordering: independent of `ci-test-builds-and-housekeeping`, but must land before `refactor-srp-splits`, which builds on the factory, intent methods and result objects.
