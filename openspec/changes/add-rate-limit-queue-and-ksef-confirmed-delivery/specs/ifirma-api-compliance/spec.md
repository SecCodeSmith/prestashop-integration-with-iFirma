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

### Requirement: Reading the current limit from iFirma
With the `abonent` key set, the module SHALL read the day limit from iFirma at the start of a cron run, keep the reading for ten minutes, raise its local day counter to the number of requests iFirma reports as used (never lower it), and use the granted limit as a ceiling for the day cap. Without the key, or when iFirma gives no usable answer, the module SHALL change nothing and SHALL NOT stop the other cron jobs.

#### Scenario: Other clients used the account
- **GIVEN** the local day counter reads 300 and iFirma reports 1,200 used of 15,000
- **WHEN** the cron reads the limit
- **THEN** the day counter reads 1,200 and the cron answer's `limit` block says 13,800 remaining

#### Scenario: The module counted more than iFirma
- **GIVEN** the local day counter reads 1,500 and iFirma reports 1,200 used
- **WHEN** the cron reads the limit
- **THEN** the day counter still reads 1,500

#### Scenario: Granted limit below the configured cap
- **GIVEN** the day cap is 14,000 and iFirma reports 5,000 granted and 5,000 used today
- **WHEN** a request is about to start
- **THEN** the guard refuses it with "5000 requests today (cap 5000)"

#### Scenario: A fresh reading is reused
- **GIVEN** the limit was read less than ten minutes ago, on the same day
- **WHEN** the cron runs again
- **THEN** iFirma is not asked and the earlier reading is reported

#### Scenario: iFirma does not answer
- **GIVEN** the limit cannot be read (no key, an error, or an unusable answer)
- **WHEN** the cron runs
- **THEN** the counters and the earlier reading are unchanged, the `limit` block holds nulls and the queue, KSeF, sync and delivery jobs still run

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
