# Configuration

Settings of the iFirma module: the settings walk-through, every configuration key, the duplicate guard settings and the API rate limits.

[Back to README](../README.md)

## Settings walk-through

![Module configuration page](img/module-configuration.png)

Open the module configuration and fill in:

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

## Configuration keys

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

## Duplicate guard settings

Settings of the [Duplicate guard](usage.md#duplicate-guard):

| Key | Default | Meaning |
|---|---|---|
| `IFIRMA_DUPLICATE_CHECK` | `1` | `0` disables the guard; only the local record check applies |
| `IFIRMA_DUPLICATE_WINDOW_DAYS` | `60` | largest window (days) for the unfiltered query |
| `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE` | `0.01` | allowed gross difference when matching |

## Rate limits

iFirma allows 100 requests per minute and 15,000 per day. The module keeps its own per-minute
counter (`IFIRMA_RATE_WINDOW`) and refuses to start a request once the local cap is reached
(default 80, configurable through `IFIRMA_RATE_MAX_PER_MIN`); such a refusal is a transient
"rate limited" error and is never stored as a document error. Timeouts: 30 s by default, 5 s for
the customer-page sync. A shop-mail delivery may add up to 10 s of waiting when the PDF is not ready yet. With the optional `abonent` key the used/granted daily limit can be read.

The limits are shared by ALL API clients of your account (other integrations, accounting tools). The module retries rate-limited receipts on a later run, but it cannot see requests made by other clients.
