# Design

## Context

See proposal.md for motivation. The pieces this change touches today:

- **Data-change requests.** `IfirmaDataRequestService` owns every rule (eligibility `canRequest(Order, Customer)`, `validate()`, `submit()`, `approve()`, `reject()`, `buildDiff()`, cool-down and daily cap keyed by `id_customer`). The front controller `controllers/front/datarequest.php` requires a login and delegates everything to the service; the admin side goes through `IfirmaDataRequestActions`, which after approve/reject calls `IfirmaIssuancePipeline::resumeAfterRequest()`. The entity `IfirmaDataRequest` stores `status`, `requested_data`/`approved_data` (JSON), notes, `resolved_by`, `date_resolved`. The merchant and customer mails go through `IfirmaDataRequestNotifier` (templates `data_request_received|approved|rejected`, language of the order with an English fallback).
- **Guest orders.** PrestaShop creates a `Customer` row with `is_guest = 1` for guest checkout, so `$order->id_customer` always points at a customer with an e-mail. The guest-tracking page (`controllers/front/GuestTrackingController.php` in PS 8.1 and 9.1, checked in both dev shops) runs `Hook::exec('displayOrderDetail', ['order' => $order])` after it has matched reference and e-mail itself; the module's `hookDisplayOrderDetail` currently returns `''` when no customer is logged in.
- **E-mails.** `Mail::Send()` in PS 8 and 9 fills `{shop_logo}` with the embedded (cid) e-mail logo (`PS_LOGO_MAIL`, falling back to `PS_LOGO`) and `{shop_name}`, `{shop_url}`, `{color}` after the caller's variables. The module's five HTML templates print `{shop_name}` only; the test-stack memory confirms every mail already carries the inline logo part, unreferenced.
- **Document payload.** `IfirmaPayloadBuilder::documentHeader()` builds the `fakturakraj` top level; retries (`DodatkoweUwagi`, `IdentyfikatorKontrahenta`) start from the same array. The iFirma docs list `NazwaSzablonu` (string, optional, "default template when absent") with the example value `logo`; the mock (`test-stack/ifirma-mock`, 1.2.0) validates enumerations and required fields only and ignores unknown keys.
- **Order stamp and file names.** `IfirmaPayloadBuilder::buildOrderReference()` returns `ORDER_REFERENCE_PREFIX . id . '-' . reference` (`PS-ORDER-`), used for `Uwagi`, the `NUMER_ZAMOWIENIA` note, `receipt.order_reference` (64 chars) and the duplicate guard's `confirm()` (`strpos` of the stamp in the raw detail JSON, with JSON-escaped variants). The mock never parses the stamp. PDF names are built in `IfirmaHttpOutput::pdf()` (`ifirma-<name>.pdf`, both download paths) and in `IfirmaDeliveryService` for the attachment. `Mail::Send()` overwrites `{shop_url}` with the shop's front URL, so a merchant-configured address needs a placeholder of its own.
- **Settings.** `IfirmaSettings::fields()` declares each key with a type (`TYPE_TEXT`, `TYPE_EMAIL`, choice, range), `fromInput()` validates and normalises, `IfirmaSchema::configurationDefaults()` seeds install/upgrade, `ifirma.php` renders the HelperForm and removes keys on uninstall. `IfirmaSchema` has `ensure*` helpers with a per-request cache for additive schema changes, used by upgrade scripts and lazily by the code paths that need the column.
- **Tests.** Unit tests run without PrestaShop against `tests/Stubs`; the smoke run (`tests/smoke/run.php`, scenarios A to N) runs inside the Docker shops against the mock and reads the SMTP sink (`mailbox()`, `mailGet()`, `assertMail()`). Golden fixtures cover payloads, settings and the admin order panel.

## Goals / Non-Goals

**Goals:**

- One service path for a request, whatever the entry (account form, guest form, future API): the guest controller only replaces *who proves ownership*; the request itself is created for the order's own `Customer` record through the existing `submit()`.
- Auto-accept as a pure, unit-testable decision over the existing `buildDiff()` output, with the resolution stored in the same columns the admin path uses plus one flag, so the admin panel, feed, hold logic and pipeline resume need no second code path.
- The unauthenticated page cannot be used to enumerate orders or e-mails, to flood a mailbox, or to brute-force a code.
- No PDF processing in the module; iFirma renders the logo.

