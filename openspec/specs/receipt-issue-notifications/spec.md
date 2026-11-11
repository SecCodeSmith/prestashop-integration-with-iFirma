# receipt-issue-notifications Specification

## Purpose
The "iFirma" tab of the back-office notification centre, which tells employees about document issues that need their attention on any back-office page, without opening orders.

## Requirements

### Requirement: Issue events
A module record SHALL count as an issue while its current state is one of: issuance failed (`error`), unknown outcome, suspected duplicate, held by a pending data-change request, queued for issuance for more than 30 minutes, delivery failed, KSeF submission failed or rejected (`BLAD` or `ODRZUCONA`), awaiting KSeF acceptance for more than 48 hours after issuance, or document missing in iFirma. A record SHALL leave the list as soon as none of these applies.

#### Scenario: Failed issuance listed
- **WHEN** the pipeline records state `error` for order 42
- **THEN** the tab lists an issue for order 42 naming the failed issuance

#### Scenario: Delivered document not listed
- **WHEN** a record is issued, delivered and has no failed KSeF submission
- **THEN** the tab lists nothing for it

#### Scenario: Repaired record disappears
- **WHEN** the failed record of order 42 is later issued successfully
- **THEN** the issue for order 42 is no longer listed

#### Scenario: Stale queue listed
- **WHEN** order 42 has been queued for 31 minutes
- **THEN** the tab lists an issue for order 42 naming the queued issuance

#### Scenario: Rejected by KSeF listed
- **WHEN** status sync stores `ODRZUCONA` for the invoice of order 42
- **THEN** the tab lists an issue for order 42 naming the KSeF rejection

#### Scenario: Overdue KSeF wait listed
- **WHEN** the invoice of order 42 was issued 49 hours ago and still awaits KSeF acceptance
- **THEN** the tab lists an issue for order 42 naming the overdue KSeF confirmation

#### Scenario: Normal KSeF wait not listed
- **WHEN** the invoice of order 42 was issued 2 hours ago and awaits KSeF acceptance
- **THEN** the tab lists nothing for it

### Requirement: Tab in the notification centre
The back-office header notification centre SHALL get a fourth tab, **iFirma**, on every back-office page that shows the centre, for employees with the view level; employees without it SHALL see no tab and the page SHALL make no issue request.

#### Scenario: Employee with view
- **WHEN** an employee with the view level opens the dashboard and then the Orders list
- **THEN** both pages show the iFirma tab in the notification centre

#### Scenario: Employee without view
- **WHEN** an employee without the view level opens any back-office page
- **THEN** no iFirma tab is shown and no issue request is made

### Requirement: Entries
The tab SHALL list the newest twenty issues, newest first, each with the order reference, the kind of issue, the document number when there is one and the time of the last change; each entry SHALL link to the order page and the tab footer SHALL link to the documents list with the Needs attention preset. Every value SHALL be shown as text.

#### Scenario: Entry content and link
- **WHEN** order `ABCDEFGHI` has a delivery failure on document `FP 7/2026`
- **THEN** the entry reads the reference, "delivery failed", the number and the time, and opens the order page

#### Scenario: Footer link
- **WHEN** the employee clicks the footer link
- **THEN** the documents list opens with the Needs attention preset

#### Scenario: Markup in an error text
- **WHEN** a last error contains `<img src=x onerror=alert(1)>`
- **THEN** the entry shows it as text and no script runs

### Requirement: Unseen counter and per-employee seen mark
The bell counter SHALL include the number of issues that changed after the employee's seen mark; opening the tab SHALL set the mark to now for that employee only.

#### Scenario: New issue raises the counter
- **WHEN** three issues changed after the employee's seen mark
- **THEN** the counter includes 3 for the iFirma tab

#### Scenario: Opening the tab clears the count
- **WHEN** the employee opens the tab
- **THEN** the iFirma part of the counter becomes 0 and the entries stay listed

#### Scenario: Marks are per employee
- **WHEN** employee A opens the tab
- **THEN** employee B's counter is unchanged

### Requirement: Polling and feed access
The tab SHALL refresh at the cadence of the core notification centre, from a module endpoint that reads local records only, requires the module token and the view level, and SHALL never call iFirma.

#### Scenario: No API call
- **WHEN** the tab refreshes ten times
- **THEN** no request is sent to iFirma

#### Scenario: Feed without view
- **WHEN** the feed endpoint is called with a valid module token by an employee without the view level
- **THEN** it answers HTTP 403 and no entry

### Requirement: Fallback without the core centre
When the core notification centre is not found on a page, the module SHALL insert a standalone bell button with the same list and counter next to the header; when no header is found either, nothing SHALL be shown and no script error SHALL occur.

#### Scenario: Centre absent
- **WHEN** the back-office layout has no notification centre markup
- **THEN** a standalone iFirma bell with the same entries and counter appears in the header

#### Scenario: Header absent
- **WHEN** a page has neither the centre nor the header
- **THEN** nothing is inserted and the browser console shows no error from the module
