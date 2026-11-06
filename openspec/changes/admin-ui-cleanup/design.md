# Design

## Context

See proposal.md - Why.

Observed in the running PrestaShop 8.1 and 9.2 images of the test stack:

- The Orders list is the Symfony grid `order` (`OrderGridDefinitionFactory::GRID_ID = 'order'`, columns `preview`, `orders_bulk`, `id_order`, `reference`, `new`, `customer`, `total_paid_tax_incl`, `payment`, `osname`, `date_add`, `actions`; `country_name`, `company` and `shop_name` are conditional). PrestaShop fires `actionOrderGridDefinitionModifier` (param `definition`), `actionOrderGridQueryBuilderModifier` (params `search_query_builder`, `count_query_builder`, `search_criteria`) and `actionOrderGridPresenterModifier` (param `presented_grid`, by reference; `data.records` is a `RecordCollection` object whose `all()` gives the row arrays and whose constructor takes them back, not a plain array; found by the smoke check on the Docker shops, where the array-only first version left the column unfilled and the list answered 500). The orders query aliases `orders` as `o`. The `HtmlColumn` type renders `record[field]|raw`; its base options are `sortable` (default true) and `clickable`.
- The customer page template renders `renderhook('displayAdminCustomers', ...)` as a direct child of `<div class="row">` (same in 8.1 and 9.2). The core `blockwishlist` module answers that hook with `<div class="col"><div class="card">...</div></div>`. Our `admin_customer_panel.tpl` answers with a bare `<div class="card mt-2 ifirma-cp">`, so the card is a flex item of the row with no column width and no gutter: that is the "broken field" the merchant sees.
- Module facts that shape the fix: `IfirmaReceipt::getByOrder()` takes the newest record of the order (`ORDER BY id_ifirma_receipt DESC`), and `isIssued()` is `ifirma_id != '' && status != 'error'`. The unit harness (`tests/Stubs`) has no Symfony grid classes. The smoke scripts in `tests/smoke` boot the Symfony kernel on the Docker shop, so the grid can be built there through the container service `prestashop.core.grid.factory.order`. Upgrade files run only when their version is above the shop's recorded module version and not above the module's own version; releases bump the version with `npm version <level>`.

## Goals / Non-Goals

**Goals:**

- One extra column on the Orders list, filled from `ifirma_receipt` with one correlated subquery, no extra query per row and no iFirma call.
- Keep the Symfony-dependent code to a few lines in `ifirma.php`; everything that decides what to show lives in a plain class that the PHPUnit harness runs.
- A customer-page panel that spans the hook row on PrestaShop 8 and 9 with a template-only fix.

**Non-Goals:**

- Filtering or sorting the Orders list by document state.
- Showing the KSeF state, the delivery state or the payment amount in the list.
- Changing the data, actions or wording of the customer-page panel.
- Supporting the legacy (non-Symfony) order list of PrestaShop 1.7; the module requires 8.0.0.

## Decisions

### D1. Three grid hooks, thin in the module class

`hookActionOrderGridDefinitionModifier` adds an `HtmlColumn('ifirma_document')` before `actions` with `field => 'ifirma_document'`, `sortable => false`, `clickable => true`, name `$this->l('iFirma')`. `hookActionOrderGridQueryBuilderModifier` calls `addSelect()` on `search_query_builder` only, with the fragment from D2; the count query is untouched so paging stays the same. `hookActionOrderGridPresenterModifier` walks `presented_grid['data']['records']` and sets `ifirma_document` to the cell HTML from D3. Each hook returns early when the Symfony column class does not exist (`class_exists(HtmlColumn::class)`), so the unit harness and a shop without the grid never fatal.

*Alternative:* build the icon HTML inside the SQL (`CONCAT(...)`) and skip the presenter hook. Rejected: escaping and translation would move into SQL, and the query builder hook would then carry display logic.

*Alternative:* a `DataColumn` with the number as text. Rejected: the merchant asked for an icon; a number column is wider and says less at a glance.

### D2. One correlated subquery for the latest issued record

New class `classes/IfirmaOrderGridIndicator.php` (framework-free). `selectSql($orderAlias)` returns two selects: `ifirma_document_number` and `ifirma_document_status`, each a subquery `SELECT ... FROM ps_ifirma_receipt r WHERE r.id_order = o.id_order ORDER BY r.id_ifirma_receipt DESC LIMIT 1`, with the number falling back to the identifier (`IF(r.ifirma_number <> '', r.ifirma_number, r.ifirma_id)`) and an "issued" guard mirroring `isIssued()` (`r.ifirma_id <> '' AND r.status <> 'error'`). Both subqueries read the newest record of the order, so an older `error` record under a newer issued one shows the icon ("Latest record wins"), and a newest record that is held or failed shows nothing. The `idx_order` index on `ifirma_receipt` makes each subquery an index lookup.

