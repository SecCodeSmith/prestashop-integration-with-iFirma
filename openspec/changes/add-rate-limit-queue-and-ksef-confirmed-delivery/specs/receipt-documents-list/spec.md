# Spec Delta

## MODIFIED Requirements

### Requirement: Filters
The list SHALL filter on state, delivery state, KSeF state, order reference, document number and the issue-date range, and SHALL offer a preset **Needs attention** that selects records in `error`, `unknown` or `duplicate_suspected`, records `held` while the order still has a pending data-change request, records `queued` for more than 30 minutes, records with delivery `failed`, with a failed or rejected KSeF submission, awaiting KSeF acceptance for more than 48 hours, or marked missing in iFirma (the same conditions as the notification tab). The state filter SHALL offer `queued` and the delivery filter SHALL offer `awaiting_ksef`.

#### Scenario: Filter by state
- **WHEN** the employee filters the state on `error`
- **THEN** only records in state `error` are listed

#### Scenario: Needs-attention preset
- **WHEN** the employee opens the Needs attention preset
- **THEN** the list shows every record matching one of the preset conditions and no issued, delivered record without a failure

#### Scenario: Filter by awaiting KSeF
- **WHEN** the employee filters the delivery state on `awaiting_ksef`
- **THEN** only records whose delivery waits for KSeF acceptance are listed

#### Scenario: Long KSeF wait needs attention
- **WHEN** a record has been awaiting KSeF acceptance for 49 hours
- **THEN** the Needs attention preset lists it

### Requirement: Bulk runs in batches under the rate guard
A bulk run SHALL process the selection in batches, continuing automatically until every selected record was attempted, and SHALL stop when the module's rate guard (minute cap, day cap or iFirma back-off) refuses a request. The remaining records SHALL be reported as "not attempted (rate limited)" with a hint that names the refusing window (run again in a minute, or tomorrow), and none of them SHALL be marked as an error. In a retry-issuance run the refused record SHALL be queued; in a resend or refresh run it SHALL keep its recorded state.

#### Scenario: Guard refuses mid-run
- **WHEN** the guard refuses during the retry of the 12th of 40 selected records
- **THEN** the first 11 outcomes are reported, the 12th record is queued, and it and the remaining 28 are reported as not attempted

#### Scenario: Batch continuation
- **WHEN** 60 records are selected for a resend
- **THEN** the run proceeds batch after batch without further clicks until every record was attempted or the guard refused

#### Scenario: Day cap hint
- **WHEN** the day cap refuses during a resend run
- **THEN** the remaining records are reported as not attempted with the hint to run again tomorrow, and the refused record keeps its recorded state
