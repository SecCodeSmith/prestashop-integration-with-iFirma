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

## Usage flow

1. Open an order in the back office. The **iFirma** panel shows a **Create Receipt**
   button (Feature 3). Clicking it issues the document via `fakturaparagon.json`
   and stores the returned `Identyfikator`.
2. Once issued, the panel shows the document number, a **Download PDF** link and an
   **Update status in iFirma** button that registers the paid amount (Feature 2).
3. The registered customer sees the document in **My account** and on the
   **Order detail** page, and can download the PDF, which is streamed live from
   iFirma through the secured front controller `controllers/front/receipt.php`
   (Feature 1). The download is guarded by a per‑document token **and** an
   ownership check against the logged‑in customer.

## Data model

`ps_ifirma_receipt` — one row per issued document: order/customer link, iFirma id
and number, status (`created` / `paid` / `error`), the paid amount, a download
token and the full request/response payloads for auditing.

## Security notes

- API keys are stored in `ps_configuration`; the *faktura* key is validated as a
  hexadecimal string before any request is signed.
- Front‑office PDF access requires an authenticated customer, a matching document
  token, and `id_customer` ownership — a customer can never fetch another’s document.
- Back‑office actions run through `AdminIfirmaController`, protected by the standard
  PrestaShop admin token.
- iFirma error bodies returned on a `.pdf` endpoint are detected so an error JSON is
  never streamed to the browser as a PDF.

## Notes & assumptions

- The **payment registration type token** varies per iFirma document/payment type.
  `prz_faktura_kraj` is a sensible default; verify yours against
  <https://api.ifirma.pl/rejestrowanie-wplat-do-faktur/> if the status update is rejected.
- Product lines use the **gross** unit price (`LiczOd = BRT`); VAT is taken from
  each order line’s tax rate (`0%` lines are marked `ZW`). Shipping is added as a line.
- Business customers (company + VAT/NIP on the invoice address) are sent as
  `OsobaFizyczna = false` with their NIP; everyone else as a private person.

## Compatibility

PrestaShop **1.7.0 – 9.x**, PHP **7.2 – 8.x**. Requires the PHP `curl`, `hash`
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

## Legal Disclaimer & Trademarks

iFirma is a registered trademark of IFIRMA SA.

This module is an independent third-party open-source integration and is not officially affiliated with, endorsed by, or sponsored by IFIRMA SA.

The name "iFirma" and associated references are used strictly for identification, nominative reference, and descriptive compatibility purposes (interoperability / prawo cytatu) to inform users about the third-party accounting system with which this PrestaShop module integrates.