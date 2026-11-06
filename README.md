# Prestashop Module for integration with iFirma – Receipts & Invoices (PrestaShop module)

Two‑way integration between **PrestaShop** (8.x / 9.x) and the **iFirma**
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
| Create receipt | `POST` | `iapi/fakturakraj.json` (`fakturaparagon.json` is disabled for now) | `faktura` |
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
the customer-page sync. A shop-mail delivery may add up to 10 s of waiting when the PDF is not ready yet. With the optional `abonent` key the used/granted daily limit can be read.

All calls go through [`classes/IfirmaApiClient.php`](classes/IfirmaApiClient.php);
the order → payload mapping lives in
[`classes/IfirmaPayloadBuilder.php`](classes/IfirmaPayloadBuilder.php), the document and signature type
normalisers in [`classes/IfirmaDocumentSettings.php`](classes/IfirmaDocumentSettings.php), and
[`classes/IfirmaReceiptService.php`](classes/IfirmaReceiptService.php) keeps the API calls.

## Installation

1. Copy the `ifirma/` folder to `<prestashop>/modules/`.
2. Back office → **Modules** → search *iFirma* → **Install**.
3. Open the module configuration and fill in:
   - **iFirma login** – your account login / e‑mail.
   - **API key: faktura** – the hexadecimal *faktura* key from
     iFirma → *Konfiguracja → Abonent → API → Klucze*.
   - **Document type** – *Domestic VAT invoice* (`fakturakraj`), the only enabled and default
     type. *Invoice to receipt* (`fakturaparagon`) is disabled for now; a stored `fakturaparagon`
     value is treated as `fakturakraj`.
   - **Payment registration type** – URL token for the payment endpoint
     (default `prz_faktura_kraj`; adjust to your document/payment type).
   - Optionally an **Auto‑create on order status** to issue documents automatically.
   - **API key: abonent** (optional) – shows today's API usage on this page ("used/granted").
   - **Delivery channel** – *Shop e‑mail with PDF attached* (default), *iFirma e‑mail*
     (available for every created document, since `fakturakraj` is a documented send type) or
     *Do not send*. With the default channel, documents are e‑mailed to customers
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
3. **Issue** - creates a `fakturakraj` document (the only enabled type, see `IFIRMA_DOC_TYPE`)
   and stores the returned `Identyfikator`. If iFirma answers `Kod 201` naming `DodatkoweUwagi`,
   or `Kod 200` while `DodatkoweUwagi` was sent, the request is repeated once without that object
   (the reference stays in `Uwagi`); any other rejection is recorded as an error.
4. **Number** - the document number is read from iFirma and stored.
5. **Deliver** - the document is sent through the configured channel (see "Delivery").
   A delivery failure does not undo the issued document; use **Send again**.

Once issued, the panel shows the number, a **Download PDF** link and **Update status in
iFirma**, which registers the paid amount (also for adopted external documents). The
registered customer sees the document in **My account** and on the **Order detail** page
and downloads the PDF through the secured front controller `controllers/front/receipt.php`
(per-document token plus ownership check).

The panel's **Register contractor in iFirma** button creates or refreshes the order customer's iFirma contractor (`PS-C<id>`) without issuing a document, which is useful before the first document is issued.

A failure never breaks the PrestaShop status change: exceptions are logged
(Advanced Parameters > Logs) and stored as the record's last error.

### States that need attention

| State | Meaning | What to do |
|---|---|---|
| `unknown` | The create request got no usable answer (timeout, transport error), so the document may or may not exist in iFirma. The module never repeats the create blindly. | Click **Create Receipt** again: the duplicate guard first looks for the document; if it exists it is adopted, if nothing is found a new one is created. |
| `duplicate_suspected` | The guard found a document of the same buyer with the same total, sale date and products that it could not confirm by the order reference (stopped at the **guard** step). Nothing was created. | Open the candidates in the panel and **Link** the right one, or choose **Issue anyway** after checking iFirma. Orders blocked by an earlier module version, which matched on the amount alone, can simply be re-run with **Create Receipt**: the guard runs again with the current rules. |
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
   a NIP, then unfiltered if the window is within `IFIRMA_DUPLICATE_WINDOW_DAYS`. When the customer is linked to an iFirma contractor,
   that identifier is used instead of `PS-C{id_customer}`, and an unknown-contractor answer
   (Kod 201) from iFirma counts as no documents for that query.
