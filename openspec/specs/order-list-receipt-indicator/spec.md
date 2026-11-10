# order-list-receipt-indicator Specification

## Purpose
Shows the merchant, on the back-office Orders list, which orders already have an issued iFirma document, so they do not have to open each order to find out.

## Requirements

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

### Requirement: Receipt icon for an issued document
For an order whose latest module record is an issued document (it carries an iFirma identifier and is not in the `error` state), the column SHALL show a receipt icon, green when the document is paid and blue otherwise. The tooltip of the icon SHALL start with a headline that names the document kind (invoice or receipt), its furthest state (paid, sent, waiting for KSeF, issued) and the document number (or the iFirma identifier when no number is stored). Then it SHALL list one line for the delivery, one line "unpaid" when the document is not paid, and one line for KSeF when the document has a KSeF state or waits for KSeF acceptance. The words SHALL agree with the document kind in the employee's language.

#### Scenario: Issued receipt
- **WHEN** order 42 has a record of type `paragon` with iFirma identifier `123456`, number `7/2026`, state `created` and delivery `none`, and the employee's language is Polish
- **THEN** the "iFirma" cell of order 42 shows the receipt icon and its tooltip reads "Paragon wystawiony: 7/2026", then "Jeszcze nie wysłany", then "Nieopłacony"

#### Scenario: Sent invoice
- **WHEN** order 42 has a domestic invoice `FV 12/2026` in state `created`, delivered to the customer on 2026-11-09, and the employee's language is Polish
- **THEN** the tooltip reads "Faktura wysłana: FV 12/2026", then "Wysłana do klienta: 2026-11-09", then "Nieopłacona"

#### Scenario: Paid document
- **WHEN** order 42 has an issued invoice in state `paid` that was delivered on 2026-11-09, and the employee's language is Polish
- **THEN** the cell shows the green receipt icon and its tooltip reads "Faktura opłacona: <number>", then "Wysłana do klienta: 2026-11-09", with no "unpaid" line

#### Scenario: Invoice waiting for KSeF
- **WHEN** order 42 has an issued invoice with delivery `awaiting_ksef` and `ksef_status` `WYSLANA`, and the employee's language is Polish
- **THEN** the tooltip headline reads "Faktura czeka na KSeF: <number>" and a line reads "KSeF: wysłana, czeka na potwierdzenie"

#### Scenario: Accepted in KSeF
- **WHEN** the invoice of order 42 has the KSeF number `1234567890-20261109-ABCDEF123456-01`
- **THEN** a tooltip line reads "KSeF: accepted (1234567890-20261109-ABCDEF123456-01)" in English, or "KSeF: przyjęta (…)" in Polish

#### Scenario: Failed delivery
- **WHEN** the issued invoice of order 42 has delivery `failed`
- **THEN** the headline reads the issued state and a line reads "Delivery failed" ("Wysyłka nieudana" in Polish)

#### Scenario: English wording
- **WHEN** the employee's language is English and order 42 has a sent, unpaid invoice `FV 12/2026`
- **THEN** the tooltip reads "Invoice sent: FV 12/2026", then "Sent to the customer: <date>", then "Unpaid"

#### Scenario: Number not stored yet
- **WHEN** order 42 has an issued document with iFirma identifier `123456` and an empty number
- **THEN** the tooltip headline carries the identifier `123456`

#### Scenario: Latest record wins
- **WHEN** order 42 has an older record in `error` and a newer issued record
- **THEN** the cell shows the receipt icon for the newer record

### Requirement: Empty cell without an issued document
For an order with no module record, or whose latest record is not issued (no iFirma identifier, or state `error`), the column SHALL show an **Issue** action to an employee with the issue level, and SHALL be empty for every other employee. The tooltip SHALL name the last recorded state in words, followed by the hint that a click issues the document. For a failed issuance it SHALL add the first 100 characters of the stored error. Without a record it SHALL say that there is no document yet.

#### Scenario: Order without a record
- **WHEN** order 43 has no module record and the employee has the issue level
- **THEN** the "iFirma" cell of order 43 shows the Issue action and its tooltip reads "No document yet – click to issue" ("Brak dokumentu – kliknij, aby wystawić" in Polish)

