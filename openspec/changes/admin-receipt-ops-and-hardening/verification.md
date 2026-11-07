# Verification record

Recorded 2026-11-07 while applying the change in the working tree on master (base commit `d2685f9`, no commits
made during the apply). Work was done by waves of subagents with exclusive file ownership; every task was
re-verified by the manager before it was ticked (the raw results below are the manager's own runs).

## Baseline before any edit

- PHPUnit in the PS 8.1 container (PHP 8.1.33): 3552 tests, 2 failures, both the pre-existing
  `UpdateStatusPaidTest` float-formatting cases (`150.46` vs `150.46000000000001`); they pass in the 8.2 image and
  in CI. Not touched.
- `python tools/check_license_headers.py`: `All 214 source file(s) carry the license header.`

## Gate after the last code change (task 9.5)

- `php -l` (PS 8.1 container) on every changed or new PHP file: 85 files, no syntax errors.
- `build/tools/lint72.sh` (php:7.2-cli-alpine, every tracked shipped `.php`): `LINT72 rc=0`; the eight new
  untracked shipped files (`IfirmaBulkRunner`, `IfirmaDownloadThrottle`, `IfirmaInput`, `IfirmaIssueFeed`,
  `IfirmaPermission`, `upgrade-1.5.0.php`, the two `views/js` index files) linted separately in the same image:
  no syntax errors.
- `python tools/check_license_headers.py`: `All 214 source file(s) carry the license header.`
- Every changed file decodes as UTF-8 (`iconv -f UTF-8 -t UTF-8`).
- PHPUnit in the `ifirma-cov:8.2` image: `OK (4140 tests, 16519 assertions)`.
- PHPUnit in the PS 8.1 container: 4140 tests, 2 failures, the two known float cases above.
- `build/tools/gate.sh ... d2685f9` (diff-cover against the base commit): 22 files, all `(100%)`,
  `Total: 1198 lines`, `Missing: 0 lines`, `GATE: PASS`. Coverage exclusions added in this change, each with its
  reason in the code: the thin cURL calls of `IfirmaHttpTransport::request()` (options(), writeChunk() and
  outcome() carry the tested logic), the request-ending twins `statusHeader()`/`textResponse()` of the admin
  controller and `notFound()`/`tooManyRequests()`/`serverError()` of the front receipt controller (the tests
  override them).
- `tools/generate_pl_translations.php`: 538 entries written, nothing unmapped; `TranslationCoverageTest` green.
- `npm run build`: `dist/ifirma-1.4.1.zip` (367.8 kB, the `views/js` files included); `npm run verify:package`: `ok`.
- `graphify update .`: 7870 nodes, 19775 edges, 370 communities.

## Upgrade on the Docker shops (task 9.2, `tests/smoke/upgrade-150-check.php`)

| | PrestaShop 8.1.7 (`ifirma_prestashop`) | PrestaShop 9.1.4 (`ifirma91_prestashop`) |
|---|---|---|
| Tab before | id 140, `id_parent` -1, "iFirma" | id 119, `id_parent` -1, "iFirma" |
| Tab after | id 140, `id_parent` 3 (`AdminParentOrders`), "iFirma documents" in both languages | id 119, `id_parent` 3, "iFirma documents" |
| Profile-1 access rows | the four `ROLE_MOD_TAB_ADMINIFIRMA_*` rows, unchanged | unchanged |
| `actionAdminControllerSetMedia` | 0 → 1 → 1 (second run) | 0 → 1 → 1 |
| `last_download_at`, `idx_date_upd`, `IFIRMA_AUTH_FAILURE` | absent → present | absent → present |
| Script summary | `12 passed, 0 failed`, `UPGRADE_150_CHECK_OK` | `12 passed, 0 failed`, `UPGRADE_150_CHECK_OK` |

The shops still record module version 1.4.1 in `ps_module`; the release step (`npm version minor`) is the
workflow follow-up of tasks.md.

## Smoke scripts (tasks 9.1 and 5.4)

| Script | PrestaShop 8.1.7 | PrestaShop 9.1.4 |
|---|---|---|
| `tests/smoke/run.php` (scenarios J bulk runner, K issue feed, L permissions, M download throttle added) | `passed 126, failed 0, skipped 1` (cron over HTTP, as before) | `passed 126, failed 0, skipped 1` |
| `tests/smoke/order-grid-check.php` (third select, icon cell, Issue anchor for SuperAdmin, empty cell for a profile without roles) | `20 passed, 0 failed`, `ORDER_GRID_CHECK_OK` | `20 passed, 0 failed`, `ORDER_GRID_CHECK_OK` |
| `tests/smoke/contractor-link-check.php` (regression) | `IMPORT_PROBE_OK` | `IMPORT_PROBE_OK` |

Finding during J: the first retry run ended `suspected` for the second seeded order because it had the same buyer
and total as the first, now issued, document; the duplicate guard was right, and the scenario now gives the
second order a different total.

## Scripts (tasks 4.4, 5.3, 6.4, jsdom harness outside the repo)

`node --check` on both scripts: OK. A jsdom harness (scratchpad, not shipped) ran 57 checks, 0 failed: tab and
pane insertion on the Symfony and the legacy bell, the standalone button without a bell, nothing on a page
without a header, text-only rendering of entries, the seen mark on `shown.bs.tab`, the bell total adjusted after
the core rewrote it, the capture-phase Issue click not reaching the row handler, batches of the configured size
with `remaining` re-queued first, `final=1` carrying the totals, the stop on `rate_limited`, the non-JSON answer,
the native bulk entries of the documents list rewired.

## Independent review after the first commit (7302756)

A workflow of nine finders (one per area: permissions, bulk, notifications, API hardening, endpoint hardening,
list and upgrade, spec conformance of the new and of the modified capabilities, PHP 7.2 and PrestaShop 8/9
compatibility with translations and docs) reported 41 findings; each was judged by three independent refuters
(correctness, impact, reproduction), 39 survived, 2 were refuted (the multishop rate-counter rows, the install
abort order). De-duplicated to 27 items in `build/review-fixes.md`, all fixed in a second commit:

- documents list: the Needs-attention preset is carried by the sort, paging and filter URLs; the generic legacy
  list actions (save, update, delete, status, export, bulk delete and enable/disable) are refused; Download PDF
  per row; array ids read as 0;
- bulk runs: one log entry per run, totals whitelisted, `already_issued` counted as skipped, a refreshed document
  missing in iFirma reported as failed, a locally refused resend (rate guard or cool-down) leaves the record
  untouched and stops the run through a result flag; a transport failure of the in-flight batch is reported as
  unknown rather than not attempted;
- permissions: the customer-page panel hides Register, Link and Unlink without the Customers edit right; the
  refusal answers 403 like the other denials;
- settings and client: a third password input for the rachunek key, even-length hex keys, base URLs with a query
  or fragment refused, public IPv6 literals never treated as local, a stored URL that fails the rule replaced by
  the default at run time with a warning, a cool-down mark dated in the future treated as expired;
- data requests: note fields kept as HTML-typed so line breaks survive, cool-down measured from the request's
  own dates (not the admin's approve or reject), country required;
- download throttle: the per-document slot is claimed by one conditional UPDATE;
- notifications script: own writes flagged for the total observer, stale feed answers after the seen mark
  ignored, fallback panel CSS;
- tab named "Dokumenty iFirma" for Polish; the link action never returns raw exception text; docs and the two
  spec scenarios about the guard refusal aligned.

Caught by the smoke run after the fixes, not by the unit suite: `Db::getRow()` appends its own `LIMIT 1`, so the
new cool-down query with an explicit `LIMIT 1` was a MariaDB syntax error (fixed); the daily cap of ten requests
then refused the smoke customer's eleventh request, so scenario C now clears that customer's requests first.

Accepted without a code change: the seen mark's one-second resolution, a failed PDF fetch consuming the 30 s
window, the client's English pause message (like its other technical errors).

Gate after the fix wave (manager's run): `OK (4235 tests, 16904 assertions)` in the 8.2 image, `Total: 1212 lines`,
`Missing: 0 lines`, `GATE: PASS`; PS 8.1 container 4235 tests with the two known float cases; `LINT72 rc=0`;
licence check 242 files; `tests/smoke/run.php` `passed 184, failed 0, skipped 1` and `order-grid-check.php`
`ORDER_GRID_CHECK_OK` on both shops; `upgrade-150-check.php` 12/12 on both. A timing-sensitive cool-down test
(two clocks built at different instants) was pinned to one base instant per test.

## Browser checks (tasks 9.3, 9.2 browser part, 4.3, 4.4, 5.3, 6.4)

Puppeteer (headless Chrome) against both shops; screenshots in the git-ignored `build/shots/`.

| Check | 8.1.7 | 9.1.4 |
|---|---|---|
| Bell has the iFirma tab and pane on the dashboard, the Orders list and the module page; entries carry reference, kind, number, time; footer links to the preset; the seeded error text renders as text (no `img` element) | PASS | PASS |
| Unseen counter: tab count and bell total include the unseen issues before the tab is opened, tab count empty afterwards | PASS | PASS |
| Permissions page lists "iFirma documents" under Orders with View/Add/Edit/Delete | PASS | PASS |
| Salesman profile with the tab's View+Add (and the module's View, see below): panel shows Create but no manage buttons, Download PDF present, Orders list shows the Issue action, bell shows the tab, documents list opens | PASS | PASS |
| Issue from the Orders list (synthetic click): confirm, pipeline message, cell becomes the icon, URL unchanged | PASS | PASS |
| Issue link clicked with a real mouse stays on the list | FAIL, fixed below | FAIL, fixed below |
| Click on the iFirma cell outside the icon opens the order | PASS | PASS |
| Bulk "Issue iFirma documents" on six orders: progress modal, six summary rows, two Skipped, reload on close | PASS | not run |
| Documents list renders (columns, filters, paging, "Needs attention"), status filter, preset | PASS | PASS (render) |
| Bulk Retry issuance on the seeded error and unknown rows (Issued / Suspected duplicate) | PASS | not run |
| Row button Send again | FAIL (inert), fixed below | not run |
| Bulk Send again on a real mock document with delivery failed: Sent | PASS | not run |
| Rate-guard stop (`IFIRMA_RATE_MAX_PER_MIN=1`): rows "Not attempted (rate limited)", stop message | PASS | not run |
| Settings: password key inputs, remove boxes, no key in the page source, cool-down banner, save clears the banner and keeps the key | PASS | PASS |

Facts learned and recorded:

- PrestaShop runs a module's back-office hooks only for profiles holding the module's own View permission
  (`ROLE_MOD_MODULE_IFIRMA_READ`, Team > Permissions > Modules); with the tab's View and Add alone the Salesman
  saw no panel, column or bell tab, while the AJAX actions followed the tab levels. Documented in
  configuration.md, upgrading.md, the access-control spec and design D1.
- Both test shops store `IFIRMA_SYNC_INTERVAL_MIN = 0` (not set by this change; the runtime reads 0 as 15), which
  the settings validator refuses on an unchanged save; the check set 15 temporarily and restored 0.
- The "Needs attention" preset also listed the shops' own records marked missing in iFirma (hundreds, left by the
  mock resets of the smoke runs), as designed.

Defects found and fixed in a follow-up wave (F1): a real mouse click on the Issue link also navigated to the order
(the core row handler acts on mousedown/mouseup; the script now stops those events too); the documents-list row
buttons had no handler; translated messages reached the browser with HTML entities (`&quot;`) and are now decoded
in the JSON envelope; the core notification acknowledgement answered 500 for our tab type `ifirma` on every bell
click (the request is now aborted on our side); cosmetic: the modal header after a finished run and the width of
the documents-list table.

Re-check after the fixes (puppeteer, both shops unless stated):

| Check | 8.1.7 | 9.1.4 |
|---|---|---|
| Real mouse click on the Issue link: confirm, message, icon, URL stays on the list, no request to the order page | PASS | PASS |
| Documents-list row buttons Send again and Refresh: confirm, modal, one-row summary (`Sent`, `Refreshed`) | PASS | not run |
| Messages without HTML entities (`Stopped at the "guard" step (suspected): …` in the alert and the summary) | PASS | PASS |
| Bell, iFirma tab, bell again: one core acknowledgement (status 200, the order tab), no 500, no console error; our seen-mark POST still sent | PASS | PASS |
| Modal header reads "Bulk run summary" after a finished run | PASS | not run |
| Documents list at 1500 px: action buttons on one line, the table scrolls inside its panel | PASS | PASS |

Known cosmetic limitation: at a 1500 px viewport the legacy list is wider than the panel (twelve columns), so the
filter row's Search button and the Actions header sit in the table's own horizontal scroll area; Enter in a filter
field submits the filters, and wider screens show the whole table. Not changed in this release.

Unit suite after the fixes: 4142 tests (8.1 container, the two known float cases only). Final gate run by the
manager after the last change: `OK (4142 tests, 16529 assertions)` in the 8.2 image, `Total: 1209 lines`,
`Missing: 0 lines`, `GATE: PASS (100% of changed lines covered vs d2685f9)`; `graphify update .` afterwards:
7902 nodes, 19849 edges, 377 communities.
