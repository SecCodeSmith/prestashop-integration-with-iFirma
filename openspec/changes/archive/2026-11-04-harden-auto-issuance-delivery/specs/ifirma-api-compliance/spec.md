# Spec Delta

## ADDED Requirements

### Requirement: Created contractor read-back
After iFirma confirms a contractor create (`Kod` 0), the module SHALL locate the stored contractor before referencing it: by the sent identifier and the identifier reported in the answer, once more after a 2-second wait, then through the contractor search with the pre-create matching rules. The first hit's identifier SHALL be referenced on the document and stored as the customer's mapping; a document without reference SHALL be issued only when every step fails.

#### Scenario: Sent identifier confirmed at once
- **WHEN** the create answers `Kod` 0 and the read-back of `PS-C7` returns the contractor
- **THEN** the document references `PS-C7`, customer 7 is mapped to `PS-C7` with match type `module`
- **AND** no wait and no search request are made

#### Scenario: Reported identifier differs from the sent one
- **WHEN** the create answers `Kod` 0 with `Wynik` `124451232`, the read-back of `PS-C7` answers not found and the read-back of `124451232` returns the contractor
- **THEN** the document references `124451232` and customer 7 is mapped to it with match type `module`

#### Scenario: Single-object read-back answer
- **WHEN** a read-back answers `Kod` 0 with `Wynik` as one object carrying `Identyfikator` instead of a one-element list
- **THEN** the contractor counts as found

#### Scenario: Contractor visible after a short wait
- **WHEN** both immediate read-backs answer not found and the read-back repeated after the wait returns the contractor under `PS-C7`
- **THEN** the document references `PS-C7` and the mapping is stored
- **AND** exactly one wait of 2 seconds happened and no search request was made

#### Scenario: Business contractor stored under an identifier iFirma assigned
- **WHEN** every read-back by identifier answers not found and the search for the buyer NIP returns exactly one contractor with that NIP whose identifier is `IFI1909556979`
- **THEN** the document references `IFI1909556979`, customer 7 is mapped to it with match type `module`
- **AND** the contractor step is recorded as `created` with a note naming `IFI1909556979` as the identifier iFirma assigned

#### Scenario: Private person stored under an identifier iFirma assigned
- **WHEN** every read-back by identifier answers not found and the search by the buyer name returns one contractor with that name and the customer e-mail
- **THEN** that contractor's identifier is referenced and mapped with match type `module`

#### Scenario: Search is ambiguous
- **WHEN** every read-back by identifier answers not found and the NIP search returns two contractors with that NIP that neither the name nor the postcode tells apart
- **THEN** the document is created without a contractor reference and the fallback is recorded
- **AND** no mapping is stored

#### Scenario: Nothing can be found
- **WHEN** every read-back by identifier answers not found and the search returns no matching contractor
- **THEN** the document is created without a contractor reference, the fallback is recorded
- **AND** the contractor iFirma attaches to the issued document is learned as mapping with match type `document`

#### Scenario: Read-back fails at transport level
- **WHEN** a read-back by identifier gets no response or a transport error
- **THEN** the sequence continues with the wait, the repeated read-back and the search before falling back
- **AND** the transport error is part of the recorded note when the fallback is reached

#### Scenario: Create rejected
- **WHEN** the create answers a non-zero `Kod`
- **THEN** no read-back and no search are made and the fallback is recorded with iFirma's message

### Requirement: Contractor step notes
The contractor step note stored with the request SHALL be rendered in the back-office language. A fallback note SHALL name the stage that failed and, after a confirmed create, each identifier tried with iFirma's answer for it (`Kod` and `Informacja`, "empty result", or the transport error). A created note SHALL name the identifier iFirma assigned when it differs from the sent one. The order panel SHALL show the note next to the step label.

#### Scenario: Fallback after a confirmed create
- **WHEN** the create was confirmed, `PS-C1909` and the reported `PS-C1909` were read back with `Kod` 208 "Nie znaleziono kontrahenta" and the search found nothing
- **THEN** the Polish order panel shows the contractor step as *tryb awaryjny* followed by a Polish note that names `PS-C1909` with "Kod 208: Nie znaleziono kontrahenta" and states that the search found nothing
- **AND** no English sentence appears in the note

#### Scenario: Created under an assigned identifier
- **WHEN** the contractor was found through the search under `IFI1909556979` after being sent as `PS-C1909`
- **THEN** the order panel shows the step as created with a note naming `IFI1909556979` as the identifier assigned by iFirma

#### Scenario: Created under the sent identifier
- **WHEN** the contractor was read back under the sent identifier
- **THEN** the order panel shows the step as created without any note

#### Scenario: Attached contractor still shown
- **WHEN** a document issued in fallback mode has an attached contractor learned from its detail
- **THEN** the panel still appends the attached-contractor sentence after the fallback note

#### Scenario: Fallback before the create
- **WHEN** the read-back of a stored mapping or of the module identifier fails at transport level before any create
- **THEN** the fallback note names the lookup stage and the transport error
