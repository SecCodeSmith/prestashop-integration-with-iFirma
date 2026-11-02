# Prestashop Module for integration with iFirma – Receipts & Invoices (PrestaShop module)

Two‑way integration between **PrestaShop** (1.7.x / 8.x) and the **iFirma**
accounting service (`www.ifirma.pl`). It covers the three requested features:

| # | Feature | Where |
|---|---------|-------|
| 3 | **Create a Receipt** in iFirma from the order | Back office → *Orders → (order) → iFirma panel → “Create Receipt”* |
| 1 | **Return connection**: fetch the issued document (PDF) back and expose it to the registered customer | Front office → *My account* block **and** the *Order detail* page |
| 2 | **Update the status in iFirma** (register the payment) | Back office → iFirma panel → “Update status in iFirma” |

## How the iFirma API is used

Authentication follows the official spec
(<https://api.ifirma.pl/naglowek-autoryzacji/>):

```
message = url + user + keyName + requestContent      (query string stripped from url)
hash    = HMAC-SHA1(message, hex2bin(key))
header  = Authentication: IAPIS user=<user>, hmac-sha1=<hash>
```

| Action | HTTP | Endpoint | Key |
|--------|------|----------|-----|
| Create receipt | `POST` | `iapi/fakturaparagon.json` (or `fakturakraj.json`) | `faktura` |
| Download PDF | `GET` | `iapi/fakturaparagon/{id}.pdf` (`?typ=dup` = duplicate) | `faktura` |
| Register payment (status) | `POST` | `iapi/faktury/wplaty/{type}/{id}.json` body `{"Kwota": …}` | `faktura` |
| List documents (filters) | `GET` | `iapi/faktury.json?dataOd=…&dataDo=…&iloscNaStronie=…` (all three always sent) | `faktura` |
| Document detail (JSON) | `GET` | `iapi/{type}/{id}.json` | `faktura` |
| Send by e-mail | `POST` | `iapi/{type}/send/{id}.json` | `faktura` |
| Daily API limit (optional) | `GET` | `iapi/abonent/limit.json` | `abonent` |

### Error codes

A non-zero `Kod` in the response is shown as `<cause> (Kod N): <Informacja>`; HTTP status 400+
without a JSON body is reported as `HTTP <status> returned by iFirma.`

| Kod | Meaning |
|-----|---------|
| 100 | Technical problem on the iFirma side |
| 101 | Wrong encoding (UTF-8 expected) |
| 102 | Missing or invalid content type |
| 200 | Invalid request structure |
| 201 | Validation error in document data |
| 202 | Wrong service or account settings |
| 400 | Invalid authorization header |
| 401 | Invalid user login |
| 402 | No API key generated for this account |
| 403 | Invalid signature - check the API key |
| 499 | iFirma terms of service not accepted |
| 500 | Insufficient permissions (super-account required) |

### Rate limits

iFirma allows 100 requests per minute and 15,000 per day. The module keeps its own per-minute
counter (`IFIRMA_RATE_WINDOW`) and refuses to start a request once the local cap is reached
(default 80, configurable through `IFIRMA_RATE_MAX_PER_MIN`); such a refusal is a transient
"rate limited" error and is never stored as a document error. Timeouts: 30 s by default, 5 s for
the customer-page sync. With the optional `abonent` key the used/granted daily limit can be read.

All calls go through [`classes/IfirmaApiClient.php`](classes/IfirmaApiClient.php);
the order → payload mapping and business rules live in
[`classes/IfirmaReceiptService.php`](classes/IfirmaReceiptService.php).

## Installation

1. Copy the `ifirma/` folder to `<prestashop>/modules/`.
2. Back office → **Modules** → search *iFirma* → **Install**.
3. Open the module configuration and fill in:
   - **iFirma login** – your account login / e‑mail.
   - **API key: faktura** – the hexadecimal *faktura* key from
     iFirma → *Konfiguracja → Abonent → API → Klucze*.
   - **Document type** – *Invoice to receipt* (`fakturaparagon`, default) or
     *Domestic VAT invoice* (`fakturakraj`).
   - **Payment registration type** – URL token for the payment endpoint
     (default `prz_faktura_kraj`; adjust to your document/payment type).
   - Optionally an **Auto‑create on order status** to issue documents automatically.
   - **API key: abonent** (optional) – shows today's API usage on this page ("used/granted").
   - **Delivery channel** – *Shop e‑mail with PDF attached* (default), *iFirma e‑mail*
     (fakturakraj and other supported invoice types only; receipts fall back to the shop
     e‑mail) or *Do not send*. With the default channel, documents are e‑mailed to customers
     automatically on the next trigger; choose *Do not send* to disable. **E‑mail text** adds an
     optional message (up to 1000 characters).
   - **Duplicate check**, **window (days, 1‑365)** and **amount tolerance** – look for an existing
     iFirma document before issuing a new one.
   - **Allow customers to request billing data changes** and **Notification e‑mail** (empty or a
     valid address; empty uses the shop address).
   - **Status sync interval (1‑1440 minutes)** – how often an order page refreshes its document
     from iFirma; **Cron call budget (1‑90)** and **API calls per minute (1‑100)** – API usage caps.
   - **Cron endpoint** – the module shows the cron URL with its secret token and a crontab
     example (`*/15 * * * * curl -fsS "<url>"`); *Regenerate cron token* replaces the token.

## Usage flow

When an order reaches the status chosen in **Auto-issue status** (0 = off), the module
runs one issuance pipeline for that order. The admin **Create Receipt** button runs the
very same pipeline, so a manual and an automatic run behave identically. Five steps run in
this order and the first one that cannot continue stops the run (later steps do not run);
the outcome of every step is stored on the order's receipt record:

1. **Hold** - if the customer has a pending data-change request for the order, nothing is
   sent to iFirma. The record gets status `held` and the reason
   `held: data request #N pending`. When the request is approved or rejected and the
   order history already contains the trigger status, the pipeline resumes by itself.
2. **Duplicate guard** - looks in iFirma for a document that already belongs to the order
   (see "Duplicate guard"). A confirmed match is adopted instead of creating a second
   document; an unconfirmed match stops the run.
3. **Issue** - creates `fakturaparagon` (default) or `fakturakraj` (setting
   `IFIRMA_DOC_TYPE`) and stores the returned `Identyfikator`. If iFirma answers `Kod 201`
   naming `DodatkoweUwagi`, the request is repeated once without that object (the
   reference stays in `Uwagi`); any other rejection is recorded as an error.
4. **Number** - the document number is read from iFirma and stored.
5. **Deliver** - the document is sent through the configured channel (see "Delivery").
   A delivery failure does not undo the issued document; use **Send again**.

Once issued, the panel shows the number, a **Download PDF** link and **Update status in
iFirma**, which registers the paid amount (also for adopted external documents). The
registered customer sees the document in **My account** and on the **Order detail** page
and downloads the PDF through the secured front controller `controllers/front/receipt.php`
(per-document token plus ownership check).

A failure never breaks the PrestaShop status change: exceptions are logged
(Advanced Parameters > Logs) and stored as the record's last error.

### States that need attention

| State | Meaning | What to do |
|---|---|---|
| `unknown` | The create request got no usable answer (timeout, transport error), so the document may or may not exist in iFirma. The module never repeats the create blindly. | Click **Create Receipt** again: the duplicate guard first looks for the document; if it exists it is adopted, if nothing is found a new one is created. |
| `duplicate_suspected` | The guard found a matching document it could not confirm (stopped at the **guard** step). Nothing was created. | Open the candidates in the panel and **Link** the right one, or choose **Issue anyway** after checking iFirma. |
| `held` | A customer data-change request is pending (stopped at the **hold** step). | Approve or reject the request under the order; the pipeline then runs automatically when the trigger status was already reached, or click **Create Receipt**. |
| `error` | iFirma rejected the document or could not be reached before anything was sent; the reason is in the last error. | Fix the cause (data, credentials, limits) and click **Create Receipt**. |

## Monitoring

The module keeps its view of every issued document in line with iFirma. One
`faktury.json` list call per issue date refreshes: paid amount (`Zaplacono`; the
status becomes **paid** when it reaches the gross total minus
`IFIRMA_DUPLICATE_AMOUNT_TOLERANCE`), sent flag, draft flag, KSeF status and number,
linked correction (`NumerPowiazanegoPrzychodu`) and whether the document has
disappeared from iFirma ("missing", data is kept). A failed sync only records
`sync_error` and the time; status and delivery are never changed. A "rate limited"
result is transient and not shown as a document error.

Three ways to sync:

1. **Admin Refresh** - the "Refresh from iFirma" button in the order panel (one list call).
2. **Opportunistic** - when the owning customer opens the order detail and the last sync
   is older than `IFIRMA_SYNC_INTERVAL_MIN` minutes (default 15): at most one call with a
   5 s timeout, failures are swallowed.
3. **Cron** - bulk sync of receipts that are not paid, not remotely sent or not synced for
   24 h, grouped by issue date:

   ```
   https://<shop>/module/ifirma/cron?token=<IFIRMA_CRON_TOKEN>
   ```

   For shops without friendly URLs use
   `index.php?fc=module&module=ifirma&controller=cron&token=<IFIRMA_CRON_TOKEN>`.
   A wrong or missing token returns HTTP 403. Crontab example:

   ```
   */15 * * * * curl -fsS "https://<shop>/module/ifirma/cron?token=<IFIRMA_CRON_TOKEN>" >/dev/null
   ```

   A run makes at most `IFIRMA_CRON_MAX_CALLS` list calls (default 40); remaining dates
   wait for the next run. It returns a JSON summary (receipts checked, updated, marked
   paid, marked missing, errors, rate limited, calls, orders held by pending data requests).

**Rate limits:** iFirma allows 100 requests/minute and 15,000/day across ALL API clients
of your account (other integrations, accounting tools). The module stops at its own
guard (`IFIRMA_RATE_MAX_PER_MIN`, default 80) and retries rate-limited receipts on a
later run, but it cannot see requests made by other clients.

## Duplicate guard

Before the module creates a document it checks whether the order already has one,
whether created by this module earlier, by iFirma's own e-commerce integrator or by
hand. How it works:

1. **Reference stamping.** Every document the module creates carries
   `PS-ORDER-{id_order}-{order reference}` in `Uwagi` and as a `NUMER_ZAMOWIENIA`
   additional remark.
2. **Local record first.** An order with an issued local receipt record never triggers a
   remote lookup.
3. **List window.** `faktury.json` is queried from one day before the order date to today
   (always with `dataOd`/`dataDo`, 100 rows per page, paged until a short page): first by
   contractor identifier (`PS-C{id_customer}`), then by `nipKontrahenta` when the order has
   a NIP, then unfiltered if the window is within `IFIRMA_DUPLICATE_WINDOW_DAYS`.
4. **Identity match.** A listed row is a candidate only if it is not a draft, is a
   `prz_faktura_paragon` / `prz_faktura_kraj`, has the order currency and its gross is
   within `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE` of the order total.
5. **Detail confirmation.** The detail of each candidate (newest first, at most 10) is
   searched for the order reference.

Outcomes:

- **Adopt** - a candidate contains the order reference: it becomes the order receipt
  (source *external*, status *paid* when its paid amount covers the gross, no automatic
  delivery) and no new document is created.
- **Suspected** - candidates exist but none contains the reference: nothing is created,
  the receipt gets status *duplicate suspected* and the candidates are listed in the
  admin order panel (the customer sees no detail). The admin chooses **Link** (adopt that
  candidate) or **Issue anyway** (one-time bypass of the guard for that order); both
  actions are recorded on the receipt.
- **Inconclusive** - the list query failed (network, authentication, rate limit):
  automatic issuance aborts and records the error; manual issuance shows the error and
  offers **Issue anyway**.

Settings (Configuration keys):

| Key | Default | Meaning |
|---|---|---|
| `IFIRMA_DUPLICATE_CHECK` | `1` | `0` disables the guard; only the local record check applies |
| `IFIRMA_DUPLICATE_WINDOW_DAYS` | `60` | largest window (days) for the unfiltered query |
| `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE` | `0.01` | allowed gross difference when matching |

**Caveat:** do not run iFirma's own PrestaShop integrator and this module's automatic
issuance on the same iFirma account; the guard is a safety net, not a coordination
mechanism, and cannot prevent two systems issuing documents at the same moment.

## Usage flow (customer)

The order detail page always shows an accounting-document block in exactly one of
five states (internal errors, iFirma messages and candidate documents are never shown):

1. **Not issued** - no document yet. The block names the order status that will trigger
   automatic issuance (when enabled) and, if the data-change feature is on, offers
   **Request a change to billing data**.
2. **Held** - the document waits for the customer's pending data-change request. The block
   shows the request date and a **Cancel request** action.
3. **Verifying** - the shop is checking the document (suspected duplicate or unknown
   outcome). Neutral wording only; nothing for the customer to do.
4. **Issued** - number (or the iFirma id), issue date, paid/unpaid, sent date or
   "not yet sent", and a **Download (PDF)** button. Documents adopted from iFirma look the same.
   To change the document the customer contacts the shop.
5. **Missing** - the document was removed in iFirma. The customer is asked to contact the
   shop; no download link is offered.

The **My account** page lists issued documents (number, status, download link) and the count
of orders with a pending request; the block is hidden when there is nothing to show.
Customer texts are translatable (`translations/pl.php` ships Polish).

## Delivery

After a document is issued the module e-mails it to the order customer as the last step of the pipeline and records the outcome (status, channel, timestamp, error) on the receipt. The channel is set with `IFIRMA_SEND_CHANNEL`:

- `shop_mail` (default): the PDF is fetched from iFirma and attached to the `receipt_issued` e-mail sent by the shop mail system, in the order language (Polish and English templates in `mails/`; other languages fall back to English).
- `ifirma_mail`: iFirma sends the e-mail itself (`POST {type}/send/{id}.json`, recipient `SkrzynkaEmailOdbiorcy`, text from `IFIRMA_SEND_TEXT`, cut to 1000 characters; only `Kod` 0 counts as success). Only the documented types are supported (`fakturakraj` and the other invoice types, **not** `fakturaparagon`); for unsupported types the module automatically falls back to shop mail and records the channel as `shop_mail_fallback`.
- `none`: nothing is sent and delivery is recorded as skipped.

Documents adopted from iFirma (created elsewhere) are never auto-delivered; they are recorded as skipped (external). A failed delivery never undoes the issuance; the error is shown only in the back office. Use **Send again** in the order panel to resend any issued document, including adopted ones, through the configured channel (refused with a message when the channel is `none`).

> Warning: with the default channel, documents start being e-mailed to customers on the next trigger. Select `none` first if you do not want that.

## Data model

`ps_ifirma_receipt` - one row per iFirma document (issued by the module or
discovered in iFirma). Core columns: order/customer/shop link, `document_type`,
`ifirma_id` and `ifirma_number`, `status` (`created` / `paid` / `error` /
`unknown` / `duplicate_suspected`), `paid_amount`, a download `security_token`
and the full request/response payloads for auditing. Since 1.1.0 the table
also holds: `source` (`module` or `external`) and `order_reference`, which tell
where a document came from; `delivery_status`, `delivery_channel`,
`delivered_at` and `delivery_error`, which track sending the document to the
customer; `remote_paid_amount`, `remote_sent`, `remote_draft`,
`remote_missing`, `ksef_status`, `ksef_number`, `linked_document`,
`last_sync_at` and `sync_error`, which mirror the state last read from iFirma
by the synchronisation job; `duplicate_candidates` (JSON list of possible
existing documents found before issuing); and `id_data_request`, the link to
the buyer-data request that produced the document.

`ps_ifirma_data_request` (new in 1.1.0) - customer requests to change the buyer
data (company, NIP, address) used on a document. One row per request: order,
customer and shop; `status` (`pending` / `approved` / `rejected` /
`cancelled`); `requested_data` and `approved_data` (JSON); `customer_note` and
`admin_note`; `resolved_by` (employee id) and the `date_add`, `date_upd` and
`date_resolved` timestamps.

The schema is defined once in `classes/IfirmaSchema.php` and used by
`sql/install.php` and the upgrade script.

### Configuration keys

| Key | Default |
|---|---|
| `IFIRMA_BASE_URL` | iFirma API base URL |
| `IFIRMA_USER`, `IFIRMA_KEY_FAKTURA`, `IFIRMA_KEY_ABONENT`, `IFIRMA_KEY_RACHUNEK` | empty |
| `IFIRMA_DOC_TYPE` | `fakturaparagon` |
| `IFIRMA_PAYMENT_METHOD` | `PRZ` |
| `IFIRMA_PAYMENT_METHOD_MODE` (new) | `order` |
| `IFIRMA_PAYMENT_METHOD_MAP` (new) | empty |
| `IFIRMA_CONTRACTOR_SYNC` (new) | `1` |
| `IFIRMA_PAYMENT_TYPE_TOKEN` | `prz_faktura_kraj` |
| `IFIRMA_DEFAULT_UNIT` | `szt.` |
| `IFIRMA_AUTO_CREATE_STATE` | `0` |
| `IFIRMA_MARK_PAID_ON_CREATE` | `1` |
| `IFIRMA_SEND_CHANNEL` (new) | `shop_mail` |
| `IFIRMA_SEND_TEXT` (new) | empty |
| `IFIRMA_DUPLICATE_CHECK` (new) | `1` |
| `IFIRMA_DUPLICATE_WINDOW_DAYS` (new) | `60` |
| `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE` (new) | `0.01` |
| `IFIRMA_ALLOW_DATA_REQUEST` (new) | `1` |
| `IFIRMA_NOTIFY_EMAIL` (new) | empty |
| `IFIRMA_SYNC_INTERVAL_MIN` (new) | `15` |
| `IFIRMA_CRON_TOKEN` (new) | random 32 hex characters, generated on install/upgrade |
| `IFIRMA_CRON_MAX_CALLS` (new) | `40` |
| `IFIRMA_RATE_MAX_PER_MIN` (new) | `80` |
| `IFIRMA_RATE_WINDOW` (new) | empty (internal rate-limit state) |

## Upgrading from 1.0.x

Upload the 1.1.0 module over the existing one and run the upgrade from the
Module Manager. `upgrade/upgrade-1.1.0.php` adds the new `ps_ifirma_receipt`
columns (each only if it is not already present), creates
`ps_ifirma_data_request` if missing, and sets every new configuration key that
has no value yet. It never overwrites an existing setting and is idempotent, so
running it again is harmless. Existing receipts keep their data and get
`source = module` from the column default.

After upgrading, review these settings: the **delivery channel**
(`IFIRMA_SEND_CHANNEL`, default `shop_mail`; set it to `none` to keep the 1.0
behaviour of not sending anything), the **data-request switch**
(`IFIRMA_ALLOW_DATA_REQUEST`, default on) and the **cron token / schedule**
(`IFIRMA_CRON_TOKEN` is generated automatically; point your scheduler at the
synchronisation URL at the interval set in `IFIRMA_SYNC_INTERVAL_MIN`).

## Customer data-change requests

A customer can ask the shop to change the buyer data (company, NIP, name, address) of
an order **before** the document is issued. Enabled by `IFIRMA_ALLOW_DATA_REQUEST`
(default on).

- **Customer flow:** the order detail page links to the form
  (`/module/ifirma/datarequest?id_order=N`) while the order has no issued or adopted
  document and no pending request. The customer chooses company / private person, fills
  company name, NIP (10 digits, separators such as `525-000-12-34` are stripped), first
  and last name, address, postcode, city, country and an optional note. Postcode and
  city are always required. While the request is pending the customer can cancel it
  from the same page. All POST actions require a per-session form token. Once a
  document exists the form is not offered and the page tells the customer to contact
  the shop.
- **Hold on issuance:** while a request is pending, automatic issuance for the order
  does not create a document and the admin Create action is blocked with a message
  pointing to the request. When the request is approved or rejected, the pipeline runs
  immediately if automatic issuance is enabled and the trigger status was already
  reached; otherwise the next trigger or a manual Create issues the document.
- **Merchant notification:** a new request e-mails `IFIRMA_NOTIFY_EMAIL` (falls back to
  the shop e-mail) with the order reference, the requested data and the differences
  against the current invoice address.
- **Admin review:** the order panel shows each request (creator, resolver, dates,
  original and approved data, notes, diff). *Approve* lets the admin edit the fields
  (re-validated; an invalid edit keeps the request pending) and e-mails the customer;
  *Reject* requires a reason that is sent to the customer.
- **Scope of the change:** approved data overrides the buyer (`Kontrahent`) on the
  iFirma document only and is recorded in the stored request payload. The PrestaShop
  invoice address is never modified.

## Security notes

- API keys are stored in `ps_configuration`; the *faktura* key is validated as a
  hexadecimal string before any request is signed.
- Front‑office PDF access requires an authenticated customer, a matching document
  token, and `id_customer` ownership — a customer can never fetch another’s document.
- Back‑office actions run through `AdminIfirmaController`, protected by the standard
  PrestaShop admin token.
- Every admin action (create, issue anyway, link, resend, refresh, approve/reject a request,
  PDF download) requires the `AdminIfirma` admin token; calls without it are refused by PrestaShop.
- The cron endpoint is protected by a random secret token (`IFIRMA_CRON_TOKEN`, compared in
  constant time). Treat the URL like a password and regenerate the token in the module settings
  if it leaks.
- The customer data-change request form carries a CSRF token which is verified before the
  request is accepted.
- iFirma error bodies returned on a `.pdf` endpoint are detected so an error JSON is
  never streamed to the browser as a PDF.

## Notes & assumptions

- **Payment method follows the order.** `SposobZaplaty` is chosen per document: first from the
  order's payment module (the merchant map in `IFIRMA_PAYMENT_METHOD_MAP`, then the built-in
  defaults; an exact name or a `prefix*` wildcard), then from keywords in the order's payment
  method label, and finally the fallback code `IFIRMA_PAYMENT_METHOD`. Set the mode to `fixed`
  to always send the fallback code. The codes are GTK (cash), POB (cash on delivery), PRZ (bank
  transfer), KAR (card), PZA (direct debit), CZK (cheque), KOM (compensation), BAR (barter),
  DOT (DotPay), PAL (PayPal), ALG (PayU), P24 (Przelewy24), TPA (tpay.com) and ELE (electronic
  payment).
