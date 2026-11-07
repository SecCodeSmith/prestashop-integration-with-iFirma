# Spec Delta

## MODIFIED Requirements

### Requirement: iFirma column on the Orders list
The back-office Orders list SHALL show an "iFirma" column on every supported PrestaShop version, including installations upgraded from an earlier module version, to employees with the module's view level; for other employees the column SHALL NOT be added. The column SHALL be built from the module's local records only; listing orders SHALL NOT call the iFirma API.

#### Scenario: Column shown on a fresh install
- **WHEN** an employee with the view level opens the Orders list on a shop where the module was just installed
- **THEN** the list has an "iFirma" column

#### Scenario: Column shown after upgrade
- **WHEN** the module is upgraded from 1.3.2 and an employee with the view level opens the Orders list
- **THEN** the "iFirma" column is shown without reinstalling the module

#### Scenario: No API call
- **WHEN** the Orders list is rendered with 50 orders, 20 of them with an issued document
- **THEN** no request is sent to iFirma

#### Scenario: Column hidden without view
- **WHEN** an employee without the view level opens the Orders list
- **THEN** the list has no "iFirma" column

### Requirement: Empty cell without an issued document
For an order with no module record, or whose latest record is not issued (no iFirma identifier, or state `error`), the column SHALL show an **Issue** action to an employee with the issue level, whose tooltip names the last recorded state when a record exists, and SHALL be empty for every other employee.

#### Scenario: Order without a record
- **WHEN** order 43 has no module record and the employee has the issue level
- **THEN** the "iFirma" cell of order 43 shows the Issue action

#### Scenario: Order without a record, view only
- **WHEN** order 43 has no module record and the employee has the view level only
- **THEN** the "iFirma" cell of order 43 is empty

#### Scenario: Failed issuance
- **WHEN** order 43 has one record in state `error` without an iFirma identifier and the employee has the issue level
- **THEN** the cell shows the Issue action and its tooltip names the failed state

#### Scenario: Held order
- **WHEN** order 43 has one record in state `held` without an iFirma identifier and the employee has the issue level
- **THEN** the cell shows the Issue action and its tooltip names the held state

### Requirement: Column behaviour in the list
The "iFirma" column SHALL NOT be sortable or filterable, SHALL NOT change the number of orders listed or the paging, and clicking its cell outside the Issue action SHALL open the order page like the rest of the row. Values shown in the cell SHALL be escaped.

#### Scenario: Paging unchanged
- **WHEN** the shop has 120 orders and the list shows 50 per page
- **THEN** the list still reports 120 orders over 3 pages with the column present

#### Scenario: Cell click opens the order
- **WHEN** the employee clicks the "iFirma" cell of order 42 outside the Issue action
- **THEN** the order page of order 42 opens

#### Scenario: Markup in a document number
- **WHEN** the stored number of a document is `<b>FP 1</b>`
- **THEN** the tooltip shows it as text and no markup is rendered

## ADDED Requirements

### Requirement: Issue action from the list
Clicking the Issue action SHALL ask for confirmation and then run the identical issuance pipeline for that order as the order panel's Create action; the outcome message SHALL be shown on the list page and the cell SHALL show the receipt icon when a document was issued or adopted, without leaving the list.

#### Scenario: Issue from the list
- **WHEN** the employee confirms the Issue action of order 43 and the pipeline issues `FP 8/2026`
- **THEN** the list shows the message with the number and the cell of order 43 shows the receipt icon

#### Scenario: Guard stops the run
- **WHEN** the guard of order 43 finds unconfirmed candidates
- **THEN** the list shows the guard message and the cell keeps the Issue action with the suspected state in its tooltip

#### Scenario: Server refuses without the issue level
- **WHEN** the Issue request is posted by an employee without the issue level
- **THEN** it is refused with a permission message and nothing is issued

### Requirement: Bulk issue from the Orders list
The Orders list SHALL offer the bulk action **Issue iFirma documents** to employees with the issue level; it SHALL run the identical pipeline once for each selected order without an issued document, report selected orders that already have one as skipped, proceed in batches, stop when the rate guard refuses with the remaining orders reported as not attempted, and end with a per-order summary.

#### Scenario: Bulk issue
- **WHEN** the employee selects six orders, two of them with an issued document, and runs the bulk action
- **THEN** the pipeline runs for the four others, the two are reported as skipped, and the summary lists all six

#### Scenario: Guard refuses mid-run
- **WHEN** the rate guard refuses during the third of ten selected orders
- **THEN** two outcomes are reported, the third order keeps its recorded state, and it and the remaining seven are reported as not attempted

#### Scenario: Bulk action hidden without the issue level
- **WHEN** an employee with the view level only opens the Orders list
- **THEN** the bulk menu has no iFirma entry
