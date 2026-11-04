# Spec Delta

## Purpose

Define the constraints the module honours whenever it talks to the iFirma API: request signing, payload field limits, error-code mapping, rate limiting and request accounting.

## ADDED Requirements

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
The module SHALL count its own requests per calendar minute and SHALL refuse to start a request above the configured cap (default 80, below the iFirma limit of 100 per minute) with a distinct transient "rate limited" error that callers do not persist as a document error state.

#### Scenario: Under the cap
- **WHEN** fewer requests than the cap were made this minute
- **THEN** the request is sent and the counter increments

#### Scenario: Cap reached
- **WHEN** the cap is reached within the current minute
- **THEN** the request is not sent
- **AND** a rate limited error is returned

#### Scenario: New minute
- **WHEN** the calendar minute changes
- **THEN** the counter restarts from zero

#### Scenario: Transient error not persisted
- **WHEN** a pipeline step hits the rate limited error
- **THEN** the receipt record is not set to an error status

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
