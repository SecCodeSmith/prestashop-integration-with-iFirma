# Design

## Context

- `hookDisplayCustomerAccount()` (`ifirma.php`) renders `views/templates/hook/customer_account.tpl`. The tile is an `<a data-toggle="collapse" href="#ifirma-receipts-list">` and the list is `<ul class="collapse">`. Bootstrap 4's collapse script is the only thing that opens it. Classic bundles that script in `theme.js`. Hummingbird uses Bootstrap 5 (`data-bs-toggle`), and many custom themes ship no collapse script, so the click does nothing and the PDF links stay hidden. The back-office download and the order-detail button (`order_detail.tpl`) are not affected.
- `IfirmaCustomerView::forAccount()` already builds the account rows (number, status, label, issue date, external flag, download link). `buildDocument()` builds the order-page document. Both apply the same "download link or none" rule (issued, not awaiting KSeF, has a token), written out twice.
- `controllers/front/receipt.php` is the only customer PDF path. It checks login, token, ownership and the KSeF wait, then applies the day cap, throttle and download budget.
- PrestaShop's `PdfInvoiceController` checks `PS_INVOICE`, that the order exists, and ownership (customer id, or `secure_key` for guests) in `postProcess()`. Then, in `display()`, it runs `Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => $order->getInvoicesCollection()])` before it renders its own PDF. The hook has no return channel, so a module can take over only by ending the request. The link in Order history and order details is shown only when `PS_INVOICE` is on, the order state allows invoices and the order has an `OrderInvoice` row (`HistoryController::getUrlToInvoice()`).

## Goals / Non-Goals

**Goals:**
- A customer reaches their documents from "My account" in any theme, with or without JavaScript.
- The built-in invoice link hands out the iFirma document through the existing protected download, so there is still one download path and one set of limits.
- One rule decides "this document can be handed to the customer". The order page, the account entry, the new page and the built-in link all use it, and the sent rule of `add-mark-sent-and-download-after-delivery` later plugs into that same place.

**Non-Goals:**
- Making PrestaShop show its invoice link when `PS_INVOICE` is off, or adding an iFirma column to the Order history table (Classic has no per-row hook there). The module's order-detail button and the new page cover those shops.
- A guest download of iFirma documents. Guests still get the summary only.
- The back-office Orders > Invoices page. The owner chose the customer link only.
- Changing the order-detail block (`order_detail.tpl`), which works.

## Decisions

### D1. The account tile links to a module page
The tile becomes a plain `<a href="{$ifirma_account.page_url}">` that reads just "My invoices" (no count, like the core tiles). Its markup follows the active theme, as the core modules do: Hummingbird and its child themes get the account-menu link (`account-menu__link`, `customer_account_hummingbird.tpl`), every other theme the Classic tile with the column classes on the `<a>` itself and no wrapper (`customer_account.tpl`). `IfirmaAccountLinkTemplate::forTheme()` makes the choice from the theme name and its parent name, which `ifirma.php` reads from the shop's theme (`getName()` and `get('parent')`, falling back to `theme_name` on older 1.7). Both templates keep `id="ifirma-account-block"`. A new front controller, `controllers/front/documents.php` (`IfirmaDocumentsModuleFrontController`), renders `views/templates/front/documents.tpl`, which extends `customer/page.tpl`. Classic, Hummingbird and 1.7 themes all provide that layout, so the page gets the account sidebar and breadcrumb for free.
- *Alternatives:* `<details>/<summary>` inside the tile (no script, but a long list breaks the tile grid). Adding `data-bs-toggle` next to `data-toggle` (still needs a theme script and fails in custom themes). Always-open list (clutters the account home). The owner chose the page.

### D2. The page controller is glue
`IfirmaDocumentsModuleFrontController` sets `$ssl = true` and in `initContent()`:
1. If the customer is not logged in, it redirects to `authentication` with `back` set to this page. The check is explicit, like `receipt.php::redirectToLogin()`, instead of relying on `$auth`, so the unit harness can drive it through an overriding twin.
2. It reads rows with `IfirmaReceipt::getByCustomer((int) $this->context->customer->id)`, whose query already sorts newest first (`ORDER BY id_ifirma_receipt DESC`), plus `IfirmaDataRequest::countPendingByCustomer()`.
3. It assigns `IfirmaCustomerView::forAccount()` as `ifirma_account` and sets the template.

