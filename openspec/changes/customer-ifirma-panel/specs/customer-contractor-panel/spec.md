# Spec Delta

## Purpose

Shows the merchant, on the back-office customer page, how a shop customer relates to iFirma: which contractor the module uses, whether it exists in iFirma and how many documents the customer has. Lets an employee find and link an older contractor or register a new one.

## ADDED Requirements

### Requirement: Panel on the customer page
The back-office customer view SHALL show an iFirma panel for the viewed customer on every supported PrestaShop version, including installations upgraded from an earlier module version. The panel SHALL render without calling the iFirma API, and any API-backed part SHALL load after the page.

#### Scenario: Panel shown on a fresh install
- **WHEN** an employee opens the customer page of customer 7 on a shop where the module was just installed
- **THEN** the page contains the iFirma panel for customer 7

#### Scenario: Panel shown after upgrade
- **WHEN** the module is upgraded from 1.1.0 and an employee opens a customer page
- **THEN** the iFirma panel is shown without reinstalling the module

#### Scenario: Page does not wait for iFirma
- **WHEN** iFirma does not answer within the request timeout
- **THEN** the customer page and the panel's local information are still displayed, and only the API-backed parts show that they could not be loaded

### Requirement: Contractor link state
The panel SHALL show which iFirma contractor the module would reference for the customer. That is the stored link with its identifier, name and match type, or else the module identifier `PS-C{id_customer}` marked as "not linked".

#### Scenario: Linked customer
- **WHEN** customer 7 is linked to contractor `KOWALSKI-01` named "Jan Kowalski" with match type `import-email`
- **THEN** the panel shows `KOWALSKI-01`, "Jan Kowalski" and the translated match type "import, e-mail"

#### Scenario: Customer without link
- **WHEN** customer 7 has no stored link
- **THEN** the panel shows the identifier `PS-C7` and states that the customer is not linked to an existing contractor

### Requirement: Live registration check
After the panel opens, the module SHALL look the shown identifier up in iFirma once and display "registered" with the contractor name, "not found in iFirma", or "could not check" with the reason. A failed or refused check SHALL NOT change the stored link.

#### Scenario: Contractor exists
- **WHEN** the panel shows `KOWALSKI-01` and iFirma returns that contractor
- **THEN** the panel shows "registered" and the name iFirma holds

#### Scenario: Contractor missing
- **WHEN** iFirma answers that `PS-C7` does not exist
- **THEN** the panel shows "not found in iFirma" and offers the contractor search and the registration of a new contractor

#### Scenario: Check refused by the rate guard
- **WHEN** the local rate guard refuses the lookup
- **THEN** the panel shows "could not check" with the rate-guard reason and the stored link is unchanged

### Requirement: Local document count
The panel SHALL show the number of the customer's documents recorded by the module, grouped by state (issued, paid, held, needs attention) and by document type (receipt, domestic invoice, other), without calling the iFirma API. A customer with no documents SHALL show zero counts.

#### Scenario: Mixed documents
- **WHEN** customer 7 has two issued receipts, one paid domestic invoice and one document in `error`
- **THEN** the panel shows 3 issued (2 receipts, 1 domestic invoice), 1 paid and 1 needing attention

#### Scenario: No documents
- **WHEN** customer 7 has no recorded document
- **THEN** the panel shows 0 for every count

### Requirement: Document count from iFirma on request
When an employee clicks the count button, the module SHALL list the customer's documents in iFirma filtered by the shown contractor identifier and, when the customer has a NIP, by that NIP. It covers every date from the customer's registration to today, counts each document once and reports the total by type, the paid count and the period covered. No count call SHALL be made without the click.

#### Scenario: Count by contractor
- **WHEN** the employee clicks the count button for customer 7 linked to `KOWALSKI-01`, and iFirma lists 5 documents of that contractor, 4 of them paid
- **THEN** the panel shows 5 documents with their split by type, 4 paid, and the period from the customer's registration date to today

#### Scenario: Same document returned by both filters
- **WHEN** a document is returned both by the contractor filter and by the NIP filter
- **THEN** it is counted once

#### Scenario: Contractor unknown to iFirma
- **WHEN** iFirma answers that the filtered contractor identifier does not exist
- **THEN** the identifier part counts 0 documents and the NIP part, when present, is still counted

#### Scenario: Every list call is bounded
- **WHEN** the count is computed
- **THEN** every list call carries `dataOd`, `dataDo` and `iloscNaStronie`, and no single call spans more than one year

### Requirement: Remote count call budget
A single remote count SHALL make at most 20 list calls. When the budget runs out before the listing ends, the panel SHALL report the count as a lower bound ("at least N") with the period actually covered. When a call fails, the panel SHALL show the error and no total.

#### Scenario: Budget exhausted
- **WHEN** the customer's documents need more than 20 list calls
- **THEN** the panel shows "at least N documents" and the period covered by the calls that were made

#### Scenario: Call failure
- **WHEN** one list call fails with an iFirma error
- **THEN** the panel shows that error and does not show a partial count as a total

