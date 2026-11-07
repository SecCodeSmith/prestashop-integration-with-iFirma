# Prestashop Module for integration with iFirma – Receipts & Invoices (PrestaShop module)

Two‑way integration between **PrestaShop** (8.x / 9.x) and the **iFirma**
accounting service (`www.ifirma.pl`). It issues accounting documents from shop orders, brings the
PDF back to the customer and keeps the payment and KSeF status in line with iFirma.

| Feature | Where |
|---------|-------|
| **Issue a document** in iFirma from an order | Back office → *Orders → (order) → iFirma panel → “Create Receipt”* |
| **Automatic issuance** when an order reaches a chosen status | Module configuration → *Auto‑create on order status* |
| **PDF back to the customer** | Front office → *My account* block **and** the *Order detail* page; optional e‑mail with the PDF attached |
| **Payment and status sync** (register the payment, read it back) | Back office → iFirma panel → “Update status in iFirma”, “Refresh from iFirma”, and a cron URL |
| **KSeF submission** of issued invoices | Pipeline step `ksef`; “Send to KSeF” in the order panel |
| **Customer data‑change requests** before a document is issued | Front office → *Order detail* → “Request a change to billing data”; review in the order panel |
| **Contractor linking** to contractors that already exist in iFirma | Module configuration (CSV import) and the back‑office customer page |

The iFirma column in the back‑office Orders list shows at a glance which orders have a document:

![Orders list with the iFirma column](docs/img/bo-orders-list.png)

The iFirma panel on an order page issues the document, shows its state and downloads the PDF:

![iFirma panel on an order page](docs/img/bo-order-panel.png)

## Compatibility

PrestaShop **8.0 – 9.x**, PHP **7.2 – 8.x**. PrestaShop 1.7 is supported up to module 1.1.x. Verified with the Docker smoke suite on PrestaShop 8.1.7 (PHP 8.1) and 9.1.4 (PHP 8.5); see `tests/smoke/README.md`. Requires the PHP `curl`, `hash`
and `mbstring` extensions.

## Installation

1. Copy the `ifirma/` folder to `<prestashop>/modules/`.
2. Back office → **Modules** → search *iFirma* → **Install**.
3. Open the module configuration and fill in the connection fields:
   - **iFirma login** – your account login / e‑mail.
   - **API key: faktura** – the hexadecimal *faktura* key from
     iFirma → *Konfiguracja → Abonent → API → Klucze*.
   - **Document type** – *Domestic VAT invoice* (`fakturakraj`), the only enabled and default
     type. *Invoice to receipt* (`fakturaparagon`) is disabled for now; a stored `fakturaparagon`
     value is treated as `fakturakraj`.
   - **Payment registration type** – URL token for the payment endpoint
     (default `prz_faktura_kraj`; adjust to your document/payment type).
