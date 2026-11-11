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
The cron SHALL NOT retry a delivery that failed for a permanent reason, or whose outcome is unknown. Permanent: the customer has no valid e-mail address, the document is missing in iFirma, a request could not be sent because the API key is missing, or iFirma rejected the login or refused the request as invalid. Unknown outcome: a send request reached iFirma's mail service and no usable answer came back, a gateway error included. Such a record SHALL stay "failed" and need attention.

#### Scenario: Customer without an e-mail address
- **WHEN** a delivery fails because the customer has no valid e-mail address
- **THEN** no retry is scheduled

#### Scenario: Timeout of iFirma's mail call
- **GIVEN** the channel is iFirma mail
- **WHEN** the send request times out after it was sent
- **THEN** no retry is scheduled, so the customer cannot get the same e-mail twice

#### Scenario: Document missing in iFirma
- **GIVEN** the document is marked as missing in iFirma
- **WHEN** its delivery fails
- **THEN** no retry is scheduled, however the failure was reported

#### Scenario: API key missing
- **GIVEN** the `faktura` key is not configured
- **WHEN** a delivery cannot be sent for that reason
- **THEN** no retry is scheduled and the record stays "failed"

#### Scenario: Login rejected
- **WHEN** iFirma answers the delivery request with HTTP 401 or 403
- **THEN** no retry is scheduled

#### Scenario: Request refused as invalid
- **WHEN** iFirma answers the delivery request with Kod 400, 401, 402, 403, 499 or 500
- **THEN** no retry is scheduled

#### Scenario: Gateway error on the send request
- **GIVEN** the channel is iFirma mail
- **WHEN** the send request is answered with HTTP 502
- **THEN** the outcome is unknown, no retry is scheduled and the record stays "failed"

#### Scenario: Server error on the PDF download
- **GIVEN** the channel is the shop mail system
- **WHEN** the PDF download is answered with HTTP 502 and no e-mail was sent
- **THEN** the failure is temporary and the retry is scheduled under the retry rules

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
When the request limit or the authentication cool-down refuses a delivery before any request is sent to iFirma, nothing SHALL be e-mailed, the number of automatic attempts used SHALL end as it was before the attempt (also when the attempt was already recorded as started), and the delivery SHALL stay due for the next cron run. The retry job SHALL stop at the first such refusal in a run.

#### Scenario: Limit reached during the retry job
- **GIVEN** three deliveries are due for a retry and the minute cap is reached after the first
- **WHEN** the cron runs
- **THEN** one delivery is attempted, the other two keep their attempt counts and are attempted in a later run

#### Scenario: First delivery refused by the limit at issuance
- **WHEN** the delivery of a just issued document is refused by the limit before any request is sent
- **THEN** the record shows delivery "failed" with the refusal, no attempt is counted, the delivery is due at once and the cron delivers it later

#### Scenario: Refusal shows up after the attempt was recorded
- **GIVEN** the delivery goes through the shop mail system, the retry is due and the attempt was recorded as started
- **WHEN** the PDF download is refused by the request limit before it is sent
- **THEN** nothing is e-mailed, the attempt is given back so the attempts used are as before the run, and the delivery stays due now
- **AND** the record no longer carries the "outcome was not recorded" error but the refusal

### Requirement: Old failures are not retried after the upgrade
A delivery that failed before the module version that introduces the automatic retry SHALL NOT be retried automatically. It SHALL stay "failed" until an employee sends it again.

#### Scenario: Upgrade with failed deliveries
- **GIVEN** 20 documents have a failed delivery from last month
- **WHEN** the module is upgraded and the cron runs
- **THEN** no e-mail is sent for those 20 documents

### Requirement: Retry state shown to the employee
The order panel and the Orders-list tooltip SHALL show, for a failed delivery, how many of the three automatic attempts were used and when the next one is due, or that no automatic attempt remains, will be made or is scheduled. The error text SHALL be shown escaped.

#### Scenario: Retry scheduled
- **GIVEN** the first attempt failed and the second is due at 10:15
- **WHEN** an employee opens the order panel
- **THEN** the panel shows the delivery as failed, attempt 1 of 3, next attempt at 10:15

#### Scenario: Attempts used up
- **WHEN** the third attempt has failed
- **THEN** the panel shows that no automatic attempt remains and offers **Send again**

#### Scenario: First delivery refused by the limit
- **GIVEN** the delivery of a just issued document was refused by the limit and is due at 10:15
- **WHEN** an employee opens the order panel
- **THEN** the panel shows "Attempt 0 of 3, next attempt at 10:15"

