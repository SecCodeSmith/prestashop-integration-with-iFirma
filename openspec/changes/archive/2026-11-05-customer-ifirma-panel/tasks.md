# Tasks

Conventions for every task:
- Keep PHP 7.2-compatible syntax (`array()`, no typed properties).
- Keep the module license header on every new or touched `.php`/`.tpl` file; `python tools/check_license_headers.py` must pass.
- Run `php -l` on every edited PHP file and the PHPUnit suite (`vendor/bin/phpunit`) after each group.
- Run `graphify update .` after code changes (CLAUDE.md).
- Every new `l()` string goes into `tools/pl_translations_map.php`, and `translations/pl.php` is regenerated with `php tools/generate_pl_translations.php` in the same task. `tests/Unit/TranslationCoverageTest.php` enforces this.

Design decisions are referenced as D1–D9 (design.md) and scenarios by name from `specs/customer-contractor-panel/spec.md`.

## 1. Test stubs for the customer page

- [x] 1.1 Add the missing stub surface the panel needs, and document each addition in `tests/Stubs/README.md`:
  - `tests/Stubs/Customer.php`: `date_add`, the registry trait if missing, and `getAddresses($idLang)` returning registered addresses of the customer.
  - `tests/Stubs/Order.php`: static `getCustomerOrders($idCustomer)`, newest first.
  - New `tests/Stubs/Access.php` (`isGranted($role, $idProfile)` driven by a static grant list) and `tests/Stubs/Profile.php`, both loaded by `tests/bootstrap.php`.
  - The `Context` stub's employee carries `id_profile`.

  Verify the existing suite still passes unchanged.

## 2. Local link state and counts (D2, D4)

- [x] 2.1 Add `IfirmaReceipt::countByCustomer($idCustomer)` (grouped `document_type`, `status`, `COUNT(*)`, filtered by `(int)` id). Create `classes/IfirmaCustomerContractorService.php` with:
  - `build(Module)` and a constructor taking `IfirmaApiClient` and `IfirmaContractorService`;
  - state and type group constants (D4);
  - `linkState($idCustomer)`: identifier, name, matched_by, linked flag, `PS-C<id>` when unlinked;
  - `localCounts($idCustomer)`: issued, paid, held, attention, and issued by type receipt/invoice/other, all zero when empty.

  Verify that new `tests/Unit/CustomerContractorServiceTest.php` cases cover "Linked customer", "Customer without link", "Mixed documents" (2 receipts + 1 paid invoice + 1 error → 3 issued, 1 paid, 1 attention) and "No documents".

## 3. Registration check, search and ranking (D3, D6)

- [x] 3.1 Implement `check($idCustomer)` in `IfirmaCustomerContractorService`: `fetch()` the shown identifier and return `registered` (with name) / `missing` / `error` (with message). It never touches the mapping. Verify with `CustomerContractorServiceTest` using the scripted transport:
  - "Contractor exists" and "Contractor missing" (Kod 208);
  - "Check refused by the rate guard" (`IFIRMA_RATE_MAX_PER_MIN` exhausted, no request sent, mapping row unchanged);
  - a transport error.
- [x] 3.2 Implement `searchCandidates(array $criteria)`:
  - phrase building: NIP ≥ 10 digits, company, full name, lastname only while fewer than 3 phrases; max 4, case-insensitive de-duplication;
  - per-phrase `IfirmaContractorService::search()` with errors collected per phrase;
  - merge by `Identyfikator`, dropping rows without one;
  - flags nip/email/phone/name with the phone normalisation of D6;
  - sort by NIP flag, then flag count, then normalised name;
  - `refused` result with no call when only e-mail/phone are given.

  Verify with `CustomerContractorServiceTest`:
  - "Search by name and NIP": one row for `KOWALSKI-01`, phrases `1234563218`, `Jan Kowalski`, `Kowalski` (3 GETs; the surname-only phrase is added because fewer than 3 phrases existed, D6);
  - "Nothing found";
  - "Search error": rows from the healthy phrase and the error named for the failing one;
  - "Phone confirms a contractor": `+48 600 100 200` vs `600100200` ranked first with name+phone flags;
  - "E-mail only": refused, zero requests;
  - e-mail matched on `EmailDlaFaktury` case-insensitively;
  - never more than 4 search requests.

