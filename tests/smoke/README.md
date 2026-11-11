# Module smoke run

`run.php` exercises the iFirma module end to end inside the PrestaShop container,
against the iFirma mock. It uses the real PrestaShop classes (orders, carts, order
history, hooks, mail) and the real module code; only the iFirma API is mocked.

## What it covers

Each scenario resets the mock (`POST /__mock/reset`) and creates a fresh order.

### What a scenario may assume at its start (isolation)

The scenarios share one shop database and run in one PHP process, so each of them starts from the same defined state
and leaves nothing behind that a later one could trip over:

- **The mock is fresh.** `freshScenario()` and `mockReset()` reset it. A reset restarts the document numbering, so the
  first document of every scenario has the id `1` and the number `1/<year>`. A record of an earlier scenario with the
  same id would be a different document from now on (iFirma never reuses an id; only the mock does).
- **No pending automation is left over.** `mockReset()` calls `smokeClearCronWork()`: documents still waiting for KSeF
  are marked not waiting, queued records are closed as errors, and the process's `ObjectModel` cache of module records is
  emptied. Without the last step a later cron run loads a leftover through that cache and saves it back as waiting for
  KSeF again (the statements above bypass `ObjectModel`), accepts it through the colliding id and delivers it a second
  time.
- **The rate counters are real zeros.** The module raises the guard's windows and the usage counters
  (`IFIRMA_RATE_WINDOW`, `IFIRMA_RATE_DAY_WINDOW`, `IFIRMA_RATE_BACKOFF_UNTIL`, `IFIRMA_USAGE_<KIND>`) with raw SQL, which
  this process's `Configuration` cache never sees. `Configuration::updateValue('IFIRMA_RATE_WINDOW', '')` is then a
  no-op when the cached value already is `''`, and the counter keeps counting towards the cap of 80 requests a minute.
  A scenario therefore never writes those keys through `Configuration`: `smokeSetting()`, `smokeRestoreSetting()` and
  `smokeClearRateWindow()` write the row in the database and reload the cache (`smokeWriteSetting()`), and
  `smokeReadSetting()` reads a counter row from the database. `freshScenario()` clears the minute window.
- **A mail is told from other mails by its document.** The sink also holds the shop's own order mails and, after a reset,
  mails of other documents with the same number. `deliveryMailCountFor($order)` counts a delivery mail only when the
  subject has the document number and the body the order reference.
- **A scenario restores what it changes** (settings, throw-away employees and profiles, `is_guest`, the mock switches) in
  a `finally` block, and an order it needs again is created by the scenario itself.

