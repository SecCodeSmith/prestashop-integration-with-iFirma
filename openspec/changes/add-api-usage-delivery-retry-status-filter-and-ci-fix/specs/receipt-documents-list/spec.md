# Spec Delta

## ADDED Requirements

### Requirement: State column sorts by action rank
Sorting the *iFirma documents* list by its state column SHALL order the records as the Orders list orders its "iFirma" column. Ascending: issuance failed, unknown outcome, suspected duplicate, held, queued, issued and not sent, waiting for KSeF, sent, paid. Descending SHALL reverse it. Records in the same state SHALL be listed newest first in both directions. Each record SHALL be ranked by itself.

#### Scenario: Ascending puts what needs action first
- **GIVEN** the list holds a paid record, a sent record, a queued record and a failed record
- **WHEN** the employee sorts the state column ascending
- **THEN** the records are listed failed, queued, sent, paid

#### Scenario: Descending puts paid first
- **WHEN** the employee sorts the same list descending
- **THEN** the records are listed paid, sent, queued, failed

#### Scenario: Not alphabetical
- **GIVEN** a record in state `created` that was issued and not sent, and a record in state `error`
- **WHEN** the employee sorts the state column ascending
- **THEN** the `error` record is listed before the `created` record

#### Scenario: Same state, newest first
- **GIVEN** records 40 and 41 are both sent and unpaid
- **WHEN** the state column is sorted in either direction
- **THEN** record 41 is listed before record 40

#### Scenario: Older record of an order keeps its own rank
- **GIVEN** an order with an older failed record and a newer paid record
- **WHEN** the employee sorts the state column ascending
- **THEN** the failed record is listed among the failed records and the paid record among the paid ones

### Requirement: Sorting keeps filters, paging and the default order
Sorting by state SHALL keep the active filters, the Needs attention preset, the record count and the paging. Without a chosen sort the list SHALL stay newest first. Sorting SHALL send no request to iFirma, and a sort direction other than ascending or descending SHALL sort ascending without reaching the SQL text.

#### Scenario: Sort inside a filter
- **GIVEN** the delivery filter is on `failed` and matches 12 records
- **WHEN** the employee sorts the state column
- **THEN** the same 12 records are listed in rank order

#### Scenario: Paging unchanged
- **GIVEN** 300 records at 50 per page
- **WHEN** the employee sorts the state column
- **THEN** the list still reports 300 records over 6 pages

#### Scenario: Default order
- **WHEN** an employee opens the list without choosing a sort
- **THEN** the records are listed newest first

#### Scenario: Unexpected direction
- **WHEN** the sort request names the state column with the direction `sideways; DROP TABLE`
- **THEN** the list is sorted ascending and the value appears nowhere in the query

#### Scenario: No iFirma request
- **WHEN** the employee sorts the list by the state column
- **THEN** no request is sent to iFirma

### Requirement: Delivery retry shown in the list
The delivery cell of a record whose delivery failed SHALL show how many of the three automatic attempts were used and when the next one is due, or that none remains. The text SHALL be escaped.

#### Scenario: Retry scheduled
- **GIVEN** a record whose first delivery attempt failed and whose second is due at 10:15
- **WHEN** the employee opens the list
- **THEN** the delivery cell shows failed, attempt 1 of 3 and the time 10:15

#### Scenario: No attempt remains
- **WHEN** the third automatic attempt of a record has failed
- **THEN** the delivery cell shows failed and that no automatic attempt remains

#### Scenario: Failure from before the upgrade
- **GIVEN** a record whose delivery failed before the automatic retry existed
- **WHEN** the employee opens the list
- **THEN** the delivery cell shows failed and that no automatic attempt is scheduled
