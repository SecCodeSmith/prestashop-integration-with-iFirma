# receipt-status-sync Specification

## Purpose
Keeping the module's view of an issued document in line with iFirma (paid amount, sent flag, draft flag, KSeF state, linked correction, disappearance) by polling on demand, opportunistically, and in bulk via a cron endpoint, within iFirma's rate limits.

## Requirements

### Requirement: Remote fields refreshed from list rows
A sync SHALL read the document's row from `faktury.json` for its issue date and store `Zaplacono` as remote paid amount, `CzyWyslano` as remote sent, `CzySzkic` as remote draft, `KsefStatus` and `KsefNumer`, `NumerPowiazanegoPrzychodu` as linked document, and the sync timestamp. It SHALL NOT call the per-document detail endpoint.

#### Scenario: Row found
- **WHEN** a sync runs and the list for the issue date contains the document
- **THEN** all listed remote fields and the sync timestamp are stored
- **AND** no per-document detail request is made

#### Scenario: Linked correction appears
- **WHEN** the row carries a non-empty `NumerPowiazanegoPrzychodu`
- **THEN** the linked document number is stored and shown in the admin panel

### Requirement: Paid transition
When the remote paid amount is at least the document gross minus the configured tolerance, the receipt status SHALL become "paid"; a lower amount SHALL leave the status unchanged while the remote paid amount is still shown.

#### Scenario: Fully paid
- **WHEN** `Zaplacono` equals `Brutto`
- **THEN** the receipt status becomes "paid"

#### Scenario: Within tolerance
- **WHEN** `Zaplacono` is lower than `Brutto` by no more than the tolerance
- **THEN** the receipt status becomes "paid"

#### Scenario: Partially paid
- **WHEN** `Zaplacono` is lower than `Brutto` minus the tolerance
- **THEN** the status is unchanged and the remote paid amount is displayed

### Requirement: Missing-document detection
When the list for the document's issue date no longer contains its iFirma id, the module SHALL flag the receipt as missing remotely, keep the historical data, and show a warning to the admin and a neutral "no longer available, contact the shop" message to the customer.

#### Scenario: Document deleted in iFirma
- **WHEN** a sync completes and the iFirma id is absent from that day's list
- **THEN** the receipt is flagged missing and its stored number and data are kept
- **AND** the admin sees a warning and the customer sees the neutral message

#### Scenario: Document reappears
- **WHEN** a later sync finds the row again
- **THEN** the missing flag is cleared

### Requirement: On-demand admin refresh
The admin order panel SHALL offer "Refresh from iFirma" that performs one list call and shows the updated remote fields or the error.

#### Scenario: Successful refresh
- **WHEN** the admin clicks "Refresh from iFirma"
- **THEN** exactly one list call is made and the updated remote fields are shown

#### Scenario: Refresh fails
- **WHEN** the list call fails
- **THEN** the admin sees the error and the previously stored state is kept

### Requirement: Opportunistic customer sync
When the owning customer opens the order detail page and the last sync is older than the configured interval (default 15 minutes), the module SHALL perform at most one list call with a short timeout, swallow failures as a recorded sync error, and render the page with the last known state.

#### Scenario: Stale state
- **WHEN** the owner opens the page and the last sync is 20 minutes old
- **THEN** one list call is made and the page shows the refreshed state

#### Scenario: Recent sync
- **WHEN** the last sync is 5 minutes old
- **THEN** no list call is made

#### Scenario: Timeout
- **WHEN** the list call times out
- **THEN** the page renders with the last known state and no error is shown to the customer

### Requirement: Cron endpoint authentication
A module front endpoint SHALL run a bulk sync only when called with the correct secret token, which is generated at install or upgrade and shown on the configuration page, given either as the `token` query parameter or as the `X-Ifirma-Token` request header; the comparison SHALL be constant-time, an empty configured token SHALL never match, and a wrong or missing token SHALL return HTTP 403 and do nothing.

#### Scenario: Correct token
- **WHEN** the endpoint is called with the configured token
- **THEN** the bulk sync runs and returns HTTP 200

#### Scenario: Wrong or missing token
- **WHEN** the token is wrong or absent
- **THEN** the response is HTTP 403 and no iFirma request is made

#### Scenario: Token in the header
- **WHEN** the endpoint is called without a query token and with the configured token in `X-Ifirma-Token`
- **THEN** the bulk sync runs and returns HTTP 200

#### Scenario: Empty configured token
- **WHEN** the configured token is empty and the endpoint is called with an empty token
- **THEN** the response is HTTP 403

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

### Requirement: Sync errors are non-destructive
A failed sync SHALL record the error and timestamp but SHALL NOT change the receipt status or delivery state; a "rate limited" result SHALL be treated as transient and not shown as a document error.

#### Scenario: iFirma returns an error
- **WHEN** the list call returns a non-zero `Kod`
- **THEN** the sync error and time are recorded
- **AND** the receipt status and delivery state are unchanged

#### Scenario: Rate limited
- **WHEN** the request is refused because of the rate limit
- **THEN** no document error is shown and the receipt is retried on a later run

### Requirement: Register payment remains available
The admin SHALL still be able to register the order's paid amount in iFirma for an issued document, and the next sync SHALL reflect the new `Zaplacono`.

#### Scenario: Payment registered then synced
- **WHEN** the admin registers payment and a sync follows
- **THEN** the remote paid amount shown equals the registered amount

### Requirement: Cron token generation
The cron token SHALL come from the cryptographically secure random generator only; when that generator is unavailable, install, upgrade and regeneration SHALL fail with a message instead of storing a weaker token.

#### Scenario: Regeneration
- **WHEN** the administrator regenerates the cron token
- **THEN** the new token is 32 hexadecimal characters from the secure generator

#### Scenario: Generator unavailable
- **WHEN** the secure generator throws during regeneration
- **THEN** the page shows an error, the previous token stays in place and no weaker token is stored

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