| | Scenario | Asserts |
|--|----------|---------|
| A | Duplicate guard: suspected, then link | a document of the buyer (its contractor identifier, the order's sale date and product lines, the order's gross) and a same-amount document of another buyer are seeded; pipeline outcome `suspected`, receipt `duplicate_suspected`, only the buyer's document recorded as a candidate (with its sale date), nothing created in iFirma; `linkCandidate` makes the receipt `external`, issued, with the seeded id |
| A2 | Duplicate guard: same-amount document of another buyer does not block | only a same-amount document of another buyer is seeded (the unfiltered window query returns it); outcome `issued`, no candidate recorded, a new document created |
| B | Duplicate guard: adopt by order reference | external document under another contractor identifier but the buyer's name whose `Uwagi` holds the order reference; outcome `adopted`, no document created |
| C | Data request: hold, approve, issue with override | company request submitted; moving the order to "Payment accepted" is held (no document); `approveRequest` issues immediately; payload has the override company, NIP and order reference; delivery `sent` (a real SMTP transaction with the mail sink); reference visible in the iFirma detail. Mails read from the sink: the merchant notification at submission (to `IFIRMA_NOTIFY_EMAIL` or the shop e-mail, order reference in the subject, `Smoke Sp. z o.o.` in the text), the approval mail to the customer (subject "Your invoice data change was approved", order reference in the text) next to the delivery mail, and, for a second request on a new order, the rejection mail (subject "Your invoice data change was rejected", order reference and `Smoke rejection reason` in the text); KSeF is switched off while C runs, so the company invoice is delivered at once instead of waiting for KSeF |
| D | Status sync and cron | `updateStatus` + `refreshStatus` give `paid` and `remote_paid_amount`; cron token check; deleting the document in the mock and `runCron()` set `remote_missing`; the real cron URL answers 403 (wrong token) and 200 + JSON (right token); the answer also carries the `queue`, `ksef` and `delivery` blocks and `rate_limited` next to the old keys |
| D | Usage counts and refusals at the minute cap | a second section of D, on a freshly reset mock and zeroed usage counters (`IFIRMA_USAGE_*` and the minute window): two documents of the demo customer are seeded in the mock and given module records; one employee status refresh and the owner's PDF download over HTTP make the module count 1 request of kind `status` and 1 of kind `pdf` and nothing else, and the mock's `calls_total` is exactly 2 (it is 0 before). The minute cap `IFIRMA_RATE_MAX_PER_MIN` is then set to the 2 requests already sent this minute: the owner's download of the second document answers 429 with `Retry-After: 60` and the body "Too many requests at the moment. Please try again shortly." (when the HTTP login fails the check is skipped with a note and the refusal is asserted through `IfirmaReceiptService::fetchPdf()` throwing `IfirmaRateLimitedException`, which is asserted in either case), the employee's refresh answers a failure whose text starts "The status was not refreshed:", a cron run at the cap carries the `usage` block with all nine kinds, and after all of it the mock still counts 2 calls and the module still counts 2 requests (a refused request is not counted). The scenario waits for the next minute when it starts in the last 25 seconds of one, so all counts fall into one window of the guard; it restores the cap and clears the window. Spec: `email-e2e-verification` "Usage and refusal checks in the smoke run". A further section, "Customer download budget" (fresh scenario, one seeded document): the counter row of the shared customer budget (`IFIRMA_DL_CUSTOMER_MINUTE`, then `IFIRMA_DL_CUSTOMER_DAY`) is set straight to its cap for the current minute or day; the owner's download over HTTP then answers 429 with `Retry-After: 60` and the generic body, the mock's `calls_total` and the counter row stay unchanged, `IfirmaDownloadBudget::forCustomers()->take()` refuses and spends nothing, and the module's own status refresh still succeeds with exactly one new request; with the rows cleared the download works again and takes one unit (the HTTP parts are skipped with a note when the login fails). Specs: `customer-receipt-visibility` "Customer downloads have their own request budget". A last section, "Customer page sync" (fresh scenario, three orders of the demo customer, each with a seeded document and made stale by SQL): a third order of the customer has another issue date. The owner's order page, opened in-process as that customer, sends exactly one list call to the mock and refreshes both records of the same date; the second order page sends no call; the third order page, of another issue date and inside the interval, sends no call and leaves its record unchanged; with the counter row of the customer page sync budget (`IFIRMA_SYNC_CUSTOMER_MINUTE`) written full for the current minute, a stale order page sends no call, leaves the record's last sync time and attempt time unchanged and spends nothing of the budget. Specs: `receipt-status-sync` "Opportunistic customer sync" and "Customer page syncs have their own request budget" |
| E | Customer visibility | the owner's order page contains the document number; another customer gets an empty string |
| F | KSeF submission for a B2B order | a B2B order (NIP on the invoice address, KSeF mode b2b) gets a `ksef` pipeline step; skipped when the mock lacks the KSeF endpoint; the step now reads the document's KSeF state first (mock 1.4.0 detail answer) and the invoice then waits for KSeF acceptance instead of being delivered (scenario S covers the delivery) |
| G | PDF not ready, delivery retries | the mock answers Kod 404 for the first 2 PDF downloads; the module retries (waits of 3 s and 7 s, so the scenario takes about 10 s); receipt issued, `delivery_status` `sent`, mock counter back to 0 |
| H | Contractor renamed by iFirma | mock in `rename` contractor mode, customer without a contractor link; the document carries the `IFI...` identifier iFirma assigned, `_contractor.action` is `created` with the same `assigned_identifier`, the contractor map holds it with `matched_by` `module`, and the admin order panel shows it |
| I | Customer panel: search, link, issue, count, unlink | a mock contractor `KOWALSKI-01` carries the customer's name and phone; `customerSearch` lists it with the phone and name flags; `customerLink` writes a map row with `matched_by` `manual`; a document issued for the customer's order references `KOWALSKI-01`; `customerCountRemote` reports a total of at least 1 and a complete count; `customerUnlink` leaves no map row |
| J | Bulk runner: retry, resend, refresh | three orders with an `error`, an `unknown` and an issued record whose delivery failed; `IfirmaBulkRunner` kind `retry` on the two failed ids ends `issued` or `adopted` and moves `IFIRMA_RATE_WINDOW`, kind `resend` on the delivery-failed one ends `sent`, kind `refresh` on the three issued ids ends `refreshed`. A second bulk `resend` of the document right after the delivery reports it `skipped` with the "sent a moment ago" text, does not stop the run and leaves `delivered_at` unchanged. Spec: `receipt-delivery` "A manual resend right after a delivery is refused". Then the kind `mark_sent` over two failed deliveries, one already recorded as sent and one record that is not issued: the failed ones end `sent` through the channel `manual`, the other two are skipped with their reason (2 marked, 2 skipped), no message reaches the sink and no request the mock; with the per-minute counter full a selection is still marked completely and the run is not stopped |
| K | Issue feed: latest, unseen count, mark seen | a failed and a delivery-failed record are listed by `IfirmaIssueFeed::latest()` with the right kind, `unseenCount('')` counts them, `markSeen()` then `unseenCount(seenAt())` is 0 (the employee's seen time is restored) |
| L | Permissions through the admin controller | `AdminIfirmaController` with a throw-away employee of a profile without roles: `CreateReceipt` and `ResendDocument` answer the 403 failure envelope, `DownloadPdf` a 403 text; after READ + CREATE are granted `CreateReceipt` is dispatched and `ResendDocument` stays 403 (profile and employee are deleted at the end). Also: view + customer-edit without the issue level makes `CustomerRegister` answer 403 with the issue-permission text and the mock receives no call; a state-changing action (`CreateReceipt`) sent with GET answers 405 "This action must be sent with POST." and creates nothing, while the read-only `IssueFeed` still answers GET; with the employee budget counter (`IFIRMA_DL_EMPLOYEE_MINUTE`, `IFIRMA_DL_EMPLOYEE_DAY`) at its cap the `DownloadPdf` action answers 429 with a Retry-After between 1 and 60 and the back-office text without a mock call, and works once the counters are cleared. Specs: `receipt-access-control` "Employee PDF downloads have their own request budget", "State-changing back-office actions need POST", "Registering a customer's contractor needs the issue level"; `MarkSent` without the manage level answers 403 and leaves the delivery state unchanged, with it is dispatched, and sent with GET answers 405 |
| M | Customer download throttle | `IfirmaDownloadThrottle`: the second download of a document within 30 s is refused, allowed again after the window; ten stamped documents of one customer refuse an eleventh until a minute has passed. Also end to end for the demo customer: with one document at 30 downloads today (`download_day`, `download_count`) the owner's download of another document answers 429 / `Retry-After: 60` / the generic body, with no mock call, no throttle stamp and no change of the shared minute counter; another customer id is allowed (asserted through `IfirmaDownloadDayCap`, the scenario has one customer with a known password); with the count dated yesterday the download works and is counted once. Spec: `customer-receipt-visibility` "A customer's downloads are capped per day" |
| N | E-mail delivery through the SMTP sink | copy mode `none`: exactly one message to the customer, from the shop e-mail, no `bcc` and one envelope recipient, document number in the subject and in the text and HTML parts, order reference in both parts, one inline logo part, the shop link in the HTML part, one attachment `<shop-domain>-<number>.pdf` (`IfirmaShopLink::pdfFileName()`) of type `application/pdf` that starts with `%PDF-` and has the listed size; copy mode `shop` + `resend`: a second message with the shop e-mail as `bcc` (envelope recipient, not in `To`) and `delivered_at` renewed; shop e-mail equal to the customer's (upper case): one envelope recipient, no `bcc`; reject switch on: a second order is issued, `delivery_status` `failed` with a non-empty `delivery_error` and no message captured; switch off + `resend`: `sent`, error cleared, one new message. Then the automatic retry, end to end (the due time is moved in the database, nothing waits): with the sink refusing, an order is issued and the record shows delivery `failed` with one attempt used and the second attempt due in about 15 minutes; with the sink accepting, a cron run before the due time handles no retry, sends no e-mail and leaves the attempt count and the due time; after the due time is moved into the past one cron run (the `delivery_retry` block: handled 1, succeeded 1, failed 0) sends exactly one e-mail with the PDF attached and the delivery is `sent` with no due time left. Three refusals: after each due time moved into the past and a cron run the record is `failed` with two, then three attempts, the third attempt having been due about two hours after the second; it ends with no due time, the sink holds no e-mail of the document and a further cron run attempts nothing and leaves three attempts. Manual resend ends the retries: a failed delivery whose retry is already due, the sink accepting, the employee's resend, then a cron run: no retry handled, exactly one e-mail in the sink. The mails of one document are told apart by the document number in the subject and the order reference in the body (`deliveryMailCountFor()`). Restores `IFIRMA_SEND_COPY_MODE` and `PS_SHOP_EMAIL`. Run alone with `SMOKE_ONLY=N`. Spec: `email-e2e-verification` "Automatic retry verified end to end". Manual resends that follow a delivery by less than 30 seconds first move `delivered_at` 31 seconds into the past in the database (`smokeAgeDelivery()`), as an employee who waited would; a resend right after a delivery is refused with "This document was sent a moment ago. Wait before sending it again.", the sink still holds the same e-mails and the record is unchanged, and after the delivery is aged the resend sends exactly one more e-mail and renews `delivered_at`. Spec: `receipt-delivery` "A manual resend right after a delivery is refused" and "Later resend". Then "Mark as sent": a failed delivery marked through `markSent` becomes `sent` with channel `manual`, a delivery time, no error and no retry time, with no new message and no new mock request, a later cron run sends nothing, and a second mark is refused as already recorded as sent |
| O | Guest one-time code: identify, verify, safe change approved automatically | mode `safe`, template `logo`, customer `is_guest = 1`, trigger status in the history without a document; over HTTP (127.0.0.1 with the shop domain as `Host`, cookie jar): wrong e-mail gives the neutral message and an empty sink, the right e-mail one code mail (six digits read from the text part), a wrong code the generic error, the right code the data form, a postcode-only submission the confirmation; then the request is `approved` with `auto_mode` `safe` and `resolved_by` 0, the customer approval mail and the merchant mail with "Approved automatically (mode: safe)." are in the sink, the issuance resumed and the mock's document carries the new `KodPocztowy` and `NazwaSzablonu` `logo`. Restores `is_guest`, `IFIRMA_DATA_REQUEST_AUTO_ACCEPT`, `IFIRMA_DOC_TEMPLATE`. Run alone with `SMOKE_ONLY=O` |
| P | Auto-accept keeps identity changes pending | mode `safe` and a NIP change through `IfirmaDataRequestService::submit()`: request stays `pending`, merchant mail without the automatic line, the pipeline records `held`, nothing created in iFirma; mode `none` and a postcode-only change on a second order: `pending`, empty `auto_mode`, no automatic line, `held`. Restores the mode. Run alone with `SMOKE_ONLY=P` |
| Q | Stamp prefix and shop webpage | prefix `smoke`, webpage `https://smoke.example`: the mock's `Uwagi` is `smoke-{id}-{ref} https://smoke.example`, `DodatkoweUwagi.Info` the bare stamp, the stamp stored on the receipt; the delivery mail attachment and the customer download (HTTP login with the demo password, skipped with a note when it fails) are named `smoke.example-<number>.pdf`; a remote document stamped `PS-ORDER-{id}-{ref}` is adopted without a create call; one stamped `other-{id}-{ref}` is not adopted by the stamp and is recorded as a suspected duplicate. Restores the three settings. Run alone with `SMOKE_ONLY=Q` |
| R | Burst issuance under the limits | Duration about 8 to 10 minutes (the cron runs once a minute until the queue is drained, with an 8-minute deadline): run it alone with `SMOKE_ONLY=R`; the scheduled workflow leaves it out. Needs the mock 1.4.0 limit routes (`POST /__mock/rate-limit`, `/__mock/rate-limit/next-window`, the counters in `status.json`) and the cron run in-process (`IfirmaCronRunner`). R1: 50 orders reach the trigger status in a tight loop; before any cron run every one of the 50 orders has a record (none without one) and each record is issued or `queued`, at least one is queued by the module's own cap and none is `error`, `unknown` or `duplicate_suspected`; the cron (in-process, once a minute, `IFIRMA_CRON_MAX_ITEMS` 50) drains the queue; then the mock holds exactly 50 documents (read from its `status.json`) with distinct ids and numbers, each the document of exactly one order's record (the same check, with the order count of the step, ends R2, R3 and R4), the mock refused no call, its busiest minute is at most 80, and the SMTP sink holds exactly one delivery e-mail for each of the 50 orders (order reference and document number), 50 in total. R2: the mock's per-minute limit 30: at most one refusal, orders queued, nothing `error` or `unknown`, then drained to one document each. R3: the mock's per-day limit: at most one refusal, a cron run during the back-off makes no call, then drained after `next-window day`. R4: the module's own day cap: the mock refuses nothing, the rest is queued and then drained. The scenario restores every limit, cap, counter and setting in `finally`, ends with the cron endpoint over HTTP, and then asserts that the mock's limits and switches, the module's caps, counters, back-off and item cap, and the number of queued records are what they were before R |
| S | KSeF-confirmed delivery | Duration under a minute. Needs the mock 1.4.0 KSeF routes (`/__mock/ksef/accept/{id}`, `/__mock/ksef/reject/{id}`, `/__mock/ksef-auto`, the `ksef_send_calls` of a document in `status.json`), the mail sink and the cron run in-process; the customer's download is made over HTTP with the demo customer's login. B2B buyer with a NIP, mode `b2b`, cron in-process. S1: the module submits (one KSeF send call), delivery waits (`awaiting_ksef`, no e-mail, the customer's download answers 404), after `/__mock/ksef/accept` one cron run delivers; the record then stores the KSeF number and state `PRZYJETA` that the mock reports, exactly one e-mail is in the sink, the send count is still 1 and the download answers the PDF. S2: the mock's `ksef-auto` is `send`: the ksef step is skipped as "already sent by iFirma", no send call, the record shows the state the mock reports (`WYSLANA`), no e-mail; after acceptance and one cron run delivered with exactly one e-mail and still no send call. S3: submitter `ifirma` with the mock's `ksef-auto` `off` (iFirma has not sent yet): "sent by iFirma", no send call, a cron run delivers nothing (still `awaiting_ksef`, no e-mail, no send call, the download answers 404); after `/__mock/ksef/accept` and one cron run the number is stored, exactly one e-mail is in the sink, the module never sent and the download answers the PDF; the earlier variant with `ksef-auto` `send` (message and no send call at issuance) is kept. S4: KSeF rejects: `ODRZUCONA` stored, delivery keeps waiting, no e-mail, the issue feed lists the rejection. S5: a private buyer (mode `b2b`) and a NIP buyer in mode `off` are delivered at issuance with exactly one e-mail and no KSeF send call each. The module issues only `fakturakraj` (`fakturaparagon` is a disabled option, see `IfirmaDocumentSettings::enabledDocumentTypes()`), so a receipt document cannot be issued by the module and S5 uses the private buyer's invoice |

## Prerequisites

```bash
docker compose -f test-stack/docker-compose.yml up -d --build
```

Wait until PrestaShop has finished its first-boot install (a few minutes; follow it
with `docker compose -f test-stack/docker-compose.yml logs -f prestashop`). The module
folder is bind-mounted at `/var/www/html/modules/ifirma`, so this script is already in
the container. The demo data (products, the demo customer and address) must be installed,
which the default image does. The script installs the module itself if it is not installed.

## Run

```bash
docker compose -f test-stack/docker-compose.yml exec -u www-data prestashop php modules/ifirma/tests/smoke/run.php
```

Always run the scripts as `www-data` (`-u www-data`). `docker compose exec` defaults to root, and a root-run PHP script creates Smarty/Doctrine cache directories under `var/cache` that Apache can no longer write to, which breaks the whole shop with `SmartyException: unable to create directory ...`. If that already happened, repair it with `docker compose exec prestashop chown -R www-data:www-data /var/www/html/var`.

Exit code: `0` all assertions passed, `1` at least one failed, `2` the environment could
not be prepared (PrestaShop/mock/fixtures missing).

### Environment overrides

| Variable | Default | Meaning |
|----------|---------|---------|
| `PS_ROOT` | `/var/www/html` (4 levels above the script) | PrestaShop root containing `config/config.inc.php` |
| `IFIRMA_MOCK_URL` | `http://ifirma-mock:8001/iapi/` | base URL written to `IFIRMA_BASE_URL` |
| `IFIRMA_MOCK_HOST` | `http://ifirma-mock:8001` | host used for the `/__mock/*` test endpoints |
| `IFIRMA_SMTP_HOST` | `ifirma-mock` | SMTP server written to `PS_MAIL_SERVER` (the mock's mail sink) |
| `IFIRMA_SMTP_PORT` | `1025` | SMTP port written to `PS_MAIL_SMTP_PORT` |
| `PS_LOCAL_URL` | `http://localhost` | shop URL used for the cron HTTP check |
| `SMOKE_ONLY` | all | scenarios to run: a letter string (`SMOKE_ONLY=AB`) or a comma-separated list of names (`SMOKE_ONLY=A,A2,B`); see "Selecting scenarios" |
| `SMOKE_SKIP` | none | comma-separated scenarios to leave out on purpose, e.g. `SMOKE_SKIP=R` |
| `SMOKE_WRITE_FLOOR` | unset | `1` writes the executed check counts to `tests/smoke/check-floor.json` (see "The check floor") |

Pass them with `exec -e NAME=value prestashop php ...`.

## Selecting scenarios

The scenario names are `J K L M A A2 B C D E F G H I N O P Q R S` (run order). Without `SMOKE_ONLY` and `SMOKE_SKIP`
every one runs.

- `SMOKE_ONLY=AB` (no comma) is the old letter string: each character is one single-letter scenario. A value that is
  exactly one known name (`SMOKE_ONLY=A2`) selects that scenario.
- `SMOKE_ONLY=A,A2,B` (with a comma) is a list of names, the only way to select a scenario whose name has more than one
  character together with others. Plain `SMOKE_ONLY=A` does not run `A2`.
- `SMOKE_SKIP=R` removes scenarios from the selection (it wins over `SMOKE_ONLY`). The run prints `selected: ...;
  skipped on purpose: ...` at the start and again in the summary.
- An unknown name in either variable stops the run with exit code `2` before the shop is touched, so a typo can never
  silently run fewer scenarios.

The scheduled workflow uses `SMOKE_SKIP=R` and no `SMOKE_ONLY`, so a new scenario is picked up automatically (it must
then be added to `$smokeScenarioNames` in `run.php` and to the check floor).

## The check floor

`tests/smoke/check-floor.json` holds, per scenario, the minimum number of checks that scenario executes (passed plus
failed; skipped checks do not count). After a run, every selected scenario that executed fewer checks, and every
selected scenario without an entry in the file, produces a `[FAIL] check floor: ...` line and exit code `1`. So a
check cannot disappear, and a new scenario cannot be added, without a visible change to this file.
A missing or malformed `check-floor.json` is a failure of the run too (`[FAIL] check floor: ...`, exit code `1`): a run
whose number of checks cannot be verified is not green. `SmokeCheckFloor::problems()` decides it.

What the numbers mean: each entry is the smallest count of executed checks that scenario reaches on any supported shop
(a fresh PrestaShop 8.1.7 and 9.1.4 install, and the long-lived development shops), so a check that exists only on one
version cannot make the floor fail on the other. The numbers are the minimum over the runs listed in the commit that
set them, never a mean or a maximum. Scenario R's floor comes from its own run (about 8 to 10 minutes).

Raise or create the floor from a fully green run of every scenario you want covered (it refuses to write when any
check failed, and it keeps the entries of scenarios that did not run):

```bash
docker compose -f test-stack/docker-compose.yml exec -T -u www-data -e SMOKE_SKIP=R -e SMOKE_WRITE_FLOOR=1 prestashop php modules/ifirma/tests/smoke/run.php
```

Scenario R is slow; to include its floor, run it once alone with `-e SMOKE_ONLY=R -e SMOKE_WRITE_FLOOR=1`. Commit the
changed file with the change that added the checks. Lowering a number is a removal of checks: say in the commit and in
the scenario table above which checks went and why.

## Smoke in CI and where the evidence is

`.github/workflows/smoke.yml` runs the scenarios (all but R) weekly and on demand for PrestaShop 8.0.5, 8.1.7, 8.2.2,
9.0.3 and 9.1.4. Each job first checks that the shop really reports the matrix version, so a stack that ignores the
image tag fails there and not in a scenario. Evidence of every job, green or red:

- the job summary lists the `[FAIL]` lines with their detail, the totals and the selection;
- the artifact `smoke-output-<tag>` holds the complete output. Keys and tokens in it are masked to their last four
  characters by `run.php`.

## A red smoke run is fixed at its cause

A failing check is fixed in the module, the test stack or the workflow, with a unit test that reproduces the defect
first. No check is removed, skipped or loosened, no scenario or matrix entry is dropped, to get a green run. A check
whose expectation was wrong is corrected only together with the specification scenario that states the right behaviour.

## Reading the output

```
[ OK ] pipeline outcome is suspected
[FAIL] receipt status held -- expected 'held', got NULL
[SKIP] cron URL over HTTP -- localhost answered HTTP 0 (set PS_LOCAL_URL)
[INFO] order #7 ABCDEFGHI total 23.9
```

`[FAIL]` lines print the expected and actual value. The last lines summarize the counts
and list every failed assertion. `[SKIP]` lines do not affect the exit code.

## Known limitations

- It creates real orders, carts, a data request and receipts in the dev database and
  changes module and shop configuration (`IFIRMA_*`, `PS_ORDER_OUT_OF_STOCK=1` and the mail settings
  `PS_MAIL_METHOD=2`, `PS_MAIL_SERVER`, `PS_MAIL_SMTP_PORT`, `PS_MAIL_SMTP_ENCRYPTION=off`, `PS_MAIL_USER` and
  `PS_MAIL_PASSWD` empty, `PS_MAIL_TYPE=3`). Run `make destroy` in `test-stack/` to reset to a clean shop.
- Orders are created with `ps_wirepayment` / `ps_checkpayment`'s `validateOrder()`. If
  neither module is installed the script falls back to inserting a bare `Order` row with
  no order lines, so the iFirma document has no real positions and totals may be zero-ish.
- The iFirma API is the mock, not the real service; field shapes follow the mock.
- Mail is sent over SMTP to the mock's mail sink (`ifirma-mock`, port 1025), never to a real
  mailbox. The run stops with exit code 2 when the sink does not answer (an old mock image: rebuild
  it with `docker compose up -d --build --no-deps ifirma-mock`). Every scenario empties the sink first.
  The shop keeps sending through the sink after the run, so back-office mail also lands there: read it on
  the mock status page (`http://localhost:8001/__mock/status`, section Mail) or at `/__mock/mail`. To go back, set
  `PS_MAIL_METHOD=3` (mail off) or restore your own server in Advanced Parameters > E-mail.
- It needs the Docker stack; in CI it runs through `.github/workflows/smoke.yml` (see above).

## Notes from the first real run (PrestaShop 8.1.7, 2026-11-01)

- **Symfony kernel.** PrestaShop 8/9 resolve hooks and services through the Symfony container, so a CLI
  script that only requires `config/config.inc.php` fails with `Kernel Container is not available`. `run.php`
  boots the kernel right after the bootstrap (PrestaShop 9 made `AppKernel` abstract and split it into
  `AdminKernel` / `FrontKernel`, so the block picks the first concrete one); copy it into any other CLI script.
- **`vendor/` is loaded by PrestaShop.** At boot PrestaShop includes `modules/*/vendor/autoload.php`. In the
  bind-mounted dev stack that means the module's dev-only Composer autoloader (PHPUnit) is loaded into the shop.
  Composer therefore installs into `tests/vendor/` (never loaded by PrestaShop) and has no `autoload-dev` classmap (the test stubs define classes named `Module`, `Tools`,
  `Mail`... and would shadow PrestaShop's own). Never ship `vendor/`; the release zip excludes it.
- **Login checks in CLI.** `Customer::isLogged()` needs a live cookie session in PS 8, so it is always false from
  the CLI. The module checks login in the front controllers and ownership in the services.
- **Cron URL check.** Inside the container `http://localhost` answers with PrestaShop's canonical-domain redirect
  (302), so that sub-check is skipped. Run it from the host instead with a wrong token (expect HTTP 403) and with
  the real token from the module configuration page (expect HTTP 200 and a JSON summary).

## Upgrade test from 1.0.0

`upgrade-phase1.php`, `upgrade-cleanup.php` and `upgrade-phase2.php` prove the upgrade path on the Docker stack. The real 1.0.0 code issues a document while the mock's legacy switch is on; the cleanup makes the database look like a 1.0.0 shop; phase 2 upgrades to the current version the way PrestaShop does and checks the result.

Run from `test-stack/`, with the module repo at `..`:

```
git -C .. worktree add ../ifirma-1.0.0 6565eab
IFIRMA_1_0_0_DIR=$(cd ../../ifirma-1.0.0 && pwd) docker compose -f docker-compose.yml -f docker-compose.upgrade-1.0.0.yml up -d --no-deps --force-recreate prestashop
docker compose cp ../tests/smoke/upgrade-phase1.php prestashop:/tmp/upgrade-phase1.php
docker compose exec -T -u www-data -e PS_ROOT=/var/www/html prestashop php /tmp/upgrade-phase1.php   # ends PHASE1_OK order=<id>
docker compose up -d --no-deps --force-recreate prestashop                                           # the normal mount again
docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/upgrade-cleanup.php     # ends CLEANUP_OK
docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/upgrade-phase2.php <id> # ends PHASE2_OK
git -C .. worktree remove ../ifirma-1.0.0
```

Notes:

- A local compose override (image tag, ports, `PS_DOMAIN`) goes on every `up` command as another `-f`.
- Under WSL the worktree path must be the `/mnt/c/...` one, because `IFIRMA_1_0_0_DIR` is the path as Docker sees it.
- Phase 1 is copied into the container, because the 1.0.0 tree mounted at that point has no `tests/` folder; `PS_ROOT` tells the copy where PrestaShop is.
- The mock must have been rebuilt with the legacy switch: `docker compose up -d --build --no-deps ifirma-mock`.
- After the run the shop holds the upgraded module and the settings phase 1 wrote. Restore the settings you need, or run `run.php`, which writes its own.

## Verified runs

| Date | PrestaShop | PHP | Smarty | Result |
|---|---|---|---|---|
| 2026-11-01 | 8.1.7 | 8.1 | 4.3 | 47 passed, 1 skipped (in-container cron URL); upgrade 1.0.0 -> 1.1.0 verified |
| 2026-11-01 | 9.1.4 | 8.5 | 4.5 | 47 passed, 1 skipped; admin panel and configuration form rendered via `AdminKernel`; cron 403/200 from the host |
| 2026-11-06 | 8.1.7 | 8.1 | 4.3 | 90 passed, 1 skipped; upgrade test from 1.0.0 as written: phase 1 with the mock's legacy switch, cleanup (2 tables, 14 settings), phase 2 to 1.3.2 (39 checks); PDF download front and admin OK |
| 2026-11-07 | 8.1.7 | 8.1 | 4.3 | 184 passed, 1 skipped; mail sent over SMTP to the mock's sink (scenario N and the scenario C mails: recipient, sender, subject, bodies, PDF attachment, blind copy on the envelope, refusal recorded as failed) |
| 2026-11-07 | 9.1.4 | 8.5 | 4.5 | 184 passed, 1 skipped; same SMTP sink checks through Symfony Mailer, no mailer error logged apart from the deliberate refusal |

PrestaShop 9 / PHP 8.4+ also flagged implicitly nullable parameters (`Type $x = null`); the module now declares
them as `?Type $x = null` (PHP 7.1+ syntax, still inside the 7.2 floor).

## Contractor linking check

`contractor-link-check.php` seeds an "old" contractor in the mock, imports a CSV naming the demo customer,
verifies the mapping and issues a document that must carry the mapped identifier (and update the contractor
through PUT). Run it as `docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/contractor-link-check.php`.

The document request references the resolved contractor through the top-level `IdentyfikatorKontrahenta` field
(`NIPKontrahenta` carries the buyer NIP of a business customer). The check reads that field from the decoded
`request_payload` and also asserts that the `Kontrahent` block carries no `Identyfikator`, because the module
never sends `Kontrahent.Identyfikator`.

`guard-unknown-contractor-check.php` resets the mock state, confirms that `faktury.json?kontrahent=<unknown>` is answered with Kod 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze" (the live-API behaviour that stopped every first issuance at the guard step before 2026-11-02), and then runs the duplicate guard and the whole pipeline for an order whose customer has no contractor yet: the guard must proceed and the document must be issued with the freshly created `PS-C` contractor. Run it as `docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/guard-unknown-contractor-check.php`. It wipes the mock's in-memory contractors and documents, so run it last.

## Orders list column check

`order-grid-check.php` builds the real back-office Orders grid (`prestashop.core.grid.factory.order` and the grid presenter, all rows) and checks the "iFirma" column added by the three `actionOrderGrid*Modifier` hooks: the hooks are registered (the script runs `upgrade_module_1_4_0()` when they are not), the `ifirma_document` column sits right before `actions`, the query builder hook selects the number and status of the newest module record, an order with an issued document shows the `receipt_long` icon whose multi-line tooltip starts with the kind and the state in words and ends the first line with the number (for example `Invoice sent: 39/2026`), the column sorts by document state in both directions (ranks non-decreasing ascending, non-increasing descending, newest order first inside a state, total and paging unchanged), an order without any record shows an empty cell, the grid total equals `COUNT(*)` of `ps_orders`, and building the grid sends no request to the iFirma mock (measured with `IfirmaApiClient::getLimit()` before and after; skipped when `IFIRMA_KEY_ABONENT` is empty, because the mock keeps no request log). It also filters by the column through the grid's own filter object (`OrderFilters`, field `ifirma_document`, the rank as a string `"1"` to `"10"`): ten throw-away orders (copies of an existing order row with the reference `SMKGRID01` to `SMKGRID10`, one record per state: error, unknown, duplicate suspected, held, queued, no record, issued not sent, awaiting KSeF, sent, paid) are seeded, and for each state the check asserts that of the seeded orders only the one in that state is listed, that every listed order carries that rank, that the grid's reported total equals the number of listed orders, and that the same filter combined with the `reference` filter lists exactly the seeded order with a total of 1. A filter combined with the module's own sort and with another sort lists the same orders. An invalid value (`refunded`, `1 OR 1=1`, `0`, `11`) lists every order and leaves the total as it is, and the unfiltered grid lists the same orders again afterwards. The seeded orders and records are deleted at the end. Apart from that it reads fixtures from the database and changes nothing. Run it on each shop:

```
docker exec -u www-data -e PS_ROOT=/var/www/html ifirma_prestashop php /var/www/html/modules/ifirma/tests/smoke/order-grid-check.php
docker exec -u www-data -e PS_ROOT=/var/www/html ifirma91_prestashop php /var/www/html/modules/ifirma/tests/smoke/order-grid-check.php
```

On PrestaShop 8.1 and 9.1 the service ids are the same and the factory is public on the kernel container. The script keeps its output buffered and pushes a request with an array-backed session onto the request stack, because the grid's filter form needs a session that a CLI run does not have.

## Upgrade 1.5.0 check

`upgrade-150-check.php` drives `upgrade_module_1_5_0()` by hand on a running shop whose `ps_module` row still says 1.4.x (PrestaShop then does not run the script itself). It prints the `AdminIfirma` tab row, the profile-1 `ROLE_MOD_TAB_ADMINIFIRMA_%` access rows, the number of `actionAdminControllerSetMedia` registrations, the `last_download_at` column, the `idx_date_upd` index and the `IFIRMA_AUTH_FAILURE` row before the first run, after it and after a second run, and asserts: the tab id is unchanged and its parent is `AdminParentOrders`, every language is named "iFirma documents", the hook is registered once, the column and the index exist, the access rows are unchanged, the configuration key exists, and the second run changes nothing. Safe to run any number of times.

```bash
docker compose -f test-stack/docker-compose.yml exec -u www-data prestashop php modules/ifirma/tests/smoke/upgrade-150-check.php
```

Exit code `0` all assertions passed, `1` one failed. It does not change the module version recorded in `ps_module`.
