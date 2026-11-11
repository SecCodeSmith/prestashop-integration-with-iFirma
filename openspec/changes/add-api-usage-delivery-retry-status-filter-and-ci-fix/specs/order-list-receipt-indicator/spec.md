# Spec Delta

## MODIFIED Requirements

### Requirement: Column behaviour in the list
The "iFirma" column SHALL be sortable and SHALL be filterable by document state. Without a filter value it SHALL NOT change the number of orders listed or the paging, and clicking its cell outside the Issue action SHALL open the order page like the rest of the row. Values shown in the cell and its tooltip SHALL be escaped.

#### Scenario: Paging unchanged
- **WHEN** the shop has 120 orders and the list shows 50 per page
- **THEN** the list still reports 120 orders over 3 pages with the column present, sorted by the column or not

#### Scenario: Cell click opens the order
- **WHEN** the employee clicks the "iFirma" cell of order 42 outside the Issue action
- **THEN** the order page of order 42 opens

#### Scenario: Markup in a document number
- **WHEN** the stored number of a document is `<b>FP 1</b>`
- **THEN** the tooltip shows it as text and no markup is rendered

#### Scenario: Markup in a stored error
- **WHEN** the stored error of a failed record contains `"><script>alert(1)</script>`
- **THEN** the tooltip shows it as text and the browser executes nothing

## ADDED Requirements

### Requirement: Filtering by document state
The "iFirma" column SHALL offer a filter with one choice per state of the sort order: issuance failed, unknown outcome, suspected duplicate, held, queued, no document, issued and not sent, waiting for KSeF, sent, paid. A chosen state SHALL list exactly the orders the sort puts in that state, judged by their newest module record. The filter SHALL use local records only.

#### Scenario: Only failed orders
- **GIVEN** the list holds a paid order, a sent order, an order without a record, a queued order and a failed order
- **WHEN** the employee filters the "iFirma" column on "issuance failed"
- **THEN** only the failed order is listed

#### Scenario: Orders without a document
- **WHEN** the employee filters on "no document"
- **THEN** the orders without a module record are listed, and no order with an issued document

#### Scenario: Failed delivery counts as issued and not sent
- **GIVEN** an order whose invoice was issued and whose delivery failed
- **WHEN** the employee filters on "issued and not sent"
- **THEN** that order is listed

#### Scenario: Newest record decides
- **GIVEN** an order whose older record failed and whose newest record is a paid invoice
- **WHEN** the employee filters on "issuance failed"
- **THEN** that order is not listed

#### Scenario: Filtering makes no iFirma request
- **WHEN** the employee filters the list by the "iFirma" column
- **THEN** no request is sent to iFirma

### Requirement: Filter and paging agree
With a state filter, the order count and the paging SHALL cover the filtered orders only. The filter SHALL combine with the other filters of the list and with sorting by any column. Clearing the filter SHALL restore the full list.

#### Scenario: Count follows the filter
- **GIVEN** the shop has 120 orders and 7 of them failed
- **WHEN** the employee filters on "issuance failed"
- **THEN** the list reports 7 orders on one page

#### Scenario: Combined with another filter
- **GIVEN** 7 failed orders, 2 of them of customer "Nowak"
- **WHEN** the employee filters on "issuance failed" and on customer "Nowak"
- **THEN** 2 orders are listed

#### Scenario: Filter and sort together
- **WHEN** the employee filters on "sent" and sorts by the "iFirma" column
- **THEN** the sent orders are listed newest first

#### Scenario: Filter cleared
- **WHEN** the employee resets the filters
- **THEN** all 120 orders are listed again

### Requirement: Unexpected filter values are ignored
A filter value that is not one of the offered states SHALL NOT filter the list and SHALL NOT reach the SQL text.

#### Scenario: Unknown state
- **WHEN** the filter request names the state `refunded`
- **THEN** the list is shown unfiltered

#### Scenario: Injection attempt
- **WHEN** the filter request carries `1 OR 1=1` or `'; DROP TABLE ps_orders; --`
- **THEN** the list is shown unfiltered and the value appears nowhere in the query

#### Scenario: Value of the wrong shape
- **WHEN** the filter request carries a list instead of one value
- **THEN** the list is shown unfiltered and no error page appears
