# Tasks

Conventions for every task:
- Keep PHP 7.2-compatible syntax (`array()`, no typed properties) and the module licence header on every new or touched `.php`/`.tpl` file; `python tools/check_license_headers.py` must pass.
- Run `php -l` on every edited PHP file and the PHPUnit suite (`vendor/bin/phpunit`) after each group.
- Every new `l()` string goes into `tools/pl_translations_map.php`, and `translations/pl.php` is regenerated with `php tools/generate_pl_translations.php` in the same task; `tests/Unit/TranslationCoverageTest.php` enforces it.
- Run `graphify update .` after code changes (CLAUDE.md).

Design decisions are referenced as D1–D6 (design.md); scenarios by name from `specs/order-list-receipt-indicator/spec.md` and `specs/customer-contractor-panel/spec.md`.

## 1. Customer page panel layout (D5)

- [x] 1.1 Wrap the card of `views/templates/hook/admin_customer_panel.tpl` in `<div class="col-12">`, drop `mt-2`, remove the two inline `style="width:35%"` and add the `.ifirma-cp-table th { width: 35%; white-space: nowrap; }` rule to `views/css/ifirma.css`. Verify with a new `ModuleCustomerHooksTest` case that the rendered template string (through the `Module::display` stub record and a Smarty-free string check of the `.tpl` file) starts the panel with `col-12` and contains no `style="width`, and by eye on the test stack (`make up` in `test-stack`, PS 8.1 container `ifirma_prestashop`): the panel is as wide as the cards above it ("Panel spans the hook row") and each contractor value sits beside its label ("Contractor fields stay on one line").
- [x] 1.2 Repeat the visual check on a PrestaShop 9.x image (`prestashop/prestashop:9.2.x` is pulled locally; run it with the test-stack compose override or a one-off container) and note the result in the change's follow-up record. Verify: both PS versions show the full-width panel.

## 2. Grid indicator class (D2, D3)

- [x] 2.1 Create `classes/IfirmaOrderGridIndicator.php` with the status words it needs (`paid`, otherwise issued) and:
  - `selectSql($orderAlias)`: the two correlated subqueries (`ifirma_document_number`, `ifirma_document_status`) on the newest `ifirma_receipt` row of the order, number falling back to the identifier, guarded by `ifirma_id <> '' AND status <> 'error'`, table prefix from `_DB_PREFIX_`;
  - `cell(array $record, array $labels)`: `''` without a number, otherwise the escaped `<i class="material-icons ...">receipt_long</i>` with the `title` "iFirma: <number> (<state>)" and `text-success` for `paid`, `text-info` otherwise.

  Add `require_once` of the class in `ifirma.php` and extend `tests/Unit/ClassFileRequiresTest.php` if it lists the classes. Verify with new `tests/Unit/OrderGridIndicatorTest.php` covering "Issued receipt", "Paid document", "Number not stored yet", "Order without a record", "Failed issuance", "Held order" (as record inputs to `cell()`), "Markup in a document number" (escaped title), and that `selectSql('o')` names both aliases, orders by `id_ifirma_receipt DESC`, limits to 1 and carries the issued guard.

## 3. Grid hooks in the module class (D1)

- [x] 3.1 Add `hookActionOrderGridDefinitionModifier`, `hookActionOrderGridQueryBuilderModifier` and `hookActionOrderGridPresenterModifier` to `ifirma.php`: each returns early when `PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn` does not exist or the expected param is missing; the definition hook adds the `HtmlColumn('ifirma_document')` before `actions` (name `$this->l('iFirma')`, `sortable => false`, `clickable => true`); the query hook calls `addSelect()` on `search_query_builder` only; the presenter hook rewrites `presented_grid['data']['records'][*]['ifirma_document']` through `IfirmaOrderGridIndicator::cell()` with the state words from `adminStateLabels()`. Add the tooltip and column words to `tools/pl_translations_map.php` and regenerate `translations/pl.php`. Verify with `ModuleHooksTest` cases: the three hooks return without error and change nothing in the harness (no grid classes); the presenter hook, fed a records array by reference through a fake `presented_grid`, fills `ifirma_document` for an issued record and `''` for a bare one; `TranslationCoverageTest` passes.
- [x] 3.2 Register the three hooks in `install()`. Verify with the existing install test (or a new `ModuleHooksTest` case) that `Module::$hooks` lists them after `install()`.

## 4. Upgrade to 1.4.0 (D4)

- [x] 4.1 Create `upgrade/upgrade-1.4.0.php` (`upgrade_module_1_4_0`) that registers each of the three grid hooks only when it is not registered yet and returns true. Verify with `tests/Unit/UpgradeTest.php`: a new case runs it twice on a module with only the 1.2.0 hooks and ends with the three hooks registered exactly once ("Column shown after upgrade"); the chained path `1.1.0 → 1.2.0 → 1.4.0` still ends with the contractor map and the KSeF columns and the same 20 `ALTER`s; the comment that names the module version moves to 1.4.0.
- [x] 4.2 README: add the iFirma column to the back-office order section, an "Upgrading to 1.4.0" section (three hooks, no schema change, release level `minor`), and the customer-panel layout fix to the changelog. Verify the README builds no broken anchors (`grep -n "Upgrading to 1.4.0" README.md`) and the text matches the behaviour of groups 1–4.

## 5. Integration check on the Docker shop (D6)

- [x] 5.1 Create `tests/smoke/order-grid-check.php` in the style of `tests/smoke/contractor-link-check.php`: boot the kernel, seed one order with an issued receipt record and one without (reuse `createOrder()`/`seedDocument()` helpers or inline inserts into `ifirma_receipt`), get `prestashop.core.grid.factory.order`, build the grid with `OrderFilters::buildDefaults()`, present it with `prestashop.core.grid.presenter.grid_presenter`, and assert: the `ifirma_document` column sits before `actions`; the issued order's record holds the icon HTML with its number; the other order's record is `''`; the grid total equals `SELECT COUNT(*) FROM ps_orders`. Document the run command in `tests/smoke/README.md`. Verify by running it inside `ifirma_prestashop` (`wsl.exe -d Debian -- docker compose -f test-stack/docker-compose.yml exec -u www-data prestashop php /var/www/html/modules/ifirma/tests/smoke/order-grid-check.php`) and pasting the output into the change's follow-up record.
- [x] 5.2 Open the Orders list in the back office of the PS 8.1 container and of a PS 9.x container: the "iFirma" column shows the icon for the seeded order, the tooltip reads the number and state, the cell click opens the order ("Cell click opens the order"), paging and totals are unchanged ("Paging unchanged"). Record the result; add the `.ifirma-grid-icon` alignment rule to `ifirma.css` only if the icon misaligns.
- [x] 5.3 Full gate: `php -l` on every touched PHP file, `python tools/check_license_headers.py`, `vendor/bin/phpunit`, the diff-cover gate used for the previous changes, then `graphify update .`. Verify all pass and record the counts.

## Workflow follow-up

- Release this change with `npm version minor` (1.4.0) so `upgrade-1.4.0.php` runs on upgraded shops.
- Archive the change after the checks of group 5 are recorded.
