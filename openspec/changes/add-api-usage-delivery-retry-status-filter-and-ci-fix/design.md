# Design

## Context

See proposal.md for the motivation. Facts found in the code of 1.9.1 that shape the approach:

- **One gate for all requests.** `IfirmaApiClient::execute()` calls `passRateGuard()` and then the transport (`IfirmaApiClient.php:760-778`). `post`, `put`, `get`, `getBinary`, `listInvoices`, `getDetail`, `send`, `sendToKsef` and `getLimit` all go through it. There is no second transport. Status checks and PDF downloads are therefore counted today; only the proof and the visibility are missing.
- **Counters.** `IfirmaRateGuard` keeps one `bucket:count` value per window in `Configuration` (`IFIRMA_RATE_WINDOW`, `IFIRMA_RATE_DAY_WINDOW`) and raises it with one atomic `UPDATE`.
- **Refusal paths.** `IfirmaReceiptActions.php:216-228` ignores the sync summary, so a refused refresh reads as success. `controllers/front/receipt.php:62-66` turns every fetch exception into 502. `AdminIfirmaController.php:1057-1061` does the same and shows the guard text.
- **Delivery.** `IfirmaDeliveryService::deliver()` records `failed` plus `delivery_error` and never throws. The table has no attempt counter. `IfirmaAwaitingDeliveryJob` selects only `awaiting_ksef` documents that KSeF accepted. `Mail::Send` is called statically inside the service.
- **Orders grid.** `IfirmaOrderGridIndicator::rankSql()` holds the ten-state `CASE` expression. The query-builder hook joins the newest record and never touches the count query. The column has no filter.
- **Documents list.** A legacy `AdminController` list; `status` sorts as a raw string.
- **CI.** Security fails because the pinned `test-stack` commit `9834c14` has `prestashop/prestashop:latest`; the pin is an uncommitted edit in the submodule. Smoke exits 1 from `run.php:2710`, which only happens when a check failed after bootstrap; environment problems exit 2, a PHP fatal 255. The failing check is unknown: job logs need a GitHub login.

Constraints: PHP 7.2 syntax in shipped code, PrestaShop 8.0 to 9.x, no runtime dependency, the version is bumped only by the Release workflow, and `test-stack` is a separate repository.

## Goals / Non-Goals

**Goals:**

- Make the counting of every request kind provable and visible without changing how the limit decides.
- Retry temporary delivery failures automatically without ever e-mailing a customer twice.
- Reuse one definition of the state rank for sorting and filtering in both lists.
- Turn both red workflows green by removing the defects they found, and make the next smoke failure readable from the run itself.

**Non-Goals:**

- No per-kind limits or budgets. Kinds are statistics only; the minute and day caps stay the only gate.
- No new setting on the configuration form. The retry schedule is fixed.
- No retry of issuance, KSeF submission or status sync beyond what exists.
- No Orders-list column on the non-Symfony list of PrestaShop before 1.7.7 (the module supports 8.0+).
- No change to the mock's limits or counters.

## Decisions

### D1. The kind of a request is derived from its method and path

A new `IfirmaRequestKind::of($method, $path)` maps a request to one of the nine kinds with an ordered list of path patterns; anything unmatched is `other`. `execute()` asks it once, at the line where it already raises `requestCount`.

*Why not a `$kind` parameter on `post()`/`get()`?* Those signatures are contracts used by five classes; a parameter would let a caller mislabel a request or forget it. Deriving the kind keeps callers unchanged and gives one table to test.

*Why not classify in the transport?* The transport only knows HTTP. The kind is iFirma API knowledge and belongs next to the client.

### D2. Per-kind day counters reuse the rate-window storage pattern

A new `IfirmaApiUsage` owns the counters. Each kind has one `Configuration` value `IFIRMA_USAGE_<KIND>` holding `YYYY-MM-DD:count`, raised by the same single-statement `UPDATE` that `IfirmaRateGuard` uses (reset when the day changes). The client receives the recorder through its constructor with a default, like the guard.

- Counting happens only after the guard admitted the request, so refused requests are never counted and the sum of the kinds equals the day window. The one exception is `IfirmaRemoteLimit`, which may raise the day window to iFirma's own figure; the usage box then shows the difference as "other clients of the account".
- The minute and day totals shown are read from the existing guard windows, not duplicated.
- A failure to write a usage counter is logged and never fails the request: statistics must not block issuance.