- **Contractor registration.** iFirma treats a supplied `Kontrahent.Identyfikator` as a reference to
  an existing contractor (an unknown one is rejected with Kod 201, "Nie znaleziono kontrahenta"); it
  creates a contractor automatically only when the identifier is `null`. With
  `IFIRMA_CONTRACTOR_SYNC` on (default), the module first reads the contractor `PS-C<customer id>`
  (`GET kontrahenci/id/...`), creates it (`POST kontrahenci.json`) when missing, or refreshes the
  shop-owned fields (name, NIP, address, country, e-mail, private person flag) with a full-record
  `PUT` when they changed, and then issues the document with that identifier. If the contractor
  cannot be registered, or the document is still rejected with "Nie znaleziono kontrahenta", the
  document is created once with a `null` identifier so iFirma creates the contractor itself; the
  outcome (created / updated / kept / fallback) is shown in the order panel. Switch the option off
  to always send `null` and let iFirma create a new contractor from each document.
- The **payment registration type token** varies per iFirma document/payment type.
  `prz_faktura_kraj` is a sensible default; verify yours against
  <https://api.ifirma.pl/rejestrowanie-wplat-do-faktur/> if the status update is rejected.
- Product lines use the **gross** unit price (`LiczOd = BRT`); VAT is taken from
  each order line’s tax rate (`0%` lines are marked `ZW`). Shipping is added as a line.
