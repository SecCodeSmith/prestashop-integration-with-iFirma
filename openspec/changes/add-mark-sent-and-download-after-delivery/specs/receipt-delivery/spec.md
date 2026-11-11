# Spec Delta

## ADDED Requirements

### Requirement: An employee can mark a document as sent
An employee SHALL be able to mark an issued document as sent, for a delivery that happened outside the module. The action SHALL record the delivery state "sent" with the channel "manual" and the current time, SHALL clear the delivery error, SHALL send no e-mail and no request to iFirma, SHALL end a wait for KSeF acceptance and SHALL cancel a pending automatic retry. It SHALL be refused for a document that is not issued, is missing in iFirma or is already recorded as sent.

#### Scenario: Failed delivery marked as sent
- **GIVEN** a document whose delivery failed
- **WHEN** an employee with the manage level marks it as sent
- **THEN** the document's delivery state is "sent", its channel is "manual", its delivery time is now and its delivery error is empty
- **AND** no e-mail is sent and no request is sent to iFirma

#### Scenario: Skipped delivery marked as sent
- **GIVEN** a document issued while the send channel was "none"
- **WHEN** an employee marks it as sent
- **THEN** the document is recorded as sent through the channel "manual"

#### Scenario: Pending automatic retry
- **GIVEN** a document whose failed delivery is due for an automatic retry
- **WHEN** an employee marks it as sent and the cron runs afterwards
- **THEN** the cron sends no e-mail for that document

#### Scenario: Document awaiting KSeF acceptance
- **GIVEN** a document awaiting KSeF acceptance
- **WHEN** an employee confirms the warning and marks it as sent
- **THEN** the wait ends and the cron does not deliver the document after KSeF accepts it

#### Scenario: Already sent
- **GIVEN** a document recorded as sent on 2026-11-02
- **WHEN** "Mark as sent" is called for it
- **THEN** the answer says the document is already sent and its delivery time and channel are unchanged

#### Scenario: Not issued or missing
- **WHEN** "Mark as sent" is called for an order without an issued document, or for a document flagged missing in iFirma
- **THEN** the action is refused with a message and nothing is recorded

#### Scenario: Shown in the order panel
- **WHEN** an employee opens the order of a document that was marked as sent
- **THEN** the panel shows "Marked as sent on <time> by an employee"

#### Scenario: Button only for a document that is not sent
- **WHEN** an employee with the manage level opens the order of an issued document
- **THEN** the panel offers "Mark as sent" when the document is not recorded as sent, and does not offer it otherwise

#### Scenario: Logged
- **WHEN** a document is marked as sent
- **THEN** the log has one entry with the document's record id and the employee's id, and no customer data

### Requirement: The channel "none" warns about customer downloads
The configuration page SHALL say, at the send channel setting, that with the channel "none" customers cannot download a document until an employee marks it as sent.

#### Scenario: Configuration page
- **WHEN** an employee opens the module's configuration page
- **THEN** the description of the send channel says that with "none" customers cannot download documents until an employee marks them as sent
