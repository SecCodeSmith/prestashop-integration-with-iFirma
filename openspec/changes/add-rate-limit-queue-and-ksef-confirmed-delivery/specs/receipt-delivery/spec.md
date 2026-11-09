# Spec Delta

## MODIFIED Requirements

### Requirement: Delivery follows issuance
After a document is created by the module and the delivery channel is not "none", the module SHALL attempt delivery to the order customer's e-mail as the last step of the issuing pipeline and SHALL record the outcome (sent, failed or skipped), channel, timestamp and error on the receipt record. A document that goes to KSeF under the configured mode SHALL instead be recorded as awaiting KSeF acceptance, unless it is already accepted.

#### Scenario: Successful delivery after issuance
- **WHEN** the module creates a document that does not go to KSeF and the channel is shop mail
- **THEN** the customer is e-mailed immediately after creation
- **AND** the receipt record shows delivery "sent" with the channel and a timestamp

#### Scenario: Delivery is the last step
- **WHEN** the pipeline finishes creating a document
- **THEN** delivery runs after the document number has been stored

#### Scenario: KSeF document held at issuance
- **WHEN** the module creates an invoice for a buyer with a NIP in mode `b2b` and the document is not yet accepted in KSeF
- **THEN** no e-mail is sent
- **AND** the receipt record shows delivery `awaiting_ksef` and the pipeline's deliver step is recorded as skipped with the reason "waiting for KSeF acceptance"

#### Scenario: Already accepted at issuance
- **WHEN** iFirma reports the new invoice as accepted with a KSeF number before the deliver step runs
- **THEN** the customer is e-mailed in the same run

## ADDED Requirements

### Requirement: KSeF documents are delivered after acceptance
The cron SHALL deliver, oldest first and at most `IFIRMA_CRON_MAX_ITEMS` per run, every document whose delivery is awaiting KSeF acceptance and that is now accepted (a KSeF number, or the status `PRZYJETA`). It SHALL use the configured channel and record the outcome as for delivery at issuance. A document whose KSeF state is an error SHALL stay awaiting and SHALL NOT be delivered automatically.

#### Scenario: Accepted document delivered by the cron
- **WHEN** a document awaiting KSeF gets its KSeF number from status sync
- **THEN** the next cron delivery job e-mails the customer once and the record shows delivery "sent"

#### Scenario: Not yet accepted
- **WHEN** a document awaiting KSeF still shows `WYSLANA` without a number
- **THEN** the delivery job does not e-mail the customer

#### Scenario: Rejected document is not delivered
- **WHEN** a document awaiting KSeF shows `ODRZUCONA` or `BLAD`
- **THEN** no e-mail is sent and the delivery stays `awaiting_ksef`

#### Scenario: Delivery failure after acceptance
- **WHEN** the e-mail of an accepted document fails
- **THEN** the record shows delivery "failed" with the error, and the cron does not retry it automatically

#### Scenario: Channel none
- **WHEN** the channel is "none" when an invoice that goes to KSeF is issued
- **THEN** the delivery is recorded as skipped as before and the document never waits for KSeF

### Requirement: Manual resend ends the KSeF wait
A manual **Send again** of a document awaiting KSeF acceptance SHALL deliver it immediately on the employee's decision and SHALL end the wait, so the cron does not deliver it a second time. The admin panel SHALL warn before that resend that the document is not yet accepted in KSeF.

#### Scenario: Resend while awaiting
- **WHEN** an employee with the manage level sends an awaiting document again
- **THEN** the customer is e-mailed, the record shows delivery "sent"
- **AND** the cron later sends no second e-mail after acceptance

#### Scenario: Warning shown
- **WHEN** the panel shows the **Send again** action for an awaiting document
- **THEN** the panel states that the document is not yet accepted in KSeF