## 4. Remote document count (D5)

- [x] 4.1 Implement `remoteCount($idCustomer, $clock = null)`:
  - yearly windows from `max(customer date_add, today − 10 years)` to today, newest first, each ≤ 365 days;
  - per window the `kontrahent` query and, with a 10-digit NIP from the latest invoice address, the `nipKontrahenta` query, both with `iloscNaStronie` 100 and `strona` paging until a short page;
  - de-duplication by `FakturaId`;
  - Kod 201 on `kontrahent` disables that filter for the remaining windows;
  - budget of 20 calls, then `complete = false` with the covered period;
  - any other failure returns `error` and no total;
  - result `total`, `by_type` (`Rodzaj` mapping), `paid` (`Zaplacono >= Brutto - 0.01`), `period_from`, `period_to`, `calls`, `complete`.

  Verify with `CustomerContractorServiceTest` and a fixed clock:
  - "Count by contractor": 5 rows, 4 paid, period from the registration date;
  - "Same document returned by both filters": counted once;
  - "Contractor unknown to iFirma": NIP part still counted, no further `kontrahent` calls;
  - "Every list call is bounded": assert every recorded request URL has `dataOd`, `dataDo` and `iloscNaStronie` and a span ≤ 365 days;
  - "Budget exhausted": exactly 20 requests, `complete` false;
  - "Call failure": error, no total.

## 5. Link, unlink and register actions (D2, D7, D8)

- [x] 5.1 Add `link($idCustomer, $identifier)` and `unlink($idCustomer)` to `IfirmaCustomerContractorService`:
  - `link`: trimmed, non-empty, ≤ 64 characters; `fetch()` re-read; `IfirmaContractorMap::link(..., BY_MANUAL, Nazwa)` only on an array result;
  - `unlink`: `IfirmaContractorMap::unlinkCustomer()`, no API call.

  Verify with `CustomerContractorServiceTest`:
  - "Link a found contractor": map row `manual` with the iFirma name;
  - "Replace an existing link": a single row for the customer;
  - "Chosen contractor no longer exists": no row;
  - empty or 65-character identifier refused without a request;
  - "Unlink a customer": row removed, zero requests.

  Add a `tests/Unit/IssuancePipelineTest.php` (or `ContractorReferenceTest.php`) case proving that after a manual link the next document references the chosen identifier.
- [x] 5.2 In `classes/IfirmaAdminActions.php` add customer-level methods, each returning the `success/message/data` envelope with translated messages (`ifirmaadminactions` key) and catching `Exception`/`Throwable` through `failure()`:
  - `customerCheck`, `customerSearch`, `customerLink`, `customerUnlink`, `customerCountRemote`;
  - `registerCustomer($idCustomer)`: newest order via `Order::getCustomerOrders`, delegating to `registerContractor($idOrder)`; refuses "no order" and "sync disabled" with their messages.

  Add the Polish strings to the map and regenerate `pl.php`. Verify `tests/Unit/AdminActionsTest.php` covers "Register a customer" (delegates with the newest order id, `PS-C7` created and linked), "No order", "Sync disabled", an invalid customer id refused, and that `TranslationCoverageTest` passes.
- [x] 5.3 In `controllers/admin/AdminIfirmaController.php` add the following, all via `respond()`:
  - `ajaxProcessCustomerCheck`, `CustomerSearch` (criteria read with `Tools::getValue` and cast to strings), `CustomerLink`, `CustomerUnlink`, `CustomerRegister`, `CustomerCountRemote`;
  - a private `canEditCustomers()` helper: `Access::isGranted('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', id_profile)` (no `Profile` fallback: PrestaShop 1.7 support is dropped, see 6.2). Link, unlink and register are refused with a permission message when it returns false.

  Verify with new controller-level tests (in `AdminActionsTest.php` or a new `tests/Unit/AdminControllerCustomerTest.php`, using the stubs from 1.1) for "Employee without edit permission" (link row unchanged) and "Missing token". For the token case, assert that the action is not reached when `ModuleAdminController` token validation fails in the stub, or document in the test that core enforces it and assert the stub's `checkToken` path.