*Alternative:* `LEFT JOIN ifirma_receipt` with `MAX(id_ifirma_receipt)` grouping. Rejected: the orders query already has joins and no `GROUP BY`; adding one changes the count query semantics and every other module's join.

### D3. Cell HTML built in PHP, escaped, translated through the module

`IfirmaOrderGridIndicator::cell(array $record, array $labels)` returns `''` when `ifirma_document_number` is empty, otherwise `<i class="material-icons ifirma-grid-icon" title="iFirma: <number> (<state>)">receipt_long</i>`. The number and the state word go through `htmlspecialchars`. State words come from `adminStateLabels()` of the module (`Paid` for `paid`, `Issued` for everything else issued), the same words as the order panel badge. The icon class gets `text-success` for `paid` and `text-info` otherwise, matching the badge colours of the order panel. Material icons are loaded by the back office on every page, so no stylesheet is needed for the list; the small `.ifirma-grid-icon` rule (vertical alignment) goes into the existing `ifirma.css` only if the icon misaligns in the manual check.

### D4. Hook registration and the 1.4.0 upgrade

`install()` registers the three hooks. `upgrade/upgrade-1.4.0.php` registers each one only when `isRegisteredInHook()` is false, like `upgrade-1.2.0.php`. The file is named 1.4.0 because PrestaShop runs an upgrade file only when its version is not above the module's, so this change has to ship in a `minor` release; a `patch` release (1.3.3) would leave the hooks unregistered on upgraded shops. `UpgradeTest` gets the 1.4.0 cases and its version note moves from 1.3.1 to 1.4.0.

### D5. Customer panel: a column wrapper, not CSS

`admin_customer_panel.tpl` wraps its card in `<div class="col-12">` and drops `mt-2` (the row already spaces its children; the core module uses no margin). `col-12` instead of `col` so a second module answering the same hook lands on its own line under a panel that is this wide, instead of sharing the row with it. The two `th style="width:35%"` inline widths move to one `.ifirma-cp-table th` rule (`width: 35%; white-space: nowrap`) so the labels never wrap and both tables line up; the `<code>` identifier keeps its style. No JavaScript or data change.

*Alternative:* set `width: 100%` on the card with CSS. Rejected: it keeps the card as a flex item without the gutter padding, so the edges still differ from the other cards.

### D6. Verification without a browser test suite

The unit tests cover the decisions of D2 and D3 and the registration of D4. The grid wiring (D1) cannot run in the harness, so a new scripted check `tests/smoke/order-grid-check.php`, run like the other smoke scripts inside the Docker shop, gets `prestashop.core.grid.factory.order` from the Symfony container, builds the grid with the default order filters, presents it with the grid presenter and asserts: the column `ifirma_document` is present before `actions`; the record of an order with an issued receipt holds the icon HTML with its number; the record of an order without one holds `''`; the reported total equals the count without the module hooks. The look of both screens is confirmed by eye on the PS 8.1 and PS 9.x containers of the test stack.

## Risks / Trade-offs

- [The presenter hook receives `presented_grid` by reference; a PrestaShop release could change the record shape] → the hook checks `isset($params['presented_grid']['data']['records'])` and leaves the grid alone otherwise; the smoke check catches it on the next PS image.
- [Two correlated subqueries per listed row] → both are index lookups on `idx_order`; the list is paged (50 rows by default) and the count query is untouched.
- [A shop that lists orders of several shops] → the subquery matches on `id_order` only, which is unique across shops; `id_shop` is not needed.
- [`l()` in a grid hook runs outside a module template] → `Module::l()` works in any back-office request; the same is done today in `hookDisplayAdminCustomers`.
- [The column name "iFirma" and the tooltip words must be in `translations/pl.php`] → `TranslationCoverageTest` fails until they are added through `tools/pl_translations_map.php`.
- [Releasing as `patch` by habit] → the README upgrade note and the tasks say `minor`; `UpgradeTest` documents why.

## Migration Plan

1. Release as `npm version minor` (1.4.0). The release flow runs the checks and builds the package.
2. On an upgraded shop, PrestaShop runs `upgrade-1.4.0.php`, which registers the three hooks; the next Orders list shows the column. No schema change, nothing to roll back in the database.
3. Rollback: reinstall the previous package; the three hooks stay registered but point at methods that no longer exist, which PrestaShop ignores. Unregistering them by hand (Module Manager, or `unregisterHook`) is optional.
