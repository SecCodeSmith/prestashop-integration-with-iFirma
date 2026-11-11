# ifirma-api-compliance Specification

## Purpose
Define the constraints the module honours whenever it talks to the iFirma API: request signing, payload field limits, error-code mapping, rate limiting and request accounting.

## Requirements

### Requirement: Request signing
Every request SHALL carry `Authentication: IAPIS user=<login>, hmac-sha1=<hex>` where the hash is HMAC-SHA1 over url-without-query-string + login + key name + request body, keyed with the hex-decoded API key; a key that is not an even-length hexadecimal string SHALL be refused locally with a clear error.

#### Scenario: Valid key
- **WHEN** a request is sent with a valid hexadecimal key
- **THEN** the header carries the lowercase hex HMAC-SHA1 of the specified string

#### Scenario: Query string excluded
- **WHEN** a request URL includes a query string
- **THEN** the query string is not part of the signed text

#### Scenario: Invalid key
- **WHEN** the key has odd length or non-hex characters
- **THEN** no request is sent
- **AND** a clear error about the key format is reported

### Requirement: Deterministic contractor identifier
`Kontrahent.Identyfikator` SHALL be `PS-C{id_customer}`, never longer than 15 characters, and SHALL only be sent when that contractor exists in iFirma (registered by the module first); otherwise the identifier SHALL be null so iFirma creates the contractor from the document.

#### Scenario: Registered customer
- **WHEN** a document is created for customer 12345
- **THEN** `Kontrahent.Identyfikator` is `PS-C12345`

#### Scenario: Guest checkout
- **WHEN** the order belongs to a guest customer record
- **THEN** the identifier is built the same way from that customer id

#### Scenario: Excessive length
- **WHEN** the generated identifier would exceed 15 characters
- **THEN** it is cut to 15 characters

### Requirement: Payload length limits
Before sending, the module SHALL truncate fields to iFirma maxima (contractor name 150, e-mail 65, NIP 13, street 65, city 65, postcode 16, `Uwagi` 1000, line name 500) and SHALL never send a value exceeding them.

#### Scenario: Long contractor name
- **WHEN** the buyer name is 200 characters
- **THEN** the sent contractor name is 150 characters

#### Scenario: Long line name
- **WHEN** a product name exceeds 500 characters
- **THEN** the sent line name is 500 characters

#### Scenario: Multibyte text
- **WHEN** a truncated value contains Polish diacritics
- **THEN** truncation counts characters and never splits a character

#### Scenario: Value within limits
- **WHEN** a field is within its limit
- **THEN** it is sent unchanged

### Requirement: List calls always bounded
Every call to `faktury.json` SHALL include both `dataOd` and `dataDo` and an explicit page size.

#### Scenario: Guard lookup
- **WHEN** the duplicate guard lists documents
- **THEN** the request has `dataOd`, `dataDo` and `iloscNaStronie`

#### Scenario: Number lookup
- **WHEN** the module lists documents to find a document number
- **THEN** both dates and the page size are present

### Requirement: Error-code mapping
A non-zero `Kod` (100, 101, 102, 200, 201, 202, 400, 401, 402, 403, 499, 500) SHALL be translated into an actionable message naming the probable cause and kept together with the `Informacja` text from iFirma; HTTP status 400 or higher without a JSON body SHALL be reported as an HTTP error.

#### Scenario: Invalid hash
- **WHEN** iFirma answers `Kod` 403
- **THEN** the message says the hash is invalid and the API key is probably wrong
- **AND** the `Informacja` text is included

#### Scenario: Invalid user
- **WHEN** iFirma answers `Kod` 401
- **THEN** the message says the user is invalid

#### Scenario: Terms not accepted and permissions
- **WHEN** iFirma answers `Kod` 499 or 500
- **THEN** the message names unaccepted terms for 499 and insufficient permissions for 500

#### Scenario: HTTP error without body
- **WHEN** the response status is 502 with no JSON body
- **THEN** the error is reported as an HTTP error with that status

### Requirement: Non-PDF body on PDF endpoint
When a `.pdf` endpoint returns something other than a PDF, the module SHALL treat it as an error carrying the JSON `Informacja` if present and SHALL never stream it to a browser as a PDF.

