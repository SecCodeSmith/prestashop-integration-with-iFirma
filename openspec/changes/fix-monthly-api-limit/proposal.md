# Proposal

## Why

iFirma's `GET iapi/abonent/limit.json` (key `abonent`; fields `LimitWykorzystany` and `LimitPrzyznany`) reports the account's **monthly API limit** (iFirma help: "Limit wystawionych za pomocą API faktur w miesiącu to 1000"; documentation: https://api.ifirma.pl/sprawdzanie-limitu-api/). It is not the daily request quota. The daily quota (15,000 per day, 100 per minute) has no read endpoint. iFirma does not say precisely what the monthly figure counts, so the module must not call it "requests".

The module treats the reading as a daily request count, and the two figures get mixed:

- a reading raises the module's day request counter to the monthly "used" figure;
- the granted monthly figure (for example 2,000) lowers the day cap `IFIRMA_RATE_MAX_PER_DAY` (default 14,000), so the module refuses requests it is allowed to send;
- the usage panel shows an "other clients" row that only exists because of the raise;
- the limit box says "Wykorzystano 119 z 2000 zapytań" and treats a reading of yesterday as stale.

Seen in a real shop: the limit box said 119 of 2,000 while the module's own panel said 546 of 2,000 for today (366 status calls, 62 limit checks and others). The day cap had dropped to 2,000 and the two counters were mixed.

## What Changes

- **A limit reading changes no request counter and no cap.** The cron job `limit` and the employee's "Check the remaining iFirma limit" click store the reading (`IFIRMA_REMOTE_LIMIT`, format unchanged) and nothing else. The module's day window, minute window and per-kind counts are not touched by the reading. The request itself is counted like every request, as before.
- **The day cap is always the configured `IFIRMA_RATE_MAX_PER_DAY`** (default 14,000). No reading lowers it. `IfirmaRemoteLimit::grantedOn()` and the day-counter raise are removed.
- **The usage panel ("Zapytania modułu") shows only the module's own requests.** The "other clients" row and its label are removed. The day row reads `<module count> z <configured cap>`.
- **The limit box presents the monthly API limit**: used, granted, remaining and the time of the reading. A reading from an earlier day of the same calendar month is still this month's figure and is shown as such with its reading time. A reading from an earlier month is shown as outdated. The "nothing left" warning stays: when the monthly limit is used up iFirma does not accept documents through the API.
- **Docs call it the monthly API limit** ("miesięczny limit API iFirma"), keep the advice that the request limits are shared by every client of the account, and correct it: without a daily read endpoint the module cannot see other clients' requests, so lower the caps when other programs use the account.
- **No change to contracts:** the config key `IFIRMA_REMOTE_LIMIT` and its stored format `<Ymd>:<used>:<granted>:<unix time>`, the cron answer's `limit` block keys (`used`, `granted`, `remaining`; only the documented meaning becomes monthly), hook names and public method signatures used elsewhere stay as they are. No upgrade script is needed (see design).
- **Smoke:** scenario step against the mock's new `POST /__mock/monthly-limit` override proves that a reading of 1,900 of 2,000 leaves the day counter and the day cap alone.
- **BREAKING (behaviour only):** a shop whose account had a granted limit below the configured day cap sends more requests than before, up to the configured day cap. The 429 handling and the back-off stay as the safeguard.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `ifirma-api-compliance`: "Optional limit visibility" is modified; "Reading the current limit from iFirma" is removed and replaced by "Monthly API limit read from iFirma"; two requirements are added ("A limit reading leaves the module's request counters alone", "The module requests box shows only the module's own requests").

## Impact

- `classes/IfirmaRemoteLimit.php`, `classes/IfirmaRateGuard.php`, `classes/IfirmaApiUsagePresenter.php`, `classes/IfirmaRemoteLimitPresenter.php`, `ifirma.php` (labels), `translations/pl.php`, `tools/pl_translations_map.php`.
- Tests in `tests/Unit` (limit, guard, usage panel, limit box, configuration page) and `tests/smoke/run.php`.
- Docs: `docs/configuration.md`, `docs/architecture.md`, `docs/troubleshooting.md`, `docs/upgrading.md`.
