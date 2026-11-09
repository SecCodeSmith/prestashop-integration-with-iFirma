# Design

## Context

See proposal.md (Why). Facts read in this planning pass that shape the approach:

- **Rate guard today.** `IfirmaApiClient::passRateGuard()` keeps one counter row in `ps_configuration` named `IFIRMA_RATE_WINDOW`, with the value `YYYYmmddHHii:<count>`. One atomic `UPDATE … SET value = IF(same minute, count+1, minute:1) WHERE … (other minute OR count < cap)` decides from `Affected_Rows()`; a missing row is inserted. The cap is `IFIRMA_RATE_MAX_PER_MIN` (default 80, settings range 1 to 100). A refusal returns false from `execute()` before the transport, and callers detect it with `isRateLimited()`. `getBinary()` counts too. The clock is `setClock()`, otherwise `time()`. There is no per-day guard and no handling of HTTP 429: a 429 is a sent request, so `IfirmaReceiptService::unreachable()` records `unknown`. `Kod` 100 maps to "Technical problem on the iFirma side". The auth cool-down (`IFIRMA_AUTH_FAILURE`, 900 s) is the pattern for a stored back-off.
- **Refusals in the pipeline today.** A refused duplicate-guard list call stops with `OUTCOME_RATE_LIMITED` and writes no row (`stopForInconclusive`). A refused create goes through `unreachable()` → `markError()`, and `CreateReceiptTest::testRequestRefusedLocallyIsAnErrorAndNotUnknown` pins that. This contradicts the existing spec scenario "Transient error not persisted". Nothing retries either case: `controllers/front/cron.php` runs only `IfirmaStatusSyncService::runCron()`, and `findDueForSync` skips `error` rows.
- **Status hook today.** `hookActionOrderStatusPostUpdate()` calls `IfirmaIssuancePipeline::onOrderStatusChange()`, which returns silently, with no record and no log line, when the new status is not the single trigger status (`IFIRMA_AUTO_CREATE_STATE`), when credentials are missing, or when the run is rate limited at the guard. The record is first written inside a step (`ensureRow()`), so a request that ends earlier leaves nothing. Only `resumeAfterRequest()` takes the per-order `IfirmaNamedLock` (since `0636e80`); the status-hook path takes none. On the local shops, 13 of 112 (PS 8.1) and 5 of 50 (PS 9.1) smoke orders reached the trigger status with no record. Some are deliberate (`smokeReachTriggerQuietly()` turns auto issue off), and the data cannot tell which. `IfirmaBulkRunner` stops on `OUTCOME_RATE_LIMITED` and reports the rest as not attempted.
- **Record states.** `ps_ifirma_receipt.status` already has a non-issued waiting state, `held` (`markHeld()`, a data request is pending), and `IfirmaReceipt::isRetryable()` treats every non-issued, non-held row as retryable. `delivery_status` is `VARCHAR(16)` with `none`, `sent`, `failed` and `skipped`. `IfirmaIssueFeed::issuePredicate()` is the single SQL definition of "needs attention" (notification tab and documents-list preset).
- **KSeF today.** `IfirmaKsefService::submit()` checks only local fields (`isAccepted()`, `ksef_status` `WYSLANA`) and calls `sendToKsef()`; it never reads iFirma's state. Status sync reads `KsefStatus` and `KsefNumer` from the list rows and stores any non-empty value. The pipeline always calls `IfirmaDeliveryService::deliver()` after the `ksef` step; nothing defers a delivery. iFirma's API changelog (2026-02-01) says the KSeF status and number are in the invoice data and in the list. Since April 2026 iFirma can auto-send API invoices at a configured hour and blocks e-mailing invoices that belong in KSeF. No API flag exposes that setting.
- **Orders-list column today.**
  - `hookActionOrderGridDefinitionModifier()` adds an `HtmlColumn` `ifirma_document` with `'sortable' => false`.
  - `hookActionOrderGridQueryBuilderModifier()` left-joins the newest record (alias `ifr`) and selects `ifirma_document_number`, `ifirma_document_status` and `ifirma_document_state` through `IfirmaOrderGridIndicator`.
  - `hookActionOrderGridPresenterModifier()` builds the cell with `IfirmaOrderGridIndicator::cell()`. The tooltip is "iFirma: <number> (Paid|Issued)", or "Issue iFirma document (<state word>)".
  - The hook receives `search_criteria` (`ModuleHooksTest` passes it as null).
  - Core sorting: `OrderQueryBuilder::applySorting()` in PS 8.1.7 and 9.1.4 (read in both containers on 2026-11-09) sorts only by a fixed whitelist (`id_order`, `country_name`, `total_paid_tax_incl`, `reference`, `company`, `payment`, `customer`, `osname`, `date_add`). For any other `orderBy` it adds no ORDER BY at all. A module column can therefore only be sorted by the module's own hook.
  - The iFirma documents list (`AdminIfirmaController`) already sorts by status.
