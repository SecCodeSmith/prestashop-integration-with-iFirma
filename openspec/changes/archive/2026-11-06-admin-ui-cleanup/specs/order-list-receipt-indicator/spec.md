# Spec Delta

## Purpose

Shows the merchant, on the back-office Orders list, which orders already have an issued iFirma document, so they do not have to open each order to find out.

## ADDED Requirements

### Requirement: iFirma column on the Orders list
The back-office Orders list SHALL show an "iFirma" column on every supported PrestaShop version, including installations upgraded from an earlier module version. The column SHALL be built from the module's local records only; listing orders SHALL NOT call the iFirma API.

#### Scenario: Column shown on a fresh install
- **WHEN** an employee opens the Orders list on a shop where the module was just installed
- **THEN** the list has an "iFirma" column

#### Scenario: Column shown after upgrade
- **WHEN** the module is upgraded from 1.3.2 and an employee opens the Orders list
- **THEN** the "iFirma" column is shown without reinstalling the module

#### Scenario: No API call
- **WHEN** the Orders list is rendered with 50 orders, 20 of them with an issued document
- **THEN** no request is sent to iFirma

### Requirement: Receipt icon for an issued document
For an order whose latest module record is an issued document (it carries an iFirma identifier and is not in the `error` state), the column SHALL show a receipt icon. The tooltip of the icon SHALL carry the document number (or the iFirma identifier when no number is stored) and the document state.

#### Scenario: Issued receipt
- **WHEN** order 42 has a record with iFirma identifier `123456`, number `FP 7/2026` and state `created`
- **THEN** the "iFirma" cell of order 42 shows the receipt icon and its tooltip reads the number `FP 7/2026` and the state "Issued"

#### Scenario: Paid document
- **WHEN** order 42 has an issued document in state `paid`
- **THEN** the cell shows the receipt icon and its tooltip reads the number and the state "Paid"

#### Scenario: Number not stored yet
- **WHEN** order 42 has an issued document with iFirma identifier `123456` and an empty number
- **THEN** the tooltip reads the identifier `123456`

#### Scenario: Latest record wins
- **WHEN** order 42 has an older record in `error` and a newer issued record
- **THEN** the cell shows the receipt icon for the newer record

### Requirement: Empty cell without an issued document
For an order with no module record, or whose latest record is not issued (no iFirma identifier, or state `error`), the column SHALL be empty.

#### Scenario: Order without a record
- **WHEN** order 43 has no module record
- **THEN** the "iFirma" cell of order 43 is empty

#### Scenario: Failed issuance
- **WHEN** order 43 has one record in state `error` without an iFirma identifier
- **THEN** the "iFirma" cell of order 43 is empty

#### Scenario: Held order
- **WHEN** order 43 has one record in state `held` without an iFirma identifier
- **THEN** the "iFirma" cell of order 43 is empty

### Requirement: Column behaviour in the list
The "iFirma" column SHALL NOT be sortable or filterable, SHALL NOT change the number of orders listed or the paging, and clicking its cell SHALL open the order page like the rest of the row. Values shown in the cell SHALL be escaped.

#### Scenario: Paging unchanged
- **WHEN** the shop has 120 orders and the list shows 50 per page
- **THEN** the list still reports 120 orders over 3 pages with the column present

#### Scenario: Cell click opens the order
- **WHEN** the employee clicks the "iFirma" cell of order 42
- **THEN** the order page of order 42 opens

#### Scenario: Markup in a document number
- **WHEN** the stored number of a document is `<b>FP 1</b>`
- **THEN** the tooltip shows it as text and no markup is rendered
