# Spec Delta

## Purpose

Keeps a copy of each document PDF on the shop server for a configurable number of days, so that repeated downloads send no request to iFirma. Defines when a copy is stored, when it is served, what makes it unusable and how it is replaced and deleted.

## ADDED Requirements

### Requirement: A copy is stored when the original PDF is fetched
The module SHALL store a copy of a document's original PDF each time it fetches that PDF from iFirma: for a customer download, a back-office download, a back-office refresh and a delivery through the shop mail system. It SHALL keep one copy per document and replace it with the newer one. A fetch that fails SHALL store nothing.

#### Scenario: Delivery e-mail stores the copy
- **GIVEN** an order whose document was just issued and has no copy
- **WHEN** the document is e-mailed through the shop mail system
- **THEN** a copy with the bytes of the attached PDF is stored for that document

#### Scenario: Customer download stores the copy
- **GIVEN** an issued document without a copy
- **WHEN** its owner downloads it
- **THEN** one request is sent to iFirma, the PDF is returned and a copy with the same bytes is stored

#### Scenario: A newer fetch replaces the copy
- **GIVEN** a document with a copy
- **WHEN** the module fetches the PDF again and iFirma returns different bytes
- **THEN** the document has exactly one copy and it holds the newer bytes

#### Scenario: Failed fetch
- **WHEN** a download is sent and iFirma answers with an error or with a body that is not a PDF
- **THEN** no copy is stored

### Requirement: Downloads are served from a usable copy
A customer download and a back-office download of the original PDF SHALL return the stored copy when it is usable and SHALL then send no request to iFirma. A copy is usable when storing is on, the copy is not older than the configured days, it still matches its document and its bytes are intact. Without a usable copy the PDF SHALL be fetched from iFirma as before.

#### Scenario: Second download of the owner
- **GIVEN** the owner downloaded a document an hour ago
- **WHEN** the owner downloads it again
- **THEN** the same bytes are returned and no request is sent to iFirma

#### Scenario: First download after the delivery e-mail
- **GIVEN** a document that was e-mailed through the shop mail system when it was issued
- **WHEN** its owner downloads it for the first time
- **THEN** the PDF is returned and no request is sent to iFirma

#### Scenario: Employee download
- **GIVEN** a document with a usable copy
- **WHEN** an employee with the view level downloads it in the back office
- **THEN** the copy is returned and no request is sent to iFirma

#### Scenario: Request limit reached
- **GIVEN** a document with a usable copy and the module's request limit reached, or a back-off or an authentication pause running
- **WHEN** the owner downloads the document
- **THEN** the PDF is returned

#### Scenario: iFirma does not answer
- **GIVEN** a document with a usable copy while iFirma is unreachable
- **WHEN** the owner downloads the document
- **THEN** the PDF is returned

#### Scenario: No usable copy
- **GIVEN** a document without a usable copy
- **WHEN** the download is sent and iFirma answers with an error
- **THEN** the requester gets the same answer as before this capability existed

### Requirement: A served copy uses no download limit
A download served from the stored copy SHALL NOT be counted in or refused by any download limit. The customer download throttle, the customer's day cap, the shared customer-download budget and the back-office download budget SHALL apply only to a download fetched from iFirma.

#### Scenario: Customer limits used up
- **GIVEN** a document with a usable copy, and its owner's day cap reached, the shared customer-download budget used up or the same document downloaded 5 seconds ago
- **WHEN** the owner downloads the document
- **THEN** the copy is returned and no request is sent to iFirma

#### Scenario: Back-office budget used up
- **GIVEN** a document with a usable copy and the back-office download budget of the current minute used up
- **WHEN** an employee with the view level downloads the document
- **THEN** the copy is returned and no request is sent to iFirma

#### Scenario: Limits still apply to a fetch
- **GIVEN** a document without a usable copy and its owner's day cap reached
- **WHEN** the owner downloads the document
- **THEN** the download is refused as before and no request is sent to iFirma