4. Optionally set **Auto‑create on order status** (the trigger state) to issue documents automatically.
   With the default delivery channel, documents are then e‑mailed to customers on the next trigger;
   read [Delivery](docs/usage.md#delivery) first, or choose *Do not send*.

Every other setting (API key *abonent*, delivery channel, duplicate check, data‑change requests,
status sync, cron endpoint, rate limits) is described in [docs/configuration.md](docs/configuration.md).
After updating the module, clear the PrestaShop cache (Advanced Parameters > Performance > Clear cache).

## How it is used

When an order reaches the status chosen in **Auto‑create on order status**, or when you click
**Create Receipt**, the module runs one issuance pipeline for that order:
`hold → guard → create → number → ksef → deliver`.

- **hold** waits while the customer has a pending data‑change request; **guard** looks in iFirma for a
  document that already belongs to the order and adopts it instead of creating a second one.
- **create** issues the document, **number** reads its number back, **ksef** submits it to KSeF when the
  mode asks for it, and **deliver** sends the PDF through the configured channel.
- The first step that cannot continue stops the run; the outcome of every step is stored on the order's
  receipt record, and a failure never breaks the PrestaShop status change.

The registered customer sees the document on the *Order detail* page and under *My account* and
downloads the PDF through a secured link; internal errors are never shown. The page for each state
is described in [docs/usage.md](docs/usage.md#what-the-customer-sees).

An order whose newest record is `unknown`, `duplicate_suspected`, `held` or `error` needs a look in the
iFirma panel; what each state means and what to do is in
[States that need attention](docs/usage.md#states-that-need-attention).

The module refreshes its view of every issued document (paid amount, sent flag, KSeF status and number,
documents removed in iFirma) through the **Refresh from iFirma** button, an opportunistic refresh when the
customer opens the order, and a cron URL for bulk sync that the configuration page shows with its token.
See [Monitoring](docs/usage.md#monitoring) and [Rate limits](docs/configuration.md#rate-limits).

### Delivery, KSeF and the duplicate guard

- **Delivery** – the PDF is attached to a shop e‑mail (default), sent by iFirma itself, or not sent at all.
  See [Delivery](docs/usage.md#delivery).
- **KSeF** – invoices to buyers with a NIP are submitted to the National e‑Invoicing System by default;
  `all` and `off` are available. See [KSeF (e-invoicing)](docs/usage.md#ksef-e-invoicing).
- **Duplicate guard** – before creating a document the module looks for one that already belongs to the
  order, whether it was made by the module, by iFirma's own integrator or by hand. Do not run iFirma's own
  PrestaShop integrator and automatic issuance on the same account. See
  [Duplicate guard](docs/usage.md#duplicate-guard).

### Existing contractors

By default the module registers one iFirma contractor per customer (`PS-C<id_customer>`). If you already keep
contractors in iFirma, import the CSV export on the configuration page or link a customer by hand from the
back‑office customer page. See [docs/contractors.md](docs/contractors.md).

## Test stack

The mock iFirma API and the Docker test stack live in the `test-stack/` git submodule
([SecCodeSmith/test-stack](https://github.com/SecCodeSmith/test-stack), MIT). The mock keeps its state in a
file, so documents survive a restart, and has a status page at `/__mock/status`:

![Status page of the mock iFirma API](docs/img/mock-status.png)

Set‑up, builds and the submodule are described in [docs/development.md](docs/development.md); the stack
itself in [test-stack/README.md](test-stack/README.md).

## Getting help

When something goes wrong, start with [docs/troubleshooting.md](docs/troubleshooting.md): it lists the iFirma
error codes and the fixes for the common problems. To report a problem, copy the diagnostics block of the
order's iFirma panel (request, response, contractor step, last error) and the relevant lines from
Advanced Parameters > Logs (messages start with "iFirma"); neither contains API keys.

## Documentation

| Page | Contents |
|------|----------|
| [docs/architecture.md](docs/architecture.md) | How the module is built: hooks, controllers, classes, the issuance pipeline, data model, API surface, test harness |
| [docs/configuration.md](docs/configuration.md) | Settings walk-through, every configuration key, duplicate guard settings, rate limits |
| [docs/usage.md](docs/usage.md) | Issuing documents, orders list, monitoring and cron, duplicate guard, what the customer sees, delivery, KSeF, customer page, data‑change requests |
| [docs/contractors.md](docs/contractors.md) | Linking existing iFirma contractors: CSV import, matching while issuing, manual links |
| [docs/upgrading.md](docs/upgrading.md) | What to do when upgrading from 1.0.x, to 1.2.0, 1.4.0 and 1.4.1 |
| [docs/development.md](docs/development.md) | Unit tests, package builds, the test‑stack submodule, releases, dev dependencies |
| [docs/troubleshooting.md](docs/troubleshooting.md) | iFirma error codes and fixes for the common problems |
| [test-stack/README.md](test-stack/README.md) | The mock iFirma API and the Docker test stack |
| [tests/smoke/README.md](tests/smoke/README.md) | The Docker smoke suite on PrestaShop 8.1 and 9.1 |
| [tests/Stubs/README.md](tests/Stubs/README.md) | The PrestaShop stubs used by the unit tests |

## Legal Disclaimer & Trademarks

iFirma is a registered trademark of IFIRMA SA.

This module is an independent third-party open-source integration and is not officially affiliated with, endorsed by, or sponsored by IFIRMA SA.

The name "iFirma" and associated references are used strictly for identification, nominative reference, and descriptive compatibility purposes (interoperability / prawo cytatu) to inform users about the third-party accounting system with which this PrestaShop module integrates.