#### Scenario: JSON error body
- **WHEN** a PDF endpoint returns JSON with `Informacja`
- **THEN** the error message contains that text
- **AND** the customer or admin download is refused

#### Scenario: Unparseable body
- **WHEN** a PDF endpoint returns a body that is neither a PDF nor JSON
- **THEN** a generic error is reported and nothing is streamed

### Requirement: Local rate guard
The module SHALL count its own requests per calendar minute and per calendar day (shop timezone) and SHALL refuse to start a request when either count has reached its configured cap: the minute cap `IFIRMA_RATE_MAX_PER_MIN` (default 80, allowed 1 to 100, below the iFirma limit of 100 per minute) and the day cap `IFIRMA_RATE_MAX_PER_DAY` (default 14000, allowed 1 to 15000, below the iFirma limit of 15000 per day). A refusal SHALL be a distinct transient "rate limited" error that names the window that refused and that callers do not persist as a document error state. Both counters SHALL be incremented atomically, so concurrent requests cannot lose increments and exceed a cap.

#### Scenario: Under the cap
- **WHEN** fewer requests than the minute cap were made this minute and fewer than the day cap today
- **THEN** the request is sent and both counters increment

#### Scenario: Cap reached
- **WHEN** the cap is reached within the current minute
- **THEN** the request is not sent
- **AND** a rate limited error naming the minute window is returned

#### Scenario: Day cap reached
- **WHEN** the day cap is reached today and the minute count is below its cap
- **THEN** the request is not sent
- **AND** a rate limited error naming the day window is returned

#### Scenario: New minute
- **WHEN** the calendar minute changes
- **THEN** the minute counter restarts from zero

#### Scenario: New day
- **WHEN** the calendar day changes
- **THEN** the day counter restarts from zero

#### Scenario: Transient error not persisted
- **WHEN** a pipeline step hits the rate limited error
- **THEN** the receipt record is not set to an error status

#### Scenario: Concurrent requests
- **WHEN** two requests pass the guard at the same moment
- **THEN** the counter ends two higher than before

#### Scenario: Day cap setting
- **WHEN** the module is installed, or a shop running 1.6.0 is upgraded
- **THEN** `IFIRMA_RATE_MAX_PER_DAY` is 14000, and saving 0 or 15001 on the settings page is refused

### Requirement: Request timeouts
The default request timeout SHALL be 30 seconds, and the opportunistic customer-page sync SHALL use at most 5 seconds.

#### Scenario: Default call
- **WHEN** the module makes an ordinary API call
- **THEN** the timeout is 30 seconds

#### Scenario: Customer page sync
- **WHEN** the customer order page triggers a sync
- **THEN** the timeout is at most 5 seconds
- **AND** a timeout is not shown to the customer

### Requirement: Optional limit visibility
When the optional `abonent` key is configured, the module configuration page SHALL be able to display the used and granted daily API limit from `abonent/limit.json`; when it is not configured, the feature SHALL be hidden without error.

#### Scenario: Key configured
- **WHEN** the admin opens the configuration page with the `abonent` key set
- **THEN** used and granted daily limits are displayed

#### Scenario: Key not configured
- **WHEN** the `abonent` key is empty
- **THEN** no limit information is shown and no request or error occurs

#### Scenario: Limit request fails
- **WHEN** the limit request fails
- **THEN** the page still renders with a short notice that the limit is unavailable

### Requirement: Contractor registration before issuance
Before creating a document the module SHALL read the contractor `PS-C{id_customer}` from iFirma; when it is missing the module SHALL create it with the order's billing data, when its shop-owned fields (name, NIP, address, country, e-mail, person flag) differ it SHALL update it while preserving fields the merchant maintains in iFirma, and when the contractor API fails it SHALL fall back to a null identifier.

#### Scenario: First document for a customer
- **WHEN** iFirma has no contractor `PS-C7` and a document is created for customer 7
- **THEN** the module creates the contractor, then creates the document with `Kontrahent.Identyfikator` = `PS-C7`

#### Scenario: Billing data changed since the last document
- **WHEN** contractor `PS-C7` exists with a different street than the order invoice address
- **THEN** the module updates the contractor with the full record (shop-owned fields replaced, merchant fields such as bank account kept) before creating the document

#### Scenario: Unchanged contractor
- **WHEN** contractor `PS-C7` exists with the same billing data
- **THEN** no update call is made

