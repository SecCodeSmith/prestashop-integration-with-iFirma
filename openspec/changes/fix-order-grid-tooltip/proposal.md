# Proposal

## Why

Hovering the iFirma icon in the back-office Orders list shows the browser's plain `title` tooltip, unlike the rest of the back office (PrestaShop's grid actions use its own tooltip), and the tooltip lacks the issue date of the document. The first line also mixes two facts (state and number: "Faktura wysłana: FV 12/2026"), which is hard to scan.

## What Changes

- **One fact per line** for an issued document: the status (kind and furthest state), "Numer: <number>", "Data wystawienia: <date>", the KSeF line, then the delivery lines and "unpaid". The number is no longer glued to the status.
- **Issue date:** the grid reads `issue_date` of the newest record, falling back to the date of the record's creation when it is NULL or `0000-00-00`; the line is left out when neither exists. New select alias `ifirma_document_issue_date`, appended to `IfirmaOrderGridIndicator::selectSql()`.
- **PrestaShop's tooltip** for the issued icon and the Issue anchor: `data-toggle="pstooltip" data-placement="top"` plus a Bootstrap template carrying the class `ifirma-grid-tooltip` (`data-template`). The plain `title` stays as a fallback with the same text. `html` stays off; the stylesheet keeps the line breaks (`white-space: pre-line`).
- New words "Number: %s" and "Issue date: %s" (Polish "Numer: %s", "Data wystawienia: %s").
- The not-issued states keep their text.
- No contract change: no config key, hook, DB column or mail template changes; the new select alias is additive. No upgrade script.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `order-list-receipt-indicator`: "Receipt icon for an issued document" is modified (line layout, issue date); "Cell tooltip uses the back-office tooltip" is added.

## Impact

- `classes/IfirmaOrderGridTooltip.php`, `classes/IfirmaOrderGridIndicator.php`, `ifirma.php` (words), `translations/pl.php`, `tools/pl_translations_map.php`, `views/css/ifirma-admin.css`.
- Tests: `OrderGridTooltipTest`, `OrderGridIndicatorTest`, `ModuleHooksTest`; smoke `tests/smoke/order-grid-check.php`.
- Docs: `docs/usage.md`, `CHANGELOG.md`.