**Non-Goals:**

- Guest download of the PDF (download stays login-only; the guest tracking block shows the state without a link).
- Account creation for guests, or converting the guest flow into a general "magic link" login.
- A per-document choice of template (one setting for every document the module creates).
- Uploading the logo into iFirma (no API for it; documented as a manual step in the iFirma account).
- SMS or other code channels; "one-time code" means e-mail.

## Decisions

### D1. Guest flow is a separate front controller with three steps and a cookie-bound verified session

`controllers/front/guestrequest.php` (`module:ifirma/views/templates/front/guestrequest.tpl`) with steps `identify` → `verify` → `form`/`pending`, chosen by the posted action and by the session state. The verified session is stored in the PrestaShop cookie (`$this->context->cookie->ifirma_guest = "<id_order>:<expiry>:<hmac>"`, HMAC-SHA256 over `id_order|expiry` with `_COOKIE_KEY_`; the stored value uses `:` because PrestaShop's `Cookie::__set()` refuses `|` in a value), 30 minutes, cleared on submit, cancel, expiry, or when a customer is logged in. The step `form` reuses the same Smarty partial as `datarequest.tpl` (the field loop is extracted to `views/templates/front/_datarequest_fields.tpl` and included by both pages) so the two forms cannot drift.

- *Why a new controller rather than branches in `datarequest.php`:* the login-required controller stays untouched (the user asked to keep it unchanged), the guest page carries different security properties (no customer in context, neutral answers), and `FrontControllerEndingsTest` can cover each ending separately.
- *Why the cookie, not a DB session table:* the PrestaShop cookie is already encrypted and signed per visitor; a signed value bound to one order and an expiry is enough, and nothing needs to be cleaned up. The code table (D2) keeps the state that must survive and be rate-limited.
- *Alternative rejected:* a verification link in the e-mail (`?code=...`). A link verifies by itself when the mail is forwarded or the client prefetches it, and it does not fit "one time use code similar to the password reset" as requested; the mail carries only the digits.

### D2. Code store: new table `ifirma_guest_code`, salted SHA-256, counters by order and by client address

`IfirmaGuestCodeService` (new) with `issue(Order $order, string $ip): ?string` (returns the plain code once or `null` when a limit refused it), `verify(int $idOrder, string $code): bool`, `cleanup()`. Table columns: `id_ifirma_guest_code`, `id_order`, `code_hash` (hex sha256 of `salt . code`), `salt` (16 random bytes, hex), `attempts`, `ip_hash` (sha256 of the client address with the cookie key, so the table holds no raw address), `date_add`, `expires_at`, `used_at`, `invalidated` (tinyint). Indexes on `id_order` and `ip_hash, date_add`.

- `issue()`: cleanup rows older than 24 h; refuse when a code for the order was created < 60 s ago, when ≥ 5 codes for the order in 24 h, or ≥ 20 codes for the `ip_hash` in 1 h; otherwise invalidate the order's earlier unused codes, insert, return the code. Six digits from `random_int(0, 999999)` zero-padded; no fallback generator (same stance as `IfirmaSchema::generateCronToken()`).
- `verify()`: load the newest non-invalidated, unused, unexpired row for the order; `hash_equals()` on the recomputed hash; on mismatch `attempts++`, and at 5 set `invalidated = 1`; on match set `used_at`. Unknown order or no active row answers `false` after the same hash computation (constant work).
- *Why hash and not plain:* the code is a short-lived credential; a DB dump or log must not reveal it. A salted SHA-256 is enough for six digits that are also attempt-limited; `password_hash()` would be slower per attempt without a security gain here.
- *Why both per-order and per-address caps:* the per-order cap protects one customer's mailbox from being flooded by anyone who knows a reference and an e-mail; the per-address cap stops one client from spraying codes over many orders. The address is taken from `Tools::getRemoteAddr()` (honours the shop's proxy settings).
- *Neutral timing:* the identification step always computes the hash and sleeps nothing; the lookup (`Order::getByReference()` + `Customer` e-mail compare) costs the same for an unknown reference as for an ineligible order, and the mail send is the only step that differs. That is acceptable: the e-mail is sent after the response has been built, and the attacker gains only a coarse timing signal that the rate limits already blunt.

