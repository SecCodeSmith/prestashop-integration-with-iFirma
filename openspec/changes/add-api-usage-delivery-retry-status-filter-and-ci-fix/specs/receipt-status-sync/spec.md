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
When the owning customer opens the order detail page and the last sync is older than the configured interval (default 15 minutes), the module SHALL perform at most one list call with a short timeout, swallow failures as a recorded sync error, and render the page with the last known state. The one call SHALL read the first page of the viewed document's issue date only. Its rows SHALL refresh the viewed document and every other issued document of that customer with that issue date whose last sync is older than the interval. For one customer the module SHALL start at most one such call per interval, whatever the number of that customer's orders, issue dates, page views or parallel requests. The viewed document SHALL be marked as missing only when it is absent from a page that is not full; a document that is absent from a full page, and any other document of the customer that is absent from the rows, SHALL keep its stored state, and the cron sync reads it. When the request limit refuses the call, the module SHALL make no call, record nothing and render the last known state.

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
- **AND** opening the page again makes a new attempt

#### Scenario: Several orders of one issue date
- **GIVEN** a customer has 40 issued documents with the same issue date, each last synced 20 minutes ago
- **WHEN** the customer opens the 40 order pages one after another
- **THEN** one list call is made
- **AND** all 40 documents hold the refreshed state

#### Scenario: Orders of several issue dates
- **GIVEN** a customer has issued documents on 11 different issue dates, each last synced 20 minutes ago
- **WHEN** the customer opens the 11 order pages within the interval
- **THEN** one list call is made, for the issue date of the first page opened
- **AND** the documents of the other issue dates keep their stored state

#### Scenario: Parallel page views
- **GIVEN** a customer has ten issued documents, each last synced 20 minutes ago
- **WHEN** ten order pages of that customer are requested at the same moment
- **THEN** one list call is made

#### Scenario: After the interval
- **GIVEN** a customer's order page started a list call 20 minutes ago
- **WHEN** the customer opens an order page whose document was last synced 20 minutes ago
- **THEN** one list call is made

#### Scenario: A busy issue date
- **GIVEN** the shop issued 1,000 documents on the issue date and the customer's document is not among the first 100 rows
- **WHEN** the customer opens the order page
- **THEN** one list call is made and no second page is read
- **AND** the document keeps its stored state and is not marked as missing
- **AND** opening the page again within the interval makes no call

#### Scenario: Document absent from a short page
- **GIVEN** the list call returns fewer than 100 rows and the viewed document is not among them
- **WHEN** the customer opens the order page
- **THEN** the viewed document is marked as missing in iFirma

#### Scenario: Another document of the customer absent from the rows
- **GIVEN** the list call returns fewer than 100 rows, the viewed document is among them and another document of the customer with the same issue date is not
- **WHEN** the customer opens the order page
- **THEN** the viewed document is refreshed
- **AND** the other document keeps its stored state and is not marked as missing

#### Scenario: Another customer's documents
- **GIVEN** two customers each have a stale document with the same issue date
- **WHEN** the first customer opens the order page
- **THEN** only the first customer's document is refreshed and the second customer's document is unchanged
- **AND** the second customer's own order page still makes its list call

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

### Requirement: Customer page syncs have their own request budget
All syncs started by customers' order pages together SHALL use at most 10 iFirma requests per calendar minute and 1,000 per calendar day (shop timezone). A sync beyond that SHALL send no request, record nothing and render the last known state, exactly like a refusal by the request limit. The budget SHALL count only the syncs that reached iFirma, and it SHALL be separate from the limit that issuance, the cron and the downloads use, so page views cannot stop issuance.

#### Scenario: Minute budget used up
- **GIVEN** 10 customer page syncs were sent in the current minute
- **WHEN** another customer opens an order page whose last sync is 20 minutes old
- **THEN** no request is sent and the page shows the last known state without an error
- **AND** the last sync time, the sync error and the time of the last attempt of the record are unchanged

#### Scenario: Issuance is not affected
- **GIVEN** the customer page sync budget of the current minute is used up
- **WHEN** an order reaches the trigger status
- **THEN** its document is issued

#### Scenario: Day budget used up
- **GIVEN** 1,000 customer page syncs were sent today
- **WHEN** a customer opens a stale order page in a new minute
- **THEN** no request is sent

#### Scenario: A refused sync uses no budget
- **GIVEN** the module's request limit refuses the list call
- **WHEN** a customer opens a stale order page
- **THEN** the budget counts of the minute and the day are unchanged

#### Scenario: New minute
- **GIVEN** the minute budget was used up in the previous calendar minute and the day budget is not
- **WHEN** a customer opens a stale order page
- **THEN** the list call is made
