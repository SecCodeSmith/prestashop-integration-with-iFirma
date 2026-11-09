# Configuration

Settings of the iFirma module: the settings walk-through, every configuration key, the duplicate guard settings and the API rate limits.

[Back to README](../README.md)

## Settings walk-through

![Module configuration page](img/module-configuration.png)

Open the module configuration and fill in:

- **API base URL.** The iFirma API address. Leave the default for production. The module accepts
  `https://` addresses. It accepts `http://` only when the shop is in developer mode (`_PS_MODE_DEV_`) or the
  host is `localhost`, a loopback or private address (`10.x`, `172.16` to `172.31`, `192.168.x`), or a single-label
  name such as a Docker service (`http://ifirma-mock:8001/iapi/`). An address with credentials in it (`user:pass@`)
  is refused, as is any address that breaks these rules; the settings page names the field.
- **API key: faktura** – the hexadecimal *faktura* key from
  iFirma → *Konfiguracja → Abonent → API → Klucze*.
- **Stored keys.** The three API key fields (*faktura*, *abonent* and *rachunek*) are password inputs, and the
  page never shows a stored key. The description says when a key is stored. Leaving a field empty keeps the stored
  key; typing a value replaces it. To delete a stored key, leave its field empty, tick **Remove the stored key**
  under it and save. Each key must be hexadecimal.
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
- **Document template** – the name of the print template in your iFirma account (up to 100 characters, one line).
  The logo and the layout of the printed document belong to that template. They are configured in the iFirma
  account, and the module only names the template: it does not upload a logo or edit a template. Leave the field
  empty for the iFirma default.
- **Order stamp prefix** – the first segment of the order stamp the module writes into the document comments
  (default `PS-ORDER`; 1 to 32 characters of letters, digits, dot, underscore and hyphen; a trailing hyphen is
  dropped). When several shops issue documents in one iFirma account, give each shop its own prefix, and set it
  before the shop issues its first document. A prefix changed later does not rename the stamps already written.
- **Shop webpage** – the address of the shop for people who read the documents, an absolute `http://` or `https://`
  address (up to 255 characters, no user name or password). The module uses it in three places: as the link in
  its e‑mails, printed in the document comments, and as the first part of the PDF file names. Empty means the
  address of the shop itself.
- **Duplicate check**, **window (days, 1‑365)** and **amount tolerance** – look for an existing
  iFirma document before issuing a new one.
- **Allow customers to request billing data changes**, **Accept data change requests automatically** and
  **Notification e‑mail** (empty or a valid address; empty uses the shop address). The automatic mode is *Never*
  (every request waits for review, the default), *Safe changes only* (a request that keeps the company or private
  choice, the NIP and the company name, and changes only the name, address, postcode, city or country, is
  approved at once) or *Every valid request* (approved without review).
- **Status sync interval (1‑1440 minutes)** – how often an order page refreshes its document
  from iFirma; **Cron call budget (1‑90)** and **API calls per minute (1‑100)** – API usage caps.
- **Cron endpoint** – the module shows the cron URL with its secret token and a crontab
  example (`*/15 * * * * curl -fsS "<url>"`); *Regenerate cron token* replaces the token.
  The same page also shows the token-in-header form, which keeps the token out of the web server's access log:

  ```
  */15 * * * * curl -fsS -H "X-Ifirma-Token: <IFIRMA_CRON_TOKEN>" "https://<shop>/module/ifirma/cron" >/dev/null
  ```

  When both are sent, the `token` query parameter is the one checked. A wrong or missing token answers HTTP 403.