### D3. Auto-accept lives in the service's submit path as a pure criteria class

`IfirmaDataRequestAutoAccept` (new, no PrestaShop calls) with `mode()` (`none|safe|all` from `IFIRMA_DATA_REQUEST_AUTO_ACCEPT`), `decide(array $requested, array $currentValues): ?string` returning the mode that accepted or `null`. The service's `submit()` keeps storing the pending row first (so a crash between the two writes leaves a pending request, never a lost one), then asks the criteria, and on acceptance calls the entity's `approve(0, $final, $systemNote, $now)` with `auto_mode` set, saves, sends the customer approval mail and the merchant mail with the extra line. `submit()` returns the request; both front controllers check `$request->isApproved()` and call `IfirmaIssuancePipeline::build($this->module)->resumeAfterRequest($request)`, exactly what `IfirmaDataRequestActions::resolved()` does for the admin.

- *Safe criteria:* computed from `buildDiff()` plus the company flag. Current address counts as company when `company !== ''` or the normalised VAT is non-empty. Accept when: `want_invoice_for_company === currentIsCompany`, normalised `vat_number` equal, `company` equal (trimmed); the diff may then only contain `firstname`, `lastname`, `address1`, `address2`, `postcode`, `city`, `id_country`. The safe set is a class constant so moving `id_country` to the review set is a one-line change if the merchant base needs it.
- *Schema:* one new column `auto_mode VARCHAR(8) NULL` on `ifirma_data_request` (`IfirmaSchema::ensureDataRequestAutoModeColumn()`, called by `upgrade-1.6.0.php` and lazily before the first write). `resolved_by` stays `NULL`; the presenter maps (`status = approved`, `resolved_by` empty, `auto_mode` set) to the "automatic (safe fields)" / "automatic (all)" label. The admin note gets a translated system sentence ("Approved automatically: safe fields only") so the existing audit rendering shows it even on an old panel template.
- *Why the pipeline resume is called by the controller, not the service:* the service is unit-tested with stubs and must stay free of the pipeline (it is already the dependency direction used by the admin actions). Running the pipeline in a front request is already the case for `hookActionOrderStatusPostUpdate`; the 30 s API timeout is the worst case the customer may wait, and `resumeAfterRequest()` never throws.
- *Alternative rejected:* auto-approve inside `resumeAfterRequest()`/the pipeline's hold check. That would approve on the next trigger instead of at submission, and the customer would get no approval e-mail at the moment they expect one.

### D4. Guest block on the tracking page through the existing hook

`hookDisplayOrderDetail()` keeps returning `''` for a logged-in customer who is not the owner, but when no customer is logged in **and** `$this->context->controller->php_self === 'guest-tracking'` it renders the same `order_detail.tpl` with a guest view: `IfirmaCustomerView::forGuestOrder()` sets `download_url` to `''`, `request_url` and `cancel_url` to the guest page (`guestrequest?reference=<ref>`), and reuses every state label. The template needs no new branch beyond "no download button when `download_url` is empty" (already the case).

- *Why trust the page:* PrestaShop only runs the hook there after it has matched reference and e-mail itself, and the module shows nothing more than the state and a number, never the PDF.

### D5. Logo through `{shop_logo}`, mirroring the core template

Each HTML template gets the core header row: `<img src="{shop_logo}" alt="{shop_name}" style="max-height:60px">` followed by the shop name text. No module code changes: `Mail::Send()` sets the variable after the module's `$vars` are merged. When the shop has no logo, the core passes an empty `src`; the shop name text keeps the header readable. The text templates are left as they are, with the shop name in their first line where missing. The new `data_request_code.{html,txt}` (en, pl) follows the same layout.

- *Alternative rejected:* attaching `logo.png` from the module (`mails/` or `_PS_IMG_DIR_`) ourselves. Core already embeds the shop's logo in every mail; a second image would double the size and ignore the merchant's `PS_LOGO_MAIL` choice.

