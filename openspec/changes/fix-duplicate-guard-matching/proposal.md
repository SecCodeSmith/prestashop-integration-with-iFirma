# Proposal

## Why

The duplicate guard decides that a listed iFirma document is a candidate for an order from amount, currency and document type alone. When the buyer has no earlier document under their contractor identifier or NIP, the guard falls back to an unfiltered list of the whole account for up to 60 days, so every document of every customer whose gross equals the order total becomes a candidate. None of them carries the order reference, and the order is blocked as `duplicate_suspected`. The merchant reported on 2026-11-03 that every receipt of the month for the same product is flagged as a duplicate. A document can only be a duplicate of an order when it was issued to the same buyer, for the same sale date and for the same products.

## What Changes

- **Contractor match on list rows.** A listed document is a candidate only when it belongs to the order's buyer: its `IdentyfikatorKontrahenta` equals the identifier the module would reference for the customer (stored mapping or `PS-C{id_customer}`), or its `NIPKontrahenta` equals the NIP of the invoice address, or its `KontrahentNazwa` equals the buyer name the module would send (company, or first and last name) after whitespace and case normalisation. Rows of other buyers are dropped before any detail call.
- **Sale date and product verification on the detail.** A candidate whose detail does not carry the order reference is kept as suspected only when the detail's `DataSprzedazy` is the order's sale date (the order date, as the module itself stamps it) and every product line of the order has a document position with the same name and quantity. Extra document positions (shipping, fees) are tolerated. A candidate that fails either check is not a duplicate and is dropped. A detail that cannot be fetched or lacks these fields leaves the candidate unverified and still suspected.
- **Outcome rules.** A candidate whose detail carries the order reference is still adopted, regardless of sale date or positions. When no candidate survives verification the guard proceeds and the document is created. The suspected outcome is reached only with surviving candidates.
- **Candidate record.** Each recorded candidate gains the sale date read from its detail (empty when unverified) and the contractor match reason (`identifier`, `nip`, `name`). The admin order panel shows the sale date next to the issue date.
- **Test stack.** The mock seed endpoint accepts a sale date and a sale date format so smoke scenarios can seed documents that match or miss the new checks; the detail endpoint returns them.
- **Documentation.** README "Duplicate guard" steps 3 to 5 describe the contractor, sale date and product checks; the troubleshooting entry for `duplicate_suspected` names them.
- No new configuration key. Existing settings (`IFIRMA_DUPLICATE_CHECK`, `IFIRMA_DUPLICATE_WINDOW_DAYS`, `IFIRMA_DUPLICATE_AMOUNT_TOLERANCE`) keep their meaning.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `receipt-duplicate-guard`: "Candidate selection" gains the contractor criterion; "Suspected duplicate blocks issuance" now requires the surviving candidates to match the order's sale date and product lines (or be unverifiable) and records the sale date; a new requirement "Detail verification of sale date and product lines" defines the verification, its tolerance for extra positions and its handling of unverifiable details.

## Impact

- **Code:** `classes/IfirmaDuplicateMatcher.php` (buyer match on rows, detail verification, richer summary), `classes/IfirmaDuplicateGuard.php` (builds the buyer identity and the order lines, verifies details before marking suspected), `classes/IfirmaReceiptService.php` (shared helpers for the order's product lines, sale date and buyer names so the guard compares exactly what the module would send), `views/templates/hook/admin_order_panel.tpl` and `translations/pl.php` (sale date column), README.
- **Tests:** `tests/Unit/DuplicateMatcherTest.php`, `tests/Unit/DuplicateGuardTest.php`, `tests/Unit/IssuancePipelineTest.php` (list-row fixtures need contractor fields and detail bodies with `DataSprzedazy` and `Pozycje`), `tests/fixtures/detail-response.json` stays valid. Test stack (separate repository): mock seed endpoint and pytest cases, smoke scenario A in `tests/smoke/run.php` seeds a document that matches buyer, sale date and product.
- **Behaviour visible to merchants:** documents of other customers never block an order; a repeat order of the same product by the same customer on another day is no longer blocked; a repeat order on the same day for the same products is still flagged for the admin to resolve. External documents whose line names differ from the shop's product names are no longer flagged, but are still adopted when they carry the order reference.
- **iFirma API usage:** fewer detail calls, because rows of other buyers are dropped before the detail step. The list query sequence (contractor identifier, NIP, unfiltered window) is unchanged.
- **Data:** no schema change. The `duplicate_candidates` JSON gains two optional keys per candidate; older records without them render with an empty sale date.
