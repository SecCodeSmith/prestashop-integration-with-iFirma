## MODIFIED Requirements

### Requirement: Receipt icon for an issued document
For an order whose latest module record is an issued document (it carries an iFirma identifier and is not in the `error` state), the column SHALL show a receipt icon, green when the document is paid and blue otherwise. The tooltip of the icon SHALL list one fact per line, in this order: the status (the document kind, invoice or receipt, and its furthest state: paid, sent, waiting for KSeF, issued), the number line "Number: <number>" (the iFirma identifier when no number is stored), the issue date line "Issue date: <date>" (the issue date of the document, else the date the record was created; no line when neither exists), one line for KSeF when the document has a KSeF state or waits for KSeF acceptance, one line for the delivery, and one line "unpaid" when the document is not paid. The words SHALL agree with the document kind in the employee's language.

#### Scenario: Issued receipt
- **WHEN** order 42 has a record of type `paragon` with iFirma identifier `123456`, number `7/2026`, issue date 2026-11-03, state `created` and delivery `none`, and the employee's language is Polish
- **THEN** the "iFirma" cell of order 42 shows the receipt icon and its tooltip reads "Paragon wystawiony", then "Numer: 7/2026", then "Data wystawienia: 2026-11-03", then "Jeszcze nie wysłany", then "Nieopłacony"

#### Scenario: Sent invoice
- **WHEN** order 42 has a domestic invoice `FV 12/2026` in state `created`, delivered to the customer on 2026-11-09, and the employee's language is Polish
- **THEN** the tooltip reads "Faktura wysłana", then "Numer: FV 12/2026", then "Wysłana do klienta: 2026-11-09", then "Nieopłacona"

#### Scenario: Invoice with every fact
- **WHEN** order 42 has an invoice `FV 12/2026` issued on 2026-11-02, accepted in KSeF under `1234567890-20261109-ABCDEF123456-01`, delivered on 2026-11-09 and not paid, and the employee's language is English
- **THEN** the tooltip reads "Invoice sent", "Number: FV 12/2026", "Issue date: 2026-11-02", "KSeF: accepted (1234567890-20261109-ABCDEF123456-01)", "Sent to the customer: 2026-11-09" and "Unpaid", one per line, in that order

#### Scenario: Issue date falls back to the record date
- **WHEN** the issued record of order 42 has an empty issue date (`NULL` or `0000-00-00`) and was created on 2026-11-05
- **THEN** the tooltip carries the line "Issue date: 2026-11-05"

#### Scenario: No issue date at all
- **WHEN** the grid record carries no issue date
- **THEN** the tooltip has no issue date line

#### Scenario: Paid document
- **WHEN** order 42 has an issued invoice in state `paid` that was delivered on 2026-11-09, and the employee's language is Polish
- **THEN** the cell shows the green receipt icon and its tooltip reads "Faktura opłacona", then "Numer: <number>", then "Wysłana do klienta: 2026-11-09", with no "unpaid" line

#### Scenario: Invoice waiting for KSeF
- **WHEN** order 42 has an issued invoice with delivery `awaiting_ksef` and `ksef_status` `WYSLANA`, and the employee's language is Polish
- **THEN** the tooltip status reads "Faktura czeka na KSeF" and a line reads "KSeF: wysłana, czeka na potwierdzenie"

#### Scenario: Accepted in KSeF
- **WHEN** the invoice of order 42 has the KSeF number `1234567890-20261109-ABCDEF123456-01`
- **THEN** a tooltip line reads "KSeF: accepted (1234567890-20261109-ABCDEF123456-01)" in English, or "KSeF: przyjęta (…)" in Polish

#### Scenario: No KSeF state
- **WHEN** the issued document of order 42 has no KSeF state and does not wait for KSeF
- **THEN** the tooltip has no KSeF line

#### Scenario: Failed delivery
- **WHEN** the issued invoice of order 42 has delivery `failed`
- **THEN** the status reads the issued state and a line reads "Delivery failed" ("Wysyłka nieudana" in Polish)

#### Scenario: English wording
- **WHEN** the employee's language is English and order 42 has a sent, unpaid invoice `FV 12/2026`
- **THEN** the tooltip reads "Invoice sent", then "Number: FV 12/2026", then "Sent to the customer: <date>", then "Unpaid"

#### Scenario: Number not stored yet
- **WHEN** order 42 has an issued document with iFirma identifier `123456` and an empty number
- **THEN** the number line carries the identifier `123456`

#### Scenario: Latest record wins
- **WHEN** order 42 has an older record in `error` and a newer issued record
- **THEN** the cell shows the receipt icon for the newer record

## ADDED Requirements

### Requirement: Cell tooltip uses the back-office tooltip
The icon of an issued document and the Issue action SHALL carry PrestaShop's tooltip attributes (`data-toggle="pstooltip"`, `data-placement="top"`) so that the tooltip looks like the rest of the back office, and SHALL keep a plain `title` with the same text as a fallback for a page where the back-office script does not run. The tooltip SHALL render its text as text, one line per fact. No customer, order or iFirma value SHALL become markup in any attribute of the cell.

#### Scenario: Issued icon uses the back-office tooltip
- **WHEN** the Orders list shows an order with an issued document
- **THEN** the icon carries `data-toggle="pstooltip"` and `data-placement="top"` and a `title` with the same lines

#### Scenario: Issue action uses the back-office tooltip
- **WHEN** an employee with the issue level sees the Issue action of an order without an issued document
- **THEN** the anchor carries `data-toggle="pstooltip"` and `data-placement="top"` and a `title` with the state and the click hint

#### Scenario: Lines stay on separate lines
- **WHEN** the tooltip of an issued document is shown
- **THEN** its template carries the class `ifirma-grid-tooltip` and the module's stylesheet keeps the line breaks of the text

#### Scenario: Native tooltip as fallback
- **WHEN** the back-office script that turns `data-toggle="pstooltip"` into a tooltip does not run
- **THEN** the browser still shows the `title` text with its lines

#### Scenario: Injected markup stays text
- **WHEN** a document number is `"><script>alert(1)</script>'` and the error of a record holds the same text
- **THEN** every attribute of the icon or the Issue action carries it escaped, the cell contains no `<script>` and no element beyond the icon or the anchor and its icon