#### Scenario: Contractor API unavailable
- **WHEN** the contractor lookup or creation fails
- **THEN** the document is still created with a null identifier and the fallback is recorded with the request payload

#### Scenario: Identifier rejected by iFirma
- **WHEN** iFirma answers `Kod` 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze"
- **THEN** the module retries the creation once with a null identifier and records the fallback

#### Scenario: Contractor sync disabled
- **WHEN** the merchant disables contractor registration
- **THEN** no contractor call is made and every document is created with a null identifier

### Requirement: Country fields on the buyer
The buyer block SHALL carry the country name in `Kraj` and the ISO 3166-1 alpha-2 code in `KodKraju`.

#### Scenario: Polish address
- **WHEN** the invoice address country is Poland
- **THEN** the buyer block has `Kraj` = the country name in the order language and `KodKraju` = `PL`

### Requirement: Existing contractor matching
Before creating a new contractor the module SHALL try to link the customer to a contractor that already exists in iFirma, in this order: a stored customer-to-contractor mapping, the module identifier `PS-C{id_customer}`, an exact NIP match from the contractor search, then an exact normalised name match confirmed by the same e-mail or the same postcode; a successful link SHALL be stored and reused for later orders, and an ambiguous or absent match SHALL create the module contractor.

#### Scenario: Stored mapping wins
- **WHEN** customer 7 is mapped to contractor `KOWALSKI-01` and a document is created for an order of customer 7
- **THEN** the document is created with `Kontrahent.Identyfikator` = `KOWALSKI-01` and no search is made

#### Scenario: Business customer matched by NIP
- **WHEN** no mapping exists, `PS-C7` is unknown, and the contractor search for the order NIP returns one contractor with exactly that NIP
- **THEN** that contractor is used and stored as the mapping for customer 7 with match type `nip`

#### Scenario: Private person matched by name and e-mail
- **WHEN** the search by name returns a contractor whose normalised name equals the buyer name and whose e-mail equals the customer e-mail
- **THEN** that contractor is used and stored with match type `name`

#### Scenario: Ambiguous name match
- **WHEN** the search by name returns two contractors with the same name and neither e-mail nor postcode confirms one of them
- **THEN** no link is made and the module contractor `PS-C7` is created

#### Scenario: Mapped contractor disappeared
- **WHEN** the mapped contractor no longer exists in iFirma
- **THEN** the mapping is discarded and resolution continues with the remaining steps

### Requirement: Contractor import from a CSV export
The configuration page SHALL accept an iFirma contractor export (CSV, `;` or `,` separated, UTF-8 or Windows-1250) and link its rows to shop customers offline by NIP, then e-mail, then normalised name plus postcode, storing one mapping per customer, reporting counts per match type and the number of unmatched rows, and never contacting the iFirma API during the import.

#### Scenario: Import links customers
- **WHEN** the merchant uploads an export with 3 rows whose NIPs match 2 customer addresses and whose e-mail matches 1 further customer
- **THEN** 3 mappings are stored with match types `nip`, `nip`, `email` and the page reports 3 linked, 0 unmatched

#### Scenario: Re-import is idempotent
- **WHEN** the same file is uploaded again
- **THEN** the mappings are unchanged and the counts are reported again without duplicates

#### Scenario: Unusable file
- **WHEN** the file has no `Identyfikator` column
- **THEN** the import is refused with a message naming the missing column and nothing is stored

#### Scenario: Mapping visible per order
- **WHEN** an admin opens an order whose customer is mapped
- **THEN** the panel shows the linked contractor identifier and how it was matched

### Requirement: Contractor reference on documents
When issuing a document the module SHALL reference the resolved contractor through the top-level `IdentyfikatorKontrahenta` field, SHALL send `NIPKontrahenta` with the buyer's NIP when it is a 10-digit Polish NIP, and SHALL NOT send `Kontrahent.Identyfikator`; when no contractor was resolved both top-level fields are omitted so iFirma matches by NIP or creates the contractor from the `Kontrahent` block.

#### Scenario: Resolved contractor is referenced at the top level
- **WHEN** the contractor step resolved `KOWALSKI-01` for the order
- **THEN** the request carries `IdentyfikatorKontrahenta` = `KOWALSKI-01` and the `Kontrahent` block has no `Identyfikator`

