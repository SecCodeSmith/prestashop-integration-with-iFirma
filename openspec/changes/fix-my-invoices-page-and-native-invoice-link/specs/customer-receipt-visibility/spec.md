## MODIFIED Requirements

### Requirement: Account block
The customer account page SHALL show an entry that reads just "My invoices" ("Moje Faktury" in Polish), with no count. The entry SHALL be a plain link to the "My invoices" page, not a list that a script opens. The entry SHALL be hidden when the customer has no issued documents and no pending data-change requests. The entry SHALL use the markup of the active theme: the account-menu markup in Hummingbird and its child themes, the account tile (column classes on the link itself) in every other theme.

#### Scenario: Documents and pending requests
- **WHEN** the customer has two issued documents and one order with a pending request
- **THEN** the account page shows the "My invoices" entry without a count, and following it opens the "My invoices" page, which lists both documents with number, status and download link and shows a pending-request count of 1

#### Scenario: Nothing to show
- **WHEN** the customer has no issued documents and no pending requests
- **THEN** the account entry is not displayed

#### Scenario: Entry matches the theme
- **WHEN** the shop uses Hummingbird, a child theme of Hummingbird, Classic or any other theme and the customer opens the account page
- **THEN** in Hummingbird and its child themes the entry is rendered with the account-menu link markup (marked as the current page on the "My invoices" page), and in every other theme it is a tile of the same width as the core tiles

#### Scenario: Polish shop language
- **WHEN** a customer whose language is Polish opens the account page
- **THEN** the entry reads "Moje Faktury"

## ADDED Requirements

### Requirement: Account entry works without theme scripts
The account entry and the "My invoices" page SHALL work in a theme that loads no Bootstrap or other module script. Nothing a customer needs (document list, download link, pending count) SHALL be hidden behind a script-driven toggle.

#### Scenario: Theme without the Bootstrap 4 collapse script
- **WHEN** the shop uses a theme that does not load Bootstrap 4's collapse script (for example Hummingbird) and the customer clicks the "My invoices" entry
- **THEN** the "My invoices" page opens and shows the document list with its download links

#### Scenario: Scripts disabled in the browser
- **WHEN** the customer's browser runs no JavaScript
- **THEN** the entry still leads to the "My invoices" page and every download link on it can be followed

### Requirement: My invoices page
The shop SHALL offer a "My invoices" page that lists the logged-in customer's issued documents, newest first. Each row SHALL show the number, issue date and status, plus the "issued outside the shop" badge where it applies. A row SHALL have a download link exactly when the order page would offer one for that document. The page SHALL also show the pending-request count, and a short message when there is nothing to list.

#### Scenario: Documents listed with links
- **WHEN** a logged-in customer with two issued documents opens the page
- **THEN** both documents are listed newest first with number, issue date, status and a download link to the protected download

#### Scenario: Document awaiting KSeF acceptance
- **WHEN** one of the customer's documents awaits KSeF acceptance
- **THEN** that row shows its number and status and has no download link

#### Scenario: Document without a download token
- **WHEN** a document has no download token
- **THEN** that row shows its number and status and has no download link

#### Scenario: Empty page
- **WHEN** a logged-in customer with no issued documents and no pending requests opens the page directly
- **THEN** the page shows that there are no invoices yet and lists nothing

### Requirement: My invoices page access
The "My invoices" page SHALL be shown only to a logged-in customer and SHALL list only that customer's documents. It SHALL not change any stored state, and it SHALL not send any request to iFirma.

#### Scenario: Not logged in
- **WHEN** a visitor who is not logged in opens the page
- **THEN** the visitor is redirected to the login page and no document is listed

#### Scenario: Another customer's documents never listed
- **WHEN** a logged-in customer opens the page, including with an added customer id parameter in the address
- **THEN** only documents whose customer is the logged-in customer are listed

#### Scenario: Read-only page
- **WHEN** the page is opened, with any request method
- **THEN** no document, request or setting changes and no iFirma request is made

#### Scenario: Untrusted document data
- **WHEN** a document number or status label contains HTML or script markup
- **THEN** the page shows it as text and the markup is not interpreted
