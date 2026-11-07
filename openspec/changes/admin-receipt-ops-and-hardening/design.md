# Design

## Context

See proposal.md - Why. The requirements are in the delta specs of this change; this document records what was observed and the technical choices.

**Observed in the module (1.4.1):**

- `AdminIfirmaController` is a pure AJAX `ModuleAdminController`: one `ajaxProcess<Action>()` per button, each delegating to `IfirmaAdminActions` and answering the JSON envelope `success`, `message`, `data`. Only the three customer-link actions check a permission (`Access::isGranted('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE')`). The tab `AdminIfirma` is installed with `id_parent = -1`; on both Docker shops only profile 1 (SuperAdmin) holds its four roles. The order panel, the customer-page panel and the Orders-list column render for every employee; the panel's AJAX URL is `getAdminLink('AdminIfirma', false) . '&token=' . Tools::getAdminTokenLite('AdminIfirma')`.
- `IfirmaIssuancePipeline::build($module)` builds one `IfirmaApiClient` shared by the guard, the receipt service, the delivery service and the KSeF service, so one run shares one rate counter. `run()` never throws. Delivery retries a PDF download three times with waits of 3 s and 7 s through an injectable sleeper. `IfirmaStatusSyncService::runCron()` groups receipts by issue date and makes one list call per date; `syncByIssueDate()` is public.
- `ifirma_receipt` has `date_add`, `date_upd`, `status`, `delivery_status`, `ksef_status` (`BLAD` on failure), `remote_missing`, `last_error` and the indexes `idx_order`, `idx_customer`, `idx_token`. `IfirmaSchema` self-heals missing columns at runtime (`ensureReceiptKsefColumns()`), so a new column needs the same pattern plus an upgrade step.
- The rate guard is a read-modify-write on `Configuration` `IFIRMA_RATE_WINDOW` (`<minute>:<count>`). Download tokens and the cron token fall back to `uniqid()`/`mt_rand()` when `random_bytes()` throws. `IfirmaHttpTransport` verifies TLS, sets timeouts and does not follow redirects, but allows every cURL protocol and has no size cap. The settings table (`IfirmaSettings::fields()`) validates the faktura key as hex, the base URL as free text and the two other keys as free text; `text()` calls `trim()` on the raw value, so an array posts a `TypeError`.
- The unit harness (`tests/Stubs`) has `Access` (static grant list), `Tab` (add/delete/getIdFromClassName only), a scripted `Db`, `Configuration`, `Employee`, `Context`, `Tools` and the capturing-controller pattern of `AdminControllerCustomerTest`; it has no `Media` stub. The test stack runs PrestaShop 8.1.7 (port 8099) and 9.1.4 (port 8091) with `PS_DEV_MODE=1` and the mock at `http://ifirma-mock:8001/iapi/`.

**Observed in PrestaShop core (8.1.7 and 9.1.4, read in the containers):**

- The notification centre has no extension hook: `Notification::$types` is hard-coded (`order`, `customer_message`, `customer`), the poll route `admin_common_notifications` answers those three keys every 120 s and the ack route ignores an unknown `type`. Symfony pages render the bell as `#notif .notification-center` with `ul.nav a[data-toggle=tab][data-type]` and `.tab-content .tab-pane` panes and the total in `#notifications-total`; legacy controller pages render `#notification` with the same tab pattern and the total in `#total_notif_value` (JS `js/admin/notifications.js`). Both JS implementations overwrite the total on every poll.
- `actionAdminControllerSetMedia` fires on legacy pages (`AdminController::setMedia()`) and on Symfony pages (8.1.7 through `AdminLegacyLayoutController::setMedia()`, 9.1.4 through `HeadTag::mount()`); `$this->context->controller->addJS()`/`addCSS()` and `Media::addJsDef()` are honoured on both. `displayBackOfficeHeader` lands in `<head>` on both.
- Legacy request flow on 8.1.7: `checkAccess()` is `checkToken()` only, `postProcess()` runs `ajaxProcess*` without any role check, `viewAccess()` guards only `initContent()`. On 9.1.4 `LegacyController` enforces READ for a legacy request, or CREATE/UPDATE/DELETE when the GET `action` starts with `add|generator|new`, `edit|update|options|status` or `delete`. `Tab::add()` grants the four roles to SuperAdmin and the installing employee's profile; the Permissions page skips tabs with `id_parent = -1`, so a hidden tab's permissions cannot be edited. Role slugs are `ROLE_MOD_TAB_<CLASS>_<READ|CREATE|UPDATE|DELETE>`. `getAdminLink('AdminIfirma', true, [], ['ajax' => 1, ...])` yields a legacy URL with a `token` parameter on both versions.
- The legacy list machinery (`fields_list`, filters, `bulk_actions`, `processBulk*`) is intact in 9.1.4 and used by 16 core controllers; a legacy page body there is rendered by `LegacyController` (`initContent()` plus template), not `display()`.
- Orders grid: bulk action types are `SubmitBulkAction`, `ModalFormSubmitBulkAction`, `AjaxBulkAction` (all need a Symfony route) and `ButtonBulkAction` (`class`, `attributes`, no handler). Row action types need a Symfony route too. Row checkboxes are `.js-bulk-action-checkbox` named `order_orders_bulk[]` with the order id; the grid form is `#order_filter_form`; the hook `actionOrderGridDefinitionModifier` receives the definition. A module can only declare Symfony routes with its own `vendor/autoload.php`, which this module does not ship.

