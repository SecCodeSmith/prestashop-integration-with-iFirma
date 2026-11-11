# receipt-issuance-queue Specification

## Purpose
Recording every order that reaches the trigger status before any iFirma request, and issuing it at once or later from the cron endpoint, so a burst of paid orders, a refused request or a request that dies half-way never loses a document and never turns a transient limit into an error.

## Requirements

### Requirement: Order recorded as queued at the trigger status
When an order reaches the configured trigger status and the module has its iFirma credentials, the module SHALL record the order as `queued` before any iFirma request, unless its record is issued or a data-change request is pending. It SHALL then run the pipeline at once under the per-order lock without waiting. A refused run, a busy lock or an ended request SHALL leave the order queued.

#### Scenario: Queued before the first request
- **WHEN** order 42 reaches the trigger status
- **THEN** a record of order 42 in state `queued` is stored before the first iFirma request of that run is sent

#### Scenario: Issued at once
- **WHEN** order 42 reaches the trigger status and the limits allow the requests
- **THEN** the same status change issues the document and the record is issued, not queued

#### Scenario: Lock busy
- **WHEN** order 42 reaches the trigger status while a cron run holds the lock of order 42
- **THEN** the status change makes no iFirma request and the record stays queued

#### Scenario: Request ends before the create request
- **WHEN** the shop request ends (timeout or fatal error) after order 42 was queued and before its create request was sent
- **THEN** the record of order 42 stays `queued` and a later cron run issues it

#### Scenario: Held order
- **WHEN** order 42 reaches the trigger status while its data-change request is pending
- **THEN** the record is `held`, not `queued`, and no document is created

#### Scenario: Issuance not configured
- **WHEN** the trigger status setting is 0, or the credentials are missing, and an order changes status
- **THEN** no record is written and no iFirma request is sent

#### Scenario: Queued order is not issued twice
- **WHEN** order 42 is queued and its status changes to the trigger status again
- **THEN** the run issues the document once or leaves it queued, and the order never ends with two documents

### Requirement: Rate-limited issuance is queued
When an issuance run on any path (status change, employee Create, bulk retry or issue, cron) is refused by the rate limit (the local minute or day cap, or an iFirma limit answer or back-off) before a document exists, the module SHALL record the order as `queued` with the refusal message, SHALL NOT record `error` or `unknown`, and SHALL NOT create a document in that run.

#### Scenario: Guard check refused at the order status change
- **WHEN** order 42 reaches the trigger status and the duplicate-guard list call is refused by the local minute cap
- **THEN** the record of order 42 is `queued` with the refusal message
- **AND** no document is created and no `error` is recorded

#### Scenario: Create refused by iFirma
- **WHEN** the create request of order 42 is answered with HTTP 429
- **THEN** the record of order 42 is `queued`, not `error` and not `unknown`

#### Scenario: Order that already has a document
- **WHEN** the record of order 42 is already issued and a later run is refused by the rate limit
- **THEN** the record stays issued and is not queued

#### Scenario: Employee Create refused
- **WHEN** an employee clicks Create for order 42 and the run is refused by the day cap
- **THEN** the employee sees the refusal message and the record of order 42 is `queued`

#### Scenario: Queued order fails for another reason
- **WHEN** a queued order's run fails for a reason other than the rate limit, for example a network error during the guard check
- **THEN** the record leaves the queue in the state that failure records for a new order (`error` or `unknown`), so the cron does not retry it blindly

### Requirement: Cron issues queued orders
The cron endpoint SHALL issue queued orders oldest first, each through the full issuance pipeline including the duplicate guard. It SHALL handle at most `IFIRMA_CRON_MAX_ITEMS` orders per run (default 10, allowed 1 to 50) and SHALL stop at the first rate-limit refusal.

#### Scenario: Queue drained in order
- **WHEN** orders 40, 41 and 42 were queued in that order and the cron runs with enough budget
- **THEN** documents are issued for 40, 41 and 42 in that order and none of them stays queued

#### Scenario: Per-run cap
- **WHEN** 25 orders are queued and `IFIRMA_CRON_MAX_ITEMS` is 10
- **THEN** one cron run handles the 10 oldest and leaves 15 queued for the next run

#### Scenario: Refusal ends the drain
- **WHEN** the rate guard refuses during the fourth queued order of a run
- **THEN** the fourth order and every later one stay queued
- **AND** the cron summary reports the run as rate limited

#### Scenario: Document created by an earlier refused request
- **WHEN** a queued order's guard check finds a matching document in iFirma
- **THEN** the document is adopted and no second document is created

#### Scenario: Queued order rejected by iFirma
- **WHEN** the create request of a queued order is answered with a validation error
- **THEN** the record leaves the queue in state `error` with that message, as an automatic issuance would

### Requirement: Order lock and run lock
Every issuance path, the status change included, SHALL issue an order only while holding that order's lock, and the status change SHALL NOT wait for it. Only one cron run SHALL work at a time: a run that starts while another one holds the run lock SHALL make no iFirma request and SHALL report that it was skipped.

#### Scenario: Overlapping cron runs
- **WHEN** a second cron request arrives while the first run is still working
- **THEN** the second answers with a summary that says it was skipped and makes no iFirma request

#### Scenario: Status change during the drain
- **WHEN** the cron is issuing queued order 42 and an employee changes the status of order 42 to the trigger status at the same moment
- **THEN** order 42 ends with exactly one document

### Requirement: Queued state in the back office
The admin order panel SHALL show a queued order as queued, with the refusal message when there is one and a note that the cron endpoint issues it. The employee's Create action SHALL stay available and SHALL run the pipeline at once. A queued record SHALL count as needing attention when its last change is more than 30 minutes old.

#### Scenario: Panel of a queued order
- **WHEN** an employee with the view level opens order 42, which is queued after a refusal
- **THEN** the panel shows the queued state, the refusal message and the note about the cron endpoint

#### Scenario: Manual issue of a queued order
- **WHEN** an employee with the issue level clicks Create on queued order 42 and the limits allow it
- **THEN** the document is issued and the record is no longer queued

#### Scenario: Stale queue needs attention
- **WHEN** order 42 has been queued for 31 minutes because no cron job runs
- **THEN** the record is listed in the notification tab and in the needs-attention preset

#### Scenario: Fresh queue does not need attention
- **WHEN** order 42 was queued 5 minutes ago
- **THEN** the record is not listed as needing attention

### Requirement: Queue setting
The setting `IFIRMA_CRON_MAX_ITEMS` SHALL be installed with the value 10, SHALL be editable on the settings page within 1 to 50, and SHALL be removed on uninstall. An upgrade from an earlier version SHALL add it with the default.

#### Scenario: Default after install
- **WHEN** the module is installed
- **THEN** `IFIRMA_CRON_MAX_ITEMS` is 10

#### Scenario: Out-of-range value refused
- **WHEN** the merchant saves `IFIRMA_CRON_MAX_ITEMS` as 0 or 51
- **THEN** the value is refused and the stored value does not change

#### Scenario: Upgrade adds the setting
- **WHEN** a shop running 1.6.0 is upgraded
- **THEN** `IFIRMA_CRON_MAX_ITEMS` exists with the value 10
