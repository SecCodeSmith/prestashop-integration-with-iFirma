# Spec Delta

## ADDED Requirements

### Requirement: Back-office downloads from the stored copy keep their access checks
The back-office PDF download SHALL return the document's stored copy when a usable one exists (specified in `document-pdf-store`), only to an employee with the view level and only for a record that belongs to the named order. A download served from the copy SHALL NOT use the back-office download budget and SHALL be returned when that budget is used up. A refused download SHALL return no bytes of the copy.

#### Scenario: Employee with the view level
- **GIVEN** a document with a usable stored copy
- **WHEN** an employee with the view level opens the PDF download link
- **THEN** the stored copy is returned and no request is sent to iFirma

#### Scenario: Employee without the view level
- **GIVEN** a document with a usable stored copy
- **WHEN** an employee without the view level calls the admin PDF download with a valid module token
- **THEN** the download is refused with a permission message and no bytes of the copy are returned

#### Scenario: Budget used up
- **GIVEN** 30 back-office PDF downloads were sent to iFirma in the current minute
- **WHEN** an employee with the view level downloads a document that has a usable stored copy
- **THEN** the stored copy is returned and no request is sent to iFirma

#### Scenario: Budget used up without a copy
- **GIVEN** 30 back-office PDF downloads were sent to iFirma in the current minute
- **WHEN** an employee with the view level downloads a document without a usable stored copy
- **THEN** the answer is HTTP 429 with a `Retry-After` header, as before, and no request is sent to iFirma

#### Scenario: A served copy is not counted
- **WHEN** a back-office download is served from the stored copy
- **THEN** the count of the back-office download budget is unchanged

#### Scenario: Record of another order
- **GIVEN** a document with a usable stored copy
- **WHEN** the download names that document's record together with the id of a different order
- **THEN** the answer is "document not found" and no bytes of the copy are returned

### Requirement: Refreshing the PDF copy needs the manage level and POST
The "Refresh PDF copy" action SHALL require the manage level and SHALL run only for a POST request that carries a valid module token. An employee without the manage level SHALL NOT see the action. A refused request SHALL change no copy and SHALL send no request to iFirma.

#### Scenario: Employee without the manage level
- **GIVEN** a document with a stored copy
- **WHEN** an employee with the view and issue levels but not the manage level posts the action with a valid module token
- **THEN** the response is HTTP 403 with `success` false and a permission message
- **AND** the copy is unchanged and no request is sent to iFirma

#### Scenario: GET names the action
- **GIVEN** an employee with the manage level and a valid token
- **WHEN** a GET request names the "Refresh PDF copy" action for an order
- **THEN** the answer is a refusal, the copy is unchanged and no request is sent to iFirma

#### Scenario: Missing token
- **WHEN** the action arrives without a valid module token
- **THEN** it is refused by the token check before any permission check, and the copy is unchanged

#### Scenario: Panel without manage
- **WHEN** an employee without the manage level opens an order with an issued document that has a stored copy
- **THEN** the panel shows the Download PDF link and the time of the copy, but no "Refresh PDF copy" button

#### Scenario: Manage-level employee
- **WHEN** an employee with the manage level posts the action from the order panel
- **THEN** the copy is deleted and replaced as specified in `document-pdf-store`

#### Scenario: Order without an issued document
- **WHEN** an employee with the manage level posts the action for an order that has no issued document
- **THEN** the answer says the document was not found, nothing is stored and no request is sent to iFirma
