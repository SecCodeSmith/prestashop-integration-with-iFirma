# Verification record

Recorded 2026-11-06 while applying the change on master (one commit per task).

## Commits

| Task | Commit | Subject |
|---|---|---|
| 1.1 | e3041c9 | fix: give the customer-page panel its own column so it spans the hook row |
| 2.1 | 5e90303 | feat: order-grid indicator class for the iFirma column |
| 4.1 | a41624c | feat: upgrade-1.4.0 registers the three order-grid hooks |
| 4.2 | 008fd25 | docs: Orders list column, upgrade to 1.4.0 and the customer-panel layout |
| 3.1, 3.2 | dcc2f21 | feat: iFirma column on the Orders list through the three order-grid hooks |
| 5.1 | 48f1129 | test: order-grid smoke check on the Docker shop |

## Finding during 5.1 (fixed before 3.1 was committed)

The first version of the presenter hook accepted only a PHP array under `presented_grid.data.records`.
PrestaShop 8.1.7 and 9.1.4 hand a `RecordCollection` object there, so the hook returned early, the
column stayed unfilled and the Orders list answered 500 ("Key ifirma_document does not exist").
The hook now takes the rows out with `all()`, fills them and hands back a new collection of the same
class; design.md D1 context was corrected. A unit test with a fake collection covers it.

## 5.3 gate (after the last commit)

- `build/tools/lint72.sh` (php:7.2-cli-alpine, every tracked shipped .php): `LINT72 rc=0`
- `python tools/check_license_headers.py`: `All 214 source file(s) carry the license header.`
- PHPUnit in the `ifirma-cov:8.2` image: `OK (3551 tests, 14526 assertions)`
- PHPUnit in the PS 8.1 container (PHP 8.1): 3551 tests, 2 failures, both the pre-existing
  `UpdateStatusPaidTest` float-formatting cases (`123.46` vs `123.45999999999999`); they fail there
  before this change and pass in the 8.2 image and in CI. Not touched.
- `build/tools/gate.sh ... 13fb1e0` (diff-cover against the base commit of the change):
  `classes/ifirmaordergridindicator.php (100%)`, `ifirma.php (100%)`, 51 changed lines, 0 missing.
- `graphify update .`: 6138 nodes, 15723 edges, 309 communities.

## 5.1 smoke check (`tests/smoke/order-grid-check.php`)

| Shop | Result |
|---|---|
| PrestaShop 8.1.7 (`ifirma_prestashop`) | 12 passed, 0 failed, 1 skipped (mock keeps no request log); `ORDER_GRID_CHECK_OK` |
| PrestaShop 9.1.4 (`ifirma91_prestashop`) | 11 passed, 0 failed, 2 skipped (same, plus no order without a receipt row on that shop); `ORDER_GRID_CHECK_OK` |

Checks that passed on both: hooks registered, column `ifirma_document` right before `actions`
(positions 10/11), issued order's cell `<i class="material-icons ifirma-grid-icon text-info"
title="iFirma: 1/2026 (Issued)">receipt_long</i>`, grid total equal to `COUNT(*) FROM ps_orders`
(347 and 66), every order presented. On 8.1 the order without a record (327) has an empty cell.

## 5.2 and 1.2 visual check (headless Chrome, `build/shots/*.png`)

Logged into the back office of both shops with puppeteer and measured the DOM:

- Orders list, both shops: headers `ID, Reference, New client, Delivery, Customer, Total, Payment,
  Status, Date, iFirma, Actions`; icon titles `iFirma: 1/2026 (Issued)` / `(Paid)` /
  `iFirma: 2/2026 (Issued)`; paid icons carry `text-success`, others `text-info`. Clicking the
  iFirma cell of the first row opened `/sell/orders/347/view` (8.1) and `/sell/orders/66/view` (9.1).
  Totals unchanged (347 / 66). No `.ifirma-grid-icon` alignment rule was needed.
- Customer page (customer 1), both shops: the panel's parent is `col-12` inside `.row`; the panel's
  left edge equals the first card's left edge (225 px on 8.1, 224 px on 9.1) and its right edge
  equals the row's inner right edge; all eleven `th` cells share one width; every `td` of the link
  table starts on the same line as its `th` (offset 0 px).

## Ultrareview (cloud review of the branch, launched mid-apply)

Seven findings against the snapshot taken at launch time:

- Already fixed before the review finished (the snapshot predated commits dcc2f21, 48f1129, 1bafa2b):
  presenter hook and `RecordCollection`; missing `tests/Stubs/GridColumn.php`; missing
  `tests/smoke/order-grid-check.php`; graph not updated.
- Fixed after the review: the `width: 35%; white-space: nowrap` rule applied to every
  `.ifirma-cp-table th`, including the 9 header cells of the JS-built search results table; it is
  now scoped to `#ifirma-cp-link th, #ifirma-cp-counts th`. The indicator class hard-coded `'paid'`
  and `'error'`; it now uses `IfirmaReceipt::STATUS_PAID` and `STATUS_ERROR`. Re-verified: affected
  tests OK (145 tests), full suite 3551, gate 100%, smoke check OK on both shops, panel geometry
  unchanged (same left edge as the first card, label widths equal).
- Accepted as is: two correlated subselects per listed order. Both are lookups on `idx_order`
  bounded by the page size; merging them would need a join or a packed string split in PHP.

## Release note

Release this change as `npm version minor` (1.4.0); `upgrade/upgrade-1.4.0.php` runs only when the
module version is at least 1.4.0.