4. **Buyer and amount match.** A listed row is a candidate only if it is not a draft, is a
   `prz_faktura_paragon` / `prz_faktura_kraj`, has the order currency, its gross is within
   `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE` of the order total, and it belongs to the order's
   buyer: its contractor identifier equals the one the module would reference for the
   customer (the stored link or `PS-C{id_customer}`), or its NIP equals the invoice-address
   NIP, or its contractor name equals the buyer name the module would send (company, or
   first and last name; compared ignoring case and extra spaces). Documents of other
   customers are never candidates, even when they have the same total.
5. **Detail confirmation and verification.** The detail of each candidate (newest first,
   at most 10) is searched for the order reference. A candidate without the reference
   stays a candidate only when its sale date (`DataSprzedazy`) is the order date and every
   product line of the order appears among its positions with the same name and quantity
   (extra positions such as shipping are ignored). A candidate that fails either check is
   not a duplicate. A detail that cannot be read, or that lacks the sale date or the
   positions, keeps the candidate because it cannot be ruled out.

Outcomes:

- **Adopt** - a candidate contains the order reference: it becomes the order receipt
  (source *external*, status *paid* when its paid amount covers the gross, no automatic
  delivery) and no new document is created.
- **Suspected** - candidates of the buyer exist, none contains the reference, and at least
  one matches the sale date and products (or could not be verified): nothing is created,
  the receipt gets status *duplicate suspected* and the candidates are listed with their
  issue date and sale date in the admin order panel (the customer sees no detail). The
  admin chooses **Link** (adopt that candidate) or **Issue anyway** (one-time bypass of the
  guard for that order); both actions are recorded on the receipt.
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
- `ifirma_mail`: iFirma sends the e-mail itself (`POST {type}/send/{id}.json`, recipient `SkrzynkaEmailOdbiorcy`, text from `IFIRMA_SEND_TEXT`, cut to 1000 characters; only `Kod` 0 counts as success). Only the documented types are supported (`fakturakraj` is a documented send type and is the only type created, so this channel is available for every document). If a type without iFirma send support is ever enabled again, such as `fakturaparagon`, the module automatically falls back to shop mail and records the channel as `shop_mail_fallback`.
- `none`: nothing is sent and delivery is recorded as skipped.

Documents adopted from iFirma (created elsewhere) are never auto-delivered; they are recorded as skipped (external). A failed delivery never undoes the issuance; the error is shown only in the back office. Use **Send again** in the order panel to resend any issued document, including adopted ones, through the configured channel (refused with a message when the channel is `none`).

With `shop_mail`, the PDF is fetched up to 3 times, waiting 3 s and then 7 s between attempts (10 s in total, inside the order-status request). Attempts are retried on no response or a transport error, HTTP 5xx, 404, 429, a non-PDF answer and an iFirma "document not found" code. Authentication and configuration errors (`Kod` 400, 401, 402, 403, 499, 500) and refusals by the module's own rate guard are not retried. After the last failure the delivery error reads "Could not download the PDF after 3 attempts: <last iFirma message>". The customer-page download keeps a single attempt, and a PDF that takes minutes to appear still needs **Send again**.

