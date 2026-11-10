# Spec Delta

## MODIFIED Requirements

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
- **WHEN** the e-mail of an accepted document fails for a temporary reason
- **THEN** the record shows delivery "failed" with the error and one automatic attempt used
- **AND** the delivery is retried under the automatic retry rules

#### Scenario: Channel none
- **WHEN** the channel is "none" when an invoice that goes to KSeF is issued
- **THEN** the delivery is recorded as skipped as before and the document never waits for KSeF

## ADDED Requirements

### Requirement: Automatic retry of a failed delivery
The cron SHALL retry a delivery that failed for a temporary reason. A document SHALL get at most three automatic delivery attempts, the first one included. The second attempt SHALL NOT start earlier than 15 minutes after the first failure, and the third not earlier than 2 hours after the second. A retry SHALL use the channel configured at that moment and SHALL record its outcome like any delivery.

#### Scenario: Mail server back after a short outage
- **GIVEN** the shop mail system refused the e-mail of an issued receipt at 10:00
- **WHEN** the cron runs at 10:16 and the mail system accepts mail again
- **THEN** the customer is e-mailed once, the record shows delivery "sent" and no further retry is scheduled

#### Scenario: Too early
- **GIVEN** a delivery failed at 10:00
- **WHEN** the cron runs at 10:10
- **THEN** no e-mail is sent for that document

#### Scenario: Second failure
- **GIVEN** the first attempt failed at 10:00 and the retry at 10:16 fails too
- **WHEN** the cron runs at 11:00
- **THEN** no e-mail is sent, and the third attempt is not made before 12:16

#### Scenario: Third failure ends the retries
- **WHEN** the third automatic attempt fails
- **THEN** the record stays "failed" with the last error and the cron never tries that document again

#### Scenario: PDF not ready
- **GIVEN** a shop-mail delivery failed because iFirma did not have the PDF ready after the download attempts of that run
- **WHEN** the retry is due and the PDF is ready
- **THEN** the customer is e-mailed with the PDF attached

#### Scenario: Oldest first within the run cap
- **GIVEN** 15 deliveries are due for a retry and `IFIRMA_CRON_MAX_ITEMS` is 10
- **WHEN** one cron run finishes
- **THEN** the 10 with the earliest due time were attempted and 5 wait for the next run

### Requirement: Failures that are never retried automatically
The cron SHALL NOT retry a delivery that failed for a permanent reason, or whose outcome is unknown. Permanent reasons: the customer has no valid e-mail address, the document is missing in iFirma, or iFirma refused the request as invalid. The outcome is unknown when a send request reached iFirma's mail service and no answer came back. Such a record SHALL stay "failed" and need attention.

#### Scenario: Customer without an e-mail address
- **WHEN** a delivery fails because the customer has no valid e-mail address
- **THEN** no retry is scheduled

#### Scenario: Timeout of iFirma's mail call
- **GIVEN** the channel is iFirma mail
- **WHEN** the send request times out after it was sent
- **THEN** no retry is scheduled, so the customer cannot get the same e-mail twice

#### Scenario: Channel switched off
- **GIVEN** a delivery waits for its retry
- **WHEN** the channel is set to "none" before the retry is due
- **THEN** the cron sends nothing for that document

### Requirement: A retry never e-mails a customer twice
An automatic retry SHALL deliver only a document whose delivery is still "failed" when the retry starts. A manual **Send again** that succeeds SHALL end the automatic retries of that document. Two cron runs, or a cron run and an employee, SHALL NOT deliver the same document at the same time.

#### Scenario: Employee was faster
- **GIVEN** a delivery waits for its retry
- **WHEN** an employee sends the document again and the e-mail goes out
- **THEN** the cron sends no further e-mail for that document

#### Scenario: Manual resend fails
- **GIVEN** a delivery waits for its second automatic attempt
- **WHEN** an employee sends the document again and that fails
- **THEN** the automatic attempts already used and the due time stay as they were

#### Scenario: Concurrent attempts
- **WHEN** a cron run and an employee try to deliver the same document at the same moment
- **THEN** the customer receives one e-mail

#### Scenario: Copy of the delivery
- **GIVEN** the copy mode sends a copy of each delivery
- **WHEN** the second automatic attempt succeeds after a failed first one
- **THEN** one copy is sent, with the successful attempt

### Requirement: A limit refusal uses no delivery attempt
When the request limit or the authentication cool-down refuses a delivery before any request is sent, nothing SHALL be e-mailed, the number of automatic attempts used SHALL stay unchanged, and the delivery SHALL stay due for the next cron run. The retry job SHALL stop at the first such refusal in a run.

#### Scenario: Limit reached during the retry job
- **GIVEN** three deliveries are due for a retry and the minute cap is reached after the first
- **WHEN** the cron runs
- **THEN** one delivery is attempted, the other two keep their attempt counts and are attempted in a later run

#### Scenario: First delivery refused by the limit at issuance
- **WHEN** the delivery of a just issued document is refused by the limit before any request is sent
- **THEN** the record shows delivery "failed" with the refusal, no attempt is counted and the cron delivers it later

### Requirement: Old failures are not retried after the upgrade
A delivery that failed before the module version that introduces the automatic retry SHALL NOT be retried automatically. It SHALL stay "failed" until an employee sends it again.

#### Scenario: Upgrade with failed deliveries
- **GIVEN** 20 documents have a failed delivery from last month
- **WHEN** the module is upgraded and the cron runs
- **THEN** no e-mail is sent for those 20 documents

### Requirement: Retry state shown to the employee
The order panel and the Orders-list tooltip SHALL show, for a failed delivery, how many of the three automatic attempts were used and when the next one is due, or that no automatic attempt remains. The error text SHALL be shown escaped.

#### Scenario: Retry scheduled
- **GIVEN** the first attempt failed and the second is due at 10:15
- **WHEN** an employee opens the order panel
- **THEN** the panel shows the delivery as failed, attempt 1 of 3, next attempt at 10:15

#### Scenario: Attempts used up
- **WHEN** the third attempt has failed
- **THEN** the panel shows that no automatic attempt remains and offers **Send again**

#### Scenario: Markup in the error
- **WHEN** the stored delivery error contains `<script>alert(1)</script>`
- **THEN** it is shown as text and the browser executes nothing
