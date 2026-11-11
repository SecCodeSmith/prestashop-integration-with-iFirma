# Spec Delta

## MODIFIED Requirements

### Requirement: Cron jobs and their order
One authenticated cron request SHALL first delete the expired PDF copies (specified in `document-pdf-store`), then read the day limit from iFirma (specified in `ifirma-api-compliance`) and then run five jobs in this order, under one run lock: issue queued orders, retry deferred KSeF submissions, synchronise statuses, deliver documents whose KSeF acceptance arrived, retry failed deliveries that are due. When a job is refused by the rate limit, the later jobs SHALL make no iFirma request in that run. The JSON summary SHALL keep the existing status-sync keys at its top level (the status sync has no block of its own) and add a block `pdf_copies`, a block `limit`, a block each for `queue`, `ksef`, `delivery` and `delivery_retry`, and the keys `rate_limited`, `rate_limited_window`, `usage` and `time_budget_exhausted`.

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
- **AND** the block `pdf_copies` reports the number of copies deleted in the run
- **AND** the JSON reports `rate_limited` (0 or 1), `rate_limited_window`, `usage` (the module's requests of the current minute and day and the day's count per kind) and `time_budget_exhausted` (0 or 1)

#### Scenario: Expired copies deleted first
- **GIVEN** an expired PDF copy and the module's request limit reached
- **WHEN** a run finishes
- **THEN** the expired copy is deleted and the `pdf_copies` block reports 1, although no job sent a request to iFirma

#### Scenario: Deleting copies sends no request
- **WHEN** a run deletes expired PDF copies
- **THEN** no request is sent to iFirma for it and the `usage` counts do not change because of it

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