### Requirement: The delivery e-mail always fetches the current PDF
A delivery through the shop mail system SHALL fetch the PDF from iFirma even when a usable copy exists and SHALL replace the copy with what it fetched, so an e-mailed document is never older than the document in iFirma. The retry rules of the delivery SHALL stay as specified in `receipt-delivery`.

#### Scenario: Send again with a copy present
- **GIVEN** a document with a usable copy
- **WHEN** an employee sends the document again
- **THEN** one PDF request is sent to iFirma, the e-mail carries the fetched PDF and the copy holds the fetched bytes

#### Scenario: PDF not ready
- **GIVEN** a document without a copy whose PDF iFirma does not have ready
- **WHEN** the delivery runs
- **THEN** the download is retried under the existing rules and no copy is stored until a fetch succeeds

### Requirement: Copies are kept for a configurable number of days
The configuration page SHALL offer the setting "Keep PDF copies (days)", a whole number from 0 to 365, set to 30 on a new install and by an upgrade. A copy SHALL be usable for that many days counted from the moment it was stored; a download SHALL NOT extend that time. A change of the setting SHALL apply to the copies already stored.

#### Scenario: Inside the kept time
- **GIVEN** the setting is 30 and a copy was stored 29 days ago
- **WHEN** the owner downloads the document
- **THEN** the copy is returned and no request is sent to iFirma

#### Scenario: After the kept time
- **GIVEN** the setting is 30 and a copy was stored 31 days ago
- **WHEN** the owner downloads the document
- **THEN** the PDF is fetched from iFirma and the new copy is usable for 30 days from now

#### Scenario: Setting lowered
- **GIVEN** a copy stored 10 days ago and the setting changed from 30 to 7
- **WHEN** the owner downloads the document
- **THEN** the PDF is fetched from iFirma

#### Scenario: Invalid value
- **WHEN** the configuration form is saved with "abc", "-1", "1.5" or "400" as the number of days
- **THEN** an error names the allowed range and the previous value stays

#### Scenario: Upgrade
- **WHEN** the module is upgraded from a version without the setting
- **THEN** the setting is 30 and no copy exists yet

### Requirement: Storing can be turned off
With the setting at 0 the module SHALL store no copy and serve none, so every download and delivery fetches from iFirma as before this capability existed. Saving the setting as 0 SHALL delete every stored copy in the same request.

#### Scenario: Turned off
- **GIVEN** three documents with copies
- **WHEN** the owner of the shop saves the setting as 0
- **THEN** no copy is left when the page is shown again

#### Scenario: Download while off
- **GIVEN** the setting is 0
- **WHEN** a customer downloads a document twice, outside the throttle window
- **THEN** each download sends a request to iFirma and no copy is stored

#### Scenario: Turned on again
- **GIVEN** the setting was 0 and is saved as 30
- **WHEN** a document is downloaded
- **THEN** the PDF is fetched from iFirma and a copy is stored

### Requirement: A copy that no longer matches its document is not served
A copy SHALL be served only while the document is as it was when the copy was stored: the same document in iFirma (type and identifier), the same payment state, the same KSeF status and number, and not reported missing in iFirma. After any of these changed, the next download SHALL fetch the PDF from iFirma and replace the copy.

#### Scenario: KSeF acceptance arrived after the copy was stored
- **GIVEN** an employee downloaded the PDF while the document awaited KSeF acceptance
- **AND** the acceptance with the KSeF number arrived since
- **WHEN** the owner downloads the document
- **THEN** the PDF is fetched from iFirma and the copy is replaced by it

#### Scenario: Payment registered after the copy was stored
- **GIVEN** a document with a copy whose payment was registered in iFirma since
- **WHEN** the document is downloaded
- **THEN** the PDF is fetched from iFirma and the copy is replaced by it

#### Scenario: Document missing in iFirma
- **GIVEN** a document with a copy that the synchronisation found missing in iFirma since
- **WHEN** an employee downloads the document
- **THEN** the copy is not returned and the answer is the one of a download that iFirma refused

