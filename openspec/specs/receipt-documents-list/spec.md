# receipt-documents-list Specification

## Purpose
The back-office list of the module's document records (*Orders > iFirma documents*), where the merchant finds documents by state and repairs failed ones one at a time or in bulk, without opening each order.

## Requirements

### Requirement: Documents list page
The module SHALL offer a list page *iFirma documents* in the *Orders* menu for employees with the view level, one row per module record, newest first, showing the order reference, the customer, the document number or identifier, the state, the source, the delivery state, the KSeF state, the issue date, the last error (shortened) and the last update. The page SHALL read local records only and SHALL NOT call iFirma to list.

#### Scenario: Page lists records
- **WHEN** an employee with the view level opens *Orders > iFirma documents* on a shop with 300 records
- **THEN** the records are listed newest first with paging and no request is sent to iFirma

#### Scenario: Failed record shown
- **WHEN** a record is in state `error` with a last error text
- **THEN** its row shows the state and the shortened error text

### Requirement: Filters
The list SHALL filter on state, delivery state, KSeF state, order reference, document number and the issue-date range, and SHALL offer a preset **Needs attention** that selects records in `error`, `unknown` or `duplicate_suspected`, records `held` while the order still has a pending data-change request, records with delivery `failed`, with a failed KSeF submission or marked missing in iFirma (the same conditions as the notification tab).

#### Scenario: Filter by state
- **WHEN** the employee filters the state on `error`
- **THEN** only records in state `error` are listed

#### Scenario: Needs-attention preset
- **WHEN** the employee opens the Needs attention preset
- **THEN** the list shows every record matching one of the preset conditions and no issued, delivered record without a failure

### Requirement: Row actions
Each row SHALL offer: open the order; **Retry issuance** for a record that is not issued (issue level); **Send again**, **Refresh from iFirma** and **Download PDF** for an issued record (manage level for the first two, view level for the download). A retry SHALL run the identical issuance pipeline as the order panel's Create action, never bypassing the duplicate guard.

#### Scenario: Retry a failed record
- **WHEN** an employee with the issue level retries a record in state `error`
- **THEN** the pipeline runs with hold, guard, create, number, KSeF and delivery, and the row shows the new state

#### Scenario: Retry a held record
- **WHEN** the record is `held` and the order still has a pending data-change request
- **THEN** the retry stops at the hold step and the message names the request

#### Scenario: Actions hidden without the level
- **WHEN** an employee with the view level only opens the list
- **THEN** rows offer only open the order and Download PDF

### Requirement: Bulk retry issuance
The bulk action **Retry issuance** SHALL run the identical issuance pipeline once for each selected record that is not issued, in record order, and SHALL report a selected issued record as skipped. It SHALL never bypass the duplicate guard, so a record whose guard finds the document adopts it and a record whose guard finds unconfirmed candidates stays suspected.

#### Scenario: Mixed selection
- **WHEN** the selection holds two `error` records, one `unknown` record and one issued record
- **THEN** the three not-issued records are run through the pipeline, the issued one is reported as skipped, and the summary lists each outcome

#### Scenario: Unknown outcome adopts
- **WHEN** the guard of an `unknown` record finds the document iFirma created
- **THEN** the record is adopted and no new document is created

#### Scenario: Suspected stays suspected
- **WHEN** the guard of a record finds unconfirmed candidates
- **THEN** the record is reported as suspected and is not issued

### Requirement: Bulk resend
The bulk action **Send again** SHALL deliver each selected issued record through the configured channel and update its delivery state, SHALL report a not-issued record as skipped, and SHALL send nothing and report every record as "delivery disabled" when the channel is none.

#### Scenario: Resend failed deliveries
- **WHEN** the employee selects five issued records with delivery `failed` and the mail system works
- **THEN** five e-mails are sent and each record shows delivery `sent` with a new timestamp

#### Scenario: Channel none
- **WHEN** the channel is none
- **THEN** no e-mail or iFirma send call is made and each record is reported as delivery disabled

### Requirement: Bulk refresh
The bulk action **Refresh from iFirma** SHALL refresh the selected issued records grouped by issue date, with one list call per date, and SHALL apply the same non-destructive rules as the cron sync.

#### Scenario: Two issue dates
- **WHEN** ten selected records span two issue dates
- **THEN** two list calls are made and every record's remote fields are refreshed

### Requirement: Bulk runs in batches under the rate guard
A bulk run SHALL process the selection in batches, continuing automatically until every selected record was attempted, and SHALL stop when the module's per-minute rate guard refuses a request: the remaining records SHALL be reported as "not attempted (rate limited)" with a hint to run again in a minute, and none of them SHALL be marked as an error.

#### Scenario: Guard refuses mid-run
- **WHEN** the guard refuses during the retry of the 12th of 40 selected records
- **THEN** the first 11 outcomes are reported, the 12th record keeps its recorded state, and it and the remaining 28 are reported as not attempted

#### Scenario: Batch continuation
- **WHEN** 60 records are selected for a resend
- **THEN** the run proceeds batch after batch without further clicks until every record was attempted or the guard refused

### Requirement: Run summary and audit entry
When a bulk run ends the page SHALL show the per-record outcome (issued, adopted, suspected, failed, sent, skipped, not attempted) with the message of each record and the totals, SHALL reload the list, and SHALL write one PrestaShop log entry naming the employee, the action and the totals.

#### Scenario: Summary after a run
- **WHEN** a bulk retry of 8 records ends with 5 issued, 1 adopted, 1 suspected and 1 failed
- **THEN** the page lists the eight outcomes with their messages and the totals, and one log entry records the employee, "retry issuance" and the totals

### Requirement: Permission on bulk actions
Each bulk action SHALL require the level of its single-row counterpart (issue for Retry issuance, manage for Send again and Refresh), checked by the server for the whole run; a refused run SHALL change nothing.

#### Scenario: Bulk resend without manage
- **WHEN** an employee with the issue level only posts a bulk Send again from a script
- **THEN** the response is HTTP 403 with a permission message and no e-mail is sent