#### Scenario: Business buyer NIP
- **WHEN** the invoice address carries NIP `123-456-78-90`
- **THEN** the request carries `NIPKontrahenta` = `1234567890`; for a private person the field is absent

### Requirement: Kod 201 retry for unknown contractor reference
A Kod 201 response whose message says the contractor was not found or names `IdentyfikatorKontrahenta` SHALL trigger exactly one retry without `IdentyfikatorKontrahenta`.

#### Scenario: Unknown reference is retried once
- **WHEN** iFirma answers Kod 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze"
- **THEN** the module re-sends once without `IdentyfikatorKontrahenta`, stores the fallback note, and reports a second Kod 201 as an error

### Requirement: Contractor attached by iFirma is learned
After a successful issuance with no referenced identifier, or with one longer than 15 characters, the module SHALL read the document back once, record the contractor identifier iFirma attached in the stored request note, and store it as the customer's mapping (match type `document`) when it differs from the referenced one.

#### Scenario: Contractor attached by iFirma is learned
- **WHEN** the document was issued without a referenced identifier and its detail shows `Kontrahent.Identyfikator` = `AUTO-77`
- **THEN** the customer is mapped to `AUTO-77` with match type `document` and the order panel shows it

#### Scenario: Long identifiers
- **WHEN** the mapped identifier has 16 characters
- **THEN** it is sent as-is, the document is read back after issuance, and a different attached identifier replaces the mapping

### Requirement: Documented enumerations only
Every enumerated document field the module sends (`LiczOd`, `FormatDatySprzedazy`, `SposobZaplaty`, `RodzajPodpisuOdbiorcy`, `TypStawkiVat`) SHALL carry only a value listed in the fakturakraj/fakturaparagon documentation. `RodzajPodpisuOdbiorcy` SHALL come from the configuration setting `IFIRMA_SIGNATURE_TYPE` (OUP, UPO, BPO, BWO) and SHALL default to `BPO`; any other configured value SHALL be normalised to `BPO` when saved and when the payload is built.

#### Scenario: Default signature type
- **WHEN** the setting was never saved
- **THEN** the request carries `RodzajPodpisuOdbiorcy` = `BPO`

#### Scenario: Merchant choice
- **WHEN** the merchant selects BWO
- **THEN** the request carries `RodzajPodpisuOdbiorcy` = `BWO`

#### Scenario: Invalid value never reaches iFirma
- **WHEN** the stored value is `BRK` or empty
- **THEN** the request carries `BPO` and no Kod 200 mapping error occurs

### Requirement: Position VAT rates
Every position SHALL be sent with `TypStawkiVat` = `PRC` and a `StawkaVat` from the documented set (0, 0.05, 0.08, 0.23). The rate SHALL come from the order line's stored tax rate when present, otherwise from gross/net, and SHALL be snapped to the nearest documented rate when within half a percentage point; a rate further away SHALL be sent unchanged so iFirma reports it.

#### Scenario: Rounding noise
- **WHEN** gross/net of a line implies 7.99%
- **THEN** the position carries `StawkaVat` 0.08 and `TypStawkiVat` `PRC`

#### Scenario: Zero-rated line
- **WHEN** a line or the shipping carries no tax
- **THEN** the position carries `StawkaVat` 0 and `TypStawkiVat` `PRC` (never `ZW`, which would require `PKWiU`)

### Requirement: Kod 200 retry without DodatkoweUwagi
When the create call answers Kod 200 while the request contained `DodatkoweUwagi`, the module SHALL retry exactly once without that object; a Kod 200 for a request without `DodatkoweUwagi` SHALL be reported as an error.

#### Scenario: Optional object dropped once
- **WHEN** the first create answers Kod 200 and the request carried `DodatkoweUwagi`
- **THEN** the second request omits `DodatkoweUwagi` and its Kod 0 answer issues the document

### Requirement: Created contractor read-back
After iFirma confirms a contractor create (`Kod` 0), the module SHALL locate the stored contractor before referencing it: by the sent identifier and the identifier reported in the answer, once more after a 2-second wait, then through the contractor search with the pre-create matching rules. The first hit's identifier SHALL be referenced on the document and stored as the customer's mapping; a document without reference SHALL be issued only when every step fails.

