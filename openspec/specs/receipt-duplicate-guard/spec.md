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
Every document the module creates SHALL carry the order reference `{prefix}-{id_order}-{order reference}` in `Uwagi` and as a `DodatkoweUwagi` entry of type `NUMER_ZAMOWIENIA`, where `{prefix}` is the configured stamp prefix (default `PS-ORDER`). When a shop webpage is configured, `Uwagi` SHALL carry it after the stamp, separated by a space, and the stamp SHALL stay first and complete when the 1000-character limit truncates the field.

#### Scenario: Document created
- **WHEN** the module creates a document for order 15 with reference ABCDEFGHI and the default prefix
- **THEN** `Uwagi` contains `PS-ORDER-15-ABCDEFGHI`
- **AND** `DodatkoweUwagi` contains an entry of type `NUMER_ZAMOWIENIA` with `Info` equal to that reference

#### Scenario: Configured prefix
- **WHEN** the stamp prefix is `scmedical` and the module creates a document for order 15 with reference ABCDEFGHI
- **THEN** `Uwagi` and the `NUMER_ZAMOWIENIA` entry carry `scmedical-15-ABCDEFGHI`

#### Scenario: Shop webpage printed
- **WHEN** the shop webpage is `https://scmedical.pl` and a document is created for order 15
- **THEN** `Uwagi` is `PS-ORDER-15-ABCDEFGHI https://scmedical.pl` and the `NUMER_ZAMOWIENIA` entry carries only the stamp

#### Scenario: Reference stored locally
- **WHEN** a document is created
- **THEN** the order reference stamp, with the prefix that was used, is stored on the order receipt record

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

### Requirement: Confirmed match adoption
When the detail of a candidate contains the order reference stamp built with the configured prefix, or the same stamp built with the default prefix `PS-ORDER` for documents issued before the prefix was changed, the module SHALL adopt that document as the order receipt, storing iFirma id, number and type, marking the source external and deriving status from the paid amount, and SHALL NOT create a new document.

#### Scenario: Reference found in candidate
- **WHEN** a candidate detail contains `PS-ORDER-15-ABCDEFGHI` for order 15 and the prefix is the default
- **THEN** the receipt record stores that document id, number and type with source external
- **AND** no create request is sent

#### Scenario: Configured prefix found
- **WHEN** the prefix is `scmedical` and a candidate detail contains `scmedical-15-ABCDEFGHI` for order 15
- **THEN** the document is adopted

#### Scenario: Legacy stamp found after a prefix change
- **WHEN** the prefix is `scmedical` and a candidate detail contains `PS-ORDER-15-ABCDEFGHI` for order 15
- **THEN** the document is adopted

#### Scenario: Other shop's stamp ignored
- **WHEN** the prefix is `scmedical` and a candidate detail contains `hialumed-15-ABCDEFGHI`
- **THEN** the candidate is not adopted by the stamp and goes through the detail verification

#### Scenario: Adopted document is fully paid
- **WHEN** the adopted document paid amount covers its gross
- **THEN** the receipt status is paid

#### Scenario: Presentation of adopted document
- **WHEN** an admin or the customer views the order
- **THEN** the adopted document appears like a module-created one
- **AND** the admin view flags it as external

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
If the list query fails because of network error or authentication error, automatic issuance SHALL abort and record the error, and manual issuance SHALL display the error and offer Issue anyway. If the list query is refused by the rate limit, automatic and manual issuance SHALL abort and record the order as queued instead of recording an error, and manual issuance SHALL also display the rate-limit message without offering Issue anyway.

#### Scenario: Automatic issuance
- **WHEN** the list query fails because of a network or authentication error during status-triggered issuance
- **THEN** no document is created
- **AND** the error is recorded on the receipt record

#### Scenario: Manual issuance
- **WHEN** the list query fails because of a network or authentication error during an admin Create action
- **THEN** the error is displayed to the admin
- **AND** an Issue anyway action is offered

#### Scenario: Automatic issuance refused by the rate limit
- **WHEN** the list query is refused by the rate limit during status-triggered issuance
- **THEN** no document is created
- **AND** the order's record is queued with the refusal message and is not in state `error`

#### Scenario: Manual issuance refused by the rate limit
- **WHEN** the list query is refused by the rate limit during an admin Create action
- **THEN** the rate-limit message is displayed to the admin
- **AND** the order's record is queued, so the cron endpoint issues it later
- **AND** Issue anyway is not offered, because bypassing the guard would not get past the limit

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

### Requirement: Stamp prefix setting
The configuration page SHALL offer an "Order stamp prefix" setting, default `PS-ORDER`, read per shop, of 1 to 32 characters from `A-Z a-z 0-9 . _ -`; surrounding spaces and a trailing `-` SHALL be removed on save, an empty value SHALL store the default, and any other character SHALL be refused with a message naming the field and nothing SHALL be saved. The help text SHALL say that each shop sharing one iFirma account needs its own prefix.

#### Scenario: Trailing hyphen removed
- **WHEN** the admin saves `scmedical-`
- **THEN** `scmedical` is stored and documents are stamped `scmedical-{id}-{reference}`

#### Scenario: Invalid character
- **WHEN** the admin saves `sc medical`
- **THEN** the page shows an error naming the prefix field and no setting is changed

#### Scenario: Empty value
- **WHEN** the admin saves an empty prefix
- **THEN** `PS-ORDER` is stored

#### Scenario: Default after upgrade
- **WHEN** the module is upgraded from a version without the setting
- **THEN** the prefix is `PS-ORDER` and the stamps are unchanged

### Requirement: Stamp search outside the buyer block
The order stamp SHALL be searched for in the values of the decoded document detail with the buyer block (`Kontrahent`, typed by the buyer) left out, and SHALL match only as a whole token: not preceded by a letter, digit, dot, underscore or hyphen, and not followed by a letter, digit, underscore or hyphen or by a dot and a digit. A detail that is not JSON SHALL be searched as raw text by the same rule.

#### Scenario: Stamp typed into the buyer block
- **WHEN** a candidate detail holds `PS-ORDER-15-ABCDEFGHI` only inside the buyer block, for example as the street typed by another buyer
- **THEN** the candidate is not adopted by the stamp and goes through the detail verification

#### Scenario: Stamp in the remarks
- **WHEN** the stamp stands in the remarks (`Uwagi`) or in the additional remarks (`DodatkoweUwagi`), alone or followed by the shop webpage or a full stop
- **THEN** the document is adopted

#### Scenario: Stamp that is the prefix of another stamp
- **WHEN** a candidate detail holds `PS-ORDER-15-ABCDEFGHIJ` and the order's stamp is `PS-ORDER-15-ABCDEFGHI`
- **THEN** the candidate is not adopted by the stamp
