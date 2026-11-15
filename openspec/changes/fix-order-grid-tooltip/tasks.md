# Tasks

## 1. Red: tests

- [x] 1.1 Rewrite the expectations of the tests that encode the "<state>: <number>" headline and the plain-title cell (`OrderGridTooltipTest`, `OrderGridIndicatorTest`, `ModuleHooksTest`); add the scenario tests (issue date, fallback, KSeF order, tooltip attributes, inert markup). Verify: they fail on the current code.

## 2. Green

- [x] 2.1 `IfirmaOrderGridTooltip`: status, number, issue date, KSeF, delivery, unpaid.
- [x] 2.2 `IfirmaOrderGridIndicator`: `FIELD_ISSUE_DATE` select, `TOOLTIP_TEMPLATE`, `tooltipAttributes()`.
- [x] 2.3 Words in `ifirma.php`, `tools/pl_translations_map.php`, `translations/pl.php`; CSS in `views/css/ifirma-admin.css`.

## 3. Refactor, smoke, docs

- [x] 3.1 `tests/smoke/order-grid-check.php` asserts the new cell markup.
- [x] 3.2 `docs/usage.md`, `CHANGELOG.md`.
- [x] 3.3 Full suite, changed-line coverage gate, PHP 7.2 lint, `openspec validate fix-order-grid-tooltip`.