## 6. Customer page panel, hook and upgrade (D1, D3, D8, D9)

- [x] 6.1 In `ifirma.php`:
  - register `displayAdminCustomers` in `install()`;
  - add `hookDisplayAdminCustomers($params)`: read `id_customer`, refuse missing or unloaded customers with an empty string, assign link state, local counts, the pre-filled search criteria (firstname, lastname, and company, `vat_number`, `phone`/`phone_mobile` of the latest invoice address), register availability with its reason, the layout (card/panel), the AJAX URL with the `AdminIfirma` token and the match-type label from `matchedByLabel()`; it makes no API call;
  - create `views/templates/hook/admin_customer_panel.tpl` with every value through `|escape:'html':'UTF-8'`;
  - the inline script runs the check on load, the search form, a result table built with jQuery `.text()`, Link/Unlink with `confirm()` (the link text carries the overwrite warning from D7), Register, and **Count in iFirma** showing "at least N" when incomplete;
  - styles go in `views/css/ifirma.css`;
  - add the Polish strings to the map and regenerate `pl.php`.

  Verify new `tests/Unit/ModuleHooksTest.php` cases:
  - "Panel shown on a fresh install": the hook is registered and returns HTML containing the panel and `PS-C7`;
  - "Page does not wait for iFirma": rendering makes zero transport requests;
  - "Markup in a contractor name": a mapped name `<script>alert(1)</script>` appears escaped;
  - "No order" and "Sync disabled" reasons rendered;
  - `TranslationCoverageTest` passes.
- [x] 6.2 Add `upgrade/upgrade-1.2.0.php` (license header, `upgrade_module_1_2_0($module)` registering `displayAdminCustomers` and returning true; idempotent). Move the version to 1.2.0 in `ifirma.php`, `config.xml` and `package.json`. Drop PrestaShop 1.7: `ps_versions_compliancy` min `8.0.0`; remove the `displayAdminOrder` hook (install and handler; the upgrade unregisters it), the Bootstrap 3 `panel` layouts of both templates and the `Profile` permission fallback. Verify:
  - `tests/Unit/UpgradeTest.php` gains a case for "Panel shown after upgrade" (hook registered once even when run twice) and one for the legacy `displayAdminOrder` hook being unregistered;
  - `ConfigurationKeysTest`/`SchemaTest` are unchanged and pass;
  - `python tools/check_license_headers.py` passes.

## 7. Documentation

- [x] 7.1 In README:
  - add a "Customer page" section covering the panel contents, the one lookup per page view, the remote count budget and the "at least" wording, and that e-mail/phone only rank results because iFirma searches by name, company or NIP;
  - extend "Linking existing contractors" with manual linking from the customer page and the overwrite-on-next-document note;
  - add 1.2.0 to the changelog/upgrade notes.

  Verify that the README describes every user-visible action of the panel and names the 20-call cap and the 4-phrase cap exactly as implemented.

## 8. Integration check against the mock

- [x] 8.1 Add the next free scenario letter after `H` (`I`) to `tests/smoke/run.php`, registered in the `SMOKE_ONLY` handling and in the `tests/smoke/README.md` table:
  1. seed a mock contractor `KOWALSKI-01` with the customer's name and phone;
  2. call `IfirmaAdminActions::customerSearch` and assert it is listed with the phone flag;
  3. `customerLink` and assert the map row `manual`;
  4. issue a document for an order of that customer and assert `Kontrahent.Identyfikator`/`IdentyfikatorKontrahenta` is `KOWALSKI-01`;
  5. `customerCountRemote` and assert total ≥ 1 and complete;
  6. `customerUnlink` and assert no row.

  Verify `wsl.exe docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/run.php` reports no FAIL line. Open a customer page in the 8.1 and 9.1 back office and confirm the panel renders (PrestaShop 1.7 support is dropped, see 6.2). Record the PS versions checked in the task commit message.
