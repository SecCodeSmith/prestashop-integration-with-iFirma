# Spec Delta

## MODIFIED Requirements

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

## ADDED Requirements

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