### D6. `NazwaSzablonu` from one text setting

`IFIRMA_DOC_TEMPLATE` (`IfirmaSettings` `TYPE_TEXT`, `max` 100, default `''`; `scalarText()` already trims and refuses arrays; add a control-character check in `text()` for this key). `IfirmaPayloadBuilder::documentHeader()` adds `'NazwaSzablonu' => $template` only when non-empty, so every golden payload fixture stays byte-identical by default and the retries inherit the key. The mock stores the field on the document (`doc.nazwa_szablonu`), returns it in the detail JSON, prints it on the status page document row, and bumps to 1.3.0. The help text and `docs/configuration.md` say: the template (and the logo it prints) is defined in the iFirma account; the module only names it.

- *Why no validation of the name against iFirma:* there is no API to list templates. A wrong name surfaces as a create error from iFirma, which the existing error mapping records on the receipt and shows in the admin panel and the issue feed; `docs/troubleshooting.md` gets the entry.

### D7. Versioning, upgrade and translations

Module version `1.6.0`; `upgrade/upgrade-1.6.0.php` creates `ifirma_guest_code`, ensures the `auto_mode` column, seeds `IFIRMA_DATA_REQUEST_AUTO_ACCEPT = none`, `IFIRMA_DOC_TEMPLATE = ''`, `IFIRMA_ORDER_STAMP_PREFIX = PS-ORDER` and `IFIRMA_SHOP_URL = ''`.

### D8. Order stamp prefix: one setting, two accepted stamps

`IFIRMA_ORDER_STAMP_PREFIX` (`TYPE_TEXT`, 1 to 32 chars of `[A-Za-z0-9._-]`, trailing `-` and spaces stripped, empty → `PS-ORDER`; read with `Configuration::get()` so a multistore shop context gets its own value). `IfirmaPayloadBuilder::buildOrderReference(Order)` uses the configured prefix; a new `orderReferenceStamps(Order): string[]` returns the configured stamp first and, when the prefix is not the default, the legacy `PS-ORDER-…` stamp second. `IfirmaDuplicateMatcher::confirm()` accepts a string or a list and answers true when any stamp is found; `IfirmaDuplicateGuard` passes the list in `scan['reference']` (the stored `order_reference` keeps the configured stamp). The 64-character column holds the longest stamp: 32 + 1 + 10 digits + 1 + 9 = 53.

- *Why keep the legacy stamp matching:* documents issued before the merchant sets a prefix carry `PS-ORDER-…`; without the fallback the guard would re-issue them. The full stamp includes the 9-character PrestaShop reference, so a cross-shop false match needs the same order id and the same random reference, which is the residual collision risk the prefix removes for every new document.
- *Why a free text and not a derived value (shop name, domain):* the merchant must be able to keep an existing convention and choose short stable tokens; derived values change when the shop is renamed and would silently break adoption.
- *Alternative rejected:* `{prefix}` as a placeholder language in `Uwagi`. The stamp is a machine key (duplicate guard, adoption, iFirma order-number note), not a comment; anything free-form goes after it (D9).

### D9. Shop webpage: one setting, three uses

`IFIRMA_SHOP_URL` (`TYPE_TEXT`, max 255, absolute `http(s)` URL without user info, validated with the same parser `isAcceptableBaseUrl()` uses but without the dev-mode rule; empty → unset). A small resolver `IfirmaShopLink` (new, pure) gives `url()` (the setting or `Context::getContext()->shop->getBaseURL(true, true)` when empty) and `fileNamePrefix()` (host of that URL, lower-cased, `www.` stripped, `[^0-9A-Za-z._-]` → `_`).