#### Scenario: Order without a record, view only
- **WHEN** order 43 has no module record and the employee has the view level only
- **THEN** the "iFirma" cell of order 43 is empty

#### Scenario: Failed issuance
- **WHEN** order 43 has one record in state `error` without an iFirma identifier, with the stored error "Kontrahent nie istnieje", and the employee has the issue level
- **THEN** the cell shows the Issue action and its tooltip reads "Issuance failed: Kontrahent nie istnieje – click to issue"

#### Scenario: Long error shortened
- **WHEN** the stored error of the failed record is 300 characters long
- **THEN** the tooltip carries its first 100 characters followed by an ellipsis

#### Scenario: Held order
- **WHEN** order 43 has one record in state `held` without an iFirma identifier and the employee has the issue level
- **THEN** the cell shows the Issue action and its tooltip names the held state ("Wstrzymana (wniosek o zmianę danych)" in Polish)

#### Scenario: Queued order in the column
- **WHEN** order 43 has one record in state `queued` with a rate-limit refusal message and the employee has the issue level
- **THEN** the cell shows the Issue action and its tooltip reads "Queued (iFirma limit) – click to issue" ("W kolejce (limit iFirma) – kliknij, aby wystawić" in Polish)

#### Scenario: Other not-issued states
- **WHEN** the latest record of order 43 is `unknown` or `duplicate_suspected` and the employee has the issue level
- **THEN** the tooltip reads "Unknown outcome" or "Suspected duplicate" followed by the click hint

### Requirement: Column behaviour in the list
The "iFirma" column SHALL be sortable and SHALL NOT be filterable. It SHALL NOT change the number of orders listed or the paging, and clicking its cell outside the Issue action SHALL open the order page like the rest of the row. Values shown in the cell and its tooltip SHALL be escaped.

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
The Orders list SHALL offer the bulk action **Issue iFirma documents** to employees with the issue level; it SHALL run the identical pipeline once for each selected order without an issued document, report selected orders that already have one as skipped, proceed in batches, stop when the rate guard refuses with the refused order queued and the remaining orders reported as not attempted, and end with a per-order summary.

#### Scenario: Bulk issue
- **WHEN** the employee selects six orders, two of them with an issued document, and runs the bulk action
- **THEN** the pipeline runs for the four others, the two are reported as skipped, and the summary lists all six

#### Scenario: Guard refuses mid-run
- **WHEN** the rate guard refuses during the third of ten selected orders
- **THEN** two outcomes are reported, the third order is queued, and it and the remaining seven are reported as not attempted

#### Scenario: Bulk action hidden without the issue level
- **WHEN** an employee with the view level only opens the Orders list
- **THEN** the bulk menu has no iFirma entry

### Requirement: Sorting by document state
Sorting the Orders list by the "iFirma" column SHALL order the orders by the state of their newest module record. Ascending order: issuance failed, unknown outcome, suspected duplicate, held, queued, no record, issued and not sent (including a failed delivery), waiting for KSeF, sent, paid. Descending SHALL reverse that order. Orders in the same state SHALL be listed newest order first in both directions. The sort SHALL use local records only.

#### Scenario: Ascending puts what needs action first
- **WHEN** the list holds a paid order, a sent order, an order without a record, a queued order and a failed order, and the employee sorts the "iFirma" column ascending
- **THEN** the orders are listed failed, queued, without a record, sent, paid

#### Scenario: Descending puts paid first
- **WHEN** the employee sorts the same list descending
- **THEN** the orders are listed paid, sent, without a record, queued, failed

#### Scenario: Same state, newest first
- **WHEN** orders 40 and 41 both have a sent, unpaid invoice and the column is sorted in either direction
- **THEN** order 41 is listed before order 40

#### Scenario: Waiting for KSeF between issued and sent
- **WHEN** one order has an issued, unsent invoice, one waits for KSeF and one was sent, and the column is sorted ascending
- **THEN** they are listed issued, waiting for KSeF, sent

#### Scenario: Unexpected sort direction
- **WHEN** the sort request names the "iFirma" column with a direction other than `asc` or `desc`
- **THEN** the list is sorted ascending and the direction value never reaches the SQL text

#### Scenario: Sorting makes no iFirma request
- **WHEN** the employee sorts the list by the "iFirma" column
- **THEN** no request is sent to iFirma
