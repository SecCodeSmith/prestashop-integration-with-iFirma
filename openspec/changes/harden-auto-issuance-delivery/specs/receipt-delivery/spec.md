# Spec Delta

## ADDED Requirements

### Requirement: PDF download retry in shop-mail delivery
When the PDF download for a shop-mail delivery fails for a transient reason (no response or transport error, HTTP 5xx, 404 or 429, a non-PDF answer, or an iFirma document-not-found code), the module SHALL wait and try again, at most three attempts in total with waits of 3 s and 7 s. Authentication or configuration errors (`Kod` 400, 401, 402, 403, 499, 500) and refusals by the local rate guard SHALL NOT be retried. A final failure SHALL record the attempt count and the last error.

#### Scenario: PDF ready on the second attempt
- **WHEN** the first download answers `Kod` 404 "Nie znaleziono dokumentu" and the second returns a PDF
- **THEN** the e-mail is sent with that PDF attached and delivery is recorded as sent
- **AND** exactly one wait of 3 s happened between the two requests

#### Scenario: PDF never ready
- **WHEN** three downloads in a row answer "document not found"
- **THEN** no e-mail is sent, delivery is recorded as failed
- **AND** the recorded error states that 3 attempts were made and quotes the last iFirma message
- **AND** waits of 3 s and 7 s happened between the requests

#### Scenario: Authentication error is not retried
- **WHEN** the first download answers `Kod` 403
- **THEN** exactly one download request is made, no wait happens and delivery is recorded as failed with that error

#### Scenario: Rate guard refusal is not retried
- **WHEN** the local rate guard refuses the download request
- **THEN** no download request is sent, no wait happens and delivery is recorded as failed with the rate-guard message

#### Scenario: Manual resend retries the same way
- **WHEN** the admin clicks **Send again** and the first download fails transiently
- **THEN** the download is retried with the same schedule before the outcome is recorded

#### Scenario: Customer download keeps a single attempt
- **WHEN** the customer downloads the document from the order page and the download fails
- **THEN** exactly one download request is made and the customer sees the unavailable message

### Requirement: Copy of the shop-mail delivery
When a copy mode is configured, every shop-mail delivery e-mail (automatic, shop-mail fallback of the iFirma-mail channel, manual resend) SHALL carry a blind-copy recipient: the shop contact e-mail in mode `shop`, or the configured employee's e-mail in mode `employee`. An address that cannot be resolved, is invalid or equals the customer address SHALL be skipped with a log entry, leaving the customer delivery unchanged. E-mails sent by iFirma SHALL never carry a copy.

#### Scenario: Copy to the shop mailbox
- **WHEN** the copy mode is `shop`, the shop contact e-mail is `shop@example.test` and a document is delivered through shop mail
- **THEN** the customer e-mail carries `shop@example.test` as blind-copy recipient and the same PDF attachment

#### Scenario: Copy to an employee
- **WHEN** the copy mode is `employee` with an active employee whose e-mail is `anna@example.test`
- **THEN** the customer e-mail carries `anna@example.test` as blind-copy recipient

#### Scenario: Employee no longer usable
- **WHEN** the copy mode is `employee` and the configured employee was deleted, deactivated or has no valid e-mail
- **THEN** the customer e-mail is sent without a copy, delivery is recorded as sent
- **AND** a log entry names the order and says the copy was skipped

#### Scenario: Copy address equals the customer address
- **WHEN** the resolved copy address equals the customer e-mail, ignoring case
- **THEN** the e-mail is sent once, without a blind-copy recipient

#### Scenario: Copy mode none
- **WHEN** the copy mode is `none`
- **THEN** the e-mail carries no blind-copy recipient

#### Scenario: Manual resend carries the copy
- **WHEN** the copy mode is `shop` and the admin clicks **Send again**
- **THEN** the resent e-mail carries the shop contact e-mail as blind-copy recipient

#### Scenario: iFirma-mail channel unaffected
- **WHEN** the copy mode is `shop` and the channel is iFirma mail for a supported type
- **THEN** the iFirma send call carries only the customer e-mail and no shop e-mail is sent

### Requirement: Delivery copy settings
The configuration page SHALL offer a copy mode (`none`, `shop`, `employee`; default `none`) next to the delivery channel and, for mode `employee`, a list of active back-office employees to choose from. Saving mode `employee` without an existing active employee SHALL be refused with a message and nothing SHALL be saved; an unknown mode SHALL be stored as `none`. Both settings SHALL be written with their defaults on install and upgrade and removed on uninstall.

#### Scenario: Defaults after install
- **WHEN** the module is installed
- **THEN** the copy mode is `none` and the copy employee is `0`

#### Scenario: Employee list offered
- **WHEN** the admin opens the configuration page
- **THEN** the copy mode select offers `none`, `shop` and `employee`
- **AND** the employee select lists every active employee by name

#### Scenario: Employee mode without an employee refused
- **WHEN** the admin saves copy mode `employee` with no employee or an employee id that is not an active employee
- **THEN** the page shows an error naming the copy employee and no setting is changed

#### Scenario: Unknown mode normalised
- **WHEN** the posted copy mode is not one of the three values
- **THEN** `none` is stored

#### Scenario: Uninstall removes the settings
- **WHEN** the module is uninstalled
- **THEN** neither copy setting remains in the configuration