#### Scenario: Unchanged document
- **GIVEN** a document that did not change since its copy was stored
- **WHEN** the status synchronisation runs and the document is downloaded afterwards
- **THEN** the copy is returned and no PDF request is sent

### Requirement: Damaged copies are discarded
A stored copy whose bytes are not the complete PDF that was stored SHALL NOT be served: it SHALL be deleted and the PDF SHALL be fetched from iFirma.

#### Scenario: Truncated copy
- **GIVEN** a copy that holds fewer bytes than were stored
- **WHEN** the document is downloaded
- **THEN** a request is sent to iFirma, the complete PDF is returned and the copy is replaced

#### Scenario: Content is not a PDF
- **GIVEN** a copy whose content does not start as a PDF
- **WHEN** the document is downloaded
- **THEN** that content is not returned and the PDF is fetched from iFirma

### Requirement: The duplicate variant is never stored
A download that asks for the duplicate variant of a document SHALL always be fetched from iFirma. It SHALL NOT be answered from the stored copy, SHALL NOT be stored and SHALL NOT change the stored copy of the original. It SHALL obey every download limit, like any download fetched from iFirma.

#### Scenario: Duplicate asked while a copy exists
- **GIVEN** a document with a usable copy of the original
- **WHEN** a download asks for the duplicate variant
- **THEN** the duplicate is fetched from iFirma and returned
- **AND** the stored copy still holds the bytes of the original

#### Scenario: Duplicate asked without a copy
- **GIVEN** a document without a copy
- **WHEN** a download asks for the duplicate variant
- **THEN** the duplicate is returned and no copy is stored

#### Scenario: Duplicate obeys the limits
- **GIVEN** a document with a usable copy of the original and its owner's day cap reached
- **WHEN** the owner asks for the duplicate variant
- **THEN** the answer is HTTP 429 and no request is sent to iFirma

### Requirement: Oversized PDFs are not stored
A fetched PDF larger than 1 MiB SHALL be returned or e-mailed as usual and SHALL NOT be stored.

#### Scenario: PDF above the size limit
- **WHEN** iFirma returns a PDF of 2 MiB for a download
- **THEN** the PDF is returned to the requester, no copy is stored and the next download fetches it again

#### Scenario: PDF at the size limit
- **WHEN** iFirma returns a PDF of exactly 1 MiB
- **THEN** the PDF is returned and its copy is stored

### Requirement: A failed store never fails a download
When a fetched PDF cannot be stored, the PDF SHALL still be returned or e-mailed, and the failure SHALL be logged with the document's record id and without PDF content or customer data.

#### Scenario: Database refuses the copy
- **GIVEN** the database refuses the write of the copy
- **WHEN** the owner downloads the document
- **THEN** the PDF is returned
- **AND** the log has one warning that names the record id and holds no PDF bytes, no name and no address

#### Scenario: Next download after a failed store
- **GIVEN** the store of a copy failed
- **WHEN** the document is downloaded again
- **THEN** the PDF is fetched from iFirma again

### Requirement: An employee can replace the copy
The order panel of an issued document SHALL show when its PDF copy was stored and SHALL offer "Refresh PDF copy". The action SHALL delete the copy, then fetch the original from iFirma within the back-office download budget and store it. When the fetch fails or is refused, the copy SHALL stay deleted and the employee SHALL be told that the copy was not refreshed.

#### Scenario: Refresh after an edit in iFirma
- **GIVEN** a document that was edited in iFirma after its copy was stored
- **WHEN** an employee with the manage level triggers "Refresh PDF copy"
- **THEN** one PDF request is sent to iFirma and the panel shows the new time of the copy
- **AND** the next customer download returns the edited document without a request to iFirma

#### Scenario: iFirma fails during the refresh
- **GIVEN** a document with a copy
- **WHEN** the refresh is triggered and iFirma answers with an error
- **THEN** the employee is told the copy was not refreshed and the document has no copy
- **AND** the next download fetches the PDF from iFirma