With `shop_mail`, a copy of each delivery e-mail (with the PDF) can be sent as a blind copy (BCC; PrestaShop's mail layer offers no visible CC). `IFIRMA_SEND_COPY_MODE` selects the recipient: `none` (default), `shop` (the shop contact address `PS_SHOP_EMAIL`) or `employee` (the e-mail of the back-office employee chosen in `IFIRMA_SEND_COPY_EMPLOYEE`). The copy is skipped, and a line "iFirma: delivery copy skipped for order #N (...)" is written to Advanced Parameters > Logs, when the employee no longer exists, is inactive or has no valid e-mail, or when the copy address equals the customer address; the customer e-mail is still sent. Nothing is copied for e-mails sent by iFirma itself (`ifirma_mail`). Saving mode `employee` without choosing an active employee is refused. On an updated install the two keys may be absent; an absent key reads as `none` and `0`.

> Warning: with the default channel, documents start being e-mailed to customers on the next trigger. Select `none` first if you do not want that.

## KSeF (e-invoicing)

From 2026, B2B invoices must be submitted to the National e-Invoicing System (KSeF). The module can submit issued invoices through iFirma (`POST {type}/ksef/send/{id}.json`, body `{"DataWysylki": null}`, meaning send now). The setting is `IFIRMA_KSEF_MODE`:

- `b2b` (default): submit invoices whose buyer has a NIP. The buyer NIP is read from the stored request payload (`Kontrahent.NIP`), and when the payload has none, from the order's invoice address. Invoices to private buyers are skipped.
- `all`: submit every issued invoice, including invoices to private buyers. Private invoices are not required to be sent to KSeF, so use this only deliberately.
- `off`: never submit automatically. The manual button stays available.

Any other stored value is treated as `b2b`.

**Pipeline step `ksef`.** The issuing pipeline runs the `ksef` step between `number` and `deliver`. The step is skipped with a recorded reason when submission is disabled (`off`), the buyer is private in `b2b` mode, the document is already in KSeF, or the document type does not support KSeF submission. When iFirma accepts the submission (`Kod` 0), the receipt records `ksef_status` = `WYSLANA` and `ksef_sent_at`. When the submission fails, the step is non-blocking: delivery still runs, the document stays issued, the result message carries `KSeF: <error>`, and the receipt records `ksef_status` = `BLAD` and `ksef_error`.

**Order panel.** The KSeF row shows one state: not sent (with the reason), sent at (awaiting iFirma), accepted (with the KSeF number), or error (with the message). **Send to KSeF** submits the document regardless of the mode. It is never offered for a document that is already accepted.

**Customer order page.** The KSeF number is shown when one is present. KSeF errors are not shown to the customer.

**Status values.**

- `WYSLANA`: the module submitted the invoice and iFirma has not yet reported a KSeF status. The marker is kept until status sync receives a non-empty `KsefStatus`.
- `PRZYJETA`: iFirma reports that KSeF accepted the invoice; `KsefNumer` is stored with it.
- `BLAD`: the last submission failed. The message is in `ksef_error` and in the order panel.

> Warning: a KSeF submission cannot be withdrawn through the API or the module. Before enabling `all`, decide whether private-buyer invoices should go to KSeF, and check whether the iFirma account already submits invoices to KSeF on its own; the module does not detect that setting, so a second submission is possible.

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

`ps_ifirma_contractor_map` (new in 1.1.0) - one row per customer linking it to an iFirma
contractor: `id_customer` (unique), `ifirma_identifier`, `matched_by` (`module`, `nip`,
`name`, `email`, `import-nip`, `import-email`, `import-name`, `manual`), `ifirma_name`.
Created on install, on upgrade and, when missing, on first use. See "Linking existing
contractors".

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
| `IFIRMA_DOC_TYPE` | `fakturakraj` (the only enabled type; `fakturaparagon` is disabled for now and normalised to `fakturakraj`) |
| `IFIRMA_SIGNATURE_TYPE` (new) | `BPO`: recipient signature (`RodzajPodpisuOdbiorcy`), one of `OUP` (osoba upoważniona do otrzymania faktury VAT), `UPO` (upoważnienie), `BPO` (bez podpisu odbiorcy) or `BWO` (bez podpisu odbiorcy i wystawcy). Any other value, including the old `BRK`, is normalised to `BPO` when saved and when the payload is built. |
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
| `IFIRMA_SEND_COPY_MODE` (new) | `none` (`none`, `shop`, `employee`; absent reads as `none`) |
| `IFIRMA_SEND_COPY_EMPLOYEE` (new) | `0` (employee id, used with mode `employee`) |
| `IFIRMA_KSEF_MODE` (new) | `b2b` (`b2b`, `all` or `off`; any other value is treated as `b2b`) |
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

## Upgrading to 1.2.0

Upload the 1.2.0 module over the existing one and run the upgrade from the Module Manager.
There is no schema or configuration change. `upgrade/upgrade-1.2.0.php` registers the
`displayAdminCustomers` hook, which shows the iFirma panel on the back-office customer page, and
unregisters the legacy `displayAdminOrder` hook.

1.2.0 drops PrestaShop 1.7: the minimum version is 8.0.0. Shops still on 1.7 stay on 1.1.x.

## Customer page

The module adds an **iFirma** panel to the back-office customer view (hook `displayAdminCustomers`). Existing installs get it through the 1.2.0 upgrade
(see "Upgrading to 1.2.0").

The panel shows:

- **Contractor link** - the linked iFirma identifier, name and match type, or `PS-C<id_customer>`
  marked "not linked".
- **Registration check** - each time the customer page is opened, after the page has loaded, the
  module makes one contractor lookup in iFirma (`GET kontrahenci/id/{id}.json`), subject to the
  module's local rate guard. The result is "registered", "not found in iFirma" or "could not check".
- **Local document count** - taken from the module's own log, with no API call: issued, paid, held
  and needs attention. Issued is split into receipts, domestic invoices and other documents.

Buttons:

- **Count in iFirma** - lists the customer's documents with `faktury.json`, filtered by the shown
  contractor identifier (`kontrahent`) and, when an invoice address has a 10-digit NIP, by
  `nipKontrahenta`. The list is read in yearly windows (each at most 365 days, newest first) from the
  customer's registration date, going back at most 10 years, up to today. Each document is counted
  once (by FakturaId); paid means `Zaplacono` >= `Brutto`. At most 20 list calls are made per click.
  When that limit is reached the panel says "At least N document(s)" and shows the period actually
  covered.
- **Find or register a contractor** - the form is prefilled from the customer and their latest
  invoice address (first name, last name, company, NIP, e-mail, phone). iFirma's contractor search
  matches only identifier, name or NIP, so the module sends at most 4 phrases: the NIP, the company,
  the full name, and the last name alone when fewer than 3 phrases exist. E-mail and phone are not
  searchable in iFirma; they are compared with the returned contractors to mark and rank matches
  (NIP first, then the number of matching fields). A search with only e-mail or phone is refused
  with an explanation.
- **Link** - the employee picks a contractor. The module re-reads it from iFirma and stores the link
  with match type `manual` (table `ifirma_contractor_map`). Warning: the next document for that
  customer updates the chosen contractor's name, NIP, address and e-mail in iFirma with the shop data,
  as for every linked contractor.
- **Unlink** - removes the stored link only; nothing changes in iFirma.
- **Register in iFirma** - the same registration as the order panel's "Register contractor in iFirma",
  using the customer's latest order. It is unavailable without an order or when contractor sync is
  disabled.

Link, Unlink and Register require the employee's edit permission on Customers. Check, search and
count need only back-office access with the module token.

## Linking existing contractors

Without any setup the module registers one iFirma contractor per customer (identifier `PS-C<id_customer>`). If you already keep contractors in iFirma you do not want a second record for every returning customer, so the module can link a customer to a contractor that exists. A link is stored in the table `ifirma_contractor_map` (one contractor per customer) and reused for every later order.

### Bulk linking from an iFirma export

1. In iFirma open *Kontrahenci* and export the list to CSV.
2. In the module configuration page open the *iFirma contractors* panel, choose the file (up to 5 MB) and press *Import contractors*.

The file may be separated with `;`, `,` or a tab and encoded as UTF-8 (with or without BOM) or Windows-1250; the separator and encoding are detected. Columns are found by their header, ignoring case and Polish letters:

| Meaning | Accepted headers |
|---|---|
| Identifier (required) | `Identyfikator`, `ID`, `Identifier` |
| Name | `Nazwa`, `Nazwa kontrahenta`, `Name` |
| NIP | `NIP`, `VAT` |
| E-mail | `E-mail`, `Email`, `Adres e-mail` |
| Postcode | `Kod pocztowy`, `KodPocztowy`, `Kod`, `Postal code` |

A file without the identifier column is refused with the message `Missing column: Identyfikator` and nothing is stored. The import never calls the iFirma API; it matches the rows against the shop database in this order:

1. **NIP** equal (digits only) to the VAT number or `dni` of a customer address (`import-nip`);
2. **e-mail** equal to the customer e-mail, ignoring case (`import-email`);
3. **name plus postcode**: the contractor name equals the address company or the customer's first and last name (case, spaces, punctuation and Polish letters ignored) and the postcode digits are equal (`import-name`). A name alone is never enough, and a name shared by several customers with the same postcode is skipped.

Each customer gets one link. A customer that already has a link keeps it unless the new row matches by a stronger rule (NIP is stronger than e-mail, e-mail stronger than name; a link created by the module as `PS-C<id>` is replaced by any import match; a manual link is never replaced). Re-importing the same file changes nothing. The report shows the number of rows read, linked customers per match type, unmatched rows (with the first 20 identifiers) and links kept.

### Matching while issuing a document

For customers that are not in the import, the module looks for an existing contractor when it issues a document, in this order: the stored link (verified in iFirma; dropped when the contractor no longer exists), the module contractor `PS-C<id>`, a contractor with exactly the order NIP (`GET kontrahenci/<NIP>.json`; with several results the one with the same name, then the same postcode), and a contractor whose name equals the buyer name and whose e-mail or postcode confirms it. Name matching without a confirming e-mail or postcode is deliberately refused: a document on the wrong contractor is worse than one extra contractor. When nothing matches, `PS-C<id>` is created as before. After iFirma confirms the create (`Kod` 0), the module reads back the contractor it just created before referencing it: first by the sent identifier `PS-C<id>` and by the identifier iFirma reports in `Wynik`. When neither is found it waits 2 s and reads both back once more. When still nothing is found, it searches with the same rules as before a create (exact NIP; otherwise the buyer name confirmed by e-mail or postcode) and links the customer to the identifier iFirma actually stored (for example `IFI1909556979`) with match type `module`. Only when all of that fails is the document issued without a contractor reference (fallback); the contractor iFirma attaches is then learned from the issued document as before. The order panel's "created" label adds "(iFirma assigned identifier <id>)" when iFirma's identifier differs from the sent one. iFirma returns at most 20 search results and offers no contractor list, which is why the export import exists. A failing search (network error, rejected signature) counts as "no match" and never blocks issuing. A linked contractor is updated with the shop's buyer data only when those fields differ (the same merge as for `PS-C<id>`), and the order panel shows the linked contractor and how it was matched.
Because `IdentyfikatorKontrahenta` accepts at most 15 characters, a link whose identifier is longer is still sent as-is and, when iFirma attaches a different contractor to the issued document, that contractor is stored as the link with match type `document` (label "attached by iFirma to an issued document").

### Linking by hand from the customer page

A single customer can be linked to a contractor from the iFirma panel on the back-office customer page (see "Customer page"). The employee picks the contractor from the search results, and the module re-reads it from iFirma and stores the link with match type `manual`. A manual link is never replaced by a bulk import. Warning: the next document for that customer updates the chosen contractor's name, NIP, address and e-mail in iFirma with the shop data, as for every linked contractor.

### Forgetting links

*Forget all links* in the same panel (it asks for confirmation) empties `ifirma_contractor_map`. Documents already issued are not touched; the next document of each customer is matched again. A single customer can also be unlinked from the customer page (**Unlink**); that removes only the stored link and changes nothing in iFirma.

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
- Product lines use the **gross** unit price (`LiczOd = BRT`). Shipping is added as a line.
- **VAT rates on positions.** Every position is sent with `TypStawkiVat` `PRC`, never `ZW`: the
  fakturakraj table requires `PKWiU` for `ZW` positions and the shop holds no PKWiU codes, so 0%
  lines are sent as `PRC` with `StawkaVat` 0. The rate comes from the order line's stored tax rate
  when present (otherwise from gross/net) and is snapped to the documented values 0, 0.05, 0.08 and
  0.23 when it is within half a percentage point, so rounding noise (for example 7.99%) becomes
  0.08. A rate further away is sent unchanged so that iFirma reports it.
- Business customers (company + VAT/NIP on the invoice address) are sent as
  `OsobaFizyczna = false` with their NIP; everyone else as a private person.
- The contractor identifier the module creates is `PS-C{id_customer}` (at most 15 characters,
  iFirma's documented limit for new contractors). Existing contractors may carry longer
  identifiers; the module stores up to 64 characters, so the same customer always maps to the
  same iFirma contractor.
- The order reference (`PS-ORDER-{id_order}-{reference}`) is stamped in `Uwagi` and in
  `DodatkoweUwagi` (`NUMER_ZAMOWIENIA`) of every document the module issues.
- Text fields are truncated to iFirma's documented maxima before sending (e.g. buyer name 150,
  e-mail 65, street/city 65, product name 500, remarks 1000 characters).
- An approved customer data-change request overrides the buyer data for the iFirma document
  only; the PrestaShop order and addresses are never modified.

## Compatibility

PrestaShop **8.0 – 9.x**, PHP **7.2 – 8.x**. PrestaShop 1.7 is supported up to module 1.1.x. Verified with the Docker smoke suite on PrestaShop 8.1.7 (PHP 8.1) and 9.1.4 (PHP 8.5); see `tests/smoke/README.md`. Requires the PHP `curl`, `hash`
and `mbstring` extensions.

### PrestaShop 9.x notes

The module is written to be legacy-safe on PS 9 (PHP 8.1+):

- No `Tools::encrypt()` — download tokens use `random_bytes()` (CSPRNG).
- No undeclared dynamic properties (PHP 8.2 safe); all model fields are declared.
- Deprecated `Tools::strlen/substr/strtoupper` wrappers replaced by native
  `strlen` / `mb_*` calls.
- Version compliance declared up to `9.99.99`.
- The order panel renders through `displayAdminOrderMainBottom`; the deprecated
  `displayAdminOrder` hook is not used.

## Development

Unit tests run without PrestaShop:

```bash
composer install
tests/vendor/bin/phpunit
```

The tests load lightweight stubs of the PrestaShop classes the module uses (`Configuration`, `Db`, `ObjectModel`, `Order`, `Mail`, `Context`, ...) from `tests/Stubs/`, so module classes can be exercised in memory: the database is scripted per query, configuration and mail are recorded, and the HTTP layer can be replaced with `tests/Support/ScriptedTransport.php`. Call `IfirmaTestState::resetAll()` in `setUp()`; the stub API is documented in `tests/Stubs/README.md`. Tests, `vendor/` and `composer.*` are never shipped in the module package.

Other checks:

```bash
npm run build                              # installable zip in dist/ + npm tarball in the repo root
npm run verify:package                     # layout + ifirma/BUILD check of every zip in dist/
npm run verify:package -- --channel test   # ... and require that BUILD channel (CI and releases do this)
python tools/check_license_headers.py      # every source file must carry the license header
```

`python tools/check_license_headers.py` also fails on known header typos listed in its `FORBIDDEN` tuple.

### Test stack (git submodule)

The mock iFirma API and the Docker test stack live in the `test-stack/` git submodule
(<https://github.com/SecCodeSmith/test-stack>, public, MIT licensed). This repo pins one commit of it.

```bash
# fresh clone; the folder must be named ifirma (the test bootstrap resolves _PS_MODULE_DIR_ from it)
git clone --recurse-submodules https://github.com/SecCodeSmith/prestashop-integration-with-iFirma.git ifirma

# existing clone
git submodule update --init
```

To update the pinned mock, commit and push the change in the `test-stack` repo first. Then, in the module repo:

```bash
git -C test-stack pull origin master
git add test-stack
git commit -m "Update test-stack submodule"
```

CI's `mock` job checks the submodule out and fails if it is missing.

### Builds and their identity

Every package carries an `ifirma/BUILD` manifest (JSON: `version`, `build`, `commit`, `builtAt`, `channel`), generated
at build time and never written to the working tree. The module version in `ifirma.php` / `config.xml` is **not**
changed by a test build, so PrestaShop's `ps_module.version` (8 characters) and upgrade-script selection behave
exactly as for the matching release. `scripts/build-zip.mjs` reads:

| Variable | Values | Effect |
|---|---|---|
| `IFIRMA_BUILD_CHANNEL` | `local` (default), `test`, `release` | `test` names the zip `ifirma-<version>+build.<n>.zip` |
| `IFIRMA_BUILD_NUMBER` | positive integer, required for `test` | CI uses `github.run_number` |
| `IFIRMA_BUILD_COMMIT` | commit hash (cut to 7 chars) | defaults to `git rev-parse HEAD` |

```bash
npm run build:zip                                                    # dist/ifirma-<version>.zip, channel local
IFIRMA_BUILD_CHANNEL=test IFIRMA_BUILD_NUMBER=7 npm run build:zip    # dist/ifirma-<version>+build.7.zip
```

**Test builds.** CI (`.github/workflows/ci.yml`) builds a test package on every push, pull request and manual run,
only after lint, PHPUnit and the mock tests pass. Download it from the run's *Artifacts* section
(`ifirma-<version>+build.<n>`, kept 90 days).

**Releases.** Actions -> *Release* -> *Run workflow* with `patch`, `minor` or `major`. The workflow runs PHPUnit, lint
and the licence check, bumps and syncs the version (`package.json`, `package-lock.json`, `ifirma.php`, `config.xml`)
into a local commit + tag `v<version>`, builds and verifies `ifirma-<version>.zip` (channel `release`), keeps the
verified zip and tarball as the run's `release-files` artifact, and only then pushes and publishes the GitHub Release.
A failure before the push leaves the remote untouched, and the push is atomic: if the remote refuses the commit or the
tag, neither lands. If only the publish step fails, the tag already exists: download and unpack the run's
`release-files` artifact and publish those files by hand from that folder,

```bash
gh release create v<version> --title v<version> --generate-notes dist/ifirma-<version>.zip scm-ifirma-<version>.tgz
```

To rebuild the files instead, check out the tag and run `npm ci`, `IFIRMA_BUILD_CHANNEL=release npm run build` and
`npm run verify:package -- --channel release`; they land at the same paths. Deleting the tag and running the workflow
again does not redo that version: its release commit is already on `master`, so the next run releases the version
after it.

After publishing, the workflow starts CI on the new tag, so the release commit gets its own CI run and test build
(`ifirma-<version>+build.<n>`). Pushes made with the workflow's built-in token do not trigger CI on their own. If only
that last step fails, start CI by hand: Actions -> *CI* -> *Run workflow* on tag `v<version>`.

### Dev dependencies and PrestaShop

PrestaShop loads `modules/*/vendor/autoload.php` at boot and Composer prepends its autoloader, so dev
dependencies installed in a module root shadow PrestaShop's own classes (observed: PHPUnit's `nikic/php-parser` v5
breaking PrestaShop 9's module parser, and test stubs replacing `Module`). Composer therefore installs into
`tests/vendor/` (`config.vendor-dir`), a path PrestaShop never loads. Do not move it back, and never deploy a
`vendor/` directory; the release zip and the npm tarball exclude `tests/`.

## Troubleshooting

**After updating the module, clear the PrestaShop cache** (Advanced Parameters > Performance > Clear cache).
With *Template compilation* set to "Never recompile template files" (the common production setting),
PrestaShop keeps serving the old admin panel and customer templates until the cache is cleared, even though
the PHP code is already new. The module version shown in the panel diagnostics tells you which code runs.

**`Nie znaleziono kontrahenta o zadanym identyfikatorze` (Kod 201).** iFirma looks an existing contractor up
through the document's top-level `IdentyfikatorKontrahenta` field, then by the buyer's NIP (`NIPKontrahenta`),
and otherwise creates a contractor from the `Kontrahent` data. The module never sends `Kontrahent.Identyfikator`.
`IdentyfikatorKontrahenta` accepts at most 15 characters (`NIPKontrahenta` 13), so a contractor whose identifier is longer (for example an
`EC_`-prefixed name created by another integration) cannot be referenced. In that case the module still sends it; when iFirma ignores or rejects it, the document gets
the contractor iFirma attaches or creates, and the module records that one on
the order panel as "attached by iFirma to an issued document"; later orders of that customer reuse it. If iFirma
rejects a referenced identifier with this message, the module retries once without it and reports a second
rejection as an error. Version 1.1.0 registers the contractor `PS-C<customer id>` before issuing (see
"Notes & assumptions") and falls back to a document without identifier when that fails. If you still see
this message: (1) confirm the module version is 1.1.0 and the cache was cleared; (2) open the order's iFirma
panel, expand **Diagnostics: last iFirma request and response**, and check the *Contractor step* line and the
request body; (3) as a workaround, disable *Register the customer as an iFirma contractor* in the module
settings so every document is created without an identifier.

**`Zatrzymano na kroku „guard” (inconclusive)` / "stopped at the guard step".** Before issuing, the duplicate guard
lists the customer's recent iFirma documents with `faktury.json?kontrahent=<identifier>`. When the identifier does
not exist in the iFirma account (for example `PS-C<customer id>` of a customer that was never registered), iFirma
answers this list call with Kod 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze" instead of an empty list.
Older builds treated every failed list call as inconclusive, so the order stopped at the guard step and no document
was attempted. Builds from 2026-11-02 19:30 onward treat this answer, for the filtered query only, as "no documents for
that contractor" and continue with the NIP query and the unfiltered window query. Other failures (network,
authentication, rate limit, or a Kod 201 about something else) still stop the pipeline. On an older build, install the
new module zip and clear the PrestaShop cache. As a manual workaround for one order, use **Wystaw mimo to / Issue
anyway** in the order's iFirma panel; it bypasses the duplicate guard once for that order and is recorded on the receipt.

**"tryb awaryjny" / "fallback" on the contractor line of the order panel.** The document was still issued, but without a contractor reference. The contractor iFirma attached is shown after the note and is reused for the next order of that customer. The note in parentheses says which stage failed. After a confirmed create it lists each identifier tried with iFirma's answer (for example `PS-C1909: Kod 208: Nie znaleziono kontrahenta`) and whether the search found nothing or found an ambiguous result. What to check: (1) the module version (builds from 2026-11-04 onward) and that the PrestaShop cache was cleared; (2) the Diagnostics block of the order panel (request body and the `_contractor` note); (3) as a workaround, the *Register the customer as an iFirma contractor* switch in the module settings. Records written before this version show only an English sentence.

**`Niepoprawna zawartość żądania - nie można utworzyć obiektu na podstawie zawartości żądania` (Kod 200).** iFirma could not map the JSON sent to it onto a document object. The message never names the offending field; the usual cause is an undocumented enumeration value. Builds before 2026-11-02 19:45 sent `RodzajPodpisuOdbiorcy: BRK`, a value that does not exist in the iFirma API. Fix: install the current module zip (the *Recipient signature* setting defaults to `BPO`, and any other stored value is normalised to `BPO`), then clear the PrestaShop cache. To check a request yourself, expand **Diagnostics: last iFirma request and response** in the order's iFirma panel and compare every enumerated field with the documented lists: `LiczOd`, `FormatDatySprzedazy`, `SposobZaplaty`, `RodzajPodpisuOdbiorcy` (`OUP`, `UPO`, `BPO`, `BWO`) and `TypStawkiVat`. Kod 200 never names the field, so when the request contained `DodatkoweUwagi` (the only optional object the module sends) the module retries once without it; if the retry is also rejected, or the request had no `DodatkoweUwagi`, the error is recorded.

**`KSeF: <error>` (after issuing a document).** The document was issued and delivery ran, but the KSeF submission failed. The receipt shows state error (`BLAD`) with the same message. Check: (1) the iFirma account is authorised to send invoices to KSeF (the KSeF authorisation is configured in iFirma, not in the module); (2) the buyer data on the invoice is complete, since a B2B invoice needs a valid NIP; (3) retry with **Send to KSeF** in the order panel; if the same error returns, copy the diagnostics block and the message. Then refresh the status in the order panel: once iFirma reports `PRZYJETA`, the number appears on the receipt and the customer page.

**Reporting a problem.** Copy the diagnostics block (request, response, contractor step, last error) and the
relevant lines from Advanced Parameters > Logs (messages start with "iFirma"). Neither contains API keys.

## Legal Disclaimer & Trademarks

iFirma is a registered trademark of IFIRMA SA.

This module is an independent third-party open-source integration and is not officially affiliated with, endorsed by, or sponsored by IFIRMA SA.

The name "iFirma" and associated references are used strictly for identification, nominative reference, and descriptive compatibility purposes (interoperability / prawo cytatu) to inform users about the third-party accounting system with which this PrestaShop module integrates.