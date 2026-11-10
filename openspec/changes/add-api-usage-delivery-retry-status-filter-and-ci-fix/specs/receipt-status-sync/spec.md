# Spec Delta

## MODIFIED Requirements

### Requirement: On-demand admin refresh
The admin order panel SHALL offer "Refresh from iFirma" that performs one list call and shows the updated remote fields or the error. When the request limit refuses the call, the panel SHALL say that the status was not refreshed and which limit refused it, SHALL keep the stored state and SHALL record no sync error.

#### Scenario: Successful refresh
- **WHEN** the admin clicks "Refresh from iFirma"
- **THEN** exactly one list call is made and the updated remote fields are shown

#### Scenario: Refresh fails
- **WHEN** the list call fails
- **THEN** the admin sees the error and the previously stored state is kept

#### Scenario: Refresh refused by the limit
- **GIVEN** the minute cap is reached
- **WHEN** the admin clicks "Refresh from iFirma"
- **THEN** no list call is made and the admin sees that the status was not refreshed because of the minute limit
- **AND** the stored state, the last sync time and the sync error are unchanged

#### Scenario: Refresh refused during the back-off
- **GIVEN** iFirma's limit answer started a back-off that has not ended
- **WHEN** the admin clicks "Refresh from iFirma"
- **THEN** no list call is made and the answer is not a success message

### Requirement: Opportunistic customer sync
When the owning customer opens the order detail page and the last sync is older than the configured interval (default 15 minutes), the module SHALL perform at most one list call with a short timeout, swallow failures as a recorded sync error, and render the page with the last known state. When the request limit refuses the call, the module SHALL make no call, record nothing and render the last known state.

#### Scenario: Stale state
- **WHEN** the owner opens the page and the last sync is 20 minutes old
- **THEN** one list call is made and the page shows the refreshed state

#### Scenario: Recent sync
- **WHEN** the last sync is 5 minutes old
- **THEN** no list call is made

#### Scenario: Timeout
- **WHEN** the list call times out
- **THEN** the page renders with the last known state and no error is shown to the customer

#### Scenario: Refused by the limit
- **GIVEN** the last sync is 20 minutes old and the day cap is reached
- **WHEN** the owner opens the page
- **THEN** no list call is made, the page shows the last known state without an error
- **AND** the last sync time and the sync error of the record are unchanged

### Requirement: Cron jobs and their order
One authenticated cron request SHALL run five jobs in this order, under one run lock: issue queued orders, retry deferred KSeF submissions, synchronise statuses, deliver documents whose KSeF acceptance arrived, retry failed deliveries that are due. When a job is refused by the rate limit, the later jobs SHALL make no iFirma request in that run. The JSON summary SHALL keep its existing keys and add one block per job and a `rate_limited` flag.

#### Scenario: Acceptance and delivery in one run
- **WHEN** a document awaiting KSeF was accepted in iFirma since the last run
- **THEN** one cron run stores the KSeF number in the sync job and delivers the document in the delivery job

#### Scenario: Rate limit stops the later jobs
- **WHEN** the queue job is refused by the rate limit
- **THEN** the KSeF, sync, delivery and delivery-retry jobs make no iFirma request in that run
- **AND** the summary reports `rate_limited` true and names the window that refused

#### Scenario: Summary blocks
- **WHEN** a run finishes
- **THEN** the JSON also reports, per job, the records handled, succeeded, failed and left for the next run

#### Scenario: Delivery-retry block
- **GIVEN** two failed deliveries are due and one is not due yet
- **WHEN** a run finishes and one retry succeeded and one failed
- **THEN** the delivery-retry block reports 2 handled, 1 succeeded, 1 failed
- **AND** the existing keys of the summary keep their meaning

#### Scenario: Retry does not repeat a delivery of the same run
- **WHEN** the delivery job fails to deliver an accepted document
- **THEN** the delivery-retry job of the same run does not attempt that document

#### Scenario: Wrong token
- **WHEN** the cron endpoint is called with a wrong token
- **THEN** it answers 403 and no job runs
