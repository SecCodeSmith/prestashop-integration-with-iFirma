# Spec Delta

## MODIFIED Requirements

### Requirement: Download access control
The PDF download SHALL require a logged-in customer, a valid per-document token and ownership of the document (matching customer id). After those checks it SHALL return the document's stored copy when a usable one exists (specified in `document-pdf-store`) and otherwise the PDF fetched from iFirma. A document awaiting KSeF acceptance SHALL NOT be downloadable by the customer through any customer-facing path.

#### Scenario: Owner with valid token
- **WHEN** the logged-in owner requests the download with the correct token
- **THEN** the PDF is returned

#### Scenario: Owner with valid token and a stored copy
- **GIVEN** a document with a usable stored copy
- **WHEN** the logged-in owner requests the download with the correct token
- **THEN** the stored copy is returned and no iFirma request is made

#### Scenario: Invalid token
- **WHEN** a logged-in customer requests the download with a wrong or missing token
- **THEN** the response is "not found" and no PDF is returned

#### Scenario: Another customer's document
- **WHEN** a logged-in customer requests the download of a document that belongs to a different customer
- **THEN** the response is "not found" and no PDF is returned

#### Scenario: Not logged in
- **WHEN** a visitor who is not logged in requests the download
- **THEN** the visitor is redirected to the login page and no PDF is returned

#### Scenario: Awaiting KSeF acceptance
- **WHEN** the logged-in owner requests the download of a document awaiting KSeF acceptance with the correct token
- **THEN** the response is "not found", no PDF is returned and no iFirma request is made
- **AND** a stored copy of that document is not returned

#### Scenario: After acceptance
- **WHEN** the same document has been accepted in KSeF and the owner repeats the request
- **THEN** the PDF is returned

### Requirement: Download throttle
The customer PDF download SHALL fetch a given document from iFirma at most once per 30 seconds and at most ten documents per minute per customer; a request inside either window that cannot be answered from a usable stored copy (specified in `document-pdf-store`) SHALL be answered with HTTP 429 and a "try again shortly" message and SHALL make no iFirma request. A download served from the stored copy SHALL NOT be refused by the throttle and SHALL NOT start or use a throttle window.

#### Scenario: Repeated download of one document
- **GIVEN** storing PDF copies is turned off
- **WHEN** the owner downloads a document and requests it again 5 seconds later
- **THEN** the second response is HTTP 429 with the retry message and no iFirma request is made

#### Scenario: Repeated download of a stored copy
- **GIVEN** storing PDF copies is on
- **WHEN** the owner downloads a document and requests it again 5 seconds later
- **THEN** the second response returns the stored copy and no iFirma request is made

#### Scenario: Window over
- **GIVEN** storing PDF copies is turned off
- **WHEN** the owner requests the document again after 30 seconds
- **THEN** the PDF is fetched and returned

#### Scenario: Loop over many documents
- **GIVEN** a customer with 30 documents, none with a usable stored copy
- **WHEN** the customer requests 15 different ones within a minute
- **THEN** the first ten are fetched and the others answer HTTP 429

#### Scenario: Stored copies are outside the per-customer window
- **GIVEN** a customer whose ten fetches of the current minute are used
- **WHEN** the customer requests a document that has a usable stored copy
- **THEN** the stored copy is returned

#### Scenario: A stored copy starts no window
- **GIVEN** the stored copy of a document was returned to its owner 5 seconds ago
- **AND** the document changed since, so the copy is no longer usable
- **WHEN** the owner requests the document again
- **THEN** the PDF is fetched from iFirma and returned, not refused by the throttle

### Requirement: A customer's downloads are capped per day
Each customer SHALL be allowed at most 30 PDF downloads fetched from iFirma per calendar day (shop timezone), counted over all their documents together. A further download that would need a request to iFirma SHALL be answered with HTTP 429, `Retry-After: 60` and the generic message of a refused download, SHALL send no request to iFirma and SHALL use neither the shared customer-download budget nor a throttle slot. The check SHALL come after every access check. Only a download that reached iFirma counts, and a new day starts a new count. A download served from the stored copy (specified in `document-pdf-store`) SHALL NOT count and SHALL NOT be refused by the cap.

#### Scenario: The 31st download is refused
- **GIVEN** a customer whose 30 downloads of today reached iFirma
- **WHEN** the customer requests a PDF of one of their documents that has no usable stored copy
- **THEN** the answer is HTTP 429 with `Retry-After: 60` and the generic "try again shortly" message
- **AND** no request is sent to iFirma and no PDF bytes are returned

#### Scenario: Stored copy at the cap
- **GIVEN** a customer whose 30 downloads of today reached iFirma
- **WHEN** the customer requests a PDF of a document that has a usable stored copy
- **THEN** the stored copy is returned and no request is sent to iFirma

#### Scenario: Stored copies do not count
- **GIVEN** a customer who was served stored copies 40 times today and sent no download to iFirma
- **WHEN** the customer requests a PDF of a document without a usable stored copy
- **THEN** the PDF is fetched from iFirma and today's count is 1

#### Scenario: Another customer is not affected
- **GIVEN** one customer has used their 30 downloads of today
- **WHEN** another customer requests a PDF of their own document
- **THEN** the PDF is returned

#### Scenario: One customer cannot use up the shared budget
- **GIVEN** one customer with 5 issued documents who downloads in a loop all day
- **WHEN** the day ends
- **THEN** at most 30 requests were sent to iFirma for that customer
- **AND** the downloads of other customers still work and the shared customer-download budget was used only by the 30

#### Scenario: Next day
- **GIVEN** a customer reached the cap yesterday
- **WHEN** the customer requests a PDF of a document without a usable stored copy today
- **THEN** the PDF is returned and today's count starts at 1

#### Scenario: Refused downloads do not count
- **WHEN** a download is refused by the cap, by the per-document or per-customer throttle, by the shared budget or by the request limit
- **THEN** the customer's count of the day does not change

#### Scenario: Access checks come first
- **GIVEN** a customer has used their 30 downloads of today
- **WHEN** that customer requests the PDF of an order that is not theirs
- **THEN** the answer is "not found" exactly as without the cap, and not 429

## ADDED Requirements

### Requirement: A stored copy uses no customer download limit
A customer download served from the stored copy (specified in `document-pdf-store`) SHALL NOT use the shared customer-download budget (20 per calendar minute and 2,000 per calendar day for all customers together), SHALL NOT count in the customer's day cap and SHALL NOT use a throttle slot. It SHALL be returned when any of those limits is used up. Every access check SHALL still come first, and a download that needs a request to iFirma SHALL obey every limit as before.

#### Scenario: Minute budget used up
- **GIVEN** the shared customer-download budget of the current minute is used up
- **WHEN** a customer requests a document that has a usable stored copy
- **THEN** the stored copy is returned and no request is sent to iFirma

#### Scenario: A served copy is not counted
- **WHEN** a customer download is served from the stored copy
- **THEN** the minute count and the day count of the customer-download budget, the customer's count of the day and the document's throttle window are unchanged

#### Scenario: No copy while the budget is used up
- **GIVEN** the shared customer-download budget of the current minute is used up
- **WHEN** a customer requests a document without a usable stored copy
- **THEN** the answer is HTTP 429 with `Retry-After: 60` and the generic "try again shortly" message, as before

#### Scenario: Access checks still come first
- **GIVEN** a document with a usable stored copy and the shared customer-download budget used up
- **WHEN** a logged-in customer who does not own the document requests the download
- **THEN** the answer is "not found" and no bytes of the copy are returned
