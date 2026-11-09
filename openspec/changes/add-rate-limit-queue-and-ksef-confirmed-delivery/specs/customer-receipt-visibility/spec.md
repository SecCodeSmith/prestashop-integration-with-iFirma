# Spec Delta

## MODIFIED Requirements

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