- **Calls paused after a rejected key.** When iFirma rejects the credentials (a `Kod` of 400, 401, 402, 403 or 499,
  or an HTTP 401 or 403), the module makes no call to iFirma for 15 minutes and the settings page shows a warning:
  *iFirma rejected the credentials at <time>: <message>; calls are paused until <time>*. Saving the settings form
  clears the pause at once, so save after you have corrected or re-entered the key. See
  [Troubleshooting](troubleshooting.md#calls-paused-after-a-rejected-key).

## Configuration keys

| Key | Default |
|---|---|
| `IFIRMA_BASE_URL` | iFirma API base URL; must be `https`, or `http` in developer mode or for a local, private or single-label host (see the walk-through) |
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
| `IFIRMA_DOC_TEMPLATE` (new) | empty (the name of the print template in the iFirma account, sent as `NazwaSzablonu` when not empty; one line of at most 100 characters; the logo is part of the template and is configured in the iFirma account) |
| `IFIRMA_ORDER_STAMP_PREFIX` (new) | `PS-ORDER` (first segment of the order stamp in the document comments; 1 to 32 characters of `A-Z a-z 0-9 . _ -`, a trailing `-` is dropped, empty becomes the default; one prefix per shop that shares an iFirma account, set before the first document) |
| `IFIRMA_SHOP_URL` (new) | empty (shop webpage: an absolute `http` or `https` address without user information, at most 255 characters; the link in the module's e-mails, printed in the document comments and the first part of PDF file names; empty means the shop's own address) |
| `IFIRMA_ALLOW_DATA_REQUEST` (new) | `1` |
| `IFIRMA_DATA_REQUEST_AUTO_ACCEPT` (new) | `none` (`none`, `safe` or `all`; any other value is read as `none`) |
| `IFIRMA_NOTIFY_EMAIL` (new) | empty |
| `IFIRMA_SYNC_INTERVAL_MIN` (new) | `15` |
| `IFIRMA_CRON_TOKEN` (new) | random 32 hex characters, generated on install/upgrade from the system's secure random generator (if that is unavailable, the install or upgrade fails with a message rather than using a weaker token) |
| `IFIRMA_CRON_MAX_CALLS` (new) | `40` |
| `IFIRMA_RATE_MAX_PER_MIN` (new) | `80` (1 to 100) |
| `IFIRMA_RATE_MAX_PER_DAY` (new) | `14000` (1 to 15000) |
| `IFIRMA_RATE_WINDOW` (new) | empty (internal rate-limit state of the minute: `<minute>:<count>`, incremented by one atomic database statement, so two requests at the same time cannot both pass the cap) |
| `IFIRMA_RATE_DAY_WINDOW` (new) | empty (the same for the calendar day: `<day>:<count>`) |
| `IFIRMA_RATE_BACKOFF_UNTIL` (new) | empty (internal: the unix time until which iFirma asked the module to wait after it refused a request for exceeding its limit; no request is sent before it, see [Rate limits](#rate-limits)) |
| `IFIRMA_AUTH_FAILURE` (new) | empty (internal mark of the pause after a rejected key: `<unix time>|<message>`; set by a failed call, cleared by a successful save of the settings; after 15 minutes the mark no longer pauses calls) |
| `IFIRMA_SEEN_<id_employee>` (new) | not set (internal: the time an employee last opened the iFirma tab of the notification bell) |

## Duplicate guard settings

Settings of the [Duplicate guard](usage.md#duplicate-guard):

| Key | Default | Meaning |
|---|---|---|
| `IFIRMA_DUPLICATE_CHECK` | `1` | `0` disables the guard; only the local record check applies |
| `IFIRMA_DUPLICATE_WINDOW_DAYS` | `60` | largest window (days) for the unfiltered query |
| `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE` | `0.01` | allowed gross difference when matching |

## Behind a reverse proxy

The guest page limits the codes it sends per client address (20 per hour). The address is the one PrestaShop reports
(`Tools::getRemoteAddr()`). Behind a reverse proxy or a CDN that address is the proxy's, or, when PrestaShop trusts a
forwarded header such as `X-Forwarded-For` without checking where it came from, an address the visitor can choose. In both
cases the per-address cap is weaker than it looks. Configure the trusted proxies of your PrestaShop and the web server so
that the real client address is reported, and keep the per-order limits (one code per 60 seconds, 5 per 24 hours), which do
not depend on the address, in mind as the cap that always holds.

## Permissions

The module has three permission levels. They are the View, Add and Edit permissions that
**Advanced Parameters > Team > Permissions** shows for the **iFirma documents** tab under **Orders**
(the tab `AdminIfirma`):

| Level | Permission | What it allows |
|---|---|---|
| View | View | See the iFirma panel on the order page and on the customer page, the iFirma column of the Orders list, the [iFirma documents list](usage.md#ifirma-documents-list) and the iFirma tab of the notification bell, and download a document PDF. Check, search and count a customer's contractor. |
| Issue | Add | Create Receipt, Issue anyway, Link this document (a duplicate candidate) and Register contractor in iFirma. On the Orders list: the Issue action of a cell and the bulk Issue iFirma documents. On the documents list: Retry issuance and its bulk action. |
| Manage | Edit | Update status in iFirma, Send again, Send to KSeF, Refresh from iFirma, and Approve or Reject a data-change request. On the documents list: Send again, Refresh and their bulk actions. |

Defaults: the SuperAdmin profile and the profile of the employee who installed the module hold all
three levels (PrestaShop grants them when the tab is created). Every other profile has none until you
grant them, so after an upgrade grant View (and the others as needed) to each profile that works with
the documents. The levels are independent of each other: grant View together with Issue or Manage, because
a panel that cannot be seen cannot be used. The Delete permission of the tab has no effect, as the module
never deletes a document.

PrestaShop runs a module's back-office hooks only for profiles that may **view the module itself**: on the
same Permissions page, under **Modules**, the iFirma module needs its **View** permission too. Without it the
order panel, the customer panel, the Orders-list column and the notification tab stay hidden even when the
tab's View is granted (the AJAX actions still follow the tab levels). Grant both.

The **Configure** permission of the module page (Modules > Module Manager) is separate: it controls who
may open and save the settings and does not give any of the three levels.

Link, Unlink and Register on the customer page need, on top of View, the Edit permission on **Customers**.

## Rate limits

iFirma allows 100 requests per minute and 15,000 per day. The module keeps its own counters for both
windows (`IFIRMA_RATE_WINDOW` for the calendar minute, `IFIRMA_RATE_DAY_WINDOW` for the calendar day) and refuses to
start a request once either local cap is reached. The caps are `IFIRMA_RATE_MAX_PER_MIN` (default 80, 1 to 100)
and `IFIRMA_RATE_MAX_PER_DAY` (default 14,000, 1 to 15,000), both on the settings page; they stay below
iFirma's own limits on purpose. The minute is checked first. The day is the calendar day of the shop's time
zone; iFirma's own day is presumably Polish time, and the 1,000-request margin below 15,000 absorbs a mismatch
around midnight. A refusal names the window that refused ("... requests in the current minute (cap 80)" or
"... requests today (cap 14000)") and is a transient "rate limited" error, never stored as a document error.
A request refused by the day window has already used a minute slot; that only makes the guard more conservative.
Timeouts: 30 s by default, 5 s for
the customer-page sync. A shop-mail delivery may add up to 10 s of waiting when the PDF is not ready yet. With the optional `abonent` key the used/granted daily limit can be read.

A bulk run of the documents list stops at the first refusal and reports the rest as "not attempted" (see
[iFirma documents list](usage.md#ifirma-documents-list)). The cURL transport accepts only the HTTP and HTTPS protocols,
does not follow redirects, and cuts off an answer larger than 20 MB with an error.

**When iFirma refuses a request.** An HTTP 429 answer, or an answer whose message says the request limit was exceeded
("limit zapytań"), is treated as "rate limited", not as an error, an unknown outcome or a rejected key. The module then sends
nothing until the time given by the `Retry-After` header (whole seconds, at most 24 hours), or until the start of the next
minute when the header is missing or unusable (`IFIRMA_RATE_BACKOFF_UNTIL`). A request started during the back-off is refused
before any network call, with a message that names the end time. See
[Rate limited by iFirma](troubleshooting.md#rate-limited-by-ifirma).

The limits are shared by ALL API clients of your account (other integrations, accounting tools). The module counts
only its own requests, so a busy account can reach iFirma's limit before the module reaches its cap; lower the caps when
other programs use the same account. The module retries rate-limited receipts on a later run, but it cannot see
requests made by other clients.