#### Scenario: No automatic attempt will be made
- **GIVEN** a delivery failed for a permanent reason, with an unknown outcome, or was left with "outcome was not recorded", with one or two attempts used and no due time
- **WHEN** an employee opens the order panel or the Orders-list tooltip
- **THEN** it shows the delivery as failed and "No automatic attempt will be made"

#### Scenario: No automatic attempt is scheduled
- **GIVEN** a delivery failed before the module version that introduces the automatic retry
- **WHEN** an employee opens the order panel or the Orders-list tooltip
- **THEN** it shows the delivery as failed and "No automatic attempt is scheduled"

#### Scenario: Markup in the error
- **WHEN** the stored delivery error contains `<script>alert(1)</script>`
- **THEN** it is shown as text and the browser executes nothing

### Requirement: A manual resend right after a delivery is refused
A manual **Send again** of a document whose delivery was recorded as sent less than 30 seconds earlier SHALL send nothing and SHALL tell the employee that the document was just sent. The same SHALL hold for each document of a bulk resend. After 30 seconds a manual resend SHALL work as before.

#### Scenario: Double click
- **GIVEN** an employee sent a document again and the e-mail went out
- **WHEN** the same action arrives again 2 seconds later
- **THEN** no second e-mail is sent and the answer says the document was sent a moment ago

#### Scenario: Cron just delivered
- **GIVEN** the cron delivered a document 5 seconds ago
- **WHEN** an employee clicks **Send again** for it
- **THEN** no second e-mail is sent

#### Scenario: Repeated bulk run
- **GIVEN** a bulk resend delivered three documents
- **WHEN** the same bulk run is started again within 30 seconds
- **THEN** none of the three is e-mailed again and the summary reports them as just sent

#### Scenario: Later resend
- **WHEN** an employee sends the document again one minute after the last delivery
- **THEN** the customer is e-mailed as before

#### Scenario: Failed delivery can be resent at once
- **GIVEN** the last delivery of a document failed 2 seconds ago
- **WHEN** an employee clicks **Send again**
- **THEN** the delivery is attempted

### Requirement: An automatic attempt is recorded before anything is sent
Before an automatic attempt (the cron retry, or the cron delivery of a document that KSeF accepted) sends anything, the module SHALL save the attempt as counted, with no due time and an error saying that the attempt started and its outcome was not recorded; the outcome SHALL then replace it. A delivery left in that state SHALL NOT be retried automatically and needs attention. When the record cannot be saved, nothing SHALL be sent.

#### Scenario: Process stops after the e-mail left
- **GIVEN** the cron retry has recorded the attempt and sent the e-mail
- **WHEN** the process stops before the outcome is saved
- **THEN** the record shows delivery "failed" with the error "A delivery attempt started and its outcome was not recorded. Check whether the customer received the document before sending it again."
- **AND** the attempt is counted, no retry is scheduled and the cron never sends that document again

#### Scenario: Outcome cannot be saved after a successful send
- **GIVEN** the e-mail went out during an automatic attempt
- **WHEN** saving the outcome fails
- **THEN** the record keeps the "outcome was not recorded" error, the customer received one e-mail and the cron sends no second one

#### Scenario: Process stops before anything was sent
- **GIVEN** the attempt was recorded as started
- **WHEN** the process stops before any request or e-mail is sent
- **THEN** the record shows the same "outcome was not recorded" error and no retry is scheduled, although nothing was sent
- **AND** an employee has to check the delivery and may use **Send again**

#### Scenario: The attempt cannot be recorded
- **WHEN** saving the attempt fails
- **THEN** nothing is sent, the record stays as it was and the cron tries the delivery again in a later run

#### Scenario: Successful retry counts two attempts
- **GIVEN** the first delivery failed at issuance, so one attempt is counted
- **WHEN** the cron retry succeeds
- **THEN** the record shows delivery "sent", no due time and two attempts used

#### Scenario: Manual resend records nothing in advance
- **WHEN** an employee clicks **Send again**
- **THEN** the attempts used and the due time of the record stay as they were until the outcome is known

### Requirement: A cron retry makes one PDF download attempt
A cron retry of a delivery through the shop mail system SHALL request the PDF once and SHALL NOT wait between download attempts. A PDF that is not ready SHALL be a temporary failure that the retry schedule handles. The delivery at issuance and the cron delivery of an accepted document keep their repeated download attempts.

#### Scenario: PDF not ready at the retry
- **GIVEN** a failed delivery is due and iFirma does not have the PDF ready
- **WHEN** the cron retries it
- **THEN** one PDF request is sent without a wait, nothing is e-mailed, the attempt is counted and the next one is scheduled under the retry rules

#### Scenario: PDF ready at the retry
- **GIVEN** a failed delivery is due and the PDF is ready
- **WHEN** the cron retries it
- **THEN** one PDF request is sent and the customer is e-mailed with the PDF attached