- **Transport.** `IfirmaHttpTransport::request()` returns `status`, `body` and `error` only, with no response headers. `tests/Support/ScriptedTransport` follows the same shape.
- **Mock today (test-stack 1.3.0).** `_register_call()` counts every authenticated call (before the check) in `store.register_call()` and refuses above `settings.rate_limit` per calendar minute (`IFIRMA_MOCK_RATE_LIMIT`, default 0). The refusal is `_error(100, "Przekroczono limit zapytań")` with HTTP 200. `abonent/limit.json` reports the all-time total. KSeF: `ksef_send` sets `WYSLANA`, or `ZAPLANOWANA` for a dated send, and refuses `WYSLANA` and `PRZYJETA`. `/__mock/ksef/accept` and `/__mock/ksef/reject` set `PRZYJETA` with a number, or `ODRZUCONA`. The detail answer has no KSeF fields. Switch pattern: store global, setter with `_changed()`, `_clear_unlocked()`, snapshot and restore, `set_switch()`, JSON route, status field, UI form. The snapshot holds `calls_total`; the minute window is not persisted.
- **Smoke run.** `tests/smoke/run.php` has scenarios A to Q. `freshScenario()` resets the mock and clears `IFIRMA_RATE_WINDOW`, and issuance runs the pipeline in-process. A full run is 258 checks per shop. CI and local runs cover two shops (PS 8.1 and 9.1).
- **Pending changes.** `add-smtp-mock-and-mail-e2e` and `add-guest-data-request-auto-accept-and-logo` are complete but not archived. Their deltas touch `mock-status-page`, `ifirma-api-compliance` and `receipt-duplicate-guard`, but none of the requirements this change modifies.

## Goals / Non-Goals

**Goals:**
- No order in a burst loses its document, and no transient limit becomes `error` or `unknown`.
- The module never exceeds its own caps. After iFirma refuses once, the module stops calling until iFirma's window ends.
- At most one KSeF submission per invoice from all sources (module and iFirma), and no customer delivery of a KSeF invoice before acceptance.
- Every new branch is unit-tested with injected clock, transport and DB doubles; the smoke run proves the burst and the KSeF flow end to end on both shops.

**Non-Goals:**
- An hourly window (iFirma documents none), rolling windows, or a token bucket.
- Detecting iFirma's auto-send setting through the API (no such endpoint).
- Retrying failed deliveries automatically, or delivering rejected KSeF invoices.
- Moving the issuance out of the request after a data-request auto-approval (red-team follow-up from 1.6.0, separate change).
- Changing how the bulk runner batches, or the sync's list-call budget (`IFIRMA_CRON_MAX_CALLS` keeps its meaning).

## Decisions

