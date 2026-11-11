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
One authenticated cron request SHALL first read the day limit from iFirma (specified in `ifirma-api-compliance`) and then run five jobs in this order, under one run lock: issue queued orders, retry deferred KSeF submissions, synchronise statuses, deliver documents whose KSeF acceptance arrived, retry failed deliveries that are due. When a job is refused by the rate limit, the later jobs SHALL make no iFirma request in that run. The JSON summary SHALL keep the existing status-sync keys at its top level (the status sync has no block of its own) and add a block `limit`, a block each for `queue`, `ksef`, `delivery` and `delivery_retry`, and the keys `rate_limited`, `rate_limited_window`, `usage` and `time_budget_exhausted`.

#### Scenario: Acceptance and delivery in one run
- **WHEN** a document awaiting KSeF was accepted in iFirma since the last run
- **THEN** one cron run stores the KSeF number in the sync job and delivers the document in the delivery job

#### Scenario: Rate limit stops the later jobs
- **WHEN** the queue job is refused by the rate limit
- **THEN** the KSeF, sync, delivery and delivery-retry jobs make no iFirma request in that run
- **AND** the summary reports `rate_limited` true and names the window that refused

#### Scenario: Summary blocks
- **WHEN** a run finishes
- **THEN** the status-sync keys stay at the top level of the JSON, as before the other jobs existed
- **AND** the blocks `queue`, `ksef`, `delivery` and `delivery_retry` each report the records handled, succeeded, failed and left for the next run
- **AND** the block `limit` reports the requests used, granted and remaining of the day, each empty when no reading is available
- **AND** the JSON reports `rate_limited` (0 or 1), `rate_limited_window`, `usage` (the module's requests of the current minute and day and the day's count per kind) and `time_budget_exhausted` (0 or 1)

#### Scenario: Day limit read first
- **GIVEN** the `abonent` key is not set
- **WHEN** a run starts
- **THEN** the day limit is not read, the `limit` block reports no figures and the five jobs run as usual

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

## ADDED Requirements

### Requirement: A cron run has a time budget
One cron run SHALL NOT start a record or a job once 45 seconds have passed since it started, and SHALL finish a record that has started. What is not started SHALL be reported as left for the next run, and the summary SHALL report `time_budget_exhausted` 1 when something was refused for that reason, else 0. The status sync bounds itself by its call cap: it is skipped when the budget is used up at its turn, and one that has started is not interrupted.

#### Scenario: Budget used up in the middle of a job
- **GIVEN** 45 seconds have passed since the run started while the queue job works through ten queued orders
- **WHEN** the queue job is about to start the next order
- **THEN** it starts no further order, reports the rest as left and the summary reports `time_budget_exhausted` 1

#### Scenario: Later jobs only report
- **GIVEN** the budget was used up during an earlier job
- **WHEN** the run reaches the later jobs
- **THEN** they make no request and each reports what is left for the next run, and the status sync reports a run that did nothing

#### Scenario: A started record is finished
- **GIVEN** a delivery has started 44 seconds after the run started and takes 20 seconds
- **WHEN** the delivery ends
- **THEN** its outcome is recorded and only the next record is refused

#### Scenario: Run inside the budget
- **WHEN** a run finishes within 45 seconds
- **THEN** every job runs as before and the summary reports `time_budget_exhausted` 0
