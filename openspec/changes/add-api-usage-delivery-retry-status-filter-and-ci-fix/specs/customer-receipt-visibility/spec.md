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

### Requirement: Customer downloads have their own request budget
All customer-initiated PDF downloads together SHALL use at most 20 iFirma requests per calendar minute and 2,000 per calendar day (shop timezone), whatever the number of customers, orders or documents. A download beyond that budget SHALL be answered like a download refused by the request limit (HTTP 429, `Retry-After: 60`, the same generic message) and SHALL send no request. Issuing documents, status sync and the cron SHALL NOT be refused because of customer downloads.

#### Scenario: Many accounts cannot use up the shared limit
- **GIVEN** 8 customers, each with 5 issued documents, who download in a loop for one minute
- **WHEN** the minute ends
- **THEN** at most 20 requests were sent to iFirma for those downloads
- **AND** an order that reaches the trigger status in the same minute gets its document issued

#### Scenario: Minute budget used up
- **GIVEN** 20 customer downloads were sent in the current minute
- **WHEN** the owner of an order requests their PDF
- **THEN** the answer is HTTP 429 with `Retry-After: 60` and the generic "try again shortly" message, and no request is sent

#### Scenario: Day budget used up
- **GIVEN** 2,000 customer downloads were sent today
- **WHEN** a customer requests a PDF
- **THEN** the answer is HTTP 429 and no request is sent, and the module's own day count for other requests is unchanged

#### Scenario: Next minute
- **GIVEN** the minute budget was used up
- **WHEN** a customer requests a PDF in the next minute and the day budget allows it
- **THEN** the PDF is returned

#### Scenario: Budget checked after the access checks
- **GIVEN** the budget is used up
- **WHEN** another customer requests the PDF of an order that is not theirs
- **THEN** the answer is "not found" exactly as without the budget

#### Scenario: A refused download uses no budget
- **WHEN** a download is refused by the per-document or per-customer throttle, by the budget or by the request limit
- **THEN** the customer-download budget count does not change

#### Scenario: Employee downloads are not counted here
- **WHEN** an employee downloads a PDF in the back office
- **THEN** the customer-download budget count does not change