*Alternative considered:* one JSON value for all kinds. Rejected: it cannot be raised atomically, and concurrent requests (hook, cron, customer download) would lose counts.

*Alternative considered:* a new table with one row per request. Rejected: more storage and a cleanup job for a figure nobody needs per request.

### D3. Usage is rendered by a presenter and reported by the cron runner

A new `IfirmaApiUsagePresenter` turns the counters, the caps and the back-off time into the view data of a "Module requests" box on the configuration page. `ifirma.php` only wires it. `IfirmaCronRunner::runJobs()` adds the `usage` block from the same reader after the jobs, also for a limited run.

### D3a. The remaining limit is read on a click, through the existing reader

Today `renderLimitBox()` in `ifirma.php:586-611` calls `getLimit()` on every view of the configuration page: one counted request per page view, and business logic in the module class. The endpoint (`abonent/limit.json`), the client method (`IfirmaApiClient::getLimit()`) and the stored reading (`IfirmaRemoteLimit`, used by `IfirmaRemoteLimitJob` in the cron) already exist.

- The page gets a small form with the button `submitIfirmaCheckLimit`, next to the existing `submitIfirmaRegenerateCron` and handled in the same dispatch (`ifirma.php:210-216`). It is a POST inside the configuration page, so the back-office token and the module's configure permission are checked by PrestaShop before the handler runs; the handler also requires the POST method itself.
- The handler delegates to a forced refresh on `IfirmaRemoteLimit` (the ten-minute reuse applies to the cron only), so the click and the cron share one code path for storing the reading and raising the day counter.
- A new `IfirmaRemoteLimitPresenter` renders the box from the stored reading (used, granted, remaining, time, "not checked yet", "from another day") and from the outcome of a click (checked, refused with the window, unavailable). `renderLimitBox()` shrinks to wiring, and the page view sends no request.
- The request goes through `execute()`, so D1 and D2 count it as kind `limit` with no extra code.

*Why a form post and not an ajax action in `AdminIfirmaController`?* That controller's actions are gated by the module tab levels (view, issue, manage), while the configuration page is gated by the right to configure the module. A form on the page keeps one permission model for everything on it and needs no JavaScript.

*Why stop the automatic read on page view?* It spends a request each time someone opens the settings, and it makes "opening the page sends no request" (usage box, D3) impossible to promise. The cron keeps the reading fresh for shops that run it.

### D4. A limit refusal becomes a typed result

- `IfirmaApiClient` already exposes `rateLimited` and the window through `IfirmaRateDecision`. `IfirmaReceiptService::fetchPdf()` throws a new `IfirmaRateLimitedException` (carrying the window and the seconds to wait) instead of a plain `Exception` when the guard refused. It extends `Exception`, so every existing `catch (Exception $e)` keeps working (same or narrower exceptions, Liskov).
- Both download controllers catch it first and answer through a new `IfirmaHttpOutput::tooManyRequests($retryAfter, $message)`. The front message is generic; the admin message names the window. Ownership, token and throttle checks stay in front of the fetch, so the answer order for a foreign customer does not change.
- `Retry-After`: seconds to the next minute for the minute window, to the back-off end for the back-off, and to the next day capped at 3600 for the day window. Always at least 1.
- `IfirmaReceiptActions::refresh()` reads `rate_limited` and the window from the sync summary and returns a failure result with a translated text. Nothing is written to the record.
- `IfirmaStatusSyncService::opportunisticSync()` returns false on a refusal, so the page does not reload the record as if it had been synced.
- `IfirmaDeliveryService::fetchPdfWithRetry()` already stops on a refusal; it now rethrows the typed exception so the delivery can tell "refused" from "failed" (D6).

### D5. Delivery retry state: two columns and a policy object

`ps_ifirma_receipt` gains `delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0` and `delivery_retry_at DATETIME NULL`, added by `IfirmaSchema` and a new upgrade script. `delivery_status` keeps its values; a delivery waiting for a retry is `failed` with a non-null `delivery_retry_at`. Existing failed rows keep `NULL` and are therefore never selected, which is what the spec requires after an upgrade.

A new immutable `IfirmaDeliveryRetryPolicy` holds the constants (`MAX_ATTEMPTS = 3`, waits of 900 and 7200 seconds) and answers one question: given the attempts used and the failure class, when is the next attempt due, or is there none.