**Security audit (2026-11-07, static).** Findings and their disposition in this change:

| Id | Severity | Finding | Disposition |
|---|---|---|---|
| S1 | medium | State-changing AJAX actions have no permission check (token only on PS 8) | D1 |
| S2 | medium | Customer PDF download proxies iFirma unthrottled and shares the rate cap | D13 |
| S3 | medium | Payment amount not validated (`amount[]`, negative, excessive) | D14 |
| S4 | medium | Base URL unvalidated; cURL protocols unrestricted | D9 |
| S5 | low | No cool-down after an authentication failure; bare HTTP 401/403 retried with waits | D8 |
| S6 | low | Exception text returned to the browser (`exit($e->getMessage())`, any `Throwable` in the envelope) | D11 |
| S7 | low | Admin PDF file name uses the raw identifier | D11 |
| S8 | low | Synchronous retry waits inside the order-status hook | Deferred: it is a latency problem of the status hook, not an exposure; fixing it means deferring delivery, which changes the pipeline contract (own change) |
| S9 | low | API keys as plain text inputs; cron token only in the URL | D12 |
| S10 | low | Weaker RNG fallback for tokens | D12 |
| S11 | low | Rate counter is a non-atomic read-modify-write | D10 |
| S12 | low | Data-request text stored as `TYPE_HTML`, no name/address/postcode validators | D14 |
| S13 | low | Data-request submission not throttled | D14 |
| S14 | info | `trim()` on array input; optional keys not hex-checked | D14, D12 |
| S15 | info | Delivery e-mail variables not HTML-escaped | D11 |
| S16 | info | PDF responses lack `nosniff` | D11 |
| S17 | info | `pSQL()` on an unchecked token value | D14 |
| - | - | Encryption of the API keys at rest | Deferred: `ps_configuration` holds every module's secrets in clear; a module-only cipher adds key management without removing the exposure (DB access) |

## Goals / Non-Goals

**Goals:**

- One permission helper, one action-to-level table, and every back-office entry point of the module behind them, on PrestaShop 8 and 9 alike.
- Bulk operations that reuse the existing pipeline, delivery and sync code unchanged, and that cannot exceed the rate guard or a request's time budget.
- No Symfony bundle, route or autoloader in the module: the list, the bulk endpoints and the feed stay on the legacy controller the module already has.
- Every hardening fix behind a unit test in the harness; every UI change checked on both Docker shops.

**Non-Goals:**

- Replacing the legacy controller with a Symfony grid of module records.
- Per-document permissions, per-shop permissions or an audit log beyond the PrestaShop log.
- Asynchronous (queued) issuance or delivery.
- Encrypting secrets at rest, or the status-hook latency work (S8).

## Decisions

### D1. Permission model: the tab's roles, read through one helper, enforced in the controller and the hooks