- *E-mails:* the module passes `'{ifirma_shop_link}' => url()` in every `Mail::Send()` call (delivery, notifier, code mail); the templates wrap the logo and the shop name in `<a href="{ifirma_shop_link}">` and print it in the footer; the text templates print it in the last line. The core's own `{shop_url}` is left alone because `Mail::Send()` overwrites it.
- *Document comments:* `documentHeader()` builds `Uwagi` as `stamp . ' ' . url()` only when the setting is non-empty (an explicit merchant choice, so default documents do not change), then truncates to 1000 with the stamp intact; `DodatkoweUwagi` keeps the bare stamp because iFirma treats it as an order number.
- *File names:* `IfirmaHttpOutput::pdf($pdf, $name)` takes the prefix from the resolver (a static test seam like `$sendHeader` keeps `HttpOutputTest` free of PrestaShop) and the delivery attachment uses the same helper, so the three names are always equal. The default changes from `ifirma-` to the shop domain for every installation; the proposal flags it.
- *Alternative rejected:* using `Tools::getShopDomainSsl()` only and no setting. Shops behind a vanity domain or a reverse proxy often expose a different public address than the one PrestaShop knows, and the user asked for a field. Uninstall drops the table and the two keys. New strings go to `translations/pl.php` with the module's `l()` domains (`ifirmaguestrequestmodulefrontcontroller`, `ifirmaguestcodeservice`, `ifirmadatarequestservice`, `ifirma`). Mail subjects stay in the service classes as today.

## Risks / Trade-offs

- [The guest page is a new unauthenticated endpoint that sends e-mail] → neutral answers, salted hashes, per-order and per-address caps, 5 attempts per code, 15-minute lifetime, CSRF token on every step, smoke scenario that proves refusal paths send nothing; the code service is unit-tested with a fixed clock like `IfirmaDownloadThrottle`.
- [A shop behind a proxy without `PS_SSL`/trusted-proxy settings reports one address for everyone] → the per-address cap is the outer guard only; the per-order caps protect mailboxes regardless. The cap values are constants and documented.
- [Auto-accept `all` lets a customer put any NIP on the document] → default `none`; the help text and docs say so plainly; `safe` keeps identity fields for review.
- [Country changes are treated as safe] → recorded as an explicit decision (user choice); the safe set is one constant, and the merchant notification still shows the diff, so the merchant can switch to `none` or ask for the country to move to the review set.
- [Pipeline runs inside the customer's request after an automatic approval] → `resumeAfterRequest()` never throws and is bounded by the API timeout; the customer sees the confirmation regardless of the pipeline outcome, as the spec requires.
- [Unknown `NazwaSzablonu` on the live API] → not verifiable without an account; the setting defaults to empty, the error path is the existing create-error handling, and troubleshooting documents it. The mock accepts any name and does not simulate the refusal.
- [Core `{shop_logo}` behaviour differs between PS 8.1 (Swift) and 9.x (Symfony Mailer)] → both embed and set the variable (memory from the SMTP sink runs); the smoke asserts a `cid:` reference in the HTML on both stacks.
- [A merchant changes the prefix while suspected duplicates are open or documents are mid-flight] → the legacy stamp stays accepted for adoption, stored `order_reference` values are never rewritten, and the docs say to set the prefix once, before the first document of a new shop.
- [Two shops pick the same prefix] → the prefix is a merchant responsibility; the help text and docs say each shop needs its own, and the admin panel shows the stamp on each document so a mix-up is visible.
- [PDF file name default changes for every installation] → called out in the proposal and the upgrade notes; the pattern stays file-safe and the number is still in the name.
- [`displayOrderDetail` on guest tracking is a core behaviour, not a documented contract] → verified in the 8.1 and 9.1 images; the block degrades to nothing if a future core stops calling the hook, and the guest page stays reachable by URL.

## Migration Plan

1. Ship 1.6.0; `upgrade-1.6.0.php` creates the table, adds the column and the four settings with their defaults, so behaviour is unchanged until the merchant sets a mode, a template name, a prefix or a webpage; only the PDF file-name prefix changes by default.
2. Merchant steps (documented): optionally set the auto-accept mode; optionally upload the logo in the iFirma account, create or pick the logo template and enter its name in the module; for shops sharing one iFirma account set a distinct stamp prefix per shop before its first document; optionally enter the public shop webpage.
3. Rollback: the earlier module version ignores the extra column, table and keys; no data is rewritten. Requests approved automatically remain approved rows with an empty `resolved_by`, which the old panel shows as approved without a resolver.

## Open Questions

- Exact label iFirma gives its built-in logo template in a given account (the API example uses `logo`); the docs tell the merchant to copy the name shown in their iFirma settings rather than assume it.
