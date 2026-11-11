# Spec Delta

## MODIFIED Requirements

### Requirement: Issued state
In the issued state the block SHALL show the document number (or the iFirma id when the number is unknown), the issue date, a paid or unpaid indicator derived from the remote paid amount, and whether and when the document was sent to the customer; this SHALL apply equally to documents adopted from iFirma. It SHALL show a download link only when the document was sent to the customer (specified in "Download only after the document was sent"). A document that was not sent, including one awaiting KSeF acceptance, SHALL show "not yet sent" and no download link.

#### Scenario: Paid and sent document
- **WHEN** the remote paid amount covers the gross total and the document was sent on 2026-11-02
- **THEN** the block shows the number, the issue date, a "paid" indicator, "sent on 2026-11-02" and a download link

#### Scenario: Unpaid and not yet sent
- **WHEN** the remote paid amount is lower than the gross total and the document has not been sent
- **THEN** the block shows an "unpaid" indicator and "not yet sent", and no download link

#### Scenario: Number unknown
- **WHEN** the document has an iFirma id but no stored number
- **THEN** the block shows the iFirma id in place of the number

#### Scenario: Adopted external document
- **WHEN** the document was adopted from iFirma rather than created by the module, and iFirma reports it as sent
- **THEN** the block renders the same fields and download link as for a module-created document that was sent

#### Scenario: Adopted external document that was not sent
- **WHEN** the document was adopted from iFirma, its delivery was skipped and iFirma does not report it as sent
- **THEN** the block shows the number, the issue date and "not yet sent", and no download link

#### Scenario: Awaiting KSeF acceptance
- **WHEN** the document's delivery is awaiting KSeF acceptance
- **THEN** the block shows the number, the issue date and "not yet sent", and no download link

#### Scenario: Delivery failed
- **WHEN** the e-mail delivery of the document failed
- **THEN** the block shows "not yet sent" and no download link

#### Scenario: Marked as sent by an employee
- **WHEN** an employee marked the document as sent on 2026-11-05
- **THEN** the block shows "sent on 2026-11-05" and a download link

### Requirement: Account block
The customer account page SHALL list the customer's issued documents (number, status, and a download link for each document that was sent to the customer) and show how many of their orders currently have a pending data-change request, and SHALL hide the block when there is nothing to show.

#### Scenario: Documents and pending requests
- **WHEN** the customer has two issued documents that were sent and one order with a pending request
- **THEN** the account block lists both documents with number, status and download link and shows a pending-request count of 1

#### Scenario: Document that was not sent
- **WHEN** one of the customer's issued documents was not sent
- **THEN** the account block lists it with number and status and without a download link

#### Scenario: Nothing to show
- **WHEN** the customer has no issued documents and no pending requests
- **THEN** the account block is not displayed

## ADDED Requirements

### Requirement: What counts as sent to the customer
A document SHALL count as sent to the customer when it is not awaiting KSeF acceptance and either its delivery state is "sent" (delivered by the module through any channel, or marked as sent by an employee) or iFirma reports it as sent. The order page, the account page and the download SHALL use this one rule, on stored fields only.

#### Scenario: Delivered by the module
- **WHEN** a document's delivery state is "sent"
- **THEN** it counts as sent to the customer

#### Scenario: Reported as sent by iFirma
- **WHEN** a document's delivery by the module failed and iFirma reports it as sent
- **THEN** it counts as sent to the customer

#### Scenario: Awaiting KSeF acceptance
- **WHEN** a document awaits KSeF acceptance, whatever iFirma reports
- **THEN** it does not count as sent to the customer

#### Scenario: Failed, skipped or never delivered
- **WHEN** a document's delivery state is "failed", "skipped" or empty and iFirma does not report it as sent
- **THEN** it does not count as sent to the customer

### Requirement: Download only after the document was sent
The customer PDF download SHALL be refused with "not found" unless the document was sent to the customer. The check SHALL come with the other access checks, before every download limit. A refused download SHALL return no PDF bytes and SHALL send no request to iFirma.

#### Scenario: Delivered document
- **GIVEN** a document whose e-mail delivery succeeded
- **WHEN** its logged-in owner requests the download with the correct token
- **THEN** the PDF is returned

#### Scenario: Delivery failed
- **GIVEN** a document whose e-mail delivery failed
- **WHEN** its owner requests the download with the correct token
- **THEN** the answer is "not found", no PDF bytes are returned and no request is sent to iFirma

#### Scenario: Delivery turned off
- **GIVEN** a document issued while the send channel was "none", so its delivery was skipped
- **WHEN** its owner requests the download
- **THEN** the answer is "not found"

#### Scenario: Older document that was never e-mailed
- **GIVEN** a document issued before deliveries were recorded, with no delivery state
- **WHEN** its owner requests the download
- **THEN** the answer is "not found"

#### Scenario: Awaiting KSeF acceptance
- **GIVEN** a document awaiting KSeF acceptance that iFirma reports as sent
- **WHEN** its owner requests the download
- **THEN** the answer is "not found"

#### Scenario: After a successful Send again
- **GIVEN** a document whose delivery failed
- **WHEN** an employee sends it again successfully and the owner then requests the download
- **THEN** the PDF is returned

#### Scenario: Marked as sent
- **GIVEN** a document whose delivery was skipped
- **WHEN** an employee marks it as sent and the owner then requests the download
- **THEN** the PDF is returned

#### Scenario: Sent by iFirma
- **GIVEN** a document whose delivery by the module failed and that iFirma reports as sent
- **WHEN** its owner requests the download
- **THEN** the PDF is returned

#### Scenario: The refusal reveals nothing
- **WHEN** the download of a document that was not sent is refused
- **THEN** the answer is the same "not found" as for a wrong token

#### Scenario: Checked before the limits
- **GIVEN** a customer who has used their downloads of today
- **WHEN** that customer requests a document of theirs that was not sent
- **THEN** the answer is "not found", not HTTP 429, and no limit counts the request

#### Scenario: No bytes from a stored copy
- **GIVEN** a shop that keeps PDF copies and a document that was not sent but has a stored copy
- **WHEN** its owner requests the download
- **THEN** the answer is "not found" and no bytes of the copy are returned