New class `classes/IfirmaPermission.php` (framework calls only through `Access::isGranted()` and the context employee): `canView($employee)`, `canIssue($employee)`, `canManage($employee)` read `ROLE_MOD_TAB_ADMINIFIRMA_READ`, `_CREATE` and `_UPDATE` for the employee's profile; profile `_PS_ADMIN_PROFILE_` is always granted; no employee means nothing is granted. `AdminIfirmaController` keeps one method per action but routes each through `handle($action, callable)`, which looks the required level up in a static table (`issue`: CreateReceipt, IssueAnyway, LinkCandidate, RegisterContractor, IssueFromList, BulkRun with kinds `issue` and `retry`; `manage`: UpdateStatus, ResendDocument, SendToKsef, RefreshStatus, ApproveRequest, RejectRequest, BulkRun with kinds `resend` and `refresh`; `view`: everything else, DownloadPdf, IssueFeed, IssueFeedSeen, CustomerCheck, CustomerSearch, CustomerCountRemote). A refused call answers HTTP 403 with the usual envelope; the token check stays PrestaShop's and runs first. The three customer-link actions keep their Customers-edit check on top of `view`. Hook rendering uses the same helper: the order panel, the customer panel, the Orders-list column, the bulk action and the two scripts are added only when the level allows, and the panel templates get `can_issue`/`can_manage` flags that hide buttons (the server still decides).

*PrestaShop 9 interplay:* the Symfony `LegacyController` maps a GET `action` prefix to a core permission (`update*`/`status*` to UPDATE, `add*`/`new*` to CREATE, `delete*` to DELETE, anything else to READ). Our table is always at least as strict as that mapping, and no `view`-level action name starts with one of those prefixes, so the two never contradict. The existing `UpdateStatus` already needs UPDATE on 9.1.4; this change makes 8.1.7 behave the same.

*Alternative:* module-specific settings ("profiles allowed to issue") on the configuration page. Rejected: it duplicates the Permissions page, and PrestaShop's profile roles are what administrators already know.

*Found in the browser check (I2, both versions):* `Hook::exec()` runs a module's back-office hooks only when the employee's profile holds the module's own View permission (`ROLE_MOD_MODULE_IFIRMA_READ`, Team > Permissions > Modules). A profile with the tab's View and Add levels alone therefore sees no panel, column or bell tab, while the AJAX actions (no hook involved) follow the tab levels. The module does not work around the core rule; the configuration and upgrading guides say to grant both permissions.

### D2. The tab becomes a visible menu entry; the upgrade moves it in place

`installTab()` sets `id_parent` to the id of `AdminParentOrders` (fallback: the parent of `AdminOrders`; if neither exists the tab stays hidden and the install logs it) and the name "iFirma documents". `upgrade/upgrade-1.5.0.php` updates the existing tab row (`id_parent`, name per language, position at the end of the Orders children) instead of deleting and re-adding it, so the `ps_access` rows of every profile survive; it is idempotent. Because `initAccess()` ran at the original install, SuperAdmin already holds the roles; nothing is granted to anyone else. Uninstall keeps deleting the tab.

### D3. The documents list is a legacy list on `AdminIfirmaController`

The controller gets `$this->table = 'ifirma_receipt'`, `identifier = id_ifirma_receipt`, `list_id`, `fields_list` (order reference and customer joined from `orders`/`customer` through `_select`/`_join`, number or identifier, status, source, delivery, KSeF, issue date, shortened `last_error`, `date_upd`), filters on the status fields, the reference, the number and the date range, `_orderBy = id_ifirma_receipt DESC`, and a preset `ifirma_preset=attention` that adds the issue predicate of D6 to `_where`. Row buttons (order link, Retry, Send again, Refresh, PDF) are rendered through `fields_list` callbacks with the level flags of D1. `bulk_actions` declares `retryIssuance`, `resend` and `refresh` so the list shows the checkbox column and the native dropdown; the module script of D7 rewires those entries (removes the inline `sendBulkAction` handler, binds the batch runner of D4). The server-side `processBulk*()` methods exist only as a fallback that redirects back with "the bulk actions of this page need JavaScript", so a non-JS submit never runs a synchronous bulk. Everything an AJAX action already did stays as it is.

*Alternative:* a Symfony grid with a module route. Rejected: it needs `config/routes.yml`, a PSR-4 controller and a shipped `vendor/autoload.php`; the module has neither, and the legacy list is still first-class in 9.1.4.

### D4. One bulk runner, batches driven by the browser