#### Scenario: Sent identifier confirmed at once
- **WHEN** the create answers `Kod` 0 and the read-back of `PS-C7` returns the contractor
- **THEN** the document references `PS-C7`, customer 7 is mapped to `PS-C7` with match type `module`
- **AND** no wait and no search request are made

#### Scenario: Reported identifier differs from the sent one
- **WHEN** the create answers `Kod` 0 with `Wynik` `124451232`, the read-back of `PS-C7` answers not found and the read-back of `124451232` returns the contractor
- **THEN** the document references `124451232` and customer 7 is mapped to it with match type `module`

#### Scenario: Single-object read-back answer
- **WHEN** a read-back answers `Kod` 0 with `Wynik` as one object carrying `Identyfikator` instead of a one-element list
- **THEN** the contractor counts as found

#### Scenario: Contractor visible after a short wait
- **WHEN** both immediate read-backs answer not found and the read-back repeated after the wait returns the contractor under `PS-C7`
- **THEN** the document references `PS-C7` and the mapping is stored
- **AND** exactly one wait of 2 seconds happened and no search request was made

#### Scenario: Business contractor stored under an identifier iFirma assigned
- **WHEN** every read-back by identifier answers not found and the search for the buyer NIP returns exactly one contractor with that NIP whose identifier is `IFI1909556979`
- **THEN** the document references `IFI1909556979`, customer 7 is mapped to it with match type `module`
- **AND** the contractor step is recorded as `created` with a note naming `IFI1909556979` as the identifier iFirma assigned

#### Scenario: Private person stored under an identifier iFirma assigned
- **WHEN** every read-back by identifier answers not found and the search by the buyer name returns one contractor with that name and the customer e-mail
- **THEN** that contractor's identifier is referenced and mapped with match type `module`

#### Scenario: Search is ambiguous
- **WHEN** every read-back by identifier answers not found and the NIP search returns two contractors with that NIP that neither the name nor the postcode tells apart
- **THEN** the document is created without a contractor reference and the fallback is recorded
- **AND** no mapping is stored

#### Scenario: Nothing can be found
- **WHEN** every read-back by identifier answers not found and the search returns no matching contractor
- **THEN** the document is created without a contractor reference, the fallback is recorded
- **AND** the contractor iFirma attaches to the issued document is learned as mapping with match type `document`

#### Scenario: Read-back fails at transport level
- **WHEN** a read-back by identifier gets no response or a transport error
- **THEN** the sequence continues with the wait, the repeated read-back and the search before falling back
- **AND** the transport error is part of the recorded note when the fallback is reached

#### Scenario: Create rejected
- **WHEN** the create answers a non-zero `Kod`
- **THEN** no read-back and no search are made and the fallback is recorded with iFirma's message

### Requirement: Contractor step notes
The contractor step note stored with the request SHALL be rendered in the back-office language. A fallback note SHALL name the stage that failed and, after a confirmed create, each identifier tried with iFirma's answer for it (`Kod` and `Informacja`, "empty result", or the transport error). A created note SHALL name the identifier iFirma assigned when it differs from the sent one. The order panel SHALL show the note next to the step label.

#### Scenario: Fallback after a confirmed create
- **WHEN** the create was confirmed, `PS-C1909` and the reported `PS-C1909` were read back with `Kod` 208 "Nie znaleziono kontrahenta" and the search found nothing
- **THEN** the Polish order panel shows the contractor step as *tryb awaryjny* followed by a Polish note that names `PS-C1909` with "Kod 208: Nie znaleziono kontrahenta" and states that the search found nothing
- **AND** no English sentence appears in the note

#### Scenario: Created under an assigned identifier
- **WHEN** the contractor was found through the search under `IFI1909556979` after being sent as `PS-C1909`
- **THEN** the order panel shows the step as created with a note naming `IFI1909556979` as the identifier assigned by iFirma

#### Scenario: Created under the sent identifier
- **WHEN** the contractor was read back under the sent identifier
- **THEN** the order panel shows the step as created without any note

#### Scenario: Attached contractor still shown
- **WHEN** a document issued in fallback mode has an attached contractor learned from its detail
- **THEN** the panel still appends the attached-contractor sentence after the fallback note

#### Scenario: Fallback before the create
- **WHEN** the read-back of a stored mapping or of the module identifier fails at transport level before any create
- **THEN** the fallback note names the lookup stage and the transport error

