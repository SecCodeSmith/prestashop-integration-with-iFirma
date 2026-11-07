# Contractors

How shop customers are matched and linked to iFirma contractors: bulk import, matching while issuing, manual linking and the contractor notes.

[Back to README](../README.md)

## Linking existing contractors

Without any setup the module registers one iFirma contractor per customer (identifier `PS-C<id_customer>`). If you already keep contractors in iFirma you do not want a second record for every returning customer, so the module can link a customer to a contractor that exists. A link is stored in the table `ifirma_contractor_map` (one contractor per customer) and reused for every later order.

### Bulk linking from an iFirma export

1. In iFirma open *Kontrahenci* and export the list to CSV.
2. In the module configuration page open the *iFirma contractors* panel, choose the file (up to 5 MB) and press *Import contractors*.

The file may be separated with `;`, `,` or a tab and encoded as UTF-8 (with or without BOM) or Windows-1250; the separator and encoding are detected. Columns are found by their header, ignoring case and Polish letters:

| Meaning | Accepted headers |
|---|---|
| Identifier (required) | `Identyfikator`, `ID`, `Identifier` |
| Name | `Nazwa`, `Nazwa kontrahenta`, `Name` |
| NIP | `NIP`, `VAT` |
| E-mail | `E-mail`, `Email`, `Adres e-mail` |
| Postcode | `Kod pocztowy`, `KodPocztowy`, `Kod`, `Postal code` |

A file without the identifier column is refused with the message `Missing column: Identyfikator` and nothing is stored. The import never calls the iFirma API; it matches the rows against the shop database in this order:

1. **NIP** equal (digits only) to the VAT number or `dni` of a customer address (`import-nip`);
2. **e-mail** equal to the customer e-mail, ignoring case (`import-email`);
3. **name plus postcode**: the contractor name equals the address company or the customer's first and last name (case, spaces, punctuation and Polish letters ignored) and the postcode digits are equal (`import-name`). A name alone is never enough, and a name shared by several customers with the same postcode is skipped.

Each customer gets one link. A customer that already has a link keeps it unless the new row matches by a stronger rule (NIP is stronger than e-mail, e-mail stronger than name; a link created by the module as `PS-C<id>` is replaced by any import match; a manual link is never replaced). Re-importing the same file changes nothing. The report shows the number of rows read, linked customers per match type, unmatched rows (with the first 20 identifiers) and links kept.

### Matching while issuing a document

For customers that are not in the import, the module looks for an existing contractor when it issues a document, in this order: the stored link (verified in iFirma; dropped when the contractor no longer exists), the module contractor `PS-C<id>`, a contractor with exactly the order NIP (`GET kontrahenci/<NIP>.json`; with several results the one with the same name, then the same postcode), and a contractor whose name equals the buyer name and whose e-mail or postcode confirms it. Name matching without a confirming e-mail or postcode is deliberately refused: a document on the wrong contractor is worse than one extra contractor. When nothing matches, `PS-C<id>` is created as before. After iFirma confirms the create (`Kod` 0), the module reads back the contractor it just created before referencing it: first by the sent identifier `PS-C<id>` and by the identifier iFirma reports in `Wynik`. When neither is found it waits 2 s and reads both back once more. When still nothing is found, it searches with the same rules as before a create (exact NIP; otherwise the buyer name confirmed by e-mail or postcode) and links the customer to the identifier iFirma actually stored (for example `IFI1909556979`) with match type `module`. Only when all of that fails is the document issued without a contractor reference (fallback); the contractor iFirma attaches is then learned from the issued document as before. The order panel's "created" label adds "(iFirma assigned identifier <id>)" when iFirma's identifier differs from the sent one. iFirma returns at most 20 search results and offers no contractor list, which is why the export import exists. A failing search (network error, rejected signature) counts as "no match" and never blocks issuing. A linked contractor is updated with the shop's buyer data only when those fields differ (the same merge as for `PS-C<id>`), and the order panel shows the linked contractor and how it was matched.
Because `IdentyfikatorKontrahenta` accepts at most 15 characters, a link whose identifier is longer is still sent as-is and, when iFirma attaches a different contractor to the issued document, that contractor is stored as the link with match type `document` (label "attached by iFirma to an issued document").

### Linking by hand from the customer page

A single customer can be linked to a contractor from the iFirma panel on the back-office customer page (see [Customer page](usage.md#customer-page)). The employee picks the contractor from the search results, and the module re-reads it from iFirma and stores the link with match type `manual`. A manual link is never replaced by a bulk import. Warning: the next document for that customer updates the chosen contractor's name, NIP, address and e-mail in iFirma with the shop data, as for every linked contractor.

### Forgetting links

*Forget all links* in the same panel (it asks for confirmation) empties `ifirma_contractor_map`. Documents already issued are not touched; the next document of each customer is matched again. A single customer can also be unlinked from the customer page (**Unlink**); that removes only the stored link and changes nothing in iFirma.

## Contractor notes

- **Contractor registration.** iFirma treats a supplied `Kontrahent.Identyfikator` as a reference to
  an existing contractor (an unknown one is rejected with Kod 201, "Nie znaleziono kontrahenta"); it
  creates a contractor automatically only when the identifier is `null`. With
  `IFIRMA_CONTRACTOR_SYNC` on (default), the module first reads the contractor `PS-C<customer id>`
  (`GET kontrahenci/id/...`), creates it (`POST kontrahenci.json`) when missing, or refreshes the
  shop-owned fields (name, NIP, address, country, e-mail, private person flag) with a full-record
  `PUT` when they changed, and then issues the document with that identifier. If the contractor
  cannot be registered, or the document is still rejected with "Nie znaleziono kontrahenta", the
  document is created once with a `null` identifier so iFirma creates the contractor itself; the
  outcome (created / updated / kept / fallback) is shown in the order panel. Switch the option off
  to always send `null` and let iFirma create a new contractor from each document.
- The contractor identifier the module creates is `PS-C{id_customer}` (at most 15 characters,
  iFirma's documented limit for new contractors). Existing contractors may carry longer
  identifiers; the module stores up to 64 characters, so the same customer always maps to the
  same iFirma contractor.
