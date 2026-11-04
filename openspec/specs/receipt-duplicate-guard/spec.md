# receipt-duplicate-guard Specification

## Purpose
Detect documents already present in iFirma for an order (created by the module earlier, by iFirma's own e-commerce integrator, or manually), adopt confirmed matches, block suspected ones, and let the admin resolve them.

## Requirements

### Requirement: Local record precedence
An order that already has an issued local receipt record SHALL never trigger a remote lookup or a new document creation; the existing document SHALL be reused.

#### Scenario: Order already has a receipt
- **WHEN** issuance is requested for an order with an issued local receipt record
- **THEN** no request is sent to iFirma
- **AND** the existing document is shown as the order receipt

#### Scenario: Previous attempt ended in error
- **WHEN** the local record has no iFirma document id because the earlier attempt failed
- **THEN** the order is not treated as issued and the remote lookup runs

### Requirement: Order reference stamping
Every document the module creates SHALL carry the order reference `PS-ORDER-{id_order}-{order reference}` in `Uwagi` and as a `DodatkoweUwagi` entry of type `NUMER_ZAMOWIENIA`.

#### Scenario: Document created
- **WHEN** the module creates a document for order 15 with reference ABCDEFGHI
- **THEN** `Uwagi` contains `PS-ORDER-15-ABCDEFGHI`
- **AND** `DodatkoweUwagi` contains an entry of type `NUMER_ZAMOWIENIA` with `Info` equal to that reference

#### Scenario: Reference stored locally
- **WHEN** a document is created
- **THEN** the order reference is stored on the order receipt record

### Requirement: Remote lookup before issuance
Before creating a document the module SHALL query the `faktury.json` list over the window from one day before the order date to today, always sending `dataOd` and `dataDo`, paging through all pages and filtering by contractor identifier, then by `nipKontrahenta`, then unfiltered.

#### Scenario: Filter by contractor identifier
- **WHEN** the contractor-identifier query returns at least one row
- **THEN** no further query is made and those rows are evaluated as candidates

#### Scenario: Fallback to NIP
- **WHEN** the contractor-identifier query returns no rows and the order has a NIP
- **THEN** the module queries by `nipKontrahenta` over the same window

#### Scenario: Unfiltered query within the duplicate window
- **WHEN** previous queries returned nothing and the window does not exceed the configured duplicate window (default 60 days)
- **THEN** the module queries the window without a contractor filter

#### Scenario: Window too large for unfiltered query
- **WHEN** the window exceeds the configured duplicate window
- **THEN** no unfiltered query is made

#### Scenario: Multiple pages
- **WHEN** a result page is full
- **THEN** the module requests the next page until a short page is returned

### Requirement: Candidate selection
A listed document SHALL be a duplicate candidate only if it is not a draft (`CzySzkic` false), its `Rodzaj` is `prz_faktura_paragon` or `prz_faktura_kraj`, its currency equals the order currency, and its `Brutto` is within the configured tolerance (default 0.01) of the order total.

#### Scenario: All conditions met
- **WHEN** a row is not a draft, has a producible type, the same currency and gross 123.00 for an order total of 123.00
- **THEN** the row is a candidate

#### Scenario: Draft excluded
- **WHEN** a row has `CzySzkic` true
- **THEN** the row is not a candidate

#### Scenario: Amount outside tolerance
- **WHEN** a row has `Brutto` differing from the order total by more than the configured tolerance
- **THEN** the row is not a candidate

#### Scenario: Different currency
- **WHEN** a row currency differs from the order currency
- **THEN** the row is not a candidate

### Requirement: Confirmed match adoption
When the detail of a candidate contains the order reference, the module SHALL adopt that document as the order receipt, storing iFirma id, number and type, marking the source external and deriving status from the paid amount, and SHALL NOT create a new document.

#### Scenario: Reference found in candidate
- **WHEN** a candidate detail contains `PS-ORDER-15-ABCDEFGHI` for order 15
- **THEN** the receipt record stores that document id, number and type with source external
- **AND** no create request is sent

#### Scenario: Adopted document is fully paid
- **WHEN** the adopted document paid amount covers its gross
- **THEN** the receipt status is paid

#### Scenario: Presentation of adopted document
- **WHEN** an admin or the customer views the order
- **THEN** the adopted document appears like a module-created one
- **AND** the admin view flags it as external

### Requirement: Suspected duplicate blocks issuance
When candidates exist but none contains the order reference, the module SHALL NOT create a document, SHALL record the candidates (id, number, date, gross, contractor name) on the order receipt record with status "duplicate suspected", and SHALL show them in the admin order panel.

#### Scenario: Candidates without reference
- **WHEN** one candidate exists and its detail lacks the order reference
- **THEN** no document is created
- **AND** the receipt status is "duplicate suspected" with the candidate id, number, date, gross and contractor name recorded

#### Scenario: Admin sees candidates
- **WHEN** an admin opens the order panel of a suspected order
- **THEN** each recorded candidate is listed

#### Scenario: Customer view
- **WHEN** the customer opens a suspected order
- **THEN** no internal detail about candidates is shown

### Requirement: Admin resolution of suspected duplicates
The admin SHALL be able to Link a specific candidate, which adopts it, or Issue anyway, which bypasses the guard once for that order; both actions SHALL be recorded.

#### Scenario: Link a candidate
- **WHEN** the admin chooses Link on a candidate
- **THEN** that document is adopted as the order receipt with source external
- **AND** the action is recorded on the receipt record

#### Scenario: Issue anyway
- **WHEN** the admin chooses Issue anyway
- **THEN** a document is created without the guard remote lookup
- **AND** the bypass is recorded on the receipt record

#### Scenario: Bypass is one-time
- **WHEN** a later issuance is requested for the same order after a bypass
- **THEN** the guard runs again unless the order already has an issued record

### Requirement: Inconclusive guard
If the list query fails because of network error, authentication error or rate limiting, automatic issuance SHALL abort and record the error, and manual issuance SHALL display the error and offer Issue anyway.

#### Scenario: Automatic issuance
- **WHEN** the list query fails during status-triggered issuance
- **THEN** no document is created
- **AND** the error is recorded on the receipt record

#### Scenario: Manual issuance
- **WHEN** the list query fails during an admin Create action
- **THEN** the error is displayed to the admin
- **AND** an Issue anyway action is offered

### Requirement: Guard applies to all issuance paths
Manual Create and automatic status-triggered issuance SHALL run the same duplicate guard, and the guard SHALL be disableable by a merchant setting (default enabled), in which case only the local record check applies.

#### Scenario: Both paths guarded
- **WHEN** an iFirma document for the order exists and either the admin Create action or the status trigger runs
- **THEN** both paths produce the same guard outcome

#### Scenario: Guard disabled
- **WHEN** the merchant has disabled the duplicate guard
- **THEN** no list query is sent before creation
- **AND** only the local record check applies

### Requirement: Unknown contractor filter is conclusive
The remote lookup SHALL filter by the customer's linked contractor identifier when a mapping exists and by `PS-C{id_customer}` otherwise. When iFirma answers Kod 201 with a message about the contractor for a filtered query, the guard SHALL treat that query as returning no documents and continue with the remaining queries instead of reporting an inconclusive outcome.

#### Scenario: Contractor unknown to iFirma
- **WHEN** the `kontrahent` query is answered with Kod 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze"
- **THEN** the guard runs the NIP query (when the buyer has a NIP) and the unfiltered window query, and proceeds when they return nothing

#### Scenario: Linked customer
- **WHEN** customer 7 is mapped to contractor `KOWALSKI-01`
- **THEN** the first query filters by `kontrahent=KOWALSKI-01`, not by `PS-C7`

#### Scenario: Other failures stay inconclusive
- **WHEN** the unfiltered query fails, or a filtered query fails with a transport error or a Kod 201 that does not mention the contractor
- **THEN** the outcome is inconclusive and nothing is issued