*Why not a `retrying` delivery state?* `failed` is a contract: the Needs attention preset, the issue feed, the grid tooltip and the bulk resend all read it. A new state would silently drop the document from each of them.

*Why fixed values?* The owner chose them. They sit in one class, so a later setting is an additive change.

### D6. The delivery result carries a failure class

`deliver()` returns a result that today knows sent or failed plus a local-refusal reason. It gains a failure class:

| Class | Examples | Attempt counted | Retry |
| --- | --- | --- | --- |
| temporary | `Mail::Send` false or throwing, PDF not ready after the in-run attempts, iFirma mail answered a non-permanent `Kod`, HTTP 5xx | yes | by policy |
| permanent | no valid customer e-mail, document missing, permanent `Kod` (400, 401, 402, 403, 499, 500 as in `PDF_PERMANENT_KODS`) | yes | never |
| unknown outcome | iFirma mail request sent and no answer (timeout, connection lost) | yes | never |
| refused | rate guard, authentication cool-down, before any request | no | due at once |

The service classifies where it already builds the message; the policy and the record update (`attempts`, `retry_at`) happen in one place, `IfirmaReceipt::recordDelivery()`, in the same write as today.

A manual **Send again** goes through the same `deliver()` with `manual => true`. On success it clears `delivery_retry_at`. On failure it leaves the automatic counters untouched, so an employee's click neither burns nor resets the schedule.

### D7. A sixth cron job retries due deliveries under the order lock

A new `IfirmaDeliveryRetryJob` implements `IfirmaCronJob` and is appended to the list in `IfirmaCronRunner::build()`, after `IfirmaAwaitingDeliveryJob`. Adding a class keeps the awaiting job closed.

- Selection: `delivery_status = 'failed' AND delivery_retry_at IS NOT NULL AND delivery_retry_at <= :runStart`, oldest due first, limited by the run cap. The run's start time is taken once from the cron context, so a delivery that the awaiting job failed in the same run (due 15 minutes later) is never picked up in that run.
- Per record: check the guard like the awaiting job and stop at the first refusal; take the per-order named lock without waiting and skip the record when it is busy; re-read the record under the lock and skip it unless it is still `failed` and due; deliver; release.
- Channel `none` at retry time: the delivery is recorded `skipped` and the schedule cleared.
- The report uses `IfirmaCronJobReport::counts()` with the number still due as "left".

The spec counts five jobs because the limit read that runs first is described in `ifirma-api-compliance`, not in the job list.

*Alternative considered:* widen the awaiting job's selection. Rejected: two reasons to change in one class, and its "never retried" contract is tested.

### D8. One state rank for both lists, and a filter built from it

The `CASE` expression moves to a new `IfirmaDocumentStateRank` that produces the SQL for a given record alias and lists the ten states with their rank. `IfirmaOrderGridIndicator::rankSql()` delegates to it, so the existing sort tests keep passing unchanged.

**Orders-list filter.** The definition hook adds a choice filter to the column with the ten states as labels and the rank integers as values. The query-builder hook reads the filter value from the search criteria, accepts it only when it is an integer between 1 and 10, and adds `WHERE (<rank sql>) = :ifirma_rank` with a bound parameter to the search query builder and to the count query builder. The count builder needs the same join of the newest record, added only when the filter is active, so an unfiltered list keeps its current count query. Anything else (unknown value, array, text) leaves both builders untouched.

**Documents list.** The state column gets the rank expression as its order key in `AdminIfirmaController`, with `id_ifirma_receipt DESC` as the tie-break. The direction is normalised to `ASC` or `DESC` before it reaches SQL. The rank is per record, so "no document" does not occur there.

*Alternative considered:* a stored rank column kept up to date on every write. Rejected: a derived value that must be maintained on every state change, for lists that sort a few thousand rows.

### D9. Security: the pin is committed where it belongs

The fix is the existing uncommitted edit in `test-stack` (`image: prestashop/prestashop:${PS_IMAGE_TAG:-9.1.4-apache}` and the README lines). It is committed in the `test-stack` repository, merged to its default branch, and the module pins the merged commit. `tools/check_pinned_images.py` accepts that reference (verified locally: exit 0 on the working tree, and the committed tree lists line 67 as the only finding).

The checker is not relaxed. The Trivy filesystem step after it has never run on master; it is run once the pin is in, and a finding is fixed or, when no fix exists, written down with reason and expiry date as CLAUDE.md requires.

