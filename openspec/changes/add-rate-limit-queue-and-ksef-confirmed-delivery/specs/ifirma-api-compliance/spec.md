# Spec Delta

## MODIFIED Requirements

### Requirement: Local rate guard
The module SHALL count its own requests per calendar minute and per calendar day (shop timezone) and SHALL refuse to start a request when either count has reached its configured cap: the minute cap `IFIRMA_RATE_MAX_PER_MIN` (default 80, allowed 1 to 100, below the iFirma limit of 100 per minute) and the day cap `IFIRMA_RATE_MAX_PER_DAY` (default 14000, allowed 1 to 15000, below the iFirma limit of 15000 per day). A refusal SHALL be a distinct transient "rate limited" error that names the window that refused and that callers do not persist as a document error state. Both counters SHALL be incremented atomically, so concurrent requests cannot lose increments and exceed a cap.

#### Scenario: Under the cap
- **WHEN** fewer requests than the minute cap were made this minute and fewer than the day cap today
- **THEN** the request is sent and both counters increment

#### Scenario: Cap reached
- **WHEN** the cap is reached within the current minute
- **THEN** the request is not sent
- **AND** a rate limited error naming the minute window is returned

#### Scenario: Day cap reached
- **WHEN** the day cap is reached today and the minute count is below its cap
- **THEN** the request is not sent
- **AND** a rate limited error naming the day window is returned

#### Scenario: New minute
- **WHEN** the calendar minute changes
- **THEN** the minute counter restarts from zero

#### Scenario: New day
- **WHEN** the calendar day changes
- **THEN** the day counter restarts from zero

#### Scenario: Transient error not persisted
- **WHEN** a pipeline step hits the rate limited error
- **THEN** the receipt record is not set to an error status

#### Scenario: Concurrent requests
- **WHEN** two requests pass the guard at the same moment
- **THEN** the counter ends two higher than before

#### Scenario: Day cap setting
- **WHEN** the module is installed, or a shop running 1.6.0 is upgraded
- **THEN** `IFIRMA_RATE_MAX_PER_DAY` is 14000, and saving 0 or 15001 on the settings page is refused

## ADDED Requirements

### Requirement: iFirma limit answer starts a back-off
An answer with HTTP 429, or an answer whose `Informacja` says the request limit was exceeded ("limit zapytań"), SHALL be treated as a "rate limited" error, not as an unknown outcome or a document error. It SHALL start a back-off until the time given by a `Retry-After` header (seconds, at most 24 hours), or until the start of the next minute when the header is missing or invalid. During the back-off the module SHALL send no request.

#### Scenario: HTTP 429 with Retry-After
- **WHEN** iFirma answers a request with HTTP 429 and `Retry-After: 20`
- **THEN** the request is reported as rate limited
- **AND** no further request is sent during the next 20 seconds

#### Scenario: Limit message without HTTP 429
- **WHEN** iFirma answers with HTTP 200 and `Kod` 100 with `Informacja` "Przekroczono limit zapytań"
- **THEN** the request is reported as rate limited, not as a technical error

#### Scenario: No Retry-After
- **WHEN** iFirma answers with HTTP 429 and no `Retry-After` header at 10:15:40
- **THEN** no request is sent before 10:16:00

#### Scenario: Excessive Retry-After
- **WHEN** iFirma answers with HTTP 429 and `Retry-After: 999999`
- **THEN** the back-off ends after 24 hours

#### Scenario: Back-off refusal is local
- **WHEN** a request is started during the back-off
- **THEN** it is refused before any network call with a rate limited error that names the back-off end time

#### Scenario: Other error codes unchanged
- **WHEN** iFirma answers with `Kod` 100 and an `Informacja` that does not mention the request limit
- **THEN** the answer is handled as the technical problem it was before this change
