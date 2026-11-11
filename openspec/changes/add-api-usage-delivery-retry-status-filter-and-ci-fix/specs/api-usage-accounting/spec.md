# Spec Delta

## Purpose

Shows a shop how many iFirma requests the module itself sent, in total and per kind of request, so the owner can see what uses the account's request limit.

## ADDED Requirements

### Requirement: Sent requests are counted per kind and day
The module SHALL count every request it sends to iFirma under exactly one kind, per calendar day in the shop timezone. The kinds are: `document` (create a receipt or invoice), `status` (document list and document detail), `pdf`, `email`, `ksef`, `payment`, `contractor`, `limit` and `other`. A request that was not sent SHALL NOT be counted.

#### Scenario: Status check counted
- **GIVEN** the module sent no request today
- **WHEN** an employee refreshes the status of one document and one list request is sent
- **THEN** today's count of kind `status` is 1 and every other kind is 0

#### Scenario: PDF download counted
- **WHEN** a customer downloads the PDF of their receipt and one request is sent
- **THEN** today's count of kind `pdf` rises by 1

#### Scenario: Document detail counted as status
- **WHEN** the module reads the detail of one document
- **THEN** today's count of kind `status` rises by 1

#### Scenario: Unrecognised request
- **WHEN** the module sends a request that matches no listed kind
- **THEN** it is counted under `other`

#### Scenario: Refused request not counted
- **WHEN** the local limit refuses a PDF download before it is sent
- **THEN** no kind count changes

#### Scenario: Request answered with an error still counted
- **WHEN** a status request is sent and iFirma answers with an error or with its limit answer
- **THEN** the count of kind `status` rises by 1

#### Scenario: New day
- **GIVEN** yesterday's count of kind `pdf` was 12
- **WHEN** the first PDF download of today is sent
- **THEN** today's count of kind `pdf` is 1

### Requirement: Kind counts agree with the limit counter
The sum of today's kind counts SHALL equal the number of requests the module counted toward its own day limit today, apart from a correction the module applied after reading iFirma's own counter.

#### Scenario: Mixed requests
- **GIVEN** the module sent no request today
- **WHEN** it sends 2 create requests, 3 status requests and 1 PDF download
- **THEN** the kind counts are `document` 2, `status` 3, `pdf` 1 and the module's day count is 6

### Requirement: Usage shown on the configuration page
The configuration page SHALL show the module's own requests of the last 60 seconds and of today next to their caps, and today's count per kind. It SHALL send no request to show them and SHALL show them whether or not the `abonent` key is set. Kinds with a count of zero SHALL be shown as zero.

#### Scenario: Usage box
- **GIVEN** the caps are 80 per minute and 14,000 per day, and the module sent 6 requests in the last 60 seconds and 250 today, 40 of them PDF downloads
- **WHEN** an employee opens the configuration page
- **THEN** the page shows 6 of 80 for the minute, 250 of 14,000 for the day and 40 for PDF downloads
- **AND** no request is sent to iFirma

#### Scenario: No abonent key
- **WHEN** the `abonent` key is empty
- **THEN** the usage is still shown and the limit read from iFirma stays hidden

#### Scenario: Nothing sent today
- **WHEN** the module sent no request today
- **THEN** every count is shown as 0

#### Scenario: Back-off shown
- **WHEN** iFirma's limit answer started a back-off that has not ended
- **THEN** the page states until what time the module sends nothing

### Requirement: Usage in the cron summary
The JSON summary of a cron run SHALL carry a `usage` block with the module's requests of the last 60 seconds, of today, and today's count per kind, read after the run's jobs. The block SHALL be present when the run was refused by the limit.

#### Scenario: Summary block
- **WHEN** a cron run finishes after 4 status requests on a day with no earlier request
- **THEN** the summary's `usage` block reports 4 for today and 4 for kind `status`

#### Scenario: Limited run
- **WHEN** the first job of a run is refused by the limit
- **THEN** the summary still carries the `usage` block

#### Scenario: Wrong token
- **WHEN** the cron endpoint is called with a wrong token
- **THEN** it answers 403 and reports no usage
