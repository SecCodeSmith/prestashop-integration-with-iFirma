# Spec Delta

## ADDED Requirements

### Requirement: Automatic retry verified end to end
The smoke run SHALL prove the automatic delivery retry inside the shop against the mail sink: a rejected e-mail is recorded as failed, a cron run before the due time sends nothing, and a cron run after the due time delivers exactly one e-mail with the PDF attached. The check SHALL NOT wait in real time for the due time.

#### Scenario: Rejected, then delivered by the cron
- **GIVEN** the mail sink rejects mail and an order reaches the trigger status
- **WHEN** the sink accepts mail again and the cron runs after the due time of the second attempt
- **THEN** the sink holds exactly one e-mail for that customer with the PDF attached
- **AND** the record shows delivery "sent" with two attempts used

#### Scenario: Cron before the due time
- **GIVEN** a delivery failed and its second attempt is not due yet
- **WHEN** the cron runs
- **THEN** the sink holds no e-mail for that customer and the attempt count is unchanged

#### Scenario: Three rejections
- **GIVEN** the mail sink rejects mail throughout
- **WHEN** the cron has run after each due time
- **THEN** the record shows delivery "failed" with three attempts used, the sink holds no e-mail for that customer
- **AND** a further cron run attempts nothing for that document

#### Scenario: Manual resend ends the retries
- **GIVEN** a delivery waits for its retry and the sink accepts mail again
- **WHEN** an employee sends the document again and the cron then runs after the due time
- **THEN** the sink holds exactly one e-mail for that customer

### Requirement: Usage and refusal checks in the smoke run
The smoke run SHALL prove against the iFirma mock that a status check and a PDF download each raise the module's request count and the count of their kind, that the mock counted the same requests, and that a download at the module's cap is answered with 429 while the mock receives no request.

#### Scenario: Counts match the mock
- **GIVEN** a freshly reset mock and module counters
- **WHEN** the scenario runs one status refresh and one PDF download
- **THEN** the module reports 1 request of kind `status` and 1 of kind `pdf`, and the mock reports 2 calls

#### Scenario: Download at the cap
- **GIVEN** the module's minute cap is set to the number of requests already sent this minute
- **WHEN** the owner requests the PDF over HTTP
- **THEN** the answer is 429 with a `Retry-After` header and the mock's call count does not change

#### Scenario: Refused refresh
- **GIVEN** the same cap
- **WHEN** an employee refreshes the status of a document
- **THEN** the answer says the status was not refreshed and the mock's call count does not change