### Requirement: Authentication failure starts a cool-down
When iFirma answers HTTP 401 or 403, or a `Kod` of 400, 401, 402, 403 or 499, the module SHALL record the time and message of the failure and SHALL send no further request to iFirma for 15 minutes. `Kod` 500 (insufficient permissions, endpoint-specific) SHALL NOT start a cool-down.

#### Scenario: Bare HTTP 403
- **WHEN** the PDF download for a delivery is answered with HTTP 403 and no `Kod` body
- **THEN** exactly one request is made, no wait happens and the cool-down starts

#### Scenario: Kod 500 does not pause
- **WHEN** the daily-limit call is answered with `Kod` 500
- **THEN** no cool-down starts and the next document request is sent

#### Scenario: Window over
- **WHEN** 15 minutes have passed since the failure
- **THEN** the next call is sent to iFirma

### Requirement: Calls inside the cool-down fail fast
A call attempted inside the cool-down window SHALL fail at once without an HTTP request, with a message naming the credential failure and its time; it SHALL never be retried with waits; a receipt record affected by it SHALL hold that message as its last error in state `error`, never `unknown`; and the cron run SHALL do nothing but report the cool-down.

#### Scenario: Second call blocked
- **WHEN** a create request is answered with `Kod` 401 and another document is triggered two minutes later
- **THEN** no HTTP request is made for the second document and its record holds the credential-failure message in state `error`

#### Scenario: Cron during the cool-down
- **WHEN** the cron endpoint is called inside the window
- **THEN** it makes no iFirma request, changes no record and its summary names the cool-down

### Requirement: Cool-down shown and cleared on the settings page
The configuration page SHALL show a banner naming the credential failure and its time while the cool-down is active, and saving the credentials section SHALL clear the cool-down.

#### Scenario: Banner shown
- **WHEN** the administrator opens the configuration page inside the window
- **THEN** a banner names the failure message and its time

#### Scenario: Save clears
- **WHEN** the administrator saves the configuration form inside the window
- **THEN** the cool-down ends and the next call is sent to iFirma

### Requirement: Base URL validation
The API base URL SHALL be saved only as an absolute URL with the `https` scheme and a host, without user information; `http` SHALL be accepted only when the shop runs in developer mode or the host is a loopback, private-range or single-label name; any other value SHALL be refused with a message naming the field and nothing SHALL be saved.

#### Scenario: Public https accepted
- **WHEN** `https://www.ifirma.pl/iapi/` is posted
- **THEN** it is saved

#### Scenario: Public http refused
- **WHEN** `http://www.ifirma.pl/iapi/` is posted on a shop that is not in developer mode
- **THEN** the page shows a message naming the base URL and no setting is changed

#### Scenario: Internal mock accepted
- **WHEN** `http://ifirma-mock:8001/iapi/` is posted
- **THEN** it is saved

#### Scenario: Other scheme refused
- **WHEN** `file:///etc/passwd` or `gopher://host/` is posted
- **THEN** the page shows a message naming the base URL and no setting is changed

### Requirement: Transport restrictions
The HTTP transport SHALL allow only the HTTP and HTTPS protocols, SHALL NOT follow redirects, and SHALL abort a response larger than 20 MB with a transport error.

#### Scenario: Redirect not followed
- **WHEN** iFirma answers a request with HTTP 302 to another host
- **THEN** the redirect is not followed and the call is treated as an HTTP error status

#### Scenario: Oversized body
- **WHEN** a PDF response exceeds 20 MB
- **THEN** the call ends with a transport error and no PDF is delivered

### Requirement: Document print template
When the configured document template name is not empty, every `fakturakraj` and `fakturaparagon` create request SHALL carry it as `NazwaSzablonu`; when it is empty the field SHALL be omitted so iFirma uses the account's default template. The stored request payload SHALL show the field as sent.

#### Scenario: Template configured
- **WHEN** the template name is `logo` and a document is created
- **THEN** the create request contains `"NazwaSzablonu": "logo"` and the stored request payload shows it

#### Scenario: Template empty
- **WHEN** the template name setting is empty and a document is created
- **THEN** the create request has no `NazwaSzablonu` key

#### Scenario: Retries keep the template
- **WHEN** the create is retried without `DodatkoweUwagi` or without `IdentyfikatorKontrahenta`
- **THEN** the retried request still carries the same `NazwaSzablonu`

