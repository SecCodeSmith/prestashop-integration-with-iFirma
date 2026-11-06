# Proposal

## Why

Two back-office screens of the module look wrong or say too little. On the Orders list the merchant cannot see which orders already have an iFirma document; they open each order to find out. On the customer page the iFirma panel is rendered as a bare card inside PrestaShop's Bootstrap row, so it does not take the width of the other cards and its contractor fields (identifier, name, link type, "In iFirma") sit in a squeezed, misaligned box. Both are visible on every PrestaShop 8 and 9 shop that runs the module.

## What Changes

- **Receipt indicator in the Orders list.** The back-office Orders list (the Symfony order grid of PrestaShop 8 and 9) gets an **iFirma** column. An order whose latest module record is an issued document (it has an iFirma identifier and is not in `error`) shows a receipt icon; the document number and state are in the icon's tooltip. Orders without an issued document show an empty cell. The cell is clickable like the rest of the row and leads to the order page. The column adds no filter and no sort, and the orders query stays a single query (the document is read by a subquery on the module's own table). The column is rendered from local data only, with no iFirma call.
- **Hook registration.** The grid needs three new hooks (`actionOrderGridDefinitionModifier`, `actionOrderGridQueryBuilderModifier`, `actionOrderGridPresenterModifier`). Fresh installs register them in `install()`; existing installs get them through a new idempotent upgrade script. The upgrade file is named for the next minor version (1.4.0), so this change has to be released as a `minor` bump.
- **Customer page panel layout.** The iFirma panel on the back-office customer page is wrapped in a full-width Bootstrap column (`col-12`), as the core modules do for this hook, so the card spans the hook row and lines up with the other cards of the page. The contractor-link table and the local-count table get the same label width and no longer wrap their values under the labels. No data, action or wording of the panel changes.
- **Docs and translations.** README gets the column in the order-page section, an "Upgrading to 1.4.0" note, and the panel fix in the changelog. Polish translations for the new column header and tooltip words.

## Capabilities

### New Capabilities

- `order-list-receipt-indicator`: what the back-office Orders list shows about the iFirma document of each order: when the icon appears, what its tooltip says, what happens to orders without a document, and that the list never calls iFirma.

### Modified Capabilities

- `customer-contractor-panel`: "Panel on the customer page" gains the layout rule: the panel takes the full width of the hook area and aligns with the page's own cards, on PrestaShop 8 and 9.

## Impact

- **Code:** `ifirma.php` (hook registration, three thin grid hook methods), new `classes/IfirmaOrderGridIndicator.php` (the subquery fragment and the cell HTML, framework-free), `views/templates/hook/admin_customer_panel.tpl` (column wrapper), `views/css/ifirma.css` (panel table widths), new `upgrade/upgrade-1.4.0.php`, `translations/pl.php` + `tools/pl_translations_map.php`, README.
- **Tests:** new `tests/Unit/OrderGridIndicatorTest.php`; `tests/Unit/ModuleHooksTest.php` (the hooks are no-ops without the grid classes and hand the right pieces to the indicator), `tests/Unit/UpgradeTest.php` (1.4.0 registers the three hooks once), `tests/Unit/TranslationCoverageTest.php` (new strings). A new scripted check `tests/smoke/order-grid-check.php` builds and presents the order grid through the Symfony container on the Docker shop and asserts the column and the icon cell; a manual look at the Orders list and the customer page on PrestaShop 8.1 and 9.x closes the visual part.
- **Database:** no schema change. One correlated subquery per listed order on `ifirma_receipt`, which already has an index on `id_order`.
- **iFirma API:** no new calls.
- **Behaviour visible to merchants:** a new column on the Orders list; the customer-page panel spans the full width.