New framework-free class `classes/IfirmaBulkRunner.php`: `run($kind, array $ids, $idEmployee, $budget)` takes `retry`/`issue` (ids are order ids for `issue`, receipt ids otherwise), `resend` or `refresh`, builds the pipeline, delivery or sync service once (shared client, shared counter) and processes ids in order until the batch is done or the time budget (default 20 s) is used. Each id yields `outcome` (`issued`, `adopted`, `suspected`, `held`, `failed`, `sent`, `refreshed`, `skipped`, `not_attempted`), `message` and the receipt array; a rate-guard refusal (pipeline outcome `rate_limited`, a delivery result or sync result flagged rate limited) ends the run with `stopped = rate_limited` and the untouched ids as `not_attempted`. `retry` runs the pipeline with trigger `manual` and never `bypass_guard`; an issued record is `skipped`; `refresh` groups the batch by issue date and calls `syncByIssueDate()` once per date. The AJAX action `BulkRun` takes `kind` and `ids[]` (capped at the batch size per request: 3 for `issue`/`retry`, 5 for `resend`, 25 for `refresh`) and answers the per-id results plus `stopped`. The script (`views/js/admin/ifirma-bulk.js`) collects the checked ids (`.js-bulk-action-checkbox:checked` on the Orders grid, `input[name="ifirma_receiptBox[]"]:checked` on the documents list), confirms, posts batch after batch, shows a progress modal with the running totals, stops on `stopped`, then renders the summary table and reloads the list on close. One `PrestaShopLogger` entry per run (employee, kind, totals) is written by the runner when the last batch of a run is reported (`final=1` sent by the script, or on `stopped`).

*Alternative:* process the whole selection in one request. Rejected: an issuance can take several seconds (guard calls, create, PDF retries with waits), the rate guard refuses after about 15 issuances, and the request would hit PHP time limits.

### D5. Orders list: the Issue action lives in the existing cell, the bulk action is a `ButtonBulkAction`

`IfirmaOrderGridIndicator` gains a third select (`ifirma_document_state`: the raw status of the newest record, `''` without a record) and `cell()` gains a mode: for an issued record the icon as today; otherwise, when the employee may issue, an anchor `<a href="#" class="js-ifirma-issue" data-id-order="…" title="iFirma: issue (failed: …)">` with the `add_circle_outline` icon and the state word in the tooltip; otherwise `''`. The definition hook adds `ButtonBulkAction('ifirma_issue')` with class `js-ifirma-bulk-issue` and the AJAX URL in a `data-url` attribute, only when `canIssue`. The script binds a capture-phase `click` listener on the grid table (the core clickable-row handler is bound directly on the `td`, so a bubbling handler would run after it): on `.js-ifirma-issue` it stops propagation, confirms, posts `IssueFromList` and replaces the cell's inner HTML with the `cell` HTML returned in `data.cell`. The bulk entry calls the runner of D4 with kind `issue`.

*Alternative:* a `LinkRowAction` "Issue" in the Actions dropdown. Rejected: row actions need a Symfony route (see Context).

### D6. Notification tab: own feed, own seen mark, injected into whichever bell is on the page

New framework-free class `classes/IfirmaIssueFeed.php`: `issuePredicate()` (the SQL of "status in (error, unknown, duplicate_suspected) or (status = held and a pending data request exists for the order) or delivery_status = failed or ksef_status = 'BLAD' or remote_missing = 1"), `latest($limit = 20)` (join on `orders` for the reference, newest `date_upd` first, each row classified into one kind in the order held, error, unknown, suspected, missing, ksef_failed, delivery_failed), and `unseenCount($since)` (`date_upd > since`). The seen mark is `Configuration::updateValue('IFIRMA_SEEN_' . $idEmployee, <datetime>)`, read with `Configuration::get()` like every other setting of the module; uninstall deletes `IFIRMA_SEEN_%`. The index `idx_date_upd` is added to `ifirma_receipt` for the two queries. AJAX actions: `IssueFeed` (answers `unseen`, `items` with `id_order`, `reference`, `kind`, `kind_label`, `number`, `time`, `url` built with `getAdminLink('AdminOrders', true, ['id_order' => …, 'vieworder' => 1])`, and `list_url` for the preset of D3) and `IssueFeedSeen` (stores the mark, answers the new count). Both are `view` level.