### Requirement: Document template setting
The configuration page SHALL offer a "Document template in iFirma" text setting, empty by default, trimmed on save, at most 100 characters and without control characters; a longer value or one with control characters SHALL be refused with a message naming the field and nothing SHALL be saved. The setting SHALL be written with its default on install and upgrade and removed on uninstall. Its help text SHALL say that the template, including the logo it prints, is defined in the iFirma account.

#### Scenario: Default after install
- **WHEN** the module is installed or upgraded
- **THEN** the template setting exists and is empty

#### Scenario: Valid name saved
- **WHEN** the admin saves ` logo ` with surrounding spaces
- **THEN** `logo` is stored

#### Scenario: Over-long name refused
- **WHEN** the admin saves a 101-character template name
- **THEN** the page shows an error naming the template field and no setting is changed

#### Scenario: Uninstall
- **WHEN** the module is uninstalled
- **THEN** the template setting no longer exists

### Requirement: Reading the current limit from iFirma
With the `abonent` key set, the module SHALL read the day limit from iFirma at the start of a cron run, keep the reading for ten minutes, raise its local day counter to the number of requests iFirma reports as used (never lower it), and use the granted limit as a ceiling for the day cap. Without the key, or when iFirma gives no usable answer, the module SHALL change nothing and SHALL NOT stop the other cron jobs.

#### Scenario: Other clients used the account
- **GIVEN** the local day counter reads 300 and iFirma reports 1,200 used of 15,000
- **WHEN** the cron reads the limit
- **THEN** the day counter reads 1,200 and the cron answer's `limit` block says 13,800 remaining

#### Scenario: The module counted more than iFirma
- **GIVEN** the local day counter reads 1,500 and iFirma reports 1,200 used
- **WHEN** the cron reads the limit
- **THEN** the day counter still reads 1,500

#### Scenario: Granted limit below the configured cap
- **GIVEN** the day cap is 14,000 and iFirma reports 5,000 granted and 5,000 used today
- **WHEN** a request is about to start
- **THEN** the guard refuses it with "5000 requests today (cap 5000)"

#### Scenario: A fresh reading is reused
- **GIVEN** the limit was read less than ten minutes ago, on the same day
- **WHEN** the cron runs again
- **THEN** iFirma is not asked and the earlier reading is reported

#### Scenario: iFirma does not answer
- **GIVEN** the limit cannot be read (no key, an error, or an unusable answer)
- **WHEN** the cron runs
- **THEN** the counters and the earlier reading are unchanged, the `limit` block holds nulls and the queue, KSeF, sync and delivery jobs still run

### Requirement: iFirma limit answer starts a back-off
An answer with HTTP 429, or an answer whose `Informacja` says the request limit was exceeded ("limit zapytań"), SHALL be treated as a "rate limited" error, not as an unknown outcome or a document error. It SHALL start a back-off until the time given by a `Retry-After` header (seconds, at most 24 hours), or until the start of the next minute when the header is missing or invalid. During the back-off the module SHALL send no request.

#### Scenario: HTTP 429 with Retry-After
- **WHEN** iFirma answers a request with HTTP 429 and `Retry-After: 20`
- **THEN** the request is reported as rate limited
- **AND** no further request is sent during the next 20 seconds

#### Scenario: Limit message without HTTP 429
- **WHEN** iFirma answers with HTTP 200 and `Kod` 100 with `Informacja` "Przekroczono limit zapytań"
- **THEN** the request is reported as rate limited, not as a technical error

#### Scenario: No Retry-After
- **WHEN** iFirma answers with HTTP 429 and no `Retry-After` header at 10:15:40
- **THEN** no request is sent before 10:16:00

#### Scenario: Excessive Retry-After
- **WHEN** iFirma answers with HTTP 429 and `Retry-After: 999999`
- **THEN** the back-off ends after 24 hours

#### Scenario: Back-off refusal is local
- **WHEN** a request is started during the back-off
- **THEN** it is refused before any network call with a rate limited error that names the back-off end time

#### Scenario: Other error codes unchanged
- **WHEN** iFirma answers with `Kod` 100 and an `Informacja` that does not mention the request limit
- **THEN** the answer is handled as the technical problem it was before this change
