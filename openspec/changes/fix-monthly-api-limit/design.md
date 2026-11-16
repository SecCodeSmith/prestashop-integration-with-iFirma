# Design

## Context

See proposal.md. Facts found in the code of 1.10.1:

- `IfirmaRemoteLimit::readAndStore()` stores the reading and calls `raiseDayCounterTo()`, which writes `IFIRMA_RATE_DAY_WINDOW` (`IfirmaRateGuard::DAY_COUNTER_KEY`).
- `IfirmaRateGuard::dayCap()` takes `min(configured cap, IfirmaRemoteLimit::grantedOn(today))`.
- `IfirmaApiUsagePresenter::kindsTable()` derives an "other clients" row from the day counter minus the sum of the kinds.
- `IfirmaRemoteLimitPresenter::readingBlock()` treats every reading from a day other than today as "earlier day".

Constraints: PHP 7.2 syntax, PrestaShop 1.7 to 9.x, no runtime dependency, the version is bumped only by the Release workflow.

## Decisions

- **Separate the two limits.** The monthly API limit is a fact about the account that iFirma reports; the day and minute windows are the module's own protection against iFirma's request quota. A reading is data to show and to store. It never feeds the guard. Considered and rejected: a monthly guard that refuses issuing when the monthly limit is used up (the owner chose to separate first; iFirma itself rejects the call when the limit is reached, and that answer is handled by the existing error mapping).
- **`IfirmaRemoteLimit` keeps one job: read and store.** `raiseDayCounterTo()` and `grantedOn()` are deleted because nothing else needs them. The private `snapshot($day)` goes with them; the ten-minute reuse in `refresh()` uses `stored()` and the age of the reading only. `IfirmaRateGuard` no longer requires `IfirmaRemoteLimit` and `dayCap()` is `capOf('IFIRMA_RATE_MAX_PER_DAY', DEFAULT_DAY_CAP)`.
- **Reuse of a fresh reading across midnight.** The old code compared the day of the reading with today. The reading is a monthly figure, so only its age (ten minutes) decides whether iFirma is asked again.
- **Usage panel.** `kindsTable()` lists the kinds only; the `$dayUsed` argument and the label `other_clients` go. `IfirmaApiUsagePresenter::render()` keeps its signature (the day window is still needed for the windows table).
- **Limit box.** The stored reading's month is `substr(day, 0, 6)`. Same month as now: the normal reading line (with date and time of the reading), whatever the day. Earlier month: the label `earlier_month` ("outdated"), with the date of the reading. The presenter label key `earlier_day` is replaced by `earlier_month`; the labels are wired only in `ifirma.php`, so no external caller breaks. The wording never says "requests".
- **Contracts unchanged.** The stored format keeps the reading date as its first part, which is what the month comparison reads. The cron `limit` block keeps `used`, `granted`, `remaining`.
- **No upgrade script.** Shops that already have an inflated day counter or a lowered cap lose both the next calendar day: the counter restarts from zero at midnight and the cap is no longer derived from the reading. The stored reading stays valid as it is.
- **The granted figure is the account's own.** iFirma assigns each account its own monthly grant when the user requests a limit (Limity API) and can change it during the month, so the module never assumes a fixed figure: the box and the cron show the grant of the last reading.

## Interaction with the unarchived change `add-api-usage-delivery-retry-status-filter-and-ci-fix`

That change (not archived yet) holds three requirements whose scenarios this change supersedes: "Remaining limit checked on demand" (scenario "Other clients used the account"; the click "SHALL update ... the module's day counter"), "Kind counts agree with the limit counter" (the clause "apart from a correction the module applied after reading iFirma's own counter") and "Usage shown on the configuration page". Its main-spec target for "Optional limit visibility" is the base of the MODIFIED text here. This change does not edit that change. When both are archived, archive that one first and then this one, and drop the superseded scenario and clauses named above at that point. The two ADDED requirements here state the replacement behaviour in full.

## Risks

- A shop with a granted monthly limit below its configured day cap now sends up to the configured cap per day. The cap default (14,000) is below iFirma's request quota; a shop that shares the account with other programs lowers the caps by hand (documented).
- The monthly figure's exact meaning is not documented by iFirma. The UI and docs say "monthly API limit", never "requests".