### D1. The rate guard becomes its own class with one strategy per window
`IfirmaRateGuard` (new) owns admission. It holds an ordered list of `IfirmaRateWindow` value objects: minute (counter key `IFIRMA_RATE_WINDOW`, bucket `YmdHi`, cap `IFIRMA_RATE_MAX_PER_MIN`, default 80, maximum 100) and day (counter key `IFIRMA_RATE_DAY_WINDOW`, bucket `Ymd`, cap `IFIRMA_RATE_MAX_PER_DAY`, default 14000, maximum 15000). Each window keeps today's atomic UPDATE and INSERT logic, unchanged in shape, so the existing guard tests keep proving atomicity. `admit()` checks the back-off first (no write), then the minute window, then the day window, and returns an `IfirmaRateDecision` (`allowed`, `window`, `message`). `IfirmaApiClient` receives the guard through its constructor; the default is built from `Configuration`, `Db` and the client's clock. It keeps `isRateLimited()` and gains `rateLimitWindow()` (`minute`, `day`, `backoff`, `server`).
- *Why:* SRP (the client signs and sends; the guard decides) and OCP (another window is another value object, not another `if`). The minute row and its format stay a stable contract, so upgraded shops keep their counter.
- *Order and its cost:* a request refused by the day window has already used a minute slot. That only makes the guard more conservative, so no compensating decrement (which would be a second write that can race).
- *Alternatives:* a combined `minute|day` value in one row (changes the stored contract, rejected); rolling 60 s and 24 h windows (needs a log of timestamps per request instead of one atomic counter, rejected); an hourly window (the user chose minute and day, as documented by iFirma).
- *Day boundary:* the shop timezone (PrestaShop sets PHP's default timezone). iFirma's day is presumably Polish time. The 1,000-call margin below 15,000 absorbs a mismatch at midnight.

### D2. iFirma's limit answer is recognised and starts a stored back-off
The transport contract gains an optional `headers` key (lower-cased name → value), collected with `CURLOPT_HEADERFUNCTION`; `ScriptedTransport` can script it. `IfirmaApiClient` treats an answer as a server rate limit when the HTTP status is 429, or when `Informacja` matches `/limit\s+zapyta/iu` (with any `Kod`). Then it sets the rate-limited state with window `server`. It does not record an auth cool-down or a `Kod` error, and it stores the back-off end in `IFIRMA_RATE_BACKOFF_UNTIL` (unix seconds). The end is now + `Retry-After` when that is a positive integer, capped at 86,400. Otherwise it is the start of the next calendar minute. `IfirmaRateGuard::admit()` refuses until then, with a message naming the end time.
- *Why the message match:* iFirma documents no limit answer. The mock answers HTTP 429 with `Kod` 100, and a real HTTP 200 with a `Kod` and that phrase is plausible. The phrase is narrow enough that a validation message (for example a field length limit) does not match.
- *Why safe on a create:* a 429 means the request was refused before processing, so the document does not exist. If iFirma ever created it anyway, the queued retry runs the duplicate guard first and adopts it (scenario "Document created by an earlier refused request").
- *Alternative:* sleeping and retrying inside the request (blocks the shop request or the cron for up to a minute, rejected).

### D3. Queue-first: the queue is a record state, `queued`, written before any request
`IfirmaReceipt::STATUS_QUEUED = 'queued'` and `markQueued($message)` (refused when the record is issued) follow the `held` precedent.

**Status hook path (queue-first).** `onOrderStatusChange()` keeps its two silent exits: the status is not the trigger, or credentials are missing (the module is not set up; queuing would invoice old orders the moment credentials are entered). Otherwise:
1. `IfirmaIssueQueue::enqueue(Order)` (new, SRP: it only records) loads or creates the order's record. It leaves an issued or `held` record alone, and marks every other record `queued` with an empty message and saves it. No iFirma request has been made at this point.
2. The hook takes `IfirmaNamedLock::acquire('ifirma_order_<id>', 0)`, the lock name `resumeAfterRequest()` already uses, with no wait, so a status change never blocks on a cron run. If the lock is not granted, the hook returns and the record stays queued.
3. The hook calls `run($order, ['trigger' => 'status'])` and releases the lock in `finally`.

Inside the run, the hold step can still turn the record into `held`, and the guard can adopt.

**Refusals on every path.** The pipeline marks the record queued in two places: in `stopForInconclusive()` when the guard check was rate limited (today no row is written), and in `stepCreate()` when the create was rate limited. For the second, `IfirmaReceiptService::createReceipt()` throws a typed `IfirmaRateLimitedException` instead of calling `unreachable()`. The run's outcome stays `OUTCOME_RATE_LIMITED`, so the bulk runner and its "not attempted" reporting are unchanged.

**A queued record that fails for another reason** is treated like a new record. `stopForInconclusive()` and `fail()` use `$isNew || status === queued` to decide between `markError()` and only updating `last_error`. A network or auth failure therefore leaves the queue as `error`, as a new order would today, and does not loop in the queue.

**In-flight marker.** Right before the create POST, `IfirmaReceiptService` saves the record as `unknown` with the message "iFirma has not answered the create request yet". The answer then overwrites it: issued, `error`, `unknown` with the real error, or `queued` for a 429. A request that dies mid-create leaves `unknown`. The queue job never selects `unknown`, and every later attempt runs the guard first ("No blind retry after unknown outcome"). This also covers shops that turned the duplicate check off: without the marker, a queued retry after a mid-create crash would create a second document.

**Queue order** is `date_upd ASC, id_ifirma_receipt ASC`. Queue-first stamps `date_upd` when the order reached the trigger status. A refusal inside the cron does not save the record, so it keeps its place.
- *Why not a queue table:* the order already has one record row. The admin panel, the Orders-list cell, the filters, the needs-attention predicate and `isRetryable()` all key on that row's state, so a separate table would be a second source of truth joined everywhere. No schema change is needed (`status` is a VARCHAR).
- *Why queue-first and not "queue on refusal" only:* the record then exists before anything can go wrong, so no exit path (refusal, busy lock, timeout, fatal error) can lose the order. The cost is one extra row write per paid order. The alternative "hook only enqueues, cron issues" was rejected: shops without a cron job would stop issuing altogether.
- *Manual paths queue too:* a refused manual Create or bulk retry leaves a queued record rather than nothing. Otherwise the order could be forgotten after the employee closes the page. The employee still sees the refusal message at once.
- *Test whose expectation changes:* `testRequestRefusedLocallyIsAnErrorAndNotUnknown` becomes "…IsQueuedAndNotAnError", in its own commit that cites the existing "Transient error not persisted" scenario.

### D4. One cron request runs four jobs through a runner
`IfirmaCronRunner` (new) holds an ordered list of `IfirmaCronJob` implementations, an interface with one method `run(IfirmaCronContext $context): IfirmaCronJobReport`. The context carries the item cap, the shared client and its rate state, and the clock. The jobs are:
1. `IfirmaIssueQueueJob`: queued records, oldest first, at most `IFIRMA_CRON_MAX_ITEMS`. Each goes through `IfirmaIssuancePipeline::run()` with trigger `queue`, under the per-order `IfirmaNamedLock` that `resumeAfterRequest()` uses.
2. `IfirmaKsefRetryJob`: submitter `module`, delivery `awaiting_ksef`, empty `ksef_status`, oldest first, at most the cap. Each is submitted through `IfirmaKsefService::submit()`.
3. `IfirmaStatusSyncJob`: an adapter around the existing `IfirmaStatusSyncService::runCron()`. Its summary keys stay at the top level of the JSON for backward compatibility.
4. `IfirmaAwaitingDeliveryJob`: delivery `awaiting_ksef` and accepted (`ksef_number <> ''` or `ksef_status = 'PRZYJETA'`), oldest first, at most the cap. Each goes through `IfirmaDeliveryService::deliver()` (not manual).

The runner takes `IfirmaNamedLock::acquire('ifirma_cron', 0)` and answers `{"skipped": "another cron run is busy"}` when the lock is not granted. After a job reports rate limited, the remaining jobs are not started. The JSON gains `queue`, `ksef` and `delivery` blocks (`handled`, `succeeded`, `failed`, `left`), `rate_limited` and `rate_limited_window`. The controller keeps the token check and calls the runner instead of the sync service.
- *Why a runner of jobs:* OCP. A fifth job is a new class in the list, and the controller stays glue.
- *Why the order:* customer-facing issuance first; KSeF submission before sync, so a just-submitted invoice can pick up its state; delivery last, so an acceptance read by the sync is delivered in the same run.
- *Why a separate item cap:* `IFIRMA_CRON_MAX_CALLS` counts list calls of the sync and keeps that meaning. One issuance costs about 4 to 6 calls, and the item cap (default 10, maximum 50) bounds the run time of the three new jobs.

### D5. KSeF: who submits, and a state read before every submission
- `IFIRMA_KSEF_SUBMITTER` (`module` | `ifirma`) is normalised by `IfirmaKsefService::normalizeSubmitter()`, as the mode is.
- `IfirmaKsefService::goesToKsef($receipt, $order)` answers the scope question: mode not `off`, the document is issued, its type is supported, and the buyer is a business or the mode is `all`. It ignores the submitter and the current state, so the pipeline, the delivery gate and the cron jobs share one definition. `skipReason()` uses it, plus the state checks.
- `IfirmaKsefStateReader` (new) reads `GET {type}/{id}.json` through the client and returns `IfirmaKsefRemoteState` (`status`, `number`, `readable`, `error`, `rateLimited`). A missing key reads as an empty string.
- `IfirmaKsefStatus` (new, static and pure) classifies a stored or reported status. Accepted means `PRZYJETA` or a non-empty number. Rejected means `BLAD` or `ODRZUCONA`. Any other non-empty value is in progress. Unknown iFirma values count as in progress, so they neither cause a resubmission nor a delivery.
- `submit($receipt, $order, $force)`: refuse when accepted locally (as today), then read the remote state. An unreadable state means no call, `ksef_status` unchanged, and the result "deferred" with the reason. An in-progress or accepted remote state is stored (status and number) and the result is "already in KSeF". A rejected remote state allows the submission only when `$force` (manual) is set. Otherwise, submit as today. With submitter `ifirma`, the pipeline step skips before any read ("sent by iFirma"). Manual **Send to KSeF** still reads first, whatever the submitter.
- *Why detail and not list:* one call by id. The list needs a date range and paging. The changelog says the detail carries the fields; the mock gains them (`mock-ksef-simulation`).
- *Alternative:* a mode value `ifirma` in `IFIRMA_KSEF_MODE`. Rejected, because the mode is the scope (`b2b`/`all`), and the scope is still needed with iFirma as submitter to know which documents wait for acceptance. Two orthogonal settings avoid a `b2b_ifirma` / `all_ifirma` cross product. This refines the "new KSeF mode" option the user picked, and keeps its effect.

### D6. One delivery gate for pipeline, cron, admin and customer
`IfirmaKsefDeliveryGate` (new) has two methods:
- `mustWait($receipt, $order)`: channel not `none`, `goesToKsef()`, and not accepted.
- `isWaiting($receipt)`: delivery `awaiting_ksef` and not accepted, from local fields only, so the customer page and the download make no API call.

`stepDeliver()` asks `mustWait()`. When it is true, it calls `markAwaitingKsef()` (`delivery_status = 'awaiting_ksef'`, `delivered_at` null) and records the step as skipped "waiting for KSeF acceptance". A manual `resend()` delivers as today; recording `sent` ends the wait, so the cron job no longer selects it. `findDueForSync()` adds `OR delivery_status = 'awaiting_ksef'`. `IfirmaIssueFeed::issuePredicate()` gains three conditions:
- `status = 'queued' AND date_upd < :now-30min`
- `ksef_status IN ('BLAD','ODRZUCONA')` (replaces `= 'BLAD'`)
- `delivery_status = 'awaiting_ksef' AND date_add < :now-48h`

The thresholds are named constants, and `:now` is passed in from the injectable clock, so the SQL holds no `NOW()`.

### D7. Customer side reads the gate, never the API
`IfirmaCustomerView` maps `queued` to the not-issued state. It maps an issued record with `isWaiting()` to the issued state with `sent = false` and no download link. Every customer-facing PDF path (the account download controller and the guest path from 1.6.0) checks `isWaiting()` after the ownership and token checks and before any iFirma call. It answers with the same "not found" response as a wrong token, so a probe cannot tell a held document from a foreign one.

### D8. Mock: limits, counters, KSeF auto-send
- `store.py` gains:
  - an injectable clock `_now` (default `time.time`), so pytest moves time without sleeping
  - the day window (`_day_key` from `time.localtime`, `_calls_day`)
  - `_rejected_total` and `_peak_minute`
  - runtime limits `_limit_minute` and `_limit_day` (`None` = environment value)
  - `_ksef_auto` (`off`, `send`, `accept`)
  - `Document.ksef_send_calls`
  
  `register_call()` returns a small `CallVerdict` (`allowed`, `window`, `limit`, `retry_after`). Every authenticated call still counts in the total, the minute and the day, as today.
- `main.py`:
  - `_authorized()` answers a refusal with `_error(100, "Przekroczono limit zapytań (limit minutowy: N)" | "(limit dzienny: N)", http_status=429, headers={"Retry-After": …})`; `_error` gains a `headers` parameter.
  - New routes `GET`/`POST /__mock/rate-limit`, `POST /__mock/rate-limit/next-window` and `GET`/`POST /__mock/ksef-auto`, plus UI form routes. All of them sit behind `_test_endpoints_disabled()`.
  - The detail answer adds `KsefStatus` and `KsefNumer`. `ksef_send` counts the call on the document and refuses `ZAPLANOWANA` as well.
  - Document creation applies `ksef-auto` to KSeF types whose contractor has a NIP.
  - `abonent/limit.json` reports `_calls_day` and the day limit.
- Config: `IFIRMA_MOCK_RATE_LIMIT_DAY` (default 0). Compose sets `IFIRMA_MOCK_RATE_LIMIT: ${…:-100}`, `IFIRMA_MOCK_RATE_LIMIT_DAY: ${…:-15000}` and `TZ: Europe/Warsaw`. `time.localtime` honours `TZ` when zone data exists in the image; Debian's `tzdata` is priority "required", so slim images carry it. Task 1.5 verifies this in the container instead of adding a pip dependency.
- Snapshot (format version stays 1; `restore()` tolerates missing keys): `calls_day` with `day_key`, `rejected_total`, `limit_minute`, `limit_day`, `ksef_auto`, and `ksef_send_calls` per document. The minute count and the peak stay in memory only.
- The existing test `test_rate_limit_triggers_kod_100` changes deliberately: the HTTP status becomes 429 and `Retry-After` is asserted. The commit explains why.

### D9. Smoke scenarios R and S
- **R** (`SMOKE_ONLY=R`, about 5 to 7 minutes):
  - Setup: `freshScenario()` once, then `IFIRMA_CRON_MAX_ITEMS` = 50 and `IFIRMA_KSEF_MODE` = `off` (KSeF is out of R's scope), delivery through the SMTP sink, and receipts for private buyers (about 4 to 5 calls each).
  - R1: create 50 orders, then move them to the trigger status in a tight loop. Before any cron run, assert that every order has a record (issued or `queued`), at least one is queued, and none is `error` or `unknown`. Then drain: sleep to the next calendar minute plus 1 s, run `IfirmaCronRunner` in-process, and repeat until nothing is queued or 8 minutes have passed. One drain goes through the HTTP cron endpoint, to cover the controller wiring. Assert: 50 issued records; 50 mock documents with distinct order stamps; mock `rejected_total` 0 and `peak_minute` at most 80; 50 delivery mails.
  - R2: mock per-minute limit 30, then 20 orders. Assert that the mock refused at most one call, the orders are queued, and nothing is `error` or `unknown`. Then restore 100, call next-window minute, clear `IFIRMA_RATE_BACKOFF_UNTIL`, drain, and assert 20 documents, one each.
  - R3: mock per-day limit = today + 15, then 10 orders. Assert at most one refusal, the orders queued, and no mock call during one more cron run. Then call next-window day, restore 15000, clear the back-off, drain, and assert.
  - R4: module `IFIRMA_RATE_MAX_PER_DAY` = its day count + 10, then 10 orders. Assert zero mock refusals and the orders queued. Then clear `IFIRMA_RATE_DAY_WINDOW`, drain, and assert.
  - A `finally` block restores every limit, cap, counter and setting.
- **S** (`SMOKE_ONLY=S`, under a minute): B2B buyer with a NIP, mode `b2b`, cron in-process. Sub-cases S1 to S5 as in `specs/ksef-delivery-e2e-verification`. The mock's `ksef_send_calls` and the SMTP sink are the evidence; the download check calls the download controller over HTTP with the owner's cookie (the pattern of scenario O).
- **Existing scenarios:** D (cron JSON gains keys; keep the old assertions and add one for the blocks), F (the KSeF step now makes an extra detail call; a B2B invoice now waits, so F asserts `awaiting_ksef` and then delivery after `/__mock/ksef/accept` plus cron), N (if it delivers a B2B invoice in mode `b2b`, it switches to a receipt or to mode `off`, with a comment explaining why). Every other scenario must pass unchanged. The mock's default 100-per-minute limit can only bite a scenario that makes more than 80 calls inside one minute; `freshScenario()` resets the mock's minute count together with the module's.

### D10. Settings, upgrade, version
- `IfirmaSettings::fields()` gains `IFIRMA_RATE_MAX_PER_DAY` (range 1 to 15000), `IFIRMA_CRON_MAX_ITEMS` (range 1 to 50) and `IFIRMA_KSEF_SUBMITTER` (normalised).
- `IfirmaSchema::configurationDefaults()` gains those three plus the internal counters `IFIRMA_RATE_DAY_WINDOW` and `IFIRMA_RATE_BACKOFF_UNTIL` (both `''`), which `ConfigurationKeysTest` installs and removes.
- `upgrade/upgrade-1.7.0.php` adds missing keys with their defaults (the 1.6.0 pattern) and changes no row.
- The version string becomes 1.7.0 in the same places the 1.6.0 change set it (feature commit `ced5a4a`). The release bot handles `config.xml` and `package.json` on release.
- Settings golden fixture: updated in the same commit as the fields, as a deliberate behaviour change.

### D11. Orders list: tooltip words and sorting by state
- **Data.** `IfirmaOrderGridIndicator::selectSql()` adds, from the joined newest row `ifr`: `document_type`, `delivery_status`, `DATE(delivered_at)`, `ksef_status`, `ksef_number`, `LEFT(last_error, 101)` (one character more than shown, so the builder knows when to add the ellipsis), and the rank `ifirma_document_rank`. Every expression reads the one joined row, so the list still costs one indexed lookup per order and no iFirma call. The existing three aliases keep their meaning.
- **Rank.** A `CASE` expression on `ifr`. Each state number is a named constant in the indicator, and both the SQL and the tests read the constants:
  - 1: `error`
  - 2: `unknown`
  - 3: `duplicate_suspected`
  - 4: `held`
  - 5: `queued`
  - 6: no row
  - 7: issued with delivery not `sent` and not `awaiting_ksef`
  - 8: `awaiting_ksef`
  - 9: delivery `sent` and not paid
  - 10: `paid`
  
  Issued means the same rule as `isIssued()`. An issued `paid` row wins over its delivery state.
- **Sorting.** The definition hook sets `'sortable' => true`. The query-builder hook reads `$params['search_criteria']`. When `getOrderBy()` is `ifirma_document`, it maps `getOrderWay()` through a whitelist (`desc` stays `DESC`, anything else becomes `ASC`), then calls `orderBy('ifirma_document_rank', $way)` and `addOrderBy('o.id_order', 'DESC')`. The count query is untouched, so paging and totals cannot change. The core adds no ORDER BY for this field (Context), so the hook's sort is the only one, and no core order is overwritten.
  - *Alternative:* ORDER BY on the alias `ifirma_document` itself. Rejected: the presenter overwrites that key with HTML, so a separate rank alias keeps the meanings apart.
- **Words.** `IfirmaOrderGridTooltip` (new; SRP: the indicator keeps the SQL and the cell markup, the tooltip class only composes lines) takes a grid record and a word table, and returns the lines.
  - The headline is "<kind> <state>: <number>". The kind is invoice for every `faktura*` type (including `fakturaparagon`) and receipt for the `paragon` types. The state is paid, else sent, else waiting for KSeF, else issued.
  - Detail lines: delivery (sent to the customer: date, not sent yet, or delivery failed), then "unpaid" when not paid, then a KSeF line classified by `IfirmaKsefStatus`: accepted with the number, in progress, waiting for iFirma (submitter `ifirma` and no state), error, or rejected.
  - For a not-issued record: the state words plus " – click to issue", and the shortened error for `error`.
  - The words come from `ifirma.php::gridCellLabels()`, which grows into separate invoice and receipt entries so Polish can agree in gender ("wysłana" or "wysłany"). They go through `$this->l()` and `decodeWords()`, so `pl.php` translates them as usual. The lines are joined with `"\n"` and escaped with `htmlspecialchars` into the `title` attribute. Browsers render a newline in `title` as a line break, so no script is needed.
- *Why sort in SQL, not in the presenter:* the presenter sees one page only, so sorting there would order each page separately.

### D12. Work split for the apply phase
The groups in tasks.md have disjoint file ownership, so Sonnet workers can run them in parallel and the manager verifies and commits each one:
- mock (test-stack only)
- rate guard and client (`IfirmaRateGuard*`, `IfirmaApiClient`, `IfirmaHttpTransport`, their tests)
- queue and cron (`IfirmaIssueQueue`, `IfirmaIssuancePipeline`, `IfirmaReceiptService`, `IfirmaCronRunner`, the jobs, `cron.php`, `IfirmaBulkRunner`)
- KSeF and delivery (`IfirmaKsef*`, `IfirmaKsefDeliveryGate`, `IfirmaDeliveryService`, `IfirmaStatusSyncService` selection)
- back office and customer (`IfirmaAdminPanelPresenter`, `IfirmaOrderGridIndicator`, `IfirmaOrderGridTooltip`, the Orders-grid hooks in `ifirma.php`, `IfirmaCustomerView`, `IfirmaIssueFeed`, the download controllers, templates, translations, `AdminIfirmaController` labels, `IfirmaSettings`, `IfirmaSchema`, the upgrade script)
- smoke
- docs

The names fixed in D1 to D6 are the interface contract between groups. The queue group depends on `IfirmaRateLimitedException` and `rateLimitWindow()`. The cron group depends on `goesToKsef()`, `IfirmaKsefDeliveryGate` and `submit()`'s deferred result. Stubs are agreed first.

## Risks / Trade-offs

- [iFirma's real limit answer differs from both the 429 and the "limit zapytań" phrase] → It would be treated as before (an error or unknown outcome), so it is no worse than today. The troubleshooting page asks merchants to report the exact answer, and the detection is one function with a unit test per shape.
- [The real detail endpoint lacks `KsefStatus`] → A missing key reads as empty, so with submitter `module` the module submits as it does today. Merchants with iFirma auto-send must choose submitter `ifirma`; configuration.md says so. A follow-up can fall back to a list call.
- [Delivery waits on iFirma's scheduled hour, possibly into the next day] → This is the behaviour the user chose. The order panel shows the wait, the 48-hour alert catches a stuck wait, and manual **Send again** remains.
- [A shop without a cron job never drains the queue] → The 30-minute stale-queue alert puts it on the notification tab. The settings page's existing cron section names the endpoint, and usage.md recommends running it every 1 to 5 minutes. Before this change, the same orders were silently never issued.
- [The day window uses the shop timezone while iFirma uses its own] → The cap is 14,000, 1,000 below the limit. iFirma's 429 path covers the rest.
- [Two cron runs or a cron run and a status hook race on one order] → The run lock plus the per-order lock, now taken by the status hook too, without waiting, plus the pipeline's issued check under the lock (the existing resume pattern).
- [Every paid order is briefly `queued`, and briefly `unknown` while its create is in flight] → Both states last for one request. The customer block shows `queued` as not issued; a half-second `unknown` renders the neutral "verifying" wording. The 30-minute alert ignores fresh queue entries. A record stuck in `unknown` because the request died is exactly the case the existing unknown-outcome rules exist for.
- [Orders that reach a paid status other than the single trigger status are still never tried] → Out of scope here; the queue only guarantees that every trigger-status order is recorded. A shop whose payment modules end in different paid statuses needs the trigger setting to name its status. This is a candidate follow-up: several trigger statuses.
- [Smoke R is long (5 to 7 minutes) and timing-sensitive] → R sleeps to calendar-minute boundaries instead of fixed delays, has an 8-minute deadline, restores state in `finally`, and runs alone with `SMOKE_ONLY=R`. The full run's documented duration grows accordingly.
- [The mock's default limits change behaviour for the other scenarios] → The module's cap (80) is below the mock's (100), and both reset per scenario. Task 8.1 runs the full suite on both shops before R and S are added, to prove it.
- [The customer download refusal looks like a broken link] → The block shows "not yet sent" and no link. Only a stale link from an earlier page load hits the "not found".

## Migration Plan

1. Archive the two completed changes (`add-smtp-mock-and-mail-e2e`, `add-guest-data-request-auto-accept-and-logo`) before this one is archived, so the main specs are current when its deltas apply. None of their requirements overlap this change's.
2. Mock first: commit in test-stack, push after the user's go-ahead, and bump the submodule pointer in the module.
3. Module 1.7.0. The upgrade adds the five configuration keys. Existing rows are untouched: nothing becomes `queued` or `awaiting_ksef` retroactively, and a document issued before the upgrade is never held.
4. Rollback: downgrading the module leaves the extra configuration keys (harmless). Rows in `queued` or `awaiting_ksef` would be unknown to 1.6.0. Before a downgrade, retry or deliver them (bulk **Retry issuance**, **Send again**). The red-team report and upgrading.md state this.
5. Release gates (CLAUDE.md): full unit suite, diff-cover, smoke on both shops, `npm run verify:package`, red-team pass on the dimensions touched (abuse and availability: cron, queue and limits; auth: cron token and download gate; data exposure: download of held documents; dependencies: no new runtime dependency), `graphify update .`.

## Open Questions

- The exact HTTP status and body of iFirma's limit answer, and the full vocabulary of `KsefStatus` values. Both are answered by iFirma support or a production log. The detection (D2) and the classification (D5) each live in one function, so a confirmed answer changes a constant and a test, not the design.