- Business customers (company + VAT/NIP on the invoice address) are sent as
  `OsobaFizyczna = false` with their NIP; everyone else as a private person.
- The contractor identifier is `PS-C{id_customer}` (at most 15 characters, matching iFirma's
  contractor identifier limit), so the same customer maps to the same iFirma contractor.
- The order reference (`PS-ORDER-{id_order}-{reference}`) is stamped in `Uwagi` and in
  `DodatkoweUwagi` (`NUMER_ZAMOWIENIA`) of every document the module issues.
- Text fields are truncated to iFirma's documented maxima before sending (e.g. buyer name 150,
  e-mail 65, street/city 65, product name 500, remarks 1000 characters).
- An approved customer data-change request overrides the buyer data for the iFirma document
  only; the PrestaShop order and addresses are never modified.

## Compatibility

PrestaShop **1.7.0 – 9.x**, PHP **7.2 – 8.x**. Verified with the Docker smoke suite on PrestaShop 8.1.7 (PHP 8.1) and 9.1.4 (PHP 8.5); see `tests/smoke/README.md`. Requires the PHP `curl`, `hash`
and `mbstring` extensions.

### PrestaShop 9.x notes

The module is written to be legacy-safe on PS 9 (PHP 8.1+):

- No `Tools::encrypt()` — download tokens use `random_bytes()` (CSPRNG).
- No undeclared dynamic properties (PHP 8.2 safe); all model fields are declared.
- Deprecated `Tools::strlen/substr/strtoupper` wrappers replaced by native
  `strlen` / `mb_*` calls.
