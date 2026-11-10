# customer-receipt-visibility Specification

## Purpose
Defines what a logged-in customer sees about the accounting document for an order, on the order detail page and in the account block, at every stage of its lifecycle, without exposing internal errors.

## Requirements

### Requirement: Lifecycle block always present
When the module is configured, the order detail page SHALL show an accounting-document block for every order of the logged-in customer, and the block SHALL render exactly one state: not issued, held, verifying, issued, or missing.

#### Scenario: Order without any document
- **WHEN** a customer opens the detail page of an order that has no document and no pending request
- **THEN** the block is shown in the "not issued" state and no other state is rendered

#### Scenario: Module not configured
- **WHEN** the module has no API credentials configured and the customer opens an order detail page
- **THEN** no accounting-document block is shown

#### Scenario: Only one state at a time
- **WHEN** an order has an issued document
- **THEN** the block shows only the issued state and none of the not issued, held, verifying or missing wording

### Requirement: Not-issued state
In the not-issued state the block SHALL state that no document has been issued yet, name the order status that triggers issuance when automatic issuance is enabled, and offer the "request a change to billing data" action when the data-change feature is enabled and the customer is eligible. An order queued for issuance SHALL render this state.

#### Scenario: Automatic issuance enabled
- **WHEN** automatic issuance is enabled for the status "Payment accepted" and the order has no document
- **THEN** the block says no document has been issued yet and that it will be issued when the order reaches "Payment accepted"

#### Scenario: Automatic issuance disabled
- **WHEN** automatic issuance is disabled and the order has no document
- **THEN** the block says no document has been issued yet and names no trigger status

#### Scenario: Data-change action offered
- **WHEN** the data-change feature is enabled and the customer is eligible to submit a request for the order
- **THEN** the block offers the "request a change to billing data" action

#### Scenario: Data-change feature disabled
- **WHEN** the data-change feature switch (IFIRMA_ALLOW_DATA_REQUEST) is off
- **THEN** the block does not offer the "request a change to billing data" action

#### Scenario: Queued order
- **WHEN** the customer opens an order whose record is queued for issuance
- **THEN** the block shows the not-issued state and no rate-limit message

### Requirement: Held state
When the order has a pending data-change request, the block SHALL state that the document is on hold pending the customer's request, show the request date, and offer to cancel the request.

#### Scenario: Pending request
- **WHEN** the customer opens an order that has a pending data-change request created on 2026-11-01
- **THEN** the block says the document is on hold pending the request, shows the date 2026-11-01 and offers a cancel action

#### Scenario: Request resolved
- **WHEN** the request has been approved, rejected or cancelled
- **THEN** the block no longer shows the held state

### Requirement: Verifying state with neutral wording
When the module could not conclude whether a document exists (suspected duplicate, unknown outcome, or inconclusive check), the block SHALL show neutral wording that the shop is verifying the document and SHALL NOT display internal error text, iFirma messages, or candidate documents.

#### Scenario: Suspected duplicate
- **WHEN** the order is flagged as a suspected duplicate with candidate documents
- **THEN** the block says the shop is verifying the document and lists no candidate number, amount or contractor

#### Scenario: Unknown outcome after a failed creation call
- **WHEN** the outcome of the last creation attempt is unknown and an error message is stored
- **THEN** the block shows the same neutral verifying wording and the stored error text does not appear in the page

#### Scenario: Inconclusive duplicate check
- **WHEN** the duplicate check could not complete because iFirma was unreachable
- **THEN** the block shows the verifying state

### Requirement: Issued state
In the issued state the block SHALL show the document number (or the iFirma id when the number is unknown), the issue date, a paid or unpaid indicator derived from the remote paid amount, whether and when the document was sent to the customer, and a download link; this SHALL apply equally to documents adopted from iFirma. A document awaiting KSeF acceptance SHALL show "not yet sent" and no download link.

#### Scenario: Paid and sent document
- **WHEN** the remote paid amount covers the gross total and the document was sent on 2026-11-02
- **THEN** the block shows the number, the issue date, a "paid" indicator, "sent on 2026-11-02" and a download link

#### Scenario: Unpaid and not yet sent
- **WHEN** the remote paid amount is lower than the gross total and the document has not been sent
- **THEN** the block shows an "unpaid" indicator and "not yet sent"

