# Tasks

Rules (CLAUDE.md): test first and show the red run before the code; one concern per commit; no test is deleted, skipped or weakened (a test whose expectation encodes the wrong behaviour is changed with its reason recorded); no version string is edited by hand.

## 1. Red: tests

- [x] 1.1 Rewrite the expectations of the existing tests that encode the day-counter raise, the cap lowering, the other-clients row and the earlier-day wording (`RemoteLimitTest`, `RemoteLimitCheckTest`, `ConfigPageLimitTest`, `ApiUsagePresenterTest`, `RemoteLimitPresenterTest`). Verify: they fail on the current code.
- [x] 1.2 Add the new scenario tests (reading leaves counters alone, granted below cap does not lower it, reading above the cap does not refuse, same-month earlier-day reading shown, earlier-month reading outdated, no other-clients row). Verify: they fail on the current code.

## 2. Green

- [x] 2.1 `IfirmaRemoteLimit`: remove `raiseDayCounterTo()`, `grantedOn()` and `snapshot()`; reuse by age only.
- [x] 2.2 `IfirmaRateGuard::dayCap()` is the configured cap; drop the `IfirmaRemoteLimit` dependency.
- [x] 2.3 `IfirmaApiUsagePresenter`: no other-clients row; `ifirma.php`: remove the label.
- [x] 2.4 `IfirmaRemoteLimitPresenter`: monthly wording, `earlier_month`; `ifirma.php` labels; Polish translations in `tools/pl_translations_map.php` and `translations/pl.php`.

## 3. Refactor, smoke, docs

- [x] 3.1 Smoke step in `tests/smoke/run.php` and `tests/smoke/README.md` against `POST /__mock/monthly-limit`.
- [x] 3.2 Docs: `docs/configuration.md`, `docs/architecture.md`, `docs/troubleshooting.md`, `docs/upgrading.md`.
- [x] 3.3 Full suite, changed-line coverage gate, PHP 7.2 lint, `openspec validate fix-monthly-api-limit`.
