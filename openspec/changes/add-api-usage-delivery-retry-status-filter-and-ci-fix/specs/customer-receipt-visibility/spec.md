# Spec Delta

## ADDED Requirements

### Requirement: Download refused by the request limit
When the module's request limit refuses the PDF download of a customer who may download the document, the answer SHALL be HTTP 429 with a `Retry-After` header and a generic message in the customer's language asking to try again shortly. The module SHALL send no request to iFirma, SHALL NOT wait and SHALL NOT retry in that request. The answer SHALL NOT reveal counts, caps or the account's limit.

#### Scenario: Minute cap reached
- **GIVEN** the owner of an order with an issued receipt and the module's minute cap reached
- **WHEN** the owner requests the PDF with a valid link
- **THEN** the answer is HTTP 429 with `Retry-After` of at most 60 seconds and a "try again shortly" message
- **AND** no request is sent to iFirma

#### Scenario: Day cap reached
- **GIVEN** the module's day cap is reached
- **WHEN** the owner requests the PDF
- **THEN** the answer is HTTP 429 with a `Retry-After` header and no PDF bytes

#### Scenario: Message hides the numbers
- **WHEN** a download is refused by the limit
- **THEN** the response body names no request count, no cap and no iFirma account detail

#### Scenario: Access checks come first
- **GIVEN** the minute cap is reached
- **WHEN** another customer requests the PDF of an order that is not theirs
- **THEN** the answer is "not found" exactly as without the cap, and not 429

#### Scenario: Wrong token
- **GIVEN** the minute cap is reached
- **WHEN** a request carries a wrong download token
- **THEN** the answer is the same as for a wrong token without the cap

#### Scenario: iFirma failure is still a server error
- **WHEN** the download is sent and iFirma answers with an error
- **THEN** the customer gets the existing "temporarily unavailable" answer, not 429
