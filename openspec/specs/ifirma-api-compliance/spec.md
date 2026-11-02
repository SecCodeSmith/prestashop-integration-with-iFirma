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