### D10. Smoke: evidence first, then the fix

The order matters because the failing check is unknown.

1. **Make the run readable.** The workflow pipes the smoke output through `tee` into a file, uploads it as an artifact per matrix entry, and appends the `[FAIL]` lines and the totals to the job summary. `run.php` already masks nothing sensitive in its labels; the task verifies that and masks any token it finds.
2. **Make the run honest.** With the pin from D9 the matrix tag reaches the stack. A new step compares the shop's reported version with the matrix tag and fails before the scenarios. The wait step checks the install marker of the image and a 200 answer, not any HTTP answer.
3. **Select scenarios by name.** `run.php` accepts a comma-separated list (`SMOKE_ONLY=A,A2,B`) next to today's letter string, fails on an unknown name, and the workflow excludes R with an explicit `SMOKE_SKIP=R`. A committed floor of executed checks per scenario (`tests/smoke/check-floor.json`) makes a silent drop fail.
4. **Reproduce.** Run the workflow by hand on the branch and read the artifact. If a local run is needed, use a fresh clone in a scratch directory with its own compose project name and without the fixed container names, so the two long-lived development shops are not touched.
5. **Fix each failing check at its cause** with the TDD loop: a unit test that reproduces the defect, then the fix. Candidate causes, to be confirmed or dropped by the log: behaviour on the `latest` image (newer than 9.1.4, never tested), the module installed by `run.php` on a fresh shop (every green local run used a shop with the module already installed), and version-specific behaviour on 8.0, 8.2 and 9.0 that the two local shops (8.1.7, 9.1.4) never showed.

A check is changed only when its expectation contradicts a spec scenario, in a commit that names the scenario.

### D11. Version and upgrade script

The change needs a minor version. The upgrade script is written for the version the Release workflow will produce from 1.9.1 with a minor bump, `upgrade/upgrade-1.10.0.php`, and no version string is edited by hand. If a patch release lands first, only the file name is unaffected; if another minor lands first, the script is renamed before release.

## Risks / Trade-offs

- [A retry e-mails a customer who already got the mail] → Only failures known not to have sent are retried; unknown outcomes are excluded (D6); the record is re-read under the order lock (D7); smoke scenario N counts the mails in the sink.
- [The shop mail system reports success and drops the mail later] → Out of the module's reach, as today. Documented in troubleshooting.
- [Nine more `Configuration` writes add load] → One extra single-row `UPDATE` per iFirma request, next to two that exist. A request to iFirma costs far more.
- [`Configuration` cache shows a stale count] → The usage box reads the values with the same uncached read the guard uses.
- [The count query of the Orders list gets a join] → Only when the filter is active; the unfiltered count query is unchanged and its test stays.
- [PrestaShop 8.0 and 9.x differ in the grid filter API] → Guarded by `class_exists` like `hasGrid()`, with the grid smoke check run on every matrix version.
- [429 for a download surprises an integration that expected 502] → Marked BREAKING in the proposal and in `docs/upgrading.md`. Browsers show the message either way.
- [The smoke cause turns out to be large, for example a PrestaShop 9.2 incompatibility] → Group 8 is ordered so the evidence comes first; a fix too large for this change is split into its own change, and the matrix stays as declared meanwhile, red and visible.
- [The dependency on the unarchived 1.9.0 change] → Group 1 settles it before any spec of this change is synced.
- [Old failed deliveries stay unsent] → Deliberate. The Needs attention preset lists them and bulk **Send again** exists.

## Migration Plan

1. Archive `add-rate-limit-queue-and-ksef-confirmed-delivery` (or finish its open tasks first), so its requirements are in `openspec/specs/`.
2. Land the CI fixes first (groups 2 and 8): they are independent of the module code and make every later commit verifiable.
3. Land the module work in the order of the task groups; each group is green on its own.
4. `upgrade-1.10.0.php` adds the two columns with `IfirmaSchema`'s idempotent column helper and creates the usage keys. Rollback: the columns and keys are ignored by 1.9.1, so reinstalling the previous zip works without a down-migration.
5. Red-team pass, then the Release workflow with a minor bump.

## Open Questions

- Which smoke check fails, and on which versions once the matrix tag is honoured. Answered by task 8.4; it decides the content of 8.6 but not the structure of the plan.
- Whether the Trivy filesystem step reports findings on master. Answered by task 2.3.
