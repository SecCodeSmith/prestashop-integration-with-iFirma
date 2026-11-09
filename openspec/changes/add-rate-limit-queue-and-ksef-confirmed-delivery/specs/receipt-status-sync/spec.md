# Spec Delta

## MODIFIED Requirements

### Requirement: Cron selection and budget
The bulk sync SHALL select receipts that are not paid, or not remotely sent, or not synced within 24 hours, or whose delivery is awaiting KSeF acceptance, group them by issue date so one list call updates many receipts, stop after the configured maximum list calls per run (default 40), and respond with a JSON summary.

#### Scenario: Grouped calls
- **WHEN** 30 selected receipts share 3 issue dates
- **THEN** at most 3 list calls are made

#### Scenario: Budget exhausted
- **WHEN** more issue dates are selected than the maximum calls per run
- **THEN** the run stops at the maximum and the remaining receipts are left for the next run

#### Scenario: Summary content
- **WHEN** a run finishes
- **THEN** the JSON reports receipts checked, updated, marked paid, marked missing, errors, and orders held by pending data requests

#### Scenario: Awaiting KSeF is always due
- **WHEN** a paid, remotely sent receipt synced 10 minutes ago has delivery `awaiting_ksef`
- **THEN** the next cron run selects it

## ADDED Requirements

### Requirement: Cron jobs and their order
One authenticated cron request SHALL run four jobs in this order, under one run lock: issue queued orders, retry deferred KSeF submissions, synchronise statuses, deliver documents whose KSeF acceptance arrived. When a job is refused by the rate limit, the later jobs SHALL make no iFirma request in that run. The JSON summary SHALL keep its existing keys and add one block per job and a `rate_limited` flag.

#### Scenario: Acceptance and delivery in one run
- **WHEN** a document awaiting KSeF was accepted in iFirma since the last run
- **THEN** one cron run stores the KSeF number in the sync job and delivers the document in the delivery job

#### Scenario: Rate limit stops the later jobs
- **WHEN** the queue job is refused by the rate limit
- **THEN** the KSeF, sync and delivery jobs make no iFirma request in that run
- **AND** the summary reports `rate_limited` true and names the window that refused

#### Scenario: Summary blocks
- **WHEN** a run finishes
- **THEN** the JSON also reports, per job, the records handled, succeeded, failed and left for the next run

#### Scenario: Wrong token
- **WHEN** the cron endpoint is called with a wrong token
- **THEN** it answers 403 and no job runs