#### Scenario: Number unknown
- **WHEN** the document has an iFirma id but no stored number
- **THEN** the block shows the iFirma id in place of the number

#### Scenario: Adopted external document
- **WHEN** the document was adopted from iFirma rather than created by the module
- **THEN** the block renders the same fields and download link as for a module-created document

#### Scenario: Awaiting KSeF acceptance
- **WHEN** the document's delivery is awaiting KSeF acceptance
- **THEN** the block shows the number, the issue date and "not yet sent", and no download link

### Requirement: Missing state
When the document is flagged missing in iFirma, the block SHALL state that the document is no longer available and ask the customer to contact the shop, and SHALL NOT show a download link.

#### Scenario: Document removed in iFirma
- **WHEN** the document was found missing during synchronisation with iFirma
- **THEN** the block says the document is no longer available, asks the customer to contact the shop and shows no download link

### Requirement: Download access control
The PDF download SHALL require a logged-in customer, a valid per-document token and ownership of the document (matching customer id), and SHALL stream the PDF from iFirma without storing it. A document awaiting KSeF acceptance SHALL NOT be downloadable by the customer through any customer-facing path.

#### Scenario: Owner with valid token
- **WHEN** the logged-in owner requests the download with the correct token
- **THEN** the PDF is returned and no copy of it is stored by the shop

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

#### Scenario: After acceptance
- **WHEN** the same document has been accepted in KSeF and the owner repeats the request
- **THEN** the PDF is returned

### Requirement: Account block
The customer account page SHALL list the customer's issued documents (number, status, download link) and show how many of their orders currently have a pending data-change request, and SHALL hide the block when there is nothing to show.

#### Scenario: Documents and pending requests
- **WHEN** the customer has two issued documents and one order with a pending request
- **THEN** the account block lists both documents with number, status and download link and shows a pending-request count of 1

#### Scenario: Nothing to show
- **WHEN** the customer has no issued documents and no pending requests
- **THEN** the account block is not displayed

### Requirement: Customer-language rendering
All customer-facing text in the order detail block and the account block SHALL be translatable and SHALL be rendered in the customer's language.

#### Scenario: Customer with a non-default language
- **WHEN** a customer whose language has a translation opens the order detail page
- **THEN** every label and message of the block appears in that language

#### Scenario: Untranslated language
- **WHEN** no translation exists for the customer's language
- **THEN** the block falls back to the default language text and still shows no raw error details

### Requirement: Admin-only data stays admin-only
Candidate duplicate documents, last error text, request and response payloads, and synchronisation errors SHALL NOT appear in any customer-facing view.

#### Scenario: Receipt with stored error and sync error
- **WHEN** the receipt has a stored last error, a synchronisation error and candidate duplicates
- **THEN** the order detail page and the account block contain none of that text or data

#### Scenario: Page source inspection
- **WHEN** the rendered HTML of the customer views is inspected for an order with stored payloads
- **THEN** no request or response payload content is present

### Requirement: Download throttle
The customer PDF download SHALL fetch a given document from iFirma at most once per 30 seconds and at most ten documents per minute per customer; a request inside either window SHALL be answered with HTTP 429 and a "try again shortly" message and SHALL make no iFirma request.

#### Scenario: Repeated download of one document
- **WHEN** the owner downloads a document and requests it again 5 seconds later
- **THEN** the second response is HTTP 429 with the retry message and no iFirma request is made

#### Scenario: Window over
- **WHEN** the owner requests the document again after 30 seconds
- **THEN** the PDF is fetched and returned

#### Scenario: Loop over many documents
- **WHEN** a customer with 30 documents requests 15 different ones within a minute
- **THEN** the first ten are fetched and the others answer HTTP 429

### Requirement: Download token generation
The per-document download token SHALL come from the cryptographically secure random generator only; when that generator is unavailable the record SHALL be stored without a token, the customer download SHALL NOT be offered for it, and the failure SHALL be logged, instead of storing a weaker token.

#### Scenario: Generator available
- **WHEN** a document is created
- **THEN** its token is 40 hexadecimal characters from the secure generator

#### Scenario: Generator unavailable
- **WHEN** the secure generator throws while a document is created
- **THEN** the record is stored without a token, the customer pages show the document without a download link, and the log names the failure
