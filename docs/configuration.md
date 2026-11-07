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
- **Duplicate check**, **window (days, 1‑365)** and **amount tolerance** – look for an existing
  iFirma document before issuing a new one.
- **Allow customers to request billing data changes** and **Notification e‑mail** (empty or a
  valid address; empty uses the shop address).
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
| `IFIRMA_ALLOW_DATA_REQUEST` (new) | `1` |
| `IFIRMA_NOTIFY_EMAIL` (new) | empty |
| `IFIRMA_SYNC_INTERVAL_MIN` (new) | `15` |
| `IFIRMA_CRON_TOKEN` (new) | random 32 hex characters, generated on install/upgrade from the system's secure random generator (if that is unavailable, the install or upgrade fails with a message rather than using a weaker token) |
| `IFIRMA_CRON_MAX_CALLS` (new) | `40` |
| `IFIRMA_RATE_MAX_PER_MIN` (new) | `80` |
| `IFIRMA_RATE_WINDOW` (new) | empty (internal rate-limit state: `<minute>:<count>`, incremented by one atomic database statement, so two requests at the same time cannot both pass the cap) |
| `IFIRMA_AUTH_FAILURE` (new) | empty (internal mark of the pause after a rejected key: `<unix time>|<message>`; set by a failed call, cleared by a successful save of the settings; after 15 minutes the mark no longer pauses calls) |
| `IFIRMA_SEEN_<id_employee>` (new) | not set (internal: the time an employee last opened the iFirma tab of the notification bell) |

## Duplicate guard settings

Settings of the [Duplicate guard](usage.md#duplicate-guard):

| Key | Default | Meaning |
|---|---|---|
| `IFIRMA_DUPLICATE_CHECK` | `1` | `0` disables the guard; only the local record check applies |
| `IFIRMA_DUPLICATE_WINDOW_DAYS` | `60` | largest window (days) for the unfiltered query |
| `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE` | `0.01` | allowed gross difference when matching |

## Permissions

The module has three permission levels. They are the View, Add and Edit permissions that
**Advanced Parameters > Team > Permissions** shows for the **iFirma documents** tab under **Orders**
(the tab `AdminIfirma`):

| Level | Permission | What it allows |
|---|---|---|
| View | View | See the iFirma panel on the order page and on the customer page, the iFirma column of the Orders list, the [iFirma documents list](usage.md#ifirma-documents-list) and the iFirma tab of the notification bell, and download a document PDF. Check, search and count a customer's contractor. |
| Issue | Add | Create Receipt, Issue anyway, Link this document (a duplicate candidate) and Register contractor in iFirma. On the Orders list: the Issue action of a cell and the bulk Issue iFirma documents. On the documents list: Retry issuance and its bulk action. |
| Manage | Edit | Update status in iFirma, Send again, Send to KSeF, Refresh from iFirma, and Approve or Reject a data-change request. On the documents list: Send again, Refresh and their bulk actions. |

Defaults: the SuperAdmin profile always has all three levels. Every other profile has none until you
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

iFirma allows 100 requests per minute and 15,000 per day. The module keeps its own per-minute
counter (`IFIRMA_RATE_WINDOW`) and refuses to start a request once the local cap is reached
(default 80, configurable through `IFIRMA_RATE_MAX_PER_MIN`); such a refusal is a transient
"rate limited" error and is never stored as a document error. Timeouts: 30 s by default, 5 s for
the customer-page sync. A shop-mail delivery may add up to 10 s of waiting when the PDF is not ready yet. With the optional `abonent` key the used/granted daily limit can be read.

A bulk run of the documents list stops at the first refusal and reports the rest as "not attempted" (see
[iFirma documents list](usage.md#ifirma-documents-list)). The cURL transport accepts only the HTTP and HTTPS protocols,
does not follow redirects, and cuts off an answer larger than 20 MB with an error.

The limits are shared by ALL API clients of your account (other integrations, accounting tools). The module retries rate-limited receipts on a later run, but it cannot see requests made by other clients.