`views/js/admin/ifirma-notifications.js` runs on every back-office page where it was enqueued (D7): it detects the Symfony bell (`#notif`) or the legacy bell (`#notification`), appends `<li class="nav-item"><a class="nav-link" data-toggle="tab" data-type="ifirma" href="#ifirma-notifications">iFirma <span class="ifirma-notif-count"></span></a></li>` and a `tab-pane#ifirma-notifications` with a `.notification-elements` list; when neither bell exists it inserts a standalone `dropdown` button into `.header-right` (or `#header_infos`), and when no header exists it does nothing. It polls `IssueFeed` every 120 s, builds entries with text nodes only, and posts `IssueFeedSeen` on `shown.bs.tab` of its tab and on bell click while its tab is active (the core ack POST that fires with `type=ifirma` is ignored by core, as read in `Notification::updateEmployeeLastElement()`). The bell total is adjusted through a `MutationObserver` on `#notifications-total`/`#total_notif_value`: the script remembers the last value it wrote; any other value is the core's count, to which it adds its own unseen count, and it removes the `hide` class when the sum is above zero.

*Alternative:* a dashboard widget or an e-mail digest. Rejected: the request is the notification tab, and the bell is the one place every employee sees on every page.

### D7. Script delivery: one hook, one jsDef block, no inline scripts

`hookActionAdminControllerSetMedia` (new hook, registered on install and by the 1.5.0 upgrade) checks the context employee with D1 and then: for `canView`, `addJS` of `ifirma-notifications.js` and `addCSS` of `views/css/ifirma-admin.css` on every page; for the Orders list and the module's own list page (controller name `AdminOrders`/`AdminIfirma`), `addJS` of `ifirma-bulk.js` when `canIssue` or `canManage`. `Media::addJsDef(['ifirma' => [...]])` carries the AJAX URL (`getAdminLink('AdminIfirma', true)` plus `ajax=1`), the levels, the batch sizes, the poll interval and every translated word the scripts need (`$this->l()`), so the scripts contain no text and no token. The hook fires on legacy and Symfony pages on both versions (Context); if a page type turns out not to enqueue the files on 9.1.4 the visual check of D15 catches it and `displayBackOfficeHeader` is the documented fallback.

### D8. Authentication failure cool-down in the API client

`IfirmaApiClient` learns an auth failure in `decodeJson()`/`applyKod()`: HTTP 401/403 of the transport, or `Kod` 400, 401, 402, 403 or 499 (`Kod` 500 is endpoint-specific: the abonent limit call can answer it while invoicing works, so it does not count). It stores `IFIRMA_AUTH_FAILURE` = `<unix time>|<message>` (global configuration) and, in `execute()` before the rate guard, refuses every call while the mark is younger than 900 s: `lastError` = "iFirma rejected the credentials at <time>: <message>; calls are paused until <time + 15 min>", `lastRequestWasSent()` false, a new `isAuthBlocked()` true. The pipeline's create step therefore ends in `error` with that message (no request was sent, so `unknown` would be wrong), delivery's retry loop sees "not sent" and makes no further attempt (and `PDF_PERMANENT_KODS` is joined by HTTP 401/403 of the last response), `runCron()` checks `isAuthBlocked()` first and returns `auth_blocked => true` with nothing touched, the limit box on the settings page does not call either. `postProcessConfiguration()` deletes the mark on every successful save; `getContent()` renders a warning banner from the mark while it is set.

*Alternative:* count consecutive failures before pausing. Rejected: one rejected signature means every later call with the same key fails the same way; a single failure is enough.

### D9. Base URL rule and transport limits

`IfirmaSettings` gets `TYPE_URL`: `Validate::isAbsoluteUrl()`, `parse_url()` must give a host and no user info, the scheme must be `https`, or `http` when `_PS_MODE_DEV_` is true or the host is `localhost`, `127.0.0.0/8`, `::1`, `10/8`, `172.16/12`, `192.168/16` or a single-label name (no dot). The settings error table names the field. `IfirmaHttpTransport` sets `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` to HTTP|HTTPS, `CURLOPT_FOLLOWLOCATION` false explicitly, and a `CURLOPT_WRITEFUNCTION` that appends to a buffer and returns -1 once 20 MB are exceeded (cURL then reports a write error, which the transport maps to `status 0` and an "answer too large" error). The stub transport of the tests is unaffected; the cURL options are asserted through a seam that exposes the option array.

