# Proposal

## Why

The merchant can see a customer's iFirma state only one order at a time, in the order panel. Nothing on the back-office customer page says whether the customer is registered as an iFirma contractor, which contractor the module uses for them, or how many documents they already have. When a returning customer was registered in iFirma before the module existed (by hand, by another shop or by an import), the automatic matching (NIP, then name confirmed by e-mail or postcode) often can't decide. The module then creates a second contractor `PS-C<id>`. The merchant has no place to look up the older contractor and link it by hand, even though the link type `manual` already exists in the mapping table.

## What Changes

- **iFirma panel on the customer page.** The back-office customer view (hook `displayAdminCustomers`, both the legacy and the Symfony customer page) shows an iFirma panel with:
  - the contractor link: the linked identifier, its name and how it was matched, or `PS-C<id>`, or "not linked";
  - a live registration check: one contractor lookup in iFirma when the panel opens, loaded asynchronously so the customer page never waits. It shows "registered", "not found in iFirma", or "could not check" with the error;
  - the document count from the module log for that customer, split by state (issued, paid, held, needs attention) and by type (receipt, domestic invoice, other);
  - a **Count in iFirma** button that lists the customer's documents through the iFirma API, filtered by the linked contractor identifier and, when known, the customer's NIP. It covers yearly windows from the customer's registration date to today and reports the count by type, the paid count and the period covered. It says "at least N" when the call budget is used up before the list ends.
- **Contractor search and choice.** When the customer is not linked, or the linked contractor is not found, the panel offers a search for older contractors. The fields come pre-filled from the customer and their addresses: first name, last name, company, NIP, e-mail, phone. Name, surname, company and NIP are sent to the iFirma contractor search, which only matches identifier, name or NIP. The results are merged without duplicates. E-mail and phone can't be searched in iFirma. They are compared with each returned row to mark and rank matches (NIP, e-mail, phone, name). A search with only an e-mail or a phone is refused with an explanation. The employee picks one contractor from the list and confirms. The module re-reads it from iFirma and stores the link with match type `manual`. The same search and choice are available to replace an existing link, and an **Unlink** action removes the link.
- **Register as new contractor.** When the employee finds no older contractor, the panel offers to register the customer as a new contractor (`PS-C<id>`). This uses the existing contractor registration with the data of the customer's latest order. It is unavailable when the customer has no order or contractor sync is disabled.
- **Warning on linking.** The confirmation dialog states that the next document for this customer will update the chosen contractor's name, NIP, address and e-mail in iFirma with the shop data. This is the existing behaviour for every linked contractor.
- **Upgrade.** The module version moves to 1.2.0. A new upgrade script registers the `displayAdminCustomers` hook on existing installations. Fresh installs register it in `install()`.
- **Translations and docs.** Polish translations for every new string. README gets a "Customer page" section and an entry in "Linking existing contractors".

## Capabilities

### New Capabilities

- `customer-contractor-panel`: the back-office customer view of the iFirma relationship. It covers contractor link state, the live registration check, local and API document counts, the contractor search with e-mail/phone ranking, manual linking, unlinking and registration from the customer page, and access control on those actions.

### Modified Capabilities

None. Manual links go through the existing customer-to-contractor mapping. The "Existing contractor matching" requirement in `ifirma-api-compliance` already makes a stored mapping win at issuance, so issuance behaviour is unchanged. "List calls always bounded" still holds: every count call carries `dataOd`, `dataDo` and `iloscNaStronie`.

## Impact

- **Code:** `ifirma.php` (hook registration, `hookDisplayAdminCustomers`, local count query, panel assignment), new `classes/IfirmaCustomerContractorService.php` (registration check, search and ranking, API count, link/unlink for a customer), `classes/IfirmaContractorService.php` (`search` and `fetch` reused unchanged), `classes/IfirmaReceipt.php` (count by customer grouped by state and type), `classes/IfirmaAdminActions.php` (customer-level actions; registration delegates to the existing order-level registration with the latest order), `controllers/admin/AdminIfirmaController.php` (AJAX actions `CustomerCheck`, `CustomerSearch`, `CustomerLink`, `CustomerUnlink`, `CustomerRegister`, `CustomerCountRemote`), new template `views/templates/hook/admin_customer_panel.tpl`, `views/css/ifirma.css`, `upgrade/upgrade-1.2.0.php`, `config.xml` (version), `translations/pl.php`, `tools/pl_translations_map.php`, README.
- **Tests:** new `tests/Unit/CustomerContractorServiceTest.php`. Extended `tests/Unit/AdminActionsTest.php`, `tests/Unit/ModuleHooksTest.php`, `tests/Unit/UpgradeTest.php` and `tests/Unit/TranslationCoverageTest.php` (coverage of the new strings). New smoke scenario in `tests/smoke/run.php` against the iFirma mock (search, link, count).
- **iFirma API usage:** one contractor lookup each time a customer page is opened (subject to the local rate guard; a refusal shows "could not check"). Up to 4 search calls per search. The remote count costs one list call per yearly window and page, capped at 20 calls per click. No call is made without an employee opening the page or clicking.
- **Data:** no schema change. Manual links are rows in `ifirma_contractor_map` with `matched_by = manual`.
- **Behaviour visible to merchants:** a new panel on every customer page. Documents issued after a manual link reference the chosen contractor instead of creating `PS-C<id>`.
