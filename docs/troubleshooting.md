# Troubleshooting

iFirma error codes and the fixes for the problems seen most often.

[Back to README](../README.md)

## Error codes

A non-zero `Kod` in the response is shown as `<cause> (Kod N): <Informacja>`; HTTP status 400+
without a JSON body is reported as `HTTP <status> returned by iFirma.`

| Kod | Meaning |
|-----|---------|
| 100 | Technical problem on the iFirma side |
| 101 | Wrong encoding (UTF-8 expected) |
| 102 | Missing or invalid content type |
| 200 | Invalid request structure |
| 201 | Validation error in document data |
| 202 | Wrong service or account settings |
| 208 | Contractor not found in iFirma ("Nie znaleziono kontrahenta") |
| 400 | Invalid authorization header |
| 401 | Invalid user login |
| 402 | No API key generated for this account |
| 403 | Invalid signature - check the API key |
| 499 | iFirma terms of service not accepted |
| 500 | Insufficient permissions (super-account required) |

## Troubleshooting

**After updating the module, clear the PrestaShop cache** (Advanced Parameters > Performance > Clear cache).
With *Template compilation* set to "Never recompile template files" (the common production setting),
PrestaShop keeps serving the old admin panel and customer templates until the cache is cleared, even though
the PHP code is already new. The module version shown in the panel diagnostics tells you which code runs.

**`Nie znaleziono kontrahenta o zadanym identyfikatorze` (Kod 201).** iFirma looks an existing contractor up
through the document's top-level `IdentyfikatorKontrahenta` field, then by the buyer's NIP (`NIPKontrahenta`),
and otherwise creates a contractor from the `Kontrahent` data. The module never sends `Kontrahent.Identyfikator`.
`IdentyfikatorKontrahenta` accepts at most 15 characters (`NIPKontrahenta` 13), so a contractor whose identifier is longer (for example an
`EC_`-prefixed name created by another integration) cannot be referenced. In that case the module still sends it; when iFirma ignores or rejects it, the document gets
the contractor iFirma attaches or creates, and the module records that one on
the order panel as "attached by iFirma to an issued document"; later orders of that customer reuse it. If iFirma
rejects a referenced identifier with this message, the module retries once without it and reports a second
rejection as an error. Version 1.1.0 registers the contractor `PS-C<customer id>` before issuing (see
"Notes & assumptions") and falls back to a document without identifier when that fails. If you still see
this message: (1) on older builds, update the module and clear the cache; (2) open the order's iFirma
panel, expand **Diagnostics: last iFirma request and response**, and check the *Contractor step* line and the
request body; (3) as a workaround, disable *Register the customer as an iFirma contractor* in the module
settings so every document is created without an identifier.

**`Zatrzymano na kroku „guard” (inconclusive)` / "stopped at the guard step".** Before issuing, the duplicate guard
lists the customer's recent iFirma documents with `faktury.json?kontrahent=<identifier>`. When the identifier does
not exist in the iFirma account (for example `PS-C<customer id>` of a customer that was never registered), iFirma
answers this list call with Kod 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze" instead of an empty list.
Older builds treated every failed list call as inconclusive, so the order stopped at the guard step and no document
was attempted. Current builds treat this answer, for the filtered query only, as "no documents for
that contractor" and continue with the NIP query and the unfiltered window query. Other failures (network,
authentication, rate limit, or a Kod 201 about something else) still stop the pipeline. On an older build, install the
new module zip and clear the PrestaShop cache. As a manual workaround for one order, use **Wystaw mimo to / Issue
anyway** in the order's iFirma panel; it bypasses the duplicate guard once for that order and is recorded on the receipt.

**"tryb awaryjny" / "fallback" on the contractor line of the order panel.** The document was still issued, but without a contractor reference. The contractor iFirma attached is shown after the note and is reused for the next order of that customer. The note in parentheses says which stage failed. After a confirmed create it lists each identifier tried with iFirma's answer (for example `PS-C1909: Kod 208: Nie znaleziono kontrahenta`) and whether the search found nothing or found an ambiguous result. What to check: (1) that the PrestaShop cache was cleared (older builds: update the module and clear the cache); (2) the Diagnostics block of the order panel (request body and the `_contractor` note); (3) as a workaround, the *Register the customer as an iFirma contractor* switch in the module settings. Records written before this version show only an English sentence.

**`Niepoprawna zawartość żądania - nie można utworzyć obiektu na podstawie zawartości żądania` (Kod 200).** iFirma could not map the JSON sent to it onto a document object. The message never names the offending field; the usual cause is an undocumented enumeration value. Older builds sent `RodzajPodpisuOdbiorcy: BRK`, a value that does not exist in the iFirma API. Fix: install the current module zip (the *Recipient signature* setting defaults to `BPO`, and any other stored value is normalised to `BPO`), then clear the PrestaShop cache. To check a request yourself, expand **Diagnostics: last iFirma request and response** in the order's iFirma panel and compare every enumerated field with the documented lists: `LiczOd`, `FormatDatySprzedazy`, `SposobZaplaty`, `RodzajPodpisuOdbiorcy` (`OUP`, `UPO`, `BPO`, `BWO`) and `TypStawkiVat`. Kod 200 never names the field, so when the request contained `DodatkoweUwagi` (the only optional object the module sends) the module retries once without it; if the retry is also rejected, or the request had no `DodatkoweUwagi`, the error is recorded.

**`KSeF: <error>` (after issuing a document).** The document was issued and delivery ran, but the KSeF submission failed. The receipt shows state error (`BLAD`) with the same message. Check: (1) the iFirma account is authorised to send invoices to KSeF (the KSeF authorisation is configured in iFirma, not in the module); (2) the buyer data on the invoice is complete, since a B2B invoice needs a valid NIP; (3) retry with **Send to KSeF** in the order panel; if the same error returns, copy the diagnostics block and the message. Then refresh the status in the order panel: once iFirma reports `PRZYJETA`, the number appears on the receipt and the customer page.

**Reporting a problem.** Copy the diagnostics block (request, response, contractor step, last error) and the
relevant lines from Advanced Parameters > Logs (messages start with "iFirma"). Neither contains API keys.