### D10. Atomic rate counter

`passRateGuard()` becomes one statement: `UPDATE ps_configuration SET value = IF(SUBSTRING_INDEX(value, ':', 1) = '<minute>', CONCAT('<minute>:', SUBSTRING_INDEX(value, ':', -1) + 1), '<minute>:1') WHERE name = 'IFIRMA_RATE_WINDOW' AND (SUBSTRING_INDEX(value, ':', 1) <> '<minute>' OR SUBSTRING_INDEX(value, ':', -1) < <cap>)`; one affected row means the request may go, zero means the cap is reached (or the row is missing, in which case the row is created with `<minute>:1` once and the request goes). `Configuration::get('IFIRMA_RATE_WINDOW')` stays only for the message. The configuration cache is bypassed for this key because the decision is taken by the database, not by the cached value.

### D11. Error disclosure, PDF output and mail escaping

`IfirmaActionSupport::failure()` keeps the message of a module exception (class exactly `Exception`, or any `Ifirma*Exception`) and replaces every other `Throwable` (`Error`, `TypeError`, `PrestaShopException` and subclasses, `PDOException`) by "Internal error; details are in the PrestaShop log", writing the original class and message with `PrestaShopLogger::addLog(..., 3)`. `ajaxProcessDownloadPdf()` catches `Throwable`, answers `text/plain; charset=utf-8` with `X-Content-Type-Options: nosniff` and the same rule. `IfirmaHttpOutput::pdf($pdf, $name)` sanitises the name itself (`[^0-9A-Za-z._-]` to `_`), adds `nosniff` and `Content-Disposition: attachment`, and both download controllers hand it the raw number or identifier. The delivery mail variables `{firstname}`, `{lastname}` and `{order_reference}` are passed through `htmlspecialchars` for the HTML template (the text template keeps the raw values).

### D12. Secrets on the settings page, cron token in a header, CSPRNG only

The three key inputs become `type => 'password'` with an empty `fields_value`, a description "a key is stored" when set, and a `clear_<KEY>` checkbox each. `postProcessConfiguration()` substitutes the stored value for a key posted empty unless its clear box is set (then `''`), before `IfirmaSettings::fromInput()`, so validation and saving stay unchanged. `IFIRMA_KEY_ABONENT` and `IFIRMA_KEY_RACHUNEK` become `TYPE_HEX`. `cron.php` reads the token from `Tools::getValue('token')` or `$_SERVER['HTTP_X_IFIRMA_TOKEN']` (query wins when both are present); the settings page shows both forms. `IfirmaReceiptService::generateSecurityToken()` returns `''` and logs when `random_bytes()` throws (the customer view already hides the download link without a token); `IfirmaSchema::generateCronToken()` lets the exception propagate as a `RuntimeException`, which `install()` turns into a failed install with the message, the upgrade into `false`, and the regenerate button into an error box.

### D13. Customer download throttle with one column

New nullable `last_download_at DATETIME` on `ifirma_receipt` (schema, `sql/install.php` through the schema, `IfirmaSchema::ensureReceiptDownloadColumn()` called by the front receipt controller, the upgrade script). `receipt.php` checks, after the ownership check and before the fetch: the receipt's `last_download_at` within 30 s, or ten or more of the customer's receipts with `last_download_at` within 60 s (so at most ten documents per minute), answers HTTP 429 `text/plain` with the retry message; otherwise it stamps the column and fetches. The admin download is not throttled (it is behind the view level).

*Alternative:* cache the PDF. Rejected: the spec says the shop stores no copy of the document, and a cache would need storage and invalidation rules.

### D14. Input validation: amount, data requests, arrays