### Requirement: Contractor search for an older contractor
The panel SHALL offer a contractor search with first name, last name, company, NIP, e-mail and phone, pre-filled from the customer and their addresses. The module SHALL send the full name, company and NIP to the iFirma contractor search, at most 4 phrases per search. It SHALL merge the returned contractors, one row per identifier, and show identifier, name, NIP, e-mail, phone, postcode and city.

#### Scenario: Search by name and NIP
- **WHEN** the employee searches with name "Jan Kowalski" and NIP `1234563218`, and iFirma returns contractor `KOWALSKI-01` for both phrases
- **THEN** the list shows `KOWALSKI-01` once

#### Scenario: Nothing found
- **WHEN** iFirma returns no contractor for any phrase
- **THEN** the panel states that no contractor was found and offers the registration of a new contractor

#### Scenario: Search error
- **WHEN** one search phrase fails with a transport error and another returns contractors
- **THEN** the returned contractors are listed and the panel names the phrase whose search failed

### Requirement: E-mail and phone ranking
iFirma cannot search by e-mail or phone. The module SHALL compare the entered e-mail (case-insensitively) and phone (digits only, ignoring a leading 48 country code) with each returned contractor. It SHALL mark which of NIP, e-mail, phone and normalised name match, and order rows by number of matches with NIP first. A search with only an e-mail or phone SHALL be refused with an explanation.

#### Scenario: Phone confirms a contractor
- **WHEN** the search for "Jan Kowalski" returns two contractors with that name and only one has phone `+48 600 100 200`, equal to the entered phone `600100200`
- **THEN** that contractor is listed first and marked as matching name and phone

#### Scenario: E-mail only
- **WHEN** the employee searches with only the e-mail filled in
- **THEN** no search is sent to iFirma and the panel explains that iFirma searches by name, company or NIP and that e-mail and phone are used to confirm results

### Requirement: Manual link to a chosen contractor
The employee SHALL be able to choose one listed contractor and confirm. The confirmation SHALL say that the next document updates that contractor's owned data in iFirma with the shop data. On confirmation the module SHALL read the contractor from iFirma again and store or replace the customer's link with match type `manual`. A contractor that cannot be read SHALL NOT be linked.

#### Scenario: Link a found contractor
- **WHEN** the employee chooses `KOWALSKI-01` for customer 7 and confirms, and iFirma returns that contractor
- **THEN** customer 7 is linked to `KOWALSKI-01` with match type `manual`, the panel shows the new link, and the next document for customer 7 references `KOWALSKI-01`

#### Scenario: Replace an existing link
- **WHEN** customer 7 is linked to `PS-C7` and the employee links `KOWALSKI-01`
- **THEN** the single stored link of customer 7 points to `KOWALSKI-01`

#### Scenario: Chosen contractor no longer exists
- **WHEN** the employee confirms `KOWALSKI-01` but iFirma answers that it does not exist
- **THEN** no link is stored and the panel says the contractor was not found

### Requirement: Unlink
The employee SHALL be able to remove the customer's stored link after confirming. Removing it SHALL NOT change or delete anything in iFirma.

#### Scenario: Unlink a customer
- **WHEN** the employee unlinks customer 7 linked to `KOWALSKI-01` and confirms
- **THEN** customer 7 has no stored link, the panel shows `PS-C7` as not linked, and no iFirma call modifies contractor `KOWALSKI-01`

### Requirement: Register as a new contractor from the customer page
When contractor sync is enabled and the customer has at least one order, the panel SHALL offer to register the customer in iFirma. This uses the same contractor registration and matching as the order panel, with the data of the customer's latest order. Otherwise the action SHALL be unavailable and the panel SHALL say why.

#### Scenario: Register a customer
- **WHEN** the employee registers customer 7, whose latest order is order 42, and no existing contractor matches
- **THEN** contractor `PS-C7` is created from order 42's data, the customer is linked to it, and the panel shows the result message

#### Scenario: No order
- **WHEN** customer 7 has no order
- **THEN** the register action is unavailable and the panel says a registration needs at least one order

#### Scenario: Sync disabled
- **WHEN** contractor sync is disabled in the module settings
- **THEN** the register action is unavailable and the panel says so

### Requirement: Access control on panel actions
Every panel action (check, count, search, link, unlink, register) SHALL require an authenticated back-office employee and a valid module admin token. Link, unlink and register SHALL also require edit permission on customers. Every value from iFirma or the employee SHALL be escaped when displayed.

#### Scenario: Missing token
- **WHEN** a request to link a contractor arrives without a valid admin token
- **THEN** it is refused and no link is stored

#### Scenario: Employee without edit permission
- **WHEN** an employee with view-only access to customers tries to unlink a customer
- **THEN** the action is refused with a permission message and the link is unchanged

#### Scenario: Markup in a contractor name
- **WHEN** iFirma returns a contractor named `<script>alert(1)</script>`
- **THEN** the panel shows the name as text and no script runs
