# Design

## Context

- The module requires `php >=7.2` (composer `require`), while the dev platform is pinned to 8.1 and CI runs 8.2. New code must avoid typed properties (7.4), constructor promotion, enums, `match`, nullsafe operators and union types, or the module breaks on supported shops. CI does not catch this today.
- The tests use hand-written PrestaShop stubs (`tests/Stubs`) and an in-memory state (`IfirmaTestState`). Services already accept injected clients, clocks, sleepers and transports. Static `build(Module)` factories are the production entry points.
- There is no coverage tooling. `phpunit.xml.dist` has no `<coverage>` section, and nobody can tell today whether a line about to change is exercised.
- Review evidence, spot-checked: 8 `new IfirmaApiClient(` sites; 15 `catch (Throwable` in `IfirmaAdminActions.php`; `matchedByLabel` at `ifirma.php:283` and `IfirmaAdminActions.php:316`; `Mail::Send` inside `IfirmaDataRequestService.php:516,574`; `cron.php:53` labels every non-200 status "Forbidden".

## Goals / Non-Goals

**Goals:**
- Every changed line is covered by a test that ran green both before and after the change (the test-first gate).
- Duplication is removed, and state transitions move onto the entities that own them.
- The public surface used by hooks, controllers and templates is unchanged, except where the tasks name it.

**Non-Goals:**
- Splitting long methods and large classes (`createReceipt`, `Pipeline::execute`, ReceiptService, AdminActions, and others). That is `refactor-srp-splits`.
- Wrapping `Configuration`, `Db` or `Context` statics behind interfaces. This is idiomatic PrestaShop, and the stubs already handle it.
- Changing user-facing text or translations. `l()` literals must stay in place so the translation scanner still finds them.

## Decisions

### D1. Test-first protocol per refactor task
Each task runs in four steps:
1. **Characterise:** run coverage on the unchanged code. Every line of the methods to be touched must be covered. Where it isn't, add tests that pin the current behaviour, and commit them before the refactor.
2. **Refactor.**
3. **Full suite:** the whole PHPUnit suite passes.
4. **Changed-line gate:** `diff-cover` against the task's base commit reports 100% of changed PHP lines covered.

A task is not done until both gates pass. If a gate fails, the task stops; no later task starts on a red tree.

