# Usage

How the module is used day to day: issuing documents, states that need attention, the Orders list, monitoring, the duplicate guard, what the customer sees, delivery, KSeF, the back-office customer page and customer data-change requests.

[Back to README](../README.md)

## Issuing documents (back office)

When an order reaches the status chosen in **Auto-issue status** (0 = off), the module
runs one issuance pipeline for that order. The admin **Create Receipt** button runs the
very same pipeline, so a manual and an automatic run behave identically. Six steps run in
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
5. **KSeF** - submits the invoice to KSeF through iFirma when the mode asks for it (see "KSeF (e-invoicing)"). The step is non-blocking: a failure is recorded on the receipt and delivery still runs.
6. **Deliver** - the document is sent through the configured channel (see "Delivery").
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

### Orders list

The back-office Orders list has an **iFirma** column, placed before the Actions column. An order whose newest module record is an issued document (it has an iFirma identifier and is not in `error`) shows a receipt icon: green when the document is paid, blue otherwise. The tooltip reads `iFirma: <number> (<state>)`, where the number falls back to the iFirma identifier until a number is stored, and the state is "Paid" or "Issued". Orders without an issued document have an empty cell. The column is not sortable or filterable and does not change paging or totals; a click on the cell opens the order like the rest of the row. The column reads the module's own `ifirma_receipt` table and makes no iFirma API call.

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

**Rate limits:** see [Rate limits](configuration.md#rate-limits).

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

Settings: see [Duplicate guard settings](configuration.md#duplicate-guard-settings).

**Caveat:** do not run iFirma's own PrestaShop integrator and this module's automatic
issuance on the same iFirma account; the guard is a safety net, not a coordination
mechanism, and cannot prevent two systems issuing documents at the same moment.

## What the customer sees

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

## Customer page

The module adds an **iFirma** panel to the back-office customer view (hook `displayAdminCustomers`). Existing installs get it through the 1.2.0 upgrade
(see [Upgrading to 1.2.0](upgrading.md#upgrading-to-120)).

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

The panel is wrapped in a full-width column (`col-12`), so it spans the hook row and lines up with the other cards of the page; no data or action changed.

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
