# Proposal

## Why

Four gaps were found on module 1.9.1 (master `e9ce900`).

1. **Request counting cannot be seen or trusted.** Every iFirma request, status checks and PDF downloads included, already passes one counting gate (`IfirmaApiClient::execute`). But no test proves that a status check or a PDF download raises the counters, the spec never says which requests count, and the shop shows no usage of its own. A refusal by the limit is also reported badly: the admin **Refresh from iFirma** answers "Status refreshed" although nothing was read, and a customer whose PDF download was refused gets a generic 502.
2. **A failed document e-mail is never sent again.** The record becomes `failed` and waits for an employee to click **Send again**. A mail server that is down for ten minutes leaves every document of that period undelivered.
3. **The iFirma state can be sorted but not filtered in the Orders list, and the Documents list sorts it alphabetically.** An employee cannot list "only failed" in the Orders list, and the Documents list puts `created` before `error`.
4. **Two GitHub workflows are red on master.**
   - *Security* fails in the step "Pinned container images". Cause, reproduced locally: the `test-stack` commit that the module pins (`9834c14`) still has `image: prestashop/prestashop:latest` in `docker-compose.yml` line 67. The fix (`${PS_IMAGE_TAG:-9.1.4-apache}`) exists only as an uncommitted edit in the local submodule.
   - *Smoke* fails in the step "Smoke scenarios" in all five matrix jobs. Because of the same line the matrix tag is ignored and all five jobs ran the same `latest` image. `run.php` ended with exit code 1, which means at least one assertion failed after a successful bootstrap. Which assertion is not known yet: the job log needs a GitHub login and the workflow keeps no copy of the smoke output.

## What Changes

- **Every request kind counts, and tests prove it.** The spec states that each request the module sends counts toward the minute and day limits whatever its kind. New unit tests prove it for the status list, the document detail and the PDF download, and a smoke check proves it against the mock.
- **Usage per kind.** The module counts the requests it sent today per kind: document, status, PDF, e-mail, KSeF, payment, contractor, limit, other. The configuration page shows the module's own use of the current minute and day against its caps, and today's count per kind. It needs no `abonent` key and sends no request. The cron JSON summary gains a `usage` block.
- **Button "Check the remaining iFirma limit" on the configuration page.** iFirma's `abonent/limit.json` endpoint, which the module already reads in the cron, is asked on the employee's click. The page then shows the requests used, granted and remaining today for the whole iFirma account, with the time of the reading. Opening the page no longer asks iFirma by itself (today every page view costs one request); it shows the last reading and its age. The click counts as one request of kind `limit` and updates the module's day counter like the cron reading does. Without the `abonent` key the button is replaced by a hint.
- **Honest refusal feedback.**
  - **Refresh from iFirma** refused by the limit answers "not refreshed" and names the window (minute, day or back-off). The stored state is kept and no sync error is recorded.
  - The customer's order page, when its background sync is refused, shows the last known state and records nothing.
  - A PDF download refused by the limit is answered at once with HTTP 429 and `Retry-After`, for the customer and for the employee. The module sends no request, does not wait and does not retry the download in that request.
- **Automatic retry of a failed delivery.** The cron retries a delivery that failed for a temporary reason. A document gets at most three automatic attempts: the first one, a second after 15 minutes and a third after 2 hours. After the third failure, or after a permanent failure (the customer has no valid e-mail address, iFirma refuses the document), nothing more is sent automatically and the record needs attention as today. A failure whose outcome is unknown (iFirma's own mail call timed out after the request was sent) is not retried, so no customer is e-mailed twice. A refusal by the limit sends nothing and uses no attempt.
- **Orders list: filter by iFirma state.** The "iFirma" column gains a select filter with the ten states of the existing sort order. The filter narrows the list and the order count together.
- **Documents list: state sorts by rank.** Sorting the *State* column of *Orders > iFirma documents* uses the same order as the Orders list (what needs action first), not the alphabet.
- **CI: Security green again.** The image pin is committed in the `test-stack` repository and the module pins that commit. The remaining steps of the Trivy job, which never ran on master, are run and their findings handled.
- **CI: Smoke made diagnosable, then fixed at the cause.** The workflow keeps the smoke output (job summary and artifact), checks that the shop runs the version the matrix asked for, waits for the finished install, and also runs scenario A2, which the letter filter dropped silently. Each failing assertion is then fixed where the defect is: in the module, the stack or the workflow. **No test or scenario is deleted, skipped or weakened to get a green run.**
- **Security fix: the configuration page checks the Configure permission itself (added during apply, on the owner's request).** On PrestaShop 9.1 the core opens a module's configuration page for an employee with any Module Manager right and does not check the module's own Configure permission; the module did not check it either, and ran its actions for any request that named them. The page now refuses an employee without the permission, and every state-changing action needs POST and a valid back-office token. It ships as its own `fix-vuln` commit.
- **Security fixes: two red-team follow-ups in older code (added on the owner's request on 2026-11-11).**
  - The minute cap holds for every 60 seconds. It was counted per calendar minute, so a burst around the turn of a minute could send twice the cap within seconds, exceed iFirma's 100 per minute and start a back-off that stops issuance.
  - The customer's background sync on the order page costs one list call per customer and issue date per interval and has its own request budget. It read every page of the issue date for every stale order, with no cap per customer, so page views could use up the limit that issuance needs.
- **BREAKING (behaviour, no API change):** the configuration page shows the last limit reading instead of a fresh one until the button is clicked; a failed delivery may now be sent later by the cron without an employee; a PDF download refused by the limit answers 429 instead of 502; a refused **Refresh** reports a failure instead of success; after a full minute's worth of requests the module waits until those requests are 60 seconds old, not until the next calendar minute; on a day with more than 100 documents the customer's order page no longer refreshes a document beyond the first list page (the cron does).

Assumptions recorded for review:

- "Refusal for download without retry" is read as: a download refused by the limit is refused at once and is not retried inside that request. It does not switch off the delivery retry of the cron.
- The retry covers every document kind the module delivers (receipts and invoices), not invoices only.
- Deliveries that already failed before the upgrade are not retried automatically, so the upgrade sends no old mail.

## Capabilities

### New Capabilities

- `api-usage-accounting`: counting the module's sent iFirma requests per kind and per day, and showing the module's own minute, day and per-kind usage on the configuration page and in the cron summary.
- `smoke-matrix-verification`: the scheduled end-to-end smoke run over every supported PrestaShop version: the version under test, readiness, the scenarios run, and the evidence kept when a run fails.

### Modified Capabilities

- `ifirma-api-compliance`: the minute cap holds for every 60 seconds instead of per calendar minute; every request kind counts toward the limits; a request refused by the limit is reported as refused and never retried in place; the limit on the configuration page is read on the employee's click instead of on every page view.
- `receipt-delivery`: automatic cron retry of a failed delivery with a fixed schedule and an attempt cap; the "no automatic retry" scenario of deliveries after KSeF acceptance is replaced.
- `receipt-status-sync`: the customer's background sync makes one call per customer and issue date per interval and has its own request budget; the admin refresh and the customer's background sync report a limit refusal truthfully; the cron runs a delivery-retry job and reports usage.
- `customer-receipt-visibility`: a download refused by the request limit answers 429 with `Retry-After`.
- `order-list-receipt-indicator`: the column is filterable by state.
- `receipt-documents-list`: the state column sorts by the action rank; the row shows the retry schedule of a failed delivery.
- `email-e2e-verification`: the smoke run proves the automatic retry after a rejected mail.
- `customer-contractor-panel`: the document count from iFirma takes today and the registration day in the shop's time zone (it took the UTC day, so the smoke run failed in the hour after the shop's midnight and a real count missed that hour's documents).
- `receipt-access-control`: the configuration page enforces the module's Configure permission itself, and its state-changing actions need POST and a valid token.

## Impact

- **Depends on** `add-rate-limit-queue-and-ksef-confirmed-delivery` being archived first. Its requirements (cron jobs, KSeF delivery, column behaviour) are modified here and are not in `openspec/specs/` yet. That change has five open tasks (8.1, 8.4, 8.5, 9.3, 9.4).
- **Module code:** `IfirmaApiClient` (reports the kind of a sent request), new `IfirmaRequestKind` and `IfirmaApiUsage`, a new usage presenter for the configuration page, a "check limit" form action on the configuration page handled through `IfirmaRemoteLimit`, `IfirmaCronRunner` (usage block, new job), new `IfirmaDeliveryRetryJob` and `IfirmaDeliveryRetryPolicy`, `IfirmaDeliveryService` and `IfirmaReceipt` (attempt count, next retry time, failure class), `IfirmaReceiptActions` and `IfirmaStatusSyncService` (refusal result), `controllers/front/receipt.php` and `AdminIfirmaController` (429 answer, state sort), `IfirmaHttpOutput`, `IfirmaOrderGridIndicator` plus a shared state-rank class, the Orders-grid hooks in `ifirma.php` (filter, count query), `IfirmaOrderGridTooltip`, `IfirmaSchema`, a new upgrade script, templates and translations.
- **Database:** three new columns on `ps_ifirma_receipt`: `delivery_attempts`, `delivery_retry_at` and `sync_attempt_at`. New configuration keys for the customer page sync budget (`IFIRMA_SYNC_CUSTOMER_MINUTE`, `IFIRMA_SYNC_CUSTOMER_DAY`). The row `IFIRMA_RATE_WINDOW` holds the count per second of the last 60 seconds; a value of an earlier version is still read. New configuration keys for the per-kind day counters (`IFIRMA_USAGE_*`). No new setting on the configuration form.
- **Tests:** new and updated unit tests in `tests/Unit`, the settings golden fixture and `ConfigurationKeysTest`, `tests/smoke/order-grid-check.php` (filter), smoke scenarios N (retry) and D (usage block, refused refresh).
- **test-stack (submodule, separate repository):** commit of the image pin in `docker-compose.yml` and `README.md`; the module pins the new commit.
- **Workflows:** `.github/workflows/smoke.yml` (evidence, readiness, version check, A2). `.github/workflows/security.yml` only if a Trivy finding requires it.
- **Docs:** `docs/configuration.md`, `docs/usage.md`, `docs/troubleshooting.md`, `docs/development.md`, `docs/upgrading.md`, `tests/smoke/README.md`.
- **Release:** a minor version produced by the Release workflow (expected 1.10.0), never bumped by hand. It adds an upgrade script, changes an endpoint answer and adds SQL, so a red-team pass runs before release.
