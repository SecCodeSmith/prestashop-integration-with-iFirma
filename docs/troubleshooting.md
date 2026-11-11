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

**`KSeF: <error>` (after issuing a document).** The document was issued, but the KSeF submission failed (the delivery to the customer waits for KSeF acceptance, see below). The receipt shows state error (`BLAD`) with the same message. Check: (1) the iFirma account is authorised to send invoices to KSeF (the KSeF authorisation is configured in iFirma, not in the module); (2) the buyer data on the invoice is complete, since a B2B invoice needs a valid NIP; (3) retry with **Send to KSeF** in the order panel; if the same error returns, copy the diagnostics block and the message. Then refresh the status in the order panel: once iFirma reports `PRZYJETA`, the number appears on the receipt and the customer page.

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

**Meaning:** during a bulk run the module's rate guard refused a request: the per-minute cap (default 80 requests a
minute), the per-day cap (default 14,000 requests a day) or a running back-off after iFirma's own limit answer (see
[Rate limits](configuration.md#rate-limits)). The run stopped there. In an issue or retry run, the order whose request was
refused is queued and the cron issues it later; the records the run did not reach keep their previous state and are not
errors. The records already handled keep their outcome.

**What to do:** read the hint at the end of the message. `Run it again in a minute.` is shown for the minute cap,
`Run it again tomorrow.` when the day cap was reached, and `Run it again after 13:15.` (the time, with the date when it
is not today) when iFirma asked the module to wait. Then select the records that were not attempted in
the [documents list](usage.md#ifirma-documents-list) and run the action again; queued orders need no new click, because the
cron issues them. The cap is shared with every other client of the same iFirma account, so a busy account may need the
bulk runs at quiet times.

### Rate limited by iFirma

**Meaning:** iFirma itself refused a request because the account exceeded its limit (100 requests a minute, 15,000 a day,
shared by every program that uses the account's API). The module recognises an HTTP 429 answer, and an answer whose message
says the request limit was exceeded ("limit zapytań"). It is a transient "rate limited" state, not an error of the document
and not a rejected key. The shop log and the admin pages show a message such as "iFirma refused the request: the request
limit was exceeded (HTTP 429). No requests until 2026-11-01 10:16:00."

**What the module does:** it starts a back-off and sends nothing until the time iFirma asked for (its `Retry-After` header,
at most 24 hours), or until the start of the next minute when the header is missing or unusable. The end of the back-off
is stored in `IFIRMA_RATE_BACKOFF_UNTIL` (unix time). A request started during the back-off is refused locally, without any
network call, and its message names the end time. A document whose create request was refused is not created: iFirma
refused it before processing.

**What to do:** nothing in most cases, the next request after the end time goes through. If it keeps happening, other
programs use the same account: lower `API calls per minute` and `API calls per day` on the settings page
([Rate limits](configuration.md#rate-limits)). The exact answer of iFirma's limit is not documented; if a limit answer is not
recognised (it shows up as a technical problem, `Kod` 100), please report its HTTP status and text.

### What uses my request limit

**Meaning:** iFirma's limit (100 requests a minute, 15,000 a day) is shared by every program that uses the account's API,
and the module counts every request it sends, status checks and PDF downloads included.

**What to do:** open the **Module requests** box on the settings page. It shows the requests of the current minute and of today
next to the caps and today's count per kind ([Module requests](configuration.md#module-requests)). A high `status` count
means the status sync or the documents list ran a lot; a high `pdf` count means many downloads or deliveries. If the day count
is higher than the kinds add up to, the row **Other clients of the account** shows what other programs used. Click **Check the
remaining iFirma limit** (needs the `abonent` key) to see what iFirma itself counts; one click costs one request. Then lower
the caps or the cron frequency ([Rate limits](configuration.md#rate-limits)).

### A KSeF invoice was not e-mailed to the customer

**Meaning:** an invoice that goes to KSeF (mode `b2b` for a buyer with a NIP, or `all`) is delivered only after KSeF has accepted it. Its delivery state is `awaiting_ksef` and the order panel says "waiting for KSeF acceptance"; the customer's page shows "not yet sent" and no download link.

**What to do:** check the KSeF row of the order panel. *Sent, awaiting acceptance* or *scheduled* only needs time (iFirma may send at the hour set in its account settings, so it can take until the next day): the cron endpoint delivers the document after the sync reads the KSeF number. *Waiting for iFirma* means the submitter is `iFirma` and iFirma has not reported a state yet. *Error* or *rejected by KSeF* needs a decision: fix the cause in iFirma, then **Send to KSeF** again, or deliver by hand with **Send again** (the panel warns that the document is not yet accepted). A wait longer than 48 hours is listed on the notification tab. Without a cron job nothing is delivered after acceptance: call the [cron endpoint](configuration.md#cron-jobs) every 1 to 5 minutes. If your iFirma account submits invoices to KSeF by itself, set **KSeF submitter** to *iFirma*; otherwise the module may submit the same invoice a second time when the detail answer carries no KSeF fields.

### Permission messages (HTTP 403)

**Messages:** "You do not have permission to issue iFirma documents.", "You do not have permission to manage iFirma
documents." and "You do not have permission to see iFirma documents." They come from the panel, the list, the bulk
summary or the PDF download when the employee's profile lacks the level the action needs.

**Meaning:** the level is read from the roles of the module's tab (see [Permissions](configuration.md#permissions)). SuperAdmin
and the profile of the employee who installed the module hold all levels; any other profile holds none until it is granted
them. After an upgrade from an earlier version, profiles other than these have no panel until an administrator grants them.

**What to do:** open **Advanced Parameters > Team > Permissions**, choose the profile and grant *View*, *Add* and *Edit* for
**Orders > iFirma documents**, as needed (see the table in [Permissions](configuration.md#permissions)). Then reload the page.

### "This action must be sent with POST." (HTTP 405)

**Message:** "This action must be sent with POST."

**Meaning:** since 1.10.0 every back-office action that changes something or sends a request to iFirma (issuing, linking, sending again, status updates, KSeF, approving or rejecting a request, the customer link, unlink and register, marking the bell as seen, and all bulk runs) runs only for a POST request. The module's own buttons send POST. A GET, for example a bookmarked or scripted `...&ajax=1&action=CreateReceipt` URL, is answered with HTTP 405 and this text, and nothing is changed. Only the PDF download and the read-only checks (the notification feed, the customer check, search and count) still accept GET.

**What to do:** change the script to send a POST with the back-office token. If a button of the module shows this message, a proxy or a browser extension is rewriting the request method; report it.

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
ago, or the customer has already downloaded 10 documents in the last 60 seconds (see [Download limits](usage.md#download-limits),
which lists every download limit). The document itself is fine. This answer has no `Retry-After` header.

**What to do:** wait a few seconds and reload. If customers hit the limit routinely, look for something that downloads the
PDF repeatedly, such as a link preview or a script. A download can also be refused with "Too many requests at the moment.
Please try again shortly." when the customer has already downloaded 30 documents today, or when all customers together have
used the customer download budget (20 per minute, 2,000 per day); it works again in the next minute or the next day.

### HTTP 429 on a PDF download because of the module's request limit

**Message for the customer:** "Too many requests at the moment. Please try again shortly." The answer is HTTP 429 with a
`Retry-After` header. For a customer the value is always 60, whichever limit refused, so the answer does not reveal the window.
For an employee it is exact: the seconds to the next minute for the minute limit (1 to 60), the seconds to the end of a back-off,
and for the day limit at most 3600.

**Message for an employee (back office download):** one of "The PDF was not downloaded: the minute request limit is
reached. Try again in a moment.", "... the day request limit is reached. Try again later." or "... iFirma asked the module to
wait before the next request. Try again shortly."

**Meaning:** the module's own request limit ([Rate limits](configuration.md#rate-limits)) or a running back-off refused the
request before it was sent: no request reached iFirma and no document was changed. It is not an error of the document and
not the download limit of the customer ([HTTP 429 on the customer's PDF download](#http-429-on-the-customers-pdf-download)).
The customer is only asked to try again; the exact window and the counters are shown to employees, never to customers. A
request that was sent and failed at iFirma is still answered with HTTP 502 ("The document is temporarily unavailable. Please try again later.").
A pause after a rejected key is not a rate limit and keeps its own message ([Calls paused after a rejected key](#calls-paused-after-a-rejected-key)).

**What to do:** wait for the seconds in `Retry-After` and download again. The module does not wait or retry by itself.
A customer request that the limit refused has already taken the 30-second slot of that document, so a retry of the same
document within 30 seconds gets the answer of the previous section (no `Retry-After`); the unit of the shared budget and the
customer's day count are given back, so they are not used up by the refusal (see
[Download limits](usage.md#download-limits)).
If it happens often, other programs use the same iFirma account or the caps are too low for your traffic: see
[Rate limited by iFirma](#rate-limited-by-ifirma).

### "The status was not refreshed" on the order panel

**Message:** "The status was not refreshed: the minute request limit is reached. Try again in a moment." (or the day
limit, or "iFirma asked the module to wait before the next request", or "iFirma reported that its request limit was
exceeded").

**Meaning:** **Refresh from iFirma** was refused by the request limit, so no list call was made. The stored state, the last
sync time and the sync error of the document are unchanged and nothing was recorded as an error. The same refusal on the
customer's order page is silent: the page shows the last known state. The page is also silent when the budget of the
customer page syncs is used up (10 calls per calendar minute and 1,000 per calendar day for all customers together):
no call is made, nothing is recorded, and the next page view after the minute or the day has passed tries again.
A customer's order pages start one list call per interval, so the page of a second order, of another issue date, opened
within the interval shows the last known state until the cron reads it. A document that the customer's page could not find
on the first page of a day with more than 100 documents, and any document other than the one opened that is not among the
rows, keeps its stored state until the cron reads it; it is not shown as missing in iFirma.

**What to do:** wait a moment and click **Refresh from iFirma** again.

### Delivery shows "sent" but no e-mail arrived

**Meaning:** the module records a delivery as *sent* when PrestaShop's mailer accepted the message; it does not wait for the recipient's mail server. With `PS_MAIL_METHOD=3` mail is switched off in the shop, and such a delivery still counts as sent.

**What to do on a production shop:** check the mail method and server under Advanced Parameters > E-mail. Then open Advanced Parameters > Logs and look for lines containing "Swift Error" or "Mailer Error"; they say why the mailer refused the message.

**On the test stack:** the shop sends to the mock's SMTP sink, not to a real mail server. Open `http://localhost:8001/__mock/status` and read the Mail section, or call `GET /__mock/mail`. `tests/smoke/run.php` leaves the shop pointed at the sink, so mail from the shop keeps going there after a smoke run until its mail settings are changed back.

### A delivery stays "failed"

**Meaning:** the e-mail of an issued document could not be sent. The order panel, the Orders-list tooltip and the delivery column of the iFirma documents list show the error and one line that tells where the automatic retry stands (see [Automatic retry of a failed delivery](usage.md#automatic-retry-of-a-failed-delivery)):

- **Attempt 1 of 3, next attempt at 10:15** (or attempt 2 of 3, or **Attempt 0 of 3** for a just issued document whose first delivery the request limit refused before anything was sent): nothing to do. The [cron endpoint](configuration.md#cron-jobs) sends the document again when the time has come. If the time has passed and the delivery is still failed, the cron is not called (call it every 1 to 5 minutes), the request limit is reached (a refusal by the limit uses no attempt, the delivery stays due) or the channel is set to `none`.
- **No automatic attempt remains**: three attempts failed. Read the error, fix the cause (shop mail settings under Advanced Parameters > E-mail, the customer's address) and use **Send again**.
- **No automatic attempt will be made**, with the error "A delivery attempt started and its outcome was not recorded. Check whether the customer received the document before sending it again.": the cron saved the attempt and the process stopped, or the result could not be saved, before the outcome was recorded. The e-mail may or may not have left, also when the run stopped before anything was sent, so the cron never tries it again. Check with the customer or the mail log, then use **Send again** if the document did not arrive.
- **No automatic attempt will be made** with any other error: the failure is permanent (no valid e-mail address of the customer, the document is missing in iFirma, the API key is missing or was rejected) or its outcome is unknown (with the channel `ifirma_mail`, the request to iFirma's mail service got no answer, so the e-mail may have been sent). Check with the customer or in iFirma, then use **Send again** if needed.
- **No automatic attempt is scheduled**: the delivery failed before the module version 1.10.0, which introduced the retry. Old failures are not retried; use **Send again** (or the bulk **Send again** of the documents list, filtered by delivery Failed).

**Why not retry everything:** a retry that could e-mail the same document twice is never made; that is why a failure with an unknown outcome waits for an employee. The one case the module cannot rule out: when the shop's mail system reports a failure although the mail server had already accepted the message (a lost SMTP answer), the retry sends the document again.

### Guest did not receive the code

**Meaning:** the guest request page answers "If the data matches an order, we have sent a code" whether or not a code was sent, so a missing e-mail has one of these causes: the reference or the e-mail did not match the order exactly (the e-mail is compared ignoring case, the reference as typed), the order already has an issued or adopted document, the data-change switch is off, or a limit refused the request (one code per order per 60 seconds, 5 codes per order per 24 hours, 20 codes per client address per hour). A code is valid for 15 minutes and is invalidated after 5 wrong attempts; the page then offers a new one.

**What to do on a production shop:** check the shop's mail method under Advanced Parameters > E-mail and the Logs for lines starting with "iFirma guest code" (they name the order id, never the address). When a limit was hit, wait for its window to pass; the customer can also log in or create an account and use the account form, which needs no code.

**On the test stack:** the code mail lands in the mock's SMTP sink (`GET /__mock/mail`, text part); the smoke scenario O reads it from there.

**Reporting a problem.** Copy the diagnostics block (request, response, contractor step, last error) and the
relevant lines from Advanced Parameters > Logs (messages start with "iFirma"). Neither contains API keys.