There is no `postProcess()` and no request input other than the session, so a forged `id_customer` parameter is never read. `getBreadcrumbLinks()` adds "Your account" (`addMyAccountToBreadcrumb()`, guarded with `method_exists` for older 1.7) and "My invoices". The template escapes every value with `|escape:'html':'UTF-8'`. Download links have no `target="_blank"`: the PDF is sent as an attachment, so the page stays and no blank tab is left open.

### D3. One "can be handed out" rule in `IfirmaCustomerView`
The rule duplicated in `forAccount()` and `buildDocument()` moves into one method that takes the status, KSeF fields and token and returns the download URL or `null`. `forAccount()`, `buildDocument()` and the built-in link policy (D5) call it, so all four entry points agree. `forAccount()` also returns `page_url` (the module link to `documents`). This is a refactor commit with no behaviour change, landed before the features.

### D4. The built-in invoice link uses `actionPDFInvoiceRender`
This is the only point after PrestaShop's own access checks and before it generates its PDF, and it exists in 1.7.x and 8.x. Task 4.1 confirms it on every PrestaShop tag the Smoke workflow covers, 9.x included.
- *Alternatives:* overriding `PdfInvoiceController` (overrides are fragile, conflict with other modules and are against the project's packaging rules). `displayPDFInvoice` (only adds content inside PrestaShop's own PDF, so two invoices would still exist). Rewriting the presented order's `invoice_url` (no stable hook on the presenter across 1.7 and 8.x, and it leaves the endpoint itself serving PrestaShop's invoice).
- Core also runs `actionPDFInvoiceRender` outside the customer link: in `PaymentModule::validateOrder()` and `OrderHistory::sendEmail()` when it attaches the invoice to a mail, in `AdminPdfController` and in the Symfony invoice PDF generators (checked in the 8.0.5 and 9.1.4 images, 2026-11-16). Ending one of those requests would break checkout, status changes or a back-office download, so the hook acts only while `Context::getContext()->controller` is a `PdfInvoiceControllerCore`, and only when the first invoice's order is the `id_order` core authorised for that request. Every other caller passes through untouched.
- Because PrestaShop's checks run first, a foreign order or a wrong `secure_key` is refused by core before the module looks at the order. The module therefore never reveals whether a foreign order is handled.

### D5. `IfirmaNativeInvoiceLink` decides, `ifirma.php` acts
`IfirmaNativeInvoiceLink` is a new policy class. It takes `decide($idOrder, $customerLoggedIn)` and returns an immutable `IfirmaNativeInvoiceOutcome`: `passThrough()`, `redirectTo($url)` or `notAvailable()`. It gets its collaborators in the constructor: the setting reader (`IfirmaSettings`), a document lookup (`IfirmaReceipt::getByOrder`), a pending-request lookup (`IfirmaDataRequest::getPendingByOrder`) and the D3 rule. In tests, each of these is replaced by a double.
- Setting off, or the order is not handled (no record and no pending request): `passThrough`.
- Requester not logged in (guest via `secure_key`): `notAvailable`.
- The D3 rule gives a URL: `redirectTo(url)`. Otherwise: `notAvailable`.

`hookActionPDFInvoiceRender($params)` only wires things up. It takes `id_order` from the first `OrderInvoice` in `$params['order_invoice_list']`. An empty or missing list, or a non-object, means `passThrough`. It casts the id to `(int)`, calls the policy, and then:
- `redirectTo`: `Tools::redirect($url)` (302 to the protected download, which re-checks everything and applies the limits).
- `notAvailable`: `IfirmaHttpOutput::notAvailable($message)` and `exit`.
- `passThrough`: return, and PrestaShop renders its own invoice.