The work lands straight on master, one commit per task, with no branch and no pull request (the user's choice on 2026-11-04). The gates therefore run locally before each commit (see D2), and CI repeats them after the push.
- *Alternative:* a global coverage threshold. Rejected, because it lets new code ride on old coverage and does not prove that the touched lines are tested.

### D2. Coverage tooling: pcov plus Cobertura plus diff-cover
- `phpunit.xml.dist` gets a `<coverage>` section that includes `classes/`, `controllers/` and `ifirma.php`.
- `composer coverage` runs `phpunit --coverage-cobertura build/coverage.xml`.
- CI adds a `coverage` job (setup-php `coverage: pcov`) that runs `diff-cover build/coverage.xml --fail-under=100`. It compares a pull request with its base, a push with the commit before the push (`github.event.before`), and a manual run with master. The test build waits for it.
- Locally, the gate is authoritative, because nothing goes through a pull request. It runs the whole suite in a PHP 8.2 image with pcov, the same PHP as CI. The checkout is mounted at `/work/ifirma`, because the bootstrap needs the folder name. It then runs diff-cover on the host against the task's base commit.
- diff-cover's `--include-untracked` misses new files on this machine. The local gate marks untracked files intent-to-add (`git add -N`) for the check and restores the index afterwards. In CI, new files are committed, so they are always part of the diff.
- *Alternative:* xdebug. It is slower, and pcov is enough for line coverage.

### D3. PHP 7.2 compatibility lint
A CI job runs `php -l` with setup-php `7.2` on all shipped PHP files, which means every tracked PHP file outside `tests/` and `tools/`. Lint only: the test suite needs PHPUnit 9 (PHP 7.3+), so the tests and dev tools may legitimately use newer syntax, and syntax is the actual risk in shipped code. The test build waits for this job.

### D4. API-client factory: `IfirmaApiClient::fromConfiguration($baseUrl = null)`
It is a static named constructor that reads `IFIRMA_USER` and the three `IFIRMA_KEY_*` keys, and applies the `?: IFIRMA_BASE_URL` fallback uniformly. Each `X::build()` calls it. The constructor stays unchanged, so the tests that inject clients are untouched.
- *Alternative:* a separate factory class. Rejected because it adds nothing over a named constructor at this size.

### D5. AdminActions error guard: `private function guard(callable $action)`
It wraps the body and has a single `catch (Throwable $e) { return $this->failure($e); }`. On PHP 7.2+, `Throwable` covers `Exception`, so behaviour is identical. The same pattern applies at `ifirma.php:~1455`.

### D6. One label map: `IfirmaMatchLabels`
It holds the match-code to label map, keyed by `IfirmaContractorMap::BY_*`. It takes a translator callable (`array($module, 'l')`), and the `l('...')` literals stay inside `ifirma.php`, passed as a pre-translated array. The scanner only finds `$this->l('literal')` calls in the module file, and that constraint decides where the literals live.

Where the two existing `matchedByLabel` maps word the same code differently, the module-file wording wins. Each differing string is listed in the task and confirmed with the user, because it is visible text.

### D7. Constants, not enums
- `IfirmaPipelineResult::OUTCOME_*` constants, plus `toArray()`.
- `IfirmaReceipt::STATUS_*` (these already exist) are used in IfirmaCustomerView and IfirmaCustomerContractorService instead of literals.

PHP 7.2 rules out enums. The values of the strings do not change, so the persisted data and the test assertions stay valid.

### D8. Entity intent methods (Tell-Don't-Ask)
- `IfirmaReceipt` gets `markError($msg)`, `markUnknown($msg)`, `markHeld($reason)` and `recordDelivery($status, $channel, $error)`. Each sets its fields only; persisting stays with the caller's existing `save()`, so the number of database writes does not change.
- `IfirmaDataRequest` gets `approve($employeeId, array $final, $note, $now)` and `reject($employeeId, $note, $now)`. Both hold the pending guard, and both throw the same exception the service throws today.
- These get direct unit tests (`IfirmaReceipt` has none today).

### D9. Result value objects
- `IfirmaContractorResolution` holds identifier, action, matchedBy, note and readback. `ensure()` returns it instead of the identifier.
- `IfirmaDeliveryResult` holds ok, status, channel and message, with `isSent()`.

Both are immutable, with private fields and getters, which is PHP 7.2-safe. During this change, callers (ReceiptService, Pipeline, AdminActions) migrate in the same task as the producer. The `getLast*()` accessors are removed only once nothing calls them; a grep must return zero callers.

### D10. `IfirmaDataRequestNotifier`
It owns template paths, the language fallback and `Mail::Send`. It is injected into `IfirmaDataRequestService` through the constructor, defaulting in `build()`. Tests inject a recording fake, so approve and reject decisions become testable without the Mail stub.

## Risks / Trade-offs

- [The label consolidation changes visible wording on one screen] → Differences are listed and confirmed with the user before the change. `TranslationCoverageTest` must stay green.
- [The `ensure()` return-type change breaks a caller that wasn't found] → It is grep-verified, and the full suite plus the changed-line gate apply. `php -l` on 7.2 catches syntax-level slips only.
- [A gap in the local gate lets an uncovered line reach master] → The local gate was proven against probes before use: an untested new class, a half-tested branch and an untested method in a tracked class all fail it, and a red test fails it. CI repeats the check after every push, and a failure there blocks the test build.
- [`diff-cover` counts reformatted lines as changed] → Do not reformat unrelated lines. Keep diffs minimal.
- [100% changed-line coverage pushes towards shallow tests] → Characterisation tests must assert outcomes (return values, saved fields, calls to fakes), not just execute lines. This is checked in review.

## Migration Plan

Each task is one commit on master (or two, with the characterisation tests committed first), in the order of tasks.md, so it can be reverted on its own. There is no branch and no pull request. Nothing changes at runtime that would need a data migration. A rollback is a revert of the task commit.
