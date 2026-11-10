# Usage

How the module is used day to day: issuing documents, states that need attention, the Orders list, the iFirma documents list with its bulk actions, monitoring and the notification tab, the duplicate guard, what the customer sees, delivery, KSeF, the back-office customer page, customer data-change requests (with automatic approval) and guest orders.

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
   If your iFirma account has more than one print template, enter the exact name of the one to use in
   **Document template** ([settings](configuration.md#settings-walk-through)); the module sends it with every
   document and iFirma prints the logo and layout defined in that template. Leave the field empty to use the
   default template. A name that does not exist in your iFirma account makes document creation fail (see
   [Create fails after setting a document template](troubleshooting.md#create-fails-after-setting-a-document-template)).
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

### Queued issuance

Every order that reaches the trigger status gets a record in the state `queued` **before** the module sends anything to
iFirma, so no exit path can lose it: a refusal by the rate limit, a request that ends early, a busy lock. The same status
change then tries to issue the order at once, under the order's lock, which it does not wait for. In the normal case
the record goes `queued` -> issued within the same request and nobody sees the state.

- **Refused by the rate limit.** When the local minute or day cap, a back-off after an iFirma limit answer
  ([Rate limited by iFirma](troubleshooting.md#rate-limited-by-ifirma)) or iFirma itself refuses a request before the
  document exists, the order stays `queued` with the refusal message. It is not an error and nothing is created. This holds on
  every path: the status change, **Create Receipt**, the bulk **Retry issuance** and the bulk issue of the Orders list (the
  employee still sees the refusal message at once). **Issue anyway** is not offered for a queued order, because bypassing the
  duplicate guard would not get past the limit.
- **What drains the queue.** The [cron endpoint](configuration.md#rate-limits) issues queued orders, oldest first, through the full
  pipeline (duplicate guard included), at most `IFIRMA_CRON_MAX_ITEMS` per run, and stops at the first refusal. Without a
  cron job, **Create Receipt** or the bulk **Retry issuance** issues them. Call the endpoint every 1 to 5 minutes.
- **The create request in flight.** Right before the create request is sent, the record becomes `unknown` with the message
  "iFirma has not answered the create request yet". The answer is stored over it. A request that dies in between therefore
  leaves `unknown`, which no automatic run retries blindly: a later attempt always runs the duplicate guard first, so it
  adopts a document iFirma did create. A request refused locally never writes the marker.
- **Other failures.** A queued order that fails for another reason (network error, rejected data) leaves the queue in the
  state that failure records for a new order (`error` or `unknown`), so the cron does not retry it blindly.
- **Orders that keep their state.** An order with an issued document, a held order (pending data-change request), an
  unknown outcome and a suspected duplicate are never turned into a queue entry.
- **Alert.** A record that has been `queued` for more than 30 minutes needs attention (notification tab and documents-list
  preset): a shop without a cron job notices it that way.
- **Nothing is queued** while the trigger status is 0 or the credentials are missing, and no order is queued
  retroactively when a shop starts issuing.

Each button needs a [permission level](configuration.md#permissions). Without View the panel is not shown
at all. With View the employee sees the state, the candidates, the request details and the **Download PDF**
link only. **Create Receipt**, **Issue anyway**, **Link this document** and **Register contractor in iFirma**
need Issue. **Update status in iFirma**, **Send again**, **Send to KSeF**, **Refresh from iFirma**, **Approve**
and **Reject** need Manage. The same levels are checked again on the server for every action, so a hidden
button cannot be replaced by a hand-made request.

### States that need attention

| State | Meaning | What to do |
|---|---|---|
| `unknown` | The create request got no usable answer (timeout, transport error), so the document may or may not exist in iFirma. The module never repeats the create blindly. | Click **Create Receipt** again: the duplicate guard first looks for the document; if it exists it is adopted, if nothing is found a new one is created. |
| `duplicate_suspected` | The guard found a document of the same buyer with the same total, sale date and products that it could not confirm by the order reference (stopped at the **guard** step). Nothing was created. | Open the candidates in the panel and **Link** the right one, or choose **Issue anyway** after checking iFirma. Orders blocked by an earlier module version, which matched on the amount alone, can simply be re-run with **Create Receipt**: the guard runs again with the current rules. |
| `queued` | The order waits for its document: it just reached the trigger status, or a run was refused by the rate limit (the message says by which window). Nothing was created. | Nothing, as a rule: the status change or the cron endpoint issues it. **Create Receipt** issues it at once when the limits allow. A queue entry older than 30 minutes is listed as needing attention (no cron job?). |
| `held` | A customer data-change request is pending (stopped at the **hold** step). | Approve or reject the request under the order; the pipeline then runs automatically when the trigger status was already reached, or click **Create Receipt**. |
| `error` | iFirma rejected the document or could not be reached before anything was sent; the reason is in the last error. | Fix the cause (data, credentials, limits) and click **Create Receipt**. |

### Orders list

The back-office Orders list has an **iFirma** column, placed before the Actions column. An order whose newest module record is an issued document (it has an iFirma identifier and is not in `error`) shows a receipt icon: green when the document is paid, blue otherwise. Hover the icon for a multi-line tooltip in the employee's language. The first line names the kind and the state: "Faktura wystawiona", "Faktura wysłana", "Faktura opłacona", "Faktura czeka na KSeF" or the "Paragon ..." forms, then the number (the iFirma identifier until a number is stored). Then come the delivery line (sent to the customer on a date, not sent yet, or delivery failed), "unpaid" when the document is not paid, and a KSeF line when the document has a KSeF state (accepted with the KSeF number, sent and awaiting confirmation, error, rejected). An order without an issued document shows its state in words and the hint to click ("No document yet - click to issue", "Issuance failed: <the first 100 characters of the error> - click to issue", "Queued (iFirma limit)", "Unknown outcome", "Suspected duplicate", "Held (data change request)"), and the **Issue** action when the employee has the issue level (see below); an employee without it sees an empty cell. All values are escaped.

**Sorting and filtering.** The column is sortable and filterable by state (a choice list with the ten states below; the count and the paging follow the filter, and an unexpected value is ignored). The documents list sorts its state column in the same order, newest first within a state. Ascending order is: issuance failed, unknown outcome, suspected duplicate, held, queued, no record, issued and not sent (a failed delivery included), waiting for KSeF, sent, paid; descending reverses it, and orders in the same state are listed newest first. The sort reads the local record only and makes no iFirma call, and it does not change paging or totals. A click on the cell opens the order like the rest of the row.

**Filtering by iFirma state.** The filter of the iFirma column is a choice list with ten choices, in this order: Issuance failed, Unknown outcome, Suspected duplicate, Held (data change request), Queued (iFirma limit), No document yet, Issued, not sent, Waiting for KSeF, Sent, Paid. It is the same order as the ascending sort. Choosing one lists only the orders in that state, and the total and the paging count the filtered orders. A delivery that failed is part of "Issued, not sent". Another value in the request is ignored and lists every order. The filter reads the local record only and makes no iFirma call; clear it with the grid's reset button.

**Retry state in the tooltip.** When the delivery of an issued document failed, the tooltip adds one line under "Delivery failed" that tells where the automatic retry stands (see [Automatic retry of a failed delivery](#automatic-retry-of-a-failed-delivery)). The line is left out where the shop has not added the retry columns yet, so the Orders page always opens.

**Issue from the list.** An employee with the issue level sees an **Issue** icon (a plus sign) in the iFirma cell of every order without an issued document. A click asks for confirmation, runs the same pipeline as **Create Receipt** for that order, duplicate guard included, and replaces the cell with the new state. The tooltip of the icon names the last state of the order's record when there is one: failed, unknown outcome, suspected duplicate or held. This is the only action of the column that calls iFirma, and only when clicked.

**Bulk issue.** The bulk action **Issue iFirma documents** issues the selected orders the same way. It runs in batches of 3 orders per request, with the same stopping rules, summary and log entry as the [iFirma documents list](#ifirma-documents-list). Orders that already have an issued document are reported as skipped.

## iFirma documents list

**Orders > iFirma documents** is the back-office list of the module's own records (one row per record in
`ifirma_receipt`), newest first. It needs the view level of the module's tab (see
[Permissions](configuration.md#permissions)). The list reads the local table only and sends no request to
iFirma.

**Columns:** ID, order reference, customer, document number (the iFirma identifier until a number is stored),
status, source (`module` or `external`), delivery, KSeF, issue date, last error (shortened) and the date of the
last change.

**Filters:** status, source, delivery, KSeF, order reference, document number, issue date and the date of the
last change. The status values are Created, Paid, Error, Unknown, Suspected duplicate and Held.

**Delivery column.** The delivery cell shows the state in words. For a failed delivery it adds a second line, under the state, that tells where the automatic retry stands: "Attempt 1 of 3, next attempt at 2026-11-10 10:15", "No automatic attempt remains", "No automatic attempt will be made" or "No automatic attempt is scheduled" (a delivery that failed before the retry existed). See [Automatic retry of a failed delivery](#automatic-retry-of-a-failed-delivery). The text is escaped.

**State sort order.** The status column sorts by state, not by the stored word. Ascending order equals the order of the constants in `IfirmaDocumentStateRank`: issuance failed (1), unknown outcome (2), suspected duplicate (3), held (4), queued (5), no record (6), issued and not sent (7), waiting for KSeF (8), sent (9), paid (10). Records in the same state are listed newest first. The sort is the same as the sort of the iFirma column of the Orders list.

**Needs attention.** The **Needs attention** button in the page header shows only the records with one of these
problems:

- status Error, Unknown outcome or Suspected duplicate;
- status Held while the order still has a pending data-change request;
- delivery Failed;
- KSeF failed (`BLAD`);
- marked missing in iFirma.

The notification tab uses the same conditions (see [Monitoring](#monitoring)). **All documents** in the page
header goes back to the full list.

**Row actions.** A record is issued when it has an iFirma identifier and its status is not Error.

| Action | Shown for | Level |
|---|---|---|
| Open order | every row | the back-office access to the order |
| Retry issuance | a record that is not issued | issue |
| Send again | an issued record | manage |
| Refresh | an issued record | manage |
| Download PDF | an issued record | view |

**Retry issuance** runs the same pipeline as **Create Receipt**, with the duplicate guard: a document the guard
finds is adopted, and nothing is bypassed. A held record stops at the hold step, and the message names the
pending data-change request.

**Bulk actions.** The selected rows are handled by one of three bulk actions:

| Bulk action | Level | Applies to | Records per request |
|---|---|---|---|
| Retry issuance | issue | records that are not issued; issued records are skipped | 3 |
| Send again | manage | issued records, through the configured delivery channel; other records are skipped | 5 |
| Refresh from iFirma | manage | issued records, one list call per issue date; other records are skipped | 25 |

The server checks the level for the whole run before anything happens. An employee without it gets HTTP 403 with
a message, and nothing is changed or sent.

**How a bulk run works.** The browser sends the selection in batches of the size shown above and keeps the rest of
the selection until every record has been attempted; no further clicks are needed. A batch also ends early when it
has used 20 seconds, and the records left over go into the next request. Nothing is queued on the server.

The run stops in one of two cases:

- the module's per-minute rate guard refuses a request (see [Rate limits](configuration.md#rate-limits));
- iFirma rejected the credentials, so the 15-minute pause applies (see [Troubleshooting](troubleshooting.md)).

The records that were not yet attempted are then reported as **Not attempted**, with the message
`not attempted (rate limited)` or `not attempted (credentials rejected)`, and the run asks you to run it again
in a minute in the first case. Records already handled keep their outcome, and the records that were not reached
keep their previous state.

**Summary.** When the run ends, a dialog lists every selected record with its order or ID, its outcome and its
message, and shows the totals per outcome. The outcomes are Issued, Adopted (the guard found the existing
document), Suspected duplicate, Held, Failed, Sent, Refreshed, Skipped (for example, already issued, or delivery
disabled with the channel set to none) and Not attempted. The dialog closes when the run has ended, and closing it
reloads the list. Each run writes one entry to Advanced Parameters > Logs with the employee, the action and the
totals. The entry is written when the last batch is answered or the run stops; a run whose connection to the
server fails in the middle writes none.

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
   The summary also carries a `usage` block with the module's own requests, read after the jobs:
   `{"minute": 6, "day": 250, "by_kind": {"document": 2, "status": 200, "pdf": 40, "email": 0, "ksef": 0,
   "payment": 0, "contractor": 8, "limit": 0, "other": 0}}` - the requests of the current minute, of today and
   today's count per kind (all nine kinds, zeros included). The block is also there when the run was refused by the
   limit. See [Module requests](configuration.md#module-requests).

**Rate limits:** see [Rate limits](configuration.md#rate-limits).

### The iFirma tab in the notification bell

An employee with the view level sees an **iFirma** tab in the back-office notification bell, on every page. The
tab lists the newest 20 records that need attention, newest first, each with its order reference, its kind of
problem and the time of its last change:

| Kind | The record |
|---|---|
| Held by a data request | is held while a data-change request is pending |
| Issuance failed | has status Error |
| Unknown outcome | has status Unknown |
| Suspected duplicate | has status Suspected duplicate |
| Missing in iFirma | was marked missing by the status sync |
| KSeF failed | has the KSeF state `BLAD` |
| Delivery failed | has delivery status Failed |

A record with several problems is listed once, under the first kind of this list. The full list is the
**Needs attention** preset of the [documents list](#ifirma-documents-list). Clicking an entry opens the order.

The tab's counter shows the records that need attention and changed since the employee last looked at the tab. The
mark is kept per employee: opening the tab, or opening the bell while the tab is open, marks them as seen, and the
bell's total is raised by the unseen count. The tab refreshes every 2 minutes from the module's local data (no
iFirma call) and pauses while the browser tab is hidden.

If the page has no notification bell, the script adds a small iFirma button with the same list to the header. If
the header has no place for it, nothing is added.

## Duplicate guard

Before the module creates a document it checks whether the order already has one,
whether created by this module earlier, by iFirma's own e-commerce integrator or by
hand. How it works:

1. **Reference stamping.** Every document the module creates carries the order stamp
   `{prefix}-{id_order}-{order reference}` in `Uwagi` and as a `NUMER_ZAMOWIENIA`
   additional remark. The prefix is the **Order stamp prefix** setting
   ([settings](configuration.md#settings-walk-through)) and defaults to `PS-ORDER`.
   When several shops share one iFirma account, give each shop its own prefix (for example
   `scmedical`) so the duplicate check never mixes up their orders; a stamp with another shop's
   prefix is never a match. Documents issued earlier with the default `PS-ORDER` stamp are still
   recognised after you change the prefix: the guard looks for the configured stamp and for
   `PS-ORDER-{id_order}-{order reference}`. If **Shop webpage** is set, it is printed in `Uwagi`
   after the stamp (stamp, a space, the address; the 1000-character limit cuts the address, never
   the stamp). The `NUMER_ZAMOWIENIA` entry holds only the stamp.
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

### Download limits

The customer download (`controllers/front/receipt.php`) fetches the PDF from iFirma on every request, so it is
limited: a document can be fetched once every 30 seconds, and a customer can download at most ten documents in any
60 seconds. A request over one of these limits gets HTTP 429 with the text "Please wait a moment before downloading
the document again."

Customer downloads also have a budget of their own, shared by all customers: 20 downloads that reach iFirma per
calendar minute and 2,000 per calendar day (shop time zone). The budget is separate from the module's request limit
(80 per minute, 14,000 per day, see [Rate limits](configuration.md#rate-limits)), so customers downloading
documents cannot use up the requests that issuing documents, the status sync and the cron need. A download over the
budget gets HTTP 429 with `Retry-After: 60` and the text "Too many requests at the moment. Please try again shortly."
(the same answer as when the module's request limit refuses a download), sends nothing to iFirma and is not counted.
The budget is checked after the login, token, owner and KSeF checks, so those answers do not change. The caps are
fixed, not settings.

The back-office **Download PDF** needs the view level and has a budget of its own, separate from the customers': all
employees together can send 30 downloads per calendar minute. A download over it answers HTTP 429 with a `Retry-After`
header (the seconds to the next minute) and the text "The PDF was not downloaded: the back-office download limit is
reached. Try again in a moment."; nothing is sent to iFirma. Issuing documents is not affected. An employee without the
view level still gets the access-denied answer first.

## Delivery

After a document is issued the module e-mails it to the order customer as the last step of the pipeline and records the outcome (status, channel, timestamp, error) on the receipt. The channel is set with `IFIRMA_SEND_CHANNEL`:

- `shop_mail` (default): the PDF is fetched from iFirma and attached to the `receipt_issued` e-mail sent by the shop mail system, in the order language (Polish and English templates in `mails/`; other languages fall back to English).
- `ifirma_mail`: iFirma sends the e-mail itself (`POST {type}/send/{id}.json`, recipient `SkrzynkaEmailOdbiorcy`, text from `IFIRMA_SEND_TEXT`, cut to 1000 characters; only `Kod` 0 counts as success). Only the documented types are supported (`fakturakraj` is a documented send type and is the only type created, so this channel is available for every document). If a type without iFirma send support is ever enabled again, such as `fakturaparagon`, the module automatically falls back to shop mail and records the channel as `shop_mail_fallback`.
- `none`: nothing is sent and delivery is recorded as skipped.

Documents adopted from iFirma (created elsewhere) are never auto-delivered; they are recorded as skipped (external). A failed delivery never undoes the issuance; the error is shown only in the back office. Use **Send again** in the order panel to resend any issued document, including adopted ones, through the configured channel (refused with a message when the channel is `none`).

**File names.** The PDF of a document is named `<shop-domain>-<number>.pdf`, for example
`hialumed.pl-FV_7_2026.pdf`, both when it is downloaded (by the customer or in the back office) and
when it is attached to the e-mail. The shop domain is the host of the **Shop webpage** setting (lower
case, without `www.`), or the shop's own domain when the setting is empty; characters other than
letters, digits, `.`, `_` and `-` become `_`. Mail filters or scripts that match the old `ifirma-*.pdf`
names must be changed (see [Upgrading to 1.6.0](upgrading.md#upgrading-to-160)).

**E-mail branding.** The e-mails of the module show the shop logo and name at the top and the shop
webpage at the bottom. The address comes from the **Shop webpage** setting
([settings](configuration.md#settings-walk-through)); empty means the shop's own address. The logo on
the printed document is a different thing: it comes from the print template of your iFirma account (see
**Document template** under [Issuing documents](#issuing-documents-back-office)).

With `shop_mail`, the PDF is fetched up to 3 times, waiting 3 s and then 7 s between attempts (10 s in total, inside the order-status request). Attempts are retried on no response or a transport error, HTTP 5xx, 404, 429, a non-PDF answer and an iFirma "document not found" code. Authentication and configuration errors (`Kod` 400, 401, 402, 403, 499, 500) and refusals by the module's own rate guard are not retried. After the last failure the delivery error reads "Could not download the PDF after 3 attempts: <last iFirma message>". The customer-page download keeps a single attempt, and a PDF that takes minutes to appear still needs **Send again**.

With `shop_mail`, a copy of each delivery e-mail (with the PDF) can be sent as a blind copy (BCC; PrestaShop's mail layer offers no visible CC). `IFIRMA_SEND_COPY_MODE` selects the recipient: `none` (default), `shop` (the shop contact address `PS_SHOP_EMAIL`) or `employee` (the e-mail of the back-office employee chosen in `IFIRMA_SEND_COPY_EMPLOYEE`). The copy is skipped, and a line "iFirma: delivery copy skipped for order #N (...)" is written to Advanced Parameters > Logs, when the employee no longer exists, is inactive or has no valid e-mail, or when the copy address equals the customer address; the customer e-mail is still sent. Nothing is copied for e-mails sent by iFirma itself (`ifirma_mail`). Saving mode `employee` without choosing an active employee is refused. On an updated install the two keys may be absent; an absent key reads as `none` and `0`.

> Warning: with the default channel, documents start being e-mailed to customers on the next trigger. Select `none` first if you do not want that.

### Automatic retry of a failed delivery

A delivery that failed for a temporary reason is sent again by the [cron endpoint](configuration.md#cron-jobs); nobody has to click. Without the cron job nothing is retried automatically and **Send again** remains the only way.

**What is retried.** A delivery that failed because the shop mail system refused the e-mail, because iFirma did not have the PDF ready after the download attempts of that run, or for another temporary reason (an HTTP 5xx answer, an iFirma code that is not on the permanent list). A retry uses the channel configured at that moment and records its outcome like any delivery. A KSeF invoice that was accepted and then failed to be e-mailed is retried the same way.

**The schedule.** A document gets at most three automatic attempts, the first one (at issuance, or after KSeF acceptance) included:

| Attempt | Not earlier than |
|---|---|
| 1 | at issuance |
| 2 | 15 minutes after the first failure |
| 3 | 2 hours after the second failure |

The cron run delivers the documents that are due, the oldest due time first, at most `IFIRMA_CRON_MAX_ITEMS` per run (the others wait for the next run). The delivery retry is the last job of a run. Because the cron is called every 1 to 5 minutes, an attempt starts a little after its due time. After the third failure the record stays Failed with the last error and the cron never tries it again.

**What is never retried.** A permanent failure: the customer has no valid e-mail address, the document is missing in iFirma, the API key is missing, or iFirma rejected the login or refused the request as invalid. A failure whose outcome is unknown: with the channel `ifirma_mail`, a send request that reached iFirma and got no answer, a gateway error or an unreadable answer (the e-mail may have gone out, so a retry could send it twice). Such a record stays Failed and needs attention: use **Send again** after you checked. With the channel set to `none` the cron sends nothing for a record that waits for a retry. A delivery that failed before the module version that introduced the retry (1.10.0) is not retried either.

**The limit does not count.** When the request limit or the cool-down after rejected credentials refuses a delivery before any request is sent, nothing is e-mailed, the attempt is not counted and the delivery stays due. The retry job stops at the first such refusal and goes on in the next run. The same holds for the first delivery of a just issued document: it shows Failed with the refusal, no attempt is counted and the cron delivers it later.

**A lost outcome is never sent twice.** Before the cron sends anything it saves the attempt: the attempt is counted, the due time is cleared and the error reads "A delivery attempt started and its outcome was not recorded. Check whether the customer received the document before sending it again." A normal attempt then replaces that text with its result. If the process stops, or the result cannot be saved, after the e-mail left, the record stays Failed with that text, the cron never picks it up again and it needs attention: check with the customer or the mail log, then use **Send again** if the document did not arrive. If the attempt cannot be saved at all, nothing is sent and the cron tries again in the next run.

**Send again.** **Send again** works as before, at any time, and a success ends the automatic retries of that document. A failed **Send again** leaves the attempts already used and the due time as they were. The cron only delivers a record that is still Failed when its turn comes, and a cron run and an employee cannot deliver the same document at the same moment, so the customer receives one e-mail. With the copy mode on, one copy is sent, with the attempt that succeeded.

**What the employee sees.** The order panel, the Orders-list tooltip and the delivery column of the iFirma documents list show, next to the error of a failed delivery, one of these lines:

| Line | Meaning |
|---|---|
| Attempt 1 of 3, next attempt at 2026-11-10 10:15 | one attempt was used; the next one is due at that time (shop time) |
| No automatic attempt remains | the three attempts are used; use **Send again** |
| No automatic attempt will be made | the failure is permanent or its outcome is unknown; use **Send again** |
| No automatic attempt is scheduled | a delivery that failed before the retry existed; use **Send again** |

The error text and the line are escaped. A delivery that did not fail shows no such line.

## KSeF (e-invoicing)

From 2026, B2B invoices must be submitted to the National e-Invoicing System (KSeF). iFirma can submit them for you, and since April 2026 it can also submit API-issued invoices on its own, at a time set in its account settings, and it refuses to e-mail an invoice that belongs in KSeF. Two settings decide what the module does, and they are independent:

- **Which documents go to KSeF** - `IFIRMA_KSEF_MODE`:
  - `b2b` (default): invoices whose buyer has a NIP. The buyer NIP is read from the stored request payload (`Kontrahent.NIP`), and when the payload has none, from the order's invoice address. Invoices to private buyers do not go.
  - `all`: every issued invoice, including invoices to private buyers. Private invoices are not required to be sent to KSeF, so use this only deliberately.
  - `off`: no document goes to KSeF automatically. The manual button stays available.
  - Any other stored value is treated as `b2b`.
- **Who submits them** - `IFIRMA_KSEF_SUBMITTER` (**KSeF submitter** on the settings page): `module` (default) or `ifirma`. With `ifirma`, choose it when your iFirma account sends API invoices to KSeF by itself: the module never calls the KSeF send endpoint and only waits for the KSeF state iFirma reports. Any other stored value is treated as `module`. The mode still decides which documents wait for acceptance, so it must not be `off` for the module to wait. With the mode `off`, no document is treated as going to KSeF whatever the submitter is.

**No double submission.** With the submitter `module`, the module reads the document's current KSeF state from iFirma (`GET {type}/{id}.json`, the fields `KsefStatus` and `KsefNumer`) before every submission and submits only when that state is empty. When iFirma already reports the invoice as sent (`WYSLANA`), scheduled (`ZAPLANOWANA`), accepted (`PRZYJETA`, or a number) or in any status the module does not know, nothing is sent and the reported state is stored on the receipt. When the state cannot be read (a timeout, an error answer, the rate limit), nothing is sent either: the step is skipped with the reason "KSeF state unavailable, retried by the cron" and the [cron endpoint](configuration.md#cron-jobs) reads the state again and submits. A rejected invoice (`ODRZUCONA`, `BLAD`) is never submitted again automatically.

**Pipeline step `ksef`.** The issuing pipeline runs the `ksef` step between `number` and `deliver`. The step is skipped with a recorded reason when submission is disabled (`off`), the buyer is private in `b2b` mode, the submitter is iFirma ("sent by iFirma"), the document is already in KSeF, iFirma already sent it ("already sent by iFirma"), its state could not be read, or the document type does not support KSeF submission. When iFirma accepts the submission (`Kod` 0), the receipt records `ksef_status` = `WYSLANA` and `ksef_sent_at`. When the submission fails, the step is non-blocking: the document stays issued, the result message carries `KSeF: <error>`, and the receipt records `ksef_status` = `BLAD` and `ksef_error`.

**Delivery after KSeF acceptance.** An invoice that goes to KSeF is **not** e-mailed at issuance, because an invoice given outside KSeF should carry its KSeF number. The `deliver` step is skipped with the reason "waiting for KSeF acceptance" and the receipt's delivery state becomes `awaiting_ksef`. The cron delivers it once status sync reports it accepted (a KSeF number, or `PRZYJETA`); an acceptance read by the sync is delivered in the same run. Until then:

- the customer's order page shows no download link and the download answers "not found";
- a rejected submission (`BLAD`, `ODRZUCONA`) keeps the delivery waiting and is not delivered automatically, and a wait longer than 48 hours needs attention (notification tab and documents-list preset);
- an e-mail that fails after acceptance is recorded as failed and the cron retries it under the [automatic retry rules](#automatic-retry-of-a-failed-delivery) when the failure is temporary; use **Send again** when it is not;
- **Send again** delivers at once on the employee's decision and ends the wait (the panel warns that the document is not yet accepted in KSeF); the cron then sends no second e-mail.

Receipts, invoices to private buyers in `b2b` mode, every document in mode `off`, and every document when the delivery channel is `none` are delivered (or recorded as skipped) at issuance as before and never wait.

The hold follows the delivery channel on purpose: with the channel `none` the module e-mails nothing, so an invoice does not wait and the customer can download it as soon as it is issued. Choose a delivering channel if a KSeF invoice must not be available before KSeF accepted it.

**Order panel.** The KSeF row shows exactly one of five states: not sent (with the reason), waiting for iFirma (submitter `ifirma`, no state reported yet), sent and awaiting acceptance (sent at `ksef_sent_at`, or reported by iFirma with its status, for example scheduled), accepted (with the KSeF number), or error (`BLAD` with the message, or `ODRZUCONA`, rejected by KSeF). **Send to KSeF** submits the document regardless of the mode and the submitter, after reading its state: it refuses when iFirma reports the document as sent, scheduled or accepted, and allows a new submission after a rejection. It is never offered for a document that is already accepted.

**Customer order page.** The KSeF number is shown when one is present. KSeF errors are not shown to the customer.

**Status values.**

- `WYSLANA`: sent and awaiting acceptance. The module submitted the invoice, or iFirma did, and no KSeF number has been reported yet.
- `ZAPLANOWANA`: iFirma scheduled the submission for a later time (set in its account settings).
- `PRZYJETA`: iFirma reports that KSeF accepted the invoice; `KsefNumer` is stored with it.
- `BLAD`: the last submission failed. The message is in `ksef_error` and in the order panel.
- `ODRZUCONA`: KSeF rejected the invoice.
- Any other value iFirma reports is shown as it is and counts as in progress: it causes neither a resubmission nor a delivery.

> Warning: a KSeF submission cannot be withdrawn through the API or the module. Before enabling `all`, decide whether private-buyer invoices should go to KSeF. If your iFirma account submits invoices to KSeF on its own, set the submitter to `iFirma`: the module reads the state before it submits, but a real iFirma answer that lacks the KSeF fields reads as "no state", and then only the submitter setting keeps the module from submitting too.

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
  disabled, and for an employee without the Issue level of the module (the panel then says so).

The panel is shown only to an employee with the View level of the module's
[permissions](configuration.md#permissions). Link, Unlink and Register still require the employee's edit
permission on Customers on top of that. Check, search and count need only the View level and the module
token.

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
- **Automatic approval:** **Accept data change requests automatically**
  (`IFIRMA_DATA_REQUEST_AUTO_ACCEPT`, [settings](configuration.md#settings-walk-through)) has three
  modes, default `none`. With `none` every request waits for an employee. With `all` every valid
  request is approved at once. With `safe` a request is approved at once only when it keeps the buyer
  identity: the company / private choice (an address counts as a company when it has a company name or
  a NIP), the NIP (compared without separators; both empty counts as unchanged) and the company name
  stay as they are, while first name, last name, address lines, postcode, city and country may
  change. Adding or changing a NIP, changing the company name and switching between company and
  private person leave the request pending for the employee. An automatic approval is stored like any
  other (the requested data becomes the approved data) and the order panel shows it as "automatic (safe
  fields)" or "automatic (all)" with the accepted differences instead of an employee name. The customer
  gets the approval e-mail, the merchant e-mail carries the line "Approved automatically (mode: safe)."
  (or "(mode: all)."), and the issuance resumes at once when automatic issuance is on and the trigger
  status was already reached; otherwise the next trigger or a manual Create issues the document. A
  failure of the resumed issuance is recorded on the receipt as usual and shows the customer no error
  text.
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
- **Checks on the form.** The postcode and the city are always required. A company request needs the company name
  and a VAT number of exactly 10 digits (separators are removed); a private request needs the first and last name.
  Names, address lines and the city are checked with PrestaShop's own rules, the postcode with PrestaShop's postcode
  rule and, when the country defines one, its postcode format. The country must be active. Maximum lengths:
  company 150 characters; first name, last name, each address line and city 65; postcode 16; customer note 1000.
- **Limits.** A customer can send a new request for an order only 10 minutes after the newest request for that order
  was created or changed, whatever its status. A customer can create at most 10 requests in 24 hours, across all
  orders. A refused request shows "Please wait 10 minutes before sending another request for this order." or
  "You have reached the limit of requests for today."
- **Admin text.** The note written on approval and the reason of a rejection are limited to 1000 characters. An
  approval whose note is too long keeps the request pending.

### Guest orders

Visitors who ordered without an account can change the invoice data at
`/module/ifirma/guestrequest` (the "Request a change to billing data" button on the guest tracking page leads there with
the reference filled in). Logged-in customers are redirected to their order history, where each order
links to the account form. The page is available only while **Allow customers to request billing data changes**
(`IFIRMA_ALLOW_DATA_REQUEST`) is on; otherwise it says that changes are not available and no code is
created or sent. It is also unavailable while the shop has no cookie key (the `cookie_key` parameter in
`app/config/parameters.php` is empty), because the session, the form token and the code hashes are keyed with it.

1. **Identify.** The visitor enters the order reference and the e-mail address of the order. The page
   always answers "If the data matches an order, we have sent a code to its e-mail address", whether or
   not the order exists, the e-mail matches or an e-mail was sent, so nothing about the order is revealed;
   the answer also always takes at least 1.5 seconds, so its timing reveals nothing either. The e-mail with
   the code has the subject "Your verification code from <shop name>" and names the order only in its body.
2. **Verify.** The visitor enters the six-digit code from the e-mail. It is valid for 15 minutes, works
   once, and is invalidated after 5 wrong attempts (a new one can be requested from the same page). A
   wrong, expired or used code gets the same "invalid or expired code" message. The e-mail contains no
   link that verifies the code by itself.
3. **Edit.** The visitor sees the same form as a logged-in customer, filled from the invoice address,
   with the same checks, the 10-minute cool-down and the daily cap of 10 requests (counted on the
   order's customer). The verified session is bound to that one order, lasts 30 minutes and ends on
   submit, cancel or login. The form shows only the order reference and the invoice address fields.

**Limits.** A new code can be requested at most once per 60 seconds per order, 5 times per order per 24
hours, and 20 times per client address per hour. A refused request sends no e-mail and shows the same
neutral message. Orders that already have an issued or adopted document get no code. Codes are stored
only as salted hashes keyed with the shop's cookie key, and used or expired codes older than 24 hours are deleted whenever a new code is
issued. When a customer reports that no code arrived, see
[Guest did not receive the code](troubleshooting.md#guest-did-not-receive-the-code).

**What the guest tracking page shows.** The module's block on the guest tracking page uses the same
state wording as the account order page and links to the guest request page while a request is possible
(not issued) or can be cancelled (held). It offers no PDF download there; for an issued document it
shows the number and asks the guest to contact the shop.

**What the merchant sees.** A guest request is reviewed like any other: it appears in the order panel,
holds the issuance while pending and is covered by the auto-accept mode above.