- Version compliance declared up to `9.99.99`.
- Rendering on the refactored order page uses `displayAdminOrderMainBottom`
  (1.7.7+/8/9); the deprecated `displayAdminOrder` path is used only on ≤ 1.7.6.

## Development

Unit tests run without PrestaShop:

```bash
composer install
tests/vendor/bin/phpunit
```

The tests load lightweight stubs of the PrestaShop classes the module uses (`Configuration`, `Db`, `ObjectModel`, `Order`, `Mail`, `Context`, ...) from `tests/Stubs/`, so module classes can be exercised in memory: the database is scripted per query, configuration and mail are recorded, and the HTTP layer can be replaced with `tests/Support/ScriptedTransport.php`. Call `IfirmaTestState::resetAll()` in `setUp()`; the stub API is documented in `tests/Stubs/README.md`. Tests, `vendor/` and `composer.*` are never shipped in the module package.

Other checks:

```bash
npm run build                              # installable zip + npm tarball in dist/
python tools/check_license_headers.py      # every source file must carry the license header
```

### Dev dependencies and PrestaShop

PrestaShop loads `modules/*/vendor/autoload.php` at boot and Composer prepends its autoloader, so dev
dependencies installed in a module root shadow PrestaShop's own classes (observed: PHPUnit's `nikic/php-parser` v5
breaking PrestaShop 9's module parser, and test stubs replacing `Module`). Composer therefore installs into
`tests/vendor/` (`config.vendor-dir`), a path PrestaShop never loads. Do not move it back, and never deploy a
`vendor/` directory; the release zip and the npm tarball exclude `tests/`.

## Legal Disclaimer & Trademarks

iFirma is a registered trademark of IFIRMA SA.

This module is an independent third-party open-source integration and is not officially affiliated with, endorsed by, or sponsored by IFIRMA SA.

The name "iFirma" and associated references are used strictly for identification, nominative reference, and descriptive compatibility purposes (interoperability / prawo cytatu) to inform users about the third-party accounting system with which this PrestaShop module integrates.