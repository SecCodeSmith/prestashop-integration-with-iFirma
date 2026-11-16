# Proposal

## Why

On the live shop a customer cannot reach their invoices: clicking the iFirma tile in "My account" does nothing, so the list with the PDF links never opens (the back-office download of the same document works). The tile keeps its list in a hidden Bootstrap 4 `collapse` block that only opens when the theme loads Bootstrap 4's collapse script (`data-toggle="collapse"`). Hummingbird (Bootstrap 5, `data-bs-toggle`) and many custom themes don't load that script, so the links are unreachable. The owner also wants the customer to get the iFirma invoice from the place PrestaShop normally offers it: the "Invoice" PDF link in Order history and order details.

## What Changes

- **Bug fix:** the "My account" tile no longer opens a hidden list. It is a plain link, styled like the other account tiles, to a new module page that lists the customer's documents with their download links. The page needs no theme script, so it works in Classic, Hummingbird and custom themes.
- The tile and the page are titled "My invoices" in the English source string, translated as **"Moje Faktury"** in Polish (replacing "Moje dokumenty iFirma"). The tile shows the number of documents as before.
- New page "My invoices" (logged-in customers only, read-only): one row per issued document with number, issue date, status, the "issued outside the shop" badge, and a download link where the order page would show one. It also shows the pending data-change request count and a short message when there is nothing to list. Downloads go through the existing protected download, with no new download path.
- New: when a customer opens PrestaShop's own invoice PDF link (`pdf-invoice`) for an order the module handles (it has an iFirma document record in any state, or a pending data-change request), the shop serves the iFirma document instead of generating its own invoice. A logged-in owner is sent to the existing protected download. If the iFirma document can't be handed out yet (not issued, held, verifying, failed, waiting for KSeF, removed in iFirma), or the requester is a guest using the order's secure key, the shop answers with a short "not available yet" text and never generates the PrestaShop invoice. Orders the module doesn't handle (for example, orders from before it was installed) keep PrestaShop's invoice unchanged.
- New setting "Serve the iFirma document from the built-in invoice link" (default on, also switched on by the upgrade, as the owner asked). When PrestaShop invoices are disabled (`PS_INVOICE` off), the configuration page warns that PrestaShop shows no invoice link at all.
- **BREAKING (guests):** with the setting on, a guest who opens the built-in invoice link (secure key) for an order with an iFirma document no longer gets PrestaShop's own invoice. This matches the existing rule that guests get no iFirma download link.
- Upgrade script registers the new hook on existing installs and stores the setting.

## Capabilities

### New Capabilities
- `customer-native-invoice-link`: how PrestaShop's built-in customer invoice PDF link behaves for orders that have an iFirma document, including the on/off setting and its configuration warning.

### Modified Capabilities
- `customer-receipt-visibility`: "Account block" changes from a collapsible list inside the tile to a tile that links to a "My invoices" page. New requirements cover the page itself and say that the account entry must work without any theme script.

## Impact

- Code: `views/templates/hook/customer_account.tpl` (tile becomes a link), new `controllers/front/documents.php` plus `views/templates/front/documents.tpl`, `classes/IfirmaCustomerView.php` (page view model next to `forAccount()`), `views/css/ifirma.css`, `ifirma.php` (hook `actionPDFInvoiceRender`, wiring only), a new policy class that decides the built-in invoice link's outcome, `classes/IfirmaSettings.php` with the configuration form, `translations/pl.php`, new `upgrade/upgrade-1.11.0.php`.
- No new table, column or iFirma request. One new hook registration and one new configuration key.
- Tests: unit tests for the tile, the page, the view model, the invoice-link policy and its negative cases (foreign order, guest, waiting document, no document), `ConfigurationKeysTest`, the settings golden fixture, and a new smoke scenario T (account tile to page to download; built-in invoice link on an order with and without an iFirma document).
- Docs: `docs/usage.md`, `docs/configuration.md`, `docs/upgrading.md`, `docs/troubleshooting.md` ("the invoices tile does nothing").
- Red team required before release: a new customer-facing page, a new hook that answers a core customer endpoint, and an upgrade script.
- Overlap with in-flight changes: `add-mark-sent-and-download-after-delivery` also modifies "Account block" (link only for sent documents). Whichever change is archived second must restate that requirement with both changes applied. Its sent rule then applies to the "My invoices" page and to the built-in invoice link, because both go through the shared view model and the protected download. `add-document-pdf-store` changes only where the PDF bytes come from and doesn't conflict.
- Version: written against the minor release the Release workflow will produce (1.11.0). No version strings are edited by hand.