#### Scenario: Budget used up
- **GIVEN** the back-office download budget of the current minute is used up
- **WHEN** the refresh is triggered
- **THEN** no request is sent to iFirma, the employee is told the download limit is reached and the document has no copy

#### Scenario: Document without a copy
- **GIVEN** an issued document without a copy
- **WHEN** an employee opens the order
- **THEN** the panel shows no copy time and the refresh stores the first copy

#### Scenario: Storing is off
- **GIVEN** the setting is 0
- **WHEN** an employee opens the order of an issued document
- **THEN** the panel shows neither a copy time nor "Refresh PDF copy"

### Requirement: Expired copies are deleted
Copies older than the configured days SHALL be deleted by every authenticated cron run, before the run sends a request to iFirma and also in a run that the rate limit stops later. On a shop without cron, storing a copy SHALL also delete expired copies of other documents. An expired copy SHALL never be served, whether or not it was deleted yet.

#### Scenario: Cron run
- **GIVEN** the setting is 30, one copy stored 40 days ago and one stored 5 days ago
- **WHEN** an authenticated cron run finishes
- **THEN** the older copy is deleted and the newer one is kept
- **AND** the summary reports one deleted copy

#### Scenario: Rate-limited cron run
- **GIVEN** an expired copy and the module's request limit reached
- **WHEN** an authenticated cron run finishes
- **THEN** the expired copy is deleted although the other jobs made no request

#### Scenario: Shop without cron
- **GIVEN** no cron run happens and the copy of one document is expired
- **WHEN** the copy of another document is stored
- **THEN** the expired copy is deleted

#### Scenario: Expired copy not deleted yet
- **GIVEN** an expired copy that no cron run and no store deleted yet
- **WHEN** its document is downloaded
- **THEN** the PDF is fetched from iFirma and the expired bytes are not returned

### Requirement: Copies are deleted with the customer and with the module
When a customer is deleted from the shop, the copies of that customer's documents SHALL be deleted in the same request. Uninstalling the module SHALL delete every copy and the setting.

#### Scenario: Customer deleted
- **GIVEN** a customer with two documents that have copies
- **WHEN** the customer is deleted in the back office
- **THEN** both copies are deleted

#### Scenario: Other customers are untouched
- **GIVEN** two customers whose documents have copies
- **WHEN** one of them is deleted
- **THEN** the copies of the other customer's documents are still stored

#### Scenario: Uninstall
- **WHEN** the module is uninstalled
- **THEN** no copy and no "Keep PDF copies (days)" setting is left in the shop database

### Requirement: Copies are reachable only through the download checks
Copies SHALL be stored in the shop database and never as files in the shop's file system. No request SHALL return a copy except the customer download and the back-office download, each after the same access checks as a download fetched from iFirma. PDF content SHALL NOT appear in any log.

#### Scenario: Another customer's document with a copy
- **GIVEN** a document with a usable copy
- **WHEN** a logged-in customer who does not own it requests the download with its token
- **THEN** the answer is "not found" and no bytes of the copy are returned

#### Scenario: Wrong token with a copy
- **GIVEN** a document with a usable copy
- **WHEN** its owner requests the download with a wrong token
- **THEN** the answer is "not found" and no bytes of the copy are returned

#### Scenario: Visitor with a copy
- **GIVEN** a document with a usable copy
- **WHEN** a visitor who is not logged in requests the download
- **THEN** the visitor is redirected to the login page and no bytes of the copy are returned

#### Scenario: Document awaiting KSeF acceptance with a copy
- **GIVEN** a document awaiting KSeF acceptance whose copy was stored by a back-office download
- **WHEN** its owner requests the download with the correct token
- **THEN** the answer is "not found" and no bytes of the copy are returned

#### Scenario: No file is written
- **WHEN** a copy is stored
- **THEN** no file is created or changed in the module directory or elsewhere in the shop's file system
