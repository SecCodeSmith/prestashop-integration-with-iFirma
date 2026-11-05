# Design

## Context

- The contractor plumbing already exists. `IfirmaContractorService` has `fetch($identifier)` (`GET kontrahenci/id/{id}.json`, returns array / null / false), `search($phrase)` (`GET kontrahenci/{phrase}.json`, at most 20 rows; per the module docs and the mock it matches only `Identyfikator`, `Nazwa` or `NIP`), `ensure()` (the matching chain used at issuance and by the order panel's **Register contractor in iFirma**) and `normalizeName()`. `IfirmaContractorMap` stores one link per customer, and its `link()` is an upsert. `BY_MANUAL = 'manual'` is defined and has a label in `Ifirma::matchedByLabel()`, but nothing writes it yet.
- At issuance, `ensure()` step a uses the stored mapping first and then `useExisting()`. That overwrites `FIELDS_OWNED` (name, NIP, address, e-mail, `OsobaFizyczna`) on the iFirma contractor when they differ. A manual link therefore makes iFirma's copy of the older contractor follow the shop data from the next document on (see Risks).
- `faktury.json` accepts `kontrahent` (identifier) and `nipKontrahenta` filters. `IfirmaApiClient::listInvoices()` enforces `dataOd`/`dataDo`/`iloscNaStronie`. iFirma returns no total count, and an unknown `kontrahent` answers Kod 201 (handled the same way in `IfirmaDuplicateGuard::filterValueUnknown()`). Rows carry `Rodzaj`, `Zaplacono`, `Brutto`, `FakturaId`.
- `ifirma_receipt` has `id_customer`, `document_type`, `status`. The module registers no customer-page hook today. All back-office AJAX goes through `AdminIfirmaController` (`ajaxProcess*`) into `IfirmaAdminActions`, which returns `success/message/data`.
- The module is at 1.1.0 with `upgrade/upgrade-1.1.0.php`. New hooks on existing installs need an upgrade script.

## Goals / Non-Goals

**Goals:**
- Keep all customer-level logic in one testable service with an injectable API client, following the pattern of `IfirmaContractorService`.
- Never block the customer page on iFirma, and make no API call the employee didn't trigger, apart from the one registration check per page open.
- Reuse the existing matching chain for registration and the existing mapping table for links, so issuance behaviour stays the same.

**Non-Goals:**
- Server-side e-mail or phone search in iFirma (the API doesn't support it) and any local copy of the iFirma contractor directory.
- A customer list column, bulk linking, or editing the iFirma contractor from the panel.
- Changing what `ensure()` overwrites on a linked contractor.
- Caching registration checks or counts between page views.

## Decisions

### D1. Hook `displayAdminCustomers`, rendered from `ifirma.php`
Register `displayAdminCustomers` in `install()` and in `upgrade/upgrade-1.2.0.php`, which is idempotent: `registerHook` is a no-op when the hook is already registered. The Symfony customer view of PrestaShop 8 and 9 fires this hook with `id_customer` in `$params`. `hookDisplayAdminCustomers` loads the customer, computes the link state and the local counts (no API), and renders `views/templates/hook/admin_customer_panel.tpl`. The template follows the order panel: a Bootstrap 4 card. PrestaShop 1.7 is no longer supported (minimum 8.0.0), so neither panel keeps a Bootstrap 3 layout, and the legacy `displayAdminOrder` hook is dropped and unregistered by the upgrade.
*Alternative:* a separate admin tab with a customer picker. Rejected because the merchant wants the information where they already look at the customer.

### D2. New `IfirmaCustomerContractorService`
Public methods:
- `linkState($idCustomer)`
- `check($idCustomer)`
- `localCounts($idCustomer)`
- `remoteCount($idCustomer, $clock)`
- `searchCandidates(array $criteria)`
- `link($idCustomer, $identifier)`
- `unlink($idCustomer)`

It wraps an `IfirmaContractorService` and the same `IfirmaApiClient`. `build(Module)` mirrors `IfirmaContractorService::build`, and tests construct it with a scripted transport and a fixed clock. Registration isn't in this service: `IfirmaAdminActions::registerCustomer($idCustomer)` finds the latest order (`Order::getCustomerOrders`, first row) and delegates to the existing `registerContractor($idOrder)`. That keeps one registration path and one set of result messages.
*Alternative:* add the methods to `IfirmaContractorService`. Rejected because that class is already 770 lines and owns the issuance chain. Customer-panel concerns (ranking, counting) would blur it.

### D3. Registration check loaded by AJAX
The template renders the link state and local counts immediately. A small script calls `ajaxProcessCustomerCheck` after the page loads. `check()` calls `fetch()` on the shown identifier (the mapped one or `PS-C<id>`). An array means `registered` (with `Nazwa`), null means `missing`, false means `error` (with `getLastError()`, which includes the rate-guard refusal text). The check never unlinks. This is deliberately unlike `ensure()` step a, which discards a dead mapping: a read-only page must not change data. The panel offers search and replace instead.
*Alternative:* check during render. Rejected because a slow iFirma would delay the whole customer page (spec: "Page does not wait for iFirma").

### D4. Local counts in SQL
Add `IfirmaReceipt::countByCustomer($idCustomer)`: `SELECT document_type, status, COUNT(*) ... WHERE id_customer = N GROUP BY document_type, status`. The service folds the result into the following groups.

States:
- **issued**: `created` or `paid`
- **paid**: `paid`
- **held**: `held`
- **needs attention**: `error`, `unknown` or `duplicate_suspected`

Types:
- **receipt**: `paragon*` / `rachunek*`
- **domestic invoice**: `fakturakraj`
- **other**: everything else

The groups are defined once as constants so the template and the tests share them. External/adopted rows (`source = external`) count like module rows because they are the customer's documents too.

### D5. Remote count: yearly windows, union by `FakturaId`, budget of 20
`remoteCount()` builds windows from `max(customer.date_add, today − 10 years)` to today. Each window covers at most 365 days and the newest window comes first. For each window it runs the `kontrahent=<identifier>` query and, when the customer has a NIP, the `nipKontrahenta=<nip>` query. Each query uses `iloscNaStronie = 100` and pages with `strona` until a short page. The NIP comes from the latest invoice address with a non-empty `vat_number`, digits only, 10 digits. Rows are de-duplicated by `FakturaId`. Kod 201 on the `kontrahent` query marks that filter as unknown for all windows (the same rule as the duplicate guard), so no further calls are made with it. The NIP query still runs.

Every call counts against a budget of 20. When the budget runs out, the result is `complete = false` with the period actually covered, and the panel prints "at least N". Any other failure returns `error` and no total. The result holds:
- `total`
- `by_type`, using the `Rodzaj` mapping from D4
- `paid`: rows with `Zaplacono >= Brutto - 0.01`
- `period_from` and `period_to`
- `calls`
- `complete`

*Alternatives:* a single unbounded window (the module's own spec requires bounded list calls, and a long window risks a rejection or a huge first page); counting only by NIP (private customers have none).

### D6. Search: phrases, merge, rank
Input criteria: `firstname`, `lastname`, `company`, `nip`, `email`, `phone`. The template pre-fills them from the customer record and their latest invoice address: company and `vat_number`, `phone` or `phone_mobile`. The phrases are built in this order and de-duplicated case-insensitively, at most 4:
1. NIP digits, when there are at least 10
2. company
3. "firstname lastname"
4. lastname alone, only when fewer than 3 phrases exist so far

That order sends the most selective phrase first. Each phrase runs through `IfirmaContractorService::search()`, and any failure is collected per phrase. Rows merge by trimmed `Identyfikator`, and rows without one are dropped.

Each row gets match flags:
- `nip`: digits equal
- `email`: case-insensitive equal on `Email` or `EmailDlaFaktury`
- `phone`: on `Telefon` or `DrugiTelefon`. Digits are compared after dropping a leading `48` from 11-digit numbers and a leading `0048`.
- `name`: `normalizeName()` equals the full name or the company

Rows sort by (nip, number of flags, normalised name). When neither name, company nor NIP is present, the service returns a `refused` result with the explanation and makes no call. The response holds:
- `rows`: identifier, name, NIP, e-mail, phone, postcode, city, flags
- `errors`: phrase → message
- `phrases_used`

*Alternative:* sending the e-mail as a phrase. Rejected: iFirma would answer "no results" and waste a call.

### D7. Link, unlink and the warning
`link()` trims the identifier, rejects an empty one or one longer than 64 characters, and calls `fetch()`. Only an array result leads to `IfirmaContractorMap::link($id, $identifier, BY_MANUAL, Nazwa)`. Null returns "not found" and false returns the error, and in both cases nothing is stored. The identifier the employee picked is never trusted without the re-read. `unlink()` calls `IfirmaContractorMap::unlinkCustomer()` only and makes no API call. The client shows a `confirm()` dialog before link and unlink. The link dialog carries the overwrite warning text from the proposal.

### D8. Controller actions and access control
New actions, all POST with `id_customer` and the `AdminIfirma` token, as in the order panel:
- `CustomerCheck`
- `CustomerCountRemote`
- `CustomerSearch`
- `CustomerLink`
- `CustomerUnlink`
- `CustomerRegister`

`ModuleAdminController` already rejects an invalid token. Link, unlink and register also check that the employee may edit customers:
- `Access::isGranted('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', $idProfile)` (always present on PrestaShop 8 and 9, so there is no `Profile` fallback)

The check is wrapped in one private helper so the test stubs can drive it. `id_customer` is validated with `Validate::isLoadedObject(new Customer(...))`. Results reuse `respond()` and the `success/message/data` envelope. Template output uses `|escape:'html':'UTF-8'`, and the JS builds result rows with `text()`/`$('<td>').text(...)`, never with HTML strings, which covers the XSS scenario.

### D9. Version 1.2.0
`ifirma.php` `$this->version`, `config.xml` and `package.json` move to 1.2.0. `upgrade-1.2.0.php` only registers the hook and returns true. There is no schema or configuration change.

## Risks / Trade-offs

- [A manual link makes the next document overwrite the older contractor's name, NIP, address and e-mail in iFirma.] → The link confirmation spells this out. Changing `useExisting()` semantics stays out of scope, so the merchant gets the same behaviour as every other link type.
- [Each customer page view costs one iFirma call.] → It is a single GET, subject to the local rate guard (80/min by default). A refusal only shows "could not check".
- [iFirma search returns at most 20 rows per phrase, so a common surname can hide the right contractor.] → NIP and company phrases run first, and the employee can edit the fields and search again with a narrower phrase.
- [Phone formats in iFirma vary (spaces, `+48`, landlines with area codes).] → The comparison is digits-only with country-prefix stripping. A non-match only affects ranking, never whether a row is listed.
- [The remote count misses documents issued to a different contractor record of the same person, for example a duplicate `PS-C7` created before the link.] → The NIP filter covers businesses. The panel labels the count with the identifier it used, so the merchant can link the other record and count again.
- [Documents older than 10 years or before the customer's registration date are not counted remotely.] → The covered period is always shown next to the count.

## Migration Plan

1. Ship 1.2.0. PrestaShop runs `upgrade-1.2.0.php` on module update, which registers `displayAdminCustomers`.
2. Existing links are untouched, and new manual links use the existing table.
3. Rollback: reinstall 1.1.0 code. The extra hook registration is harmless without the method (PrestaShop skips modules that lack the hook method), and `manual` links stay valid for 1.1.0's `ensure()`.
