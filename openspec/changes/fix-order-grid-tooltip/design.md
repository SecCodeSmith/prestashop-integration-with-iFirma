# Design

## Context

Facts found in 1.10.2. `IfirmaOrderGridIndicator::cell()` renders `<i class="material-icons ifirma-grid-icon" title="...">` for an issued document and `<a class="js-ifirma-issue" title="...">` otherwise; the title is the lines of `IfirmaOrderGridTooltip::lines()` joined with `\n` and escaped.

PrestaShop 8.1.7 and 9.1.4 both run `jQuery('[data-toggle="pstooltip"]').pstooltip({container:"body",boundary:"window"})` on every new-theme back-office page (`themes/new-theme/public/main.bundle.js`). Checked in both images: `jQuery.fn.pstooltip = jQuery.fn.tooltip` (the Bootstrap 4 tooltip under another name), its default template is `<div class="tooltip" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>`, `html` defaults to false, `sanitize` is on, and the sanitizer's allow-list admits `class` on every element. Bootstrap merges the element's `data-*` attributes into the options, so `data-template` is honoured.

## Decisions

- **Text, not HTML.** `html` stays off, so the tooltip body is set as text and nothing in it can become markup. The line breaks come from the stylesheet: the template carries the class `ifirma-grid-tooltip` and `views/css/ifirma-admin.css` sets `white-space: pre-line` on its `.tooltip-inner`. Rejected: `data-html="true"` with every line escaped and joined with `<br>` (works, but a double-escaping layer for no gain, and one missed escape is an injection).
- **Native fallback.** The `title` attribute stays with the same text. Bootstrap moves it to `data-original-title` on init; a page where the init does not run still shows the native tooltip.
- **One place for the attributes.** `IfirmaOrderGridIndicator::tooltipAttributes()` is used by the icon and by the Issue anchor. The template is the constant `TOOLTIP_TEMPLATE`, escaped for the attribute.
- **Lines stay in `IfirmaOrderGridTooltip`** (pure: words in, lines out). New words `document_number` ("Number: %s") and `document_issue_date` ("Issue date: %s") join the words built in `ifirma.php`.
- **Issue date in SQL.** `IF(<issued>, COALESCE(NULLIF(r.issue_date, '0000-00-00'), DATE(r.date_add)), '')`: the same fallback `IfirmaReceipt` uses elsewhere. Appended to `selectSql()` so existing indexes of the select list stay. Empty string means "no line".
- **KSeF line unchanged:** shown when the document has a KSeF state or waits for it; otherwise nothing.

## Risks

- A theme or page that does not run the PrestaShop tooltip init shows the native tooltip: the line breaks survive in browsers that render `\n` in `title`.
- The tooltip text is capped by `max-width` in CSS; a long KSeF number wraps.