`IfirmaHttpOutput::notAvailable()` is new. It sends a 404 status line, `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`, plus one generic translated sentence ("The invoice is not available yet. Please try again later or contact the shop."). It is tested through the existing header sink. The same text is used for every not-available reason, so the answer doesn't reveal the document's state.
- *Why redirect instead of streaming in the hook:* streaming would copy the token, KSeF and limit checks of `receipt.php` into a second path. The redirect keeps one download path. The receipt URL with its token is already in the order page's HTML, so nothing new is exposed.

### D6. The setting
`IFIRMA_NATIVE_INVOICE_LINK` (bool, default `1`) is added through `IfirmaSettings`, `tests/Unit/ConfigurationKeysTest.php` and the settings golden fixture. It shows as a switch in the configuration form, next to the customer options. While it is on and `PS_INVOICE` is off, a warning under the switch says "PrestaShop invoices are disabled, so customers see no built-in invoice link; they find documents on the order page and in My invoices." The form builder reads `PS_INVOICE` through the same injectable configuration seam it already uses. Uninstall deletes the key like the other keys.

### D7. Upgrade and install
`install()` registers `actionPDFInvoiceRender` and writes the default. The new `upgrade/upgrade-1.11.0.php` (named for the version the Release workflow will produce) does the same on existing shops: it registers the hook and sets the key to `1`, as the owner decided. No version string is edited by hand.

### D8. Wording and translations
The English source strings are "My invoices" (tile, page title, breadcrumb), "You have no invoices yet." and the not-available sentence. `translations/pl.php` gets "Moje Faktury" exactly as the owner asked, and the old `customer_account` entry "Moje dokumenty iFirma" is removed because its source string no longer exists. The tile CSS drops the collapse-specific rules. The list styles move to the page.

## Risks / Trade-offs

- Checked on 2026-11-16 (task 4.1) by grepping `controllers/front/PdfInvoiceController.php` in the cached images 8.0.5, 8.1.7, 8.2.2, 9.0.3 and 9.1.4: every tag runs `Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => ...])` in `display()`, after the `PS_INVOICE`, ownership and `secure_key` checks of `postProcess()`. Since 8.1 a request that carries `secure_key` is authorised by the key alone, even for a logged-in customer, so the policy treats the requester as the owner only when the customer is logged in and their id equals the order's customer id.
- [`actionPDFInvoiceRender` missing or moved on some PrestaShop tag] → the hook then never fires and customers get PrestaShop's invoice as before (fails open to today's behaviour, never to a foreign document). Smoke scenario T runs on all five test-stack tags and records which tags serve the iFirma PDF.
- [Another module also listens on `actionPDFInvoiceRender` and runs after ours] → ours ends the request, so later listeners don't run for handled orders. This is documented in `docs/troubleshooting.md`.
- [Guests lose PrestaShop's invoice for handled orders] → this follows the existing no-guest-download rule. It is listed under BREAKING and in `docs/upgrading.md`, and the owner can turn the setting off.
- [Shops that issue both PrestaShop and iFirma invoices for one order] → with the setting on, the customer sees only the iFirma document. That is the intent. The setting restores the old behaviour.
- [`customer/page.tpl` missing in a heavily customised theme] → the page then fails to render for that theme. The template extends the standard layout name used by every PrestaShop 1.7+ theme. The troubleshooting doc names the override path `themes/<theme>/modules/ifirma/views/templates/front/documents.tpl`.
- [Conflict with `add-mark-sent-and-download-after-delivery` on "Account block"] → whichever change is archived second restates the requirement with both rules. D3 gives the sent rule one place to land.
- [Open redirect] → the redirect target is built only by `Link::getModuleLink('ifirma', 'receipt', ['token' => ...])` from the stored token, never from request input.

## Migration Plan

1. Release through the Release workflow (minor). `upgrade-1.11.0.php` registers the hook and switches the setting on.
2. After the upgrade the owner checks: the "Moje Faktury" tile opens the page, and the Order history invoice link of an iFirma order downloads the iFirma PDF.
3. Rollback: turn the setting off (the built-in link is back to PrestaShop's invoice immediately). The page and tile have no state to roll back. A module downgrade leaves an orphan hook registration that core ignores once the method is gone.
