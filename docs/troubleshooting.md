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

### Calls paused after a rejected key

**Message:** `iFirma rejected the credentials at <time>: <message>; calls are paused until <time>`. It is shown as a
warning on the module settings page, as the error of a create or refresh attempt, and as "not attempted (credentials
rejected)" in a bulk summary.

**Meaning:** iFirma answered with HTTP 401 or 403, or with `Kod` 400, 401, 402, 403 or 499. The module then makes no call
to iFirma for 15 minutes, so the same wrong key is not sent again and again. The refused calls are not sent: a document
whose create was refused this way ends in state *error*, not *unknown*. `Kod` 500 does not start a pause, because it can
come from a single endpoint.

**What to do:** check the *faktura* key and the login in iFirma (*Konfiguracja → Abonent → API → Klucze*). `Kod` 402
means that no API key exists for the account, and `Kod` 499 that the iFirma terms of service are not accepted. Then save
the module settings: a successful save ends the pause at once. If the same message comes back after the pause, iFirma still
rejects the credentials.

### "Not attempted (rate limited)" in a bulk summary

**Meaning:** during a bulk run the module's per-minute rate guard refused a request (the default cap is 80 requests a
minute, see [Rate limits](configuration.md#rate-limits)). The run stopped there. The records it did not reach keep their
previous state and are not errors. The records already handled keep their outcome.

**What to do:** wait a minute, select the records that were not attempted in the [documents list](usage.md#ifirma-documents-list)
and run the action again. The cap is shared with every other client of the same iFirma account, so a busy account may need
the bulk runs at quiet times.

### Permission messages (HTTP 403)

**Messages:** "You do not have permission to issue iFirma documents.", "You do not have permission to manage iFirma
documents." and "You do not have permission to see iFirma documents." They come from the panel, the list, the bulk
summary or the PDF download when the employee's profile lacks the level the action needs.

**Meaning:** the level is read from the roles of the module's tab (see [Permissions](configuration.md#permissions)). SuperAdmin
and the profile of the employee who installed the module hold all levels; any other profile holds none until it is granted
them. After an upgrade from an earlier version, profiles other than these have no panel until an administrator grants them.

**What to do:** open **Advanced Parameters > Team > Permissions**, choose the profile and grant *View*, *Add* and *Edit* for
**Orders > iFirma documents**, as needed (see the table in [Permissions](configuration.md#permissions)). Then reload the page.

### Internal error on the iFirma panel or in a download

**Message:** "Internal error; details are in the PrestaShop log." Only a module error shows its own text; any other failure
(a PHP error, a database error) is replaced by this text so that no internal detail reaches the browser.

**What to do:** open **Advanced Parameters > Logs** and look for the lines that start with "iFirma". They hold the original
class and message of the failure.

### Create fails after setting a document template

**Meaning:** the **Document template** setting sends the name to iFirma as `NazwaSzablonu`. iFirma has no list of templates the module could check against, so a name that does not exist in the account is only noticed when the document is created: iFirma answers with a create error, which the module records on the receipt and shows in the order's iFirma panel and in the issue feed.

**What to do:** copy the exact name from the template settings in your iFirma account (spelling, capitals and spaces must match) and paste it into the setting. To return to the default template, clear the field and save.

### HTTP 429 on the customer's PDF download

**Message:** "Please wait a moment before downloading the document again."

**Meaning:** the customer's download was refused by the download limit: the same document was fetched less than 30 seconds
ago, or the customer has already downloaded 10 documents in the last 60 seconds (see [Download limits](usage.md#download-limits)).
The document itself is fine.

**What to do:** wait a few seconds and reload. If customers hit the limit routinely, look for something that downloads the
PDF repeatedly, such as a link preview or a script.

### Delivery shows "sent" but no e-mail arrived

**Meaning:** the module records a delivery as *sent* when PrestaShop's mailer accepted the message; it does not wait for the recipient's mail server. With `PS_MAIL_METHOD=3` mail is switched off in the shop, and such a delivery still counts as sent.

**What to do on a production shop:** check the mail method and server under Advanced Parameters > E-mail. Then open Advanced Parameters > Logs and look for lines containing "Swift Error" or "Mailer Error"; they say why the mailer refused the message.

**On the test stack:** the shop sends to the mock's SMTP sink, not to a real mail server. Open `http://localhost:8001/__mock/status` and read the Mail section, or call `GET /__mock/mail`. `tests/smoke/run.php` leaves the shop pointed at the sink, so mail from the shop keeps going there after a smoke run until its mail settings are changed back.

**Reporting a problem.** Copy the diagnostics block (request, response, contractor step, last error) and the
relevant lines from Advanced Parameters > Logs (messages start with "iFirma"). Neither contains API keys.
