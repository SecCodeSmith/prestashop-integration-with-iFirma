# Spec Delta

## MODIFIED Requirements

### Requirement: Document count from iFirma on request
When an employee clicks the count button, the module SHALL list the customer's documents in iFirma filtered by the shown contractor identifier and, when the customer has a NIP, by that NIP. It covers every date from the customer's registration to today, counts each document once and reports the total by type, the paid count and the period covered. Today and the registration day SHALL be days of the shop's time zone, the zone in which the module dates the documents it issues. No count call SHALL be made without the click.

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

#### Scenario: Count in the first hour of the shop's day
- **GIVEN** a shop in the time zone Europe/Warsaw and a document issued for customer 7 at 00:30 shop time on 11 November, dated 11 November
- **WHEN** the employee clicks the count button at 00:35 shop time, while it is still 10 November in UTC
- **THEN** the newest list call ends on 11 November, the document is counted, and the period shown ends on 11 November

#### Scenario: Customer registered in the first hour of the shop's day
- **GIVEN** a shop in the time zone Europe/Warsaw and customer 9 registered at 00:10 shop time on 11 November
- **WHEN** the employee clicks the count button at 00:35 shop time
- **THEN** one list call covers 11 November only