A small `IfirmaInput` helper (`scalar($value)` returns `''` for arrays and objects, `decimal($value)` parses `12,50`/`12.50` with at most two decimals or returns null) is used by the controller for `amount`, `faktura_id`, `identifier`, `admin_note`, `reason`, `token` and ids, by `receipt.php`/`datarequest.php` for `token`, and by `IfirmaSettings::text()`. `IfirmaReceiptService::updateStatusPaid()` refuses null, non-positive or above-gross amounts with a module exception before any call. `IfirmaDataRequestService::validate()` adds `Validate::isName` (first/last name), `Validate::isAddress` (both lines), `Validate::isCityName`, the country's zip format through `Validate::isPostCode` when the country defines one, `Country` active check, note length 1000; `admin_note` and `reason` are capped at 1000 and the two `TYPE_HTML` note fields become `TYPE_STRING` (they are escaped on output already). `canRequest()`/`submit()` add the cool-down: the newest request of the customer for the order (any status) younger than 10 minutes, or ten requests of the customer in the last 24 hours, refuse with a new reason code and message.

### D15. Verification strategy

- **Harness:** a `Media` stub (`addJsDef` recorder), `Tab::update()`/`save()` and a `Tab::$rows` map on the stub, `Access::grant()` already exists. Controller tests extend the capturing-controller pattern with a permission matrix (each action × each level). The runner, the feed, the permission helper, the settings rules, the transport options, the cool-down, the throttle and the token generation get their own test files; `UpgradeTest` covers 1.5.0 (tab move keeps the id, hook registration, column and index, idempotence).
- **Docker shops:** `tests/smoke/run.php` gains checks for `BulkRun` (retry of seeded failed rows through the mock), `IssueFeed`/`IssueFeedSeen` and the 403 envelope for a profile without roles (the script creates a throw-away employee and profile and calls the controller actions with that context); `tests/smoke/order-grid-check.php` asserts the third select and the Issue cell. The puppeteer recipe (memory: BO visual check) screenshots on 8.1 and 9.1: the iFirma tab in the bell on the dashboard, on the Orders list and on the module page; the Issue action and the bulk run on the Orders list; the documents list with the preset; the settings page with password fields and the banner.
- **Gate:** `php -l`, licence headers, PHPUnit, the diff-cover gate on changed lines, `graphify update .`.

## Risks / Trade-offs

- [The core bell JS rewrites the total every poll and on 9.1.4 may re-render the tab list] → the observer re-adds the count after each rewrite, and the script re-inserts its tab when it disappears; if both fail the count still shows on the tab itself. Checked on both shops in D15.
- [The clickable-row handler of the Orders grid could swallow the Issue click] → capture-phase listener with `stopPropagation()`; verified visually on both shops.
- [Legacy list rendering differs under the 9.1.4 `LegacyController` (no `display()`, no `initHeader()`)] → the list uses only `initContent()`/`renderList()` like the core legacy controllers; checked on 9.1 in D15.
- [`UPDATE ps_configuration` by name ignores multishop rows] → the module writes this key without a shop scope; the fallback insert uses the same scope; documented as a soft cap in a multishop with per-shop overrides.
- [A bulk run that the employee abandons mid-way leaves no trace of the remaining ids] → the summary is per batch and the list reload shows the states; nothing is queued, so nothing is lost.
- [The 15-minute cool-down blocks a shop after one transient 403 from a proxy] → the banner names the cause and the save button clears it immediately.
- [Stricter data-request validators refuse addresses that PrestaShop itself accepted] → the rules are PrestaShop's own (`Validate::is*`), so the shop's address form applies the same ones.
- [Non-SuperAdmin employees lose the panel they saw until now] → intended (proposal); documented in upgrading.md with the Permissions page steps.

## Migration Plan

1. Ship as 1.5.0 (`npm version minor`): `upgrade/upgrade-1.5.0.php` moves the tab under Orders, registers `actionAdminControllerSetMedia`, adds `last_download_at` and `idx_date_upd` when missing, writes the default of `IFIRMA_AUTH_FAILURE` (empty). Idempotent.
2. After the upgrade an administrator grants the levels to the profiles that issue or manage documents (Team > Permissions > Orders > iFirma documents). Until then only SuperAdmin sees the module screens.
3. Rollback: install the previous zip; the column and index stay (harmless), the tab stays visible (harmless), the permissions rows are untouched.

## Open Questions

- Batch sizes (3/5/25) and the 20 s budget are starting values; they can be tuned after the first runs on a real shop without touching the specs.
- Polish wording of the menu entry ("Dokumenty iFirma") and of the issue kinds is decided when the translation map is extended.
