# Spec Delta

## MODIFIED Requirements

### Requirement: Candidate selection
A listed document SHALL be a duplicate candidate only if it is not a draft (`CzySzkic` false), its `Rodzaj` is `prz_faktura_paragon` or `prz_faktura_kraj`, its currency equals the order currency, its `Brutto` is within the configured tolerance (default 0.01) of the order total, and it belongs to the order's buyer. A row belongs to the buyer when its `IdentyfikatorKontrahenta` equals the contractor identifier the module would reference for the customer (the stored mapping, otherwise `PS-C{id_customer}`), or its `NIPKontrahenta` equals the digits of the invoice-address NIP, or its `KontrahentNazwa` equals the buyer name the module would send for the order (the company name, or the first and last name) compared after trimming, collapsing whitespace and ignoring case.

#### Scenario: All conditions met
- **WHEN** a row is not a draft, has a producible type, the same currency, gross 123.00 for an order total of 123.00 and `IdentyfikatorKontrahenta` equal to the customer's contractor identifier
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

#### Scenario: Document of another buyer excluded
- **WHEN** the unfiltered window query returns a row with the order's gross, currency and type whose `IdentyfikatorKontrahenta`, `NIPKontrahenta` and `KontrahentNazwa` all differ from the order's buyer
- **THEN** the row is not a candidate
- **AND** no detail request is made for it

#### Scenario: Matched by NIP
- **WHEN** a row carries `IdentyfikatorKontrahenta` `EC_KOWALSKI` for customer 5 without a stored mapping and `NIPKontrahenta` `1234567890`, and the invoice address holds `PL 123-456-78-90`
- **THEN** the row is a candidate

#### Scenario: Matched by name
- **WHEN** a row carries no identifier or NIP known to the module and `KontrahentNazwa` `jan   KOWALSKI` for a private buyer named `Jan Kowalski`
- **THEN** the row is a candidate

#### Scenario: Linked customer identifier
- **WHEN** customer 7 is mapped to contractor `KOWALSKI-01` and a row carries `IdentyfikatorKontrahenta` `KOWALSKI-01`
- **THEN** the row is a candidate even though `PS-C7` appears nowhere on it

### Requirement: Suspected duplicate blocks issuance
When candidates exist, none contains the order reference, and at least one candidate matches the order's sale date and product lines or could not be verified, the module SHALL NOT create a document, SHALL record the surviving candidates (id, number, issue date, sale date, gross, contractor name, contractor match reason) on the order receipt record with status "duplicate suspected", and SHALL show them in the admin order panel.

#### Scenario: Candidates without reference
- **WHEN** one candidate exists, its detail lacks the order reference, its `DataSprzedazy` is the order's sale date and its positions cover the order's product lines
- **THEN** no document is created
- **AND** the receipt status is "duplicate suspected" with the candidate id, number, issue date, sale date, gross and contractor name recorded

#### Scenario: Admin sees candidates
- **WHEN** an admin opens the order panel of a suspected order
- **THEN** each recorded candidate is listed with its issue date and sale date

#### Scenario: Customer view
- **WHEN** the customer opens a suspected order
- **THEN** no internal detail about candidates is shown

#### Scenario: Older candidate records
- **WHEN** an admin opens the order panel of an order whose candidates were recorded before the sale date was stored
- **THEN** the candidates are listed with an empty sale date

## ADDED Requirements

### Requirement: Candidate detail verification
A candidate whose detail does not contain the order reference SHALL remain a duplicate candidate only if the detail's `DataSprzedazy` equals the order's sale date (the order date, as stamped on module-created documents; the calendar month when `FormatDatySprzedazy` is `MSC`) and every product line of the order is matched by a distinct document position with the same normalised name and the same quantity. Otherwise the candidate SHALL be discarded.

#### Scenario: Same buyer, same products, different sale date
- **WHEN** the only candidate's detail has `DataSprzedazy` one day before the order date and positions covering the order's product lines
- **THEN** the candidate is discarded
- **AND** the guard proceeds and a document is created

#### Scenario: Same buyer, same sale date, different product
- **WHEN** the only candidate's detail has the order's sale date and a single position `Produkt B` for an order holding one line `Produkt A`
- **THEN** the candidate is discarded
- **AND** the guard proceeds

#### Scenario: Same buyer, same sale date, different quantity
- **WHEN** the only candidate's detail has the order's sale date and a position `Produkt A` with `Ilosc` 2 for an order holding `Produkt A` with quantity 1
- **THEN** the candidate is discarded

#### Scenario: Extra positions are tolerated
- **WHEN** a candidate's detail has the order's sale date, positions for every order product line and an additional position `Koszt dostawy`
- **THEN** the candidate remains suspected

#### Scenario: Name normalisation
- **WHEN** a candidate's detail has the order's sale date and a position `  produkt   a ` for an order line `Produkt A`
- **THEN** the position matches the line

#### Scenario: Monthly sale date format
- **WHEN** a candidate's detail has `FormatDatySprzedazy` `MSC` and `DataSprzedazy` in the order's month, with positions covering the order's product lines
- **THEN** the candidate remains suspected

#### Scenario: Reference wins over verification
- **WHEN** a candidate's detail contains the order reference but a different `DataSprzedazy`
- **THEN** the document is adopted as the order receipt

#### Scenario: Several candidates, one survives
- **WHEN** two candidates of the buyer exist and only the newer one matches the sale date and product lines
- **THEN** the receipt is "duplicate suspected" and records only the surviving candidate

### Requirement: Unverifiable candidate stays suspected
When a candidate's detail cannot be fetched, lacks `DataSprzedazy` or lacks `Pozycje`, or when the candidate is beyond the per-run detail call limit, the module SHALL keep the candidate as suspected, record it with an empty sale date, and record the detail error when there was one.

#### Scenario: Detail request fails
- **WHEN** the only candidate's detail request returns HTTP 500
- **THEN** the receipt is "duplicate suspected" with that candidate recorded with an empty sale date
- **AND** the guard error names the failed detail request

#### Scenario: Detail without positions
- **WHEN** the only candidate's detail has the order's sale date but no `Pozycje`
- **THEN** the candidate remains suspected

#### Scenario: Candidates beyond the detail limit
- **WHEN** twelve candidates of the buyer exist and the detail limit is ten
- **THEN** the two candidates whose details were not fetched remain suspected with an empty sale date
