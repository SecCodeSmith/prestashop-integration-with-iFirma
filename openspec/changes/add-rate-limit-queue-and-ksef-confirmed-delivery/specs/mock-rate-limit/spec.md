# Spec Delta

## Purpose

Simulating iFirma's per-account request limits (per minute and per day) in the iFirma mock, so the module's throttling, back-off and queue can be tested end to end, with routes that change the limits and move to the next window without waiting.

## ADDED Requirements

### Requirement: Per-minute and per-day limits
The mock SHALL count every authenticated `/iapi/*` call in the current calendar minute and the current calendar day of its own clock. It SHALL refuse a call when the minute count exceeds the per-minute limit or the day count exceeds the per-day limit. A limit of 0 SHALL mean "no limit".

#### Scenario: Minute limit reached
- **WHEN** the per-minute limit is 5 and a sixth authenticated call arrives in the same calendar minute
- **THEN** the sixth call is refused

#### Scenario: Next minute
- **WHEN** the per-minute limit is 5, five calls were made in one minute and the next call arrives in the following minute
- **THEN** that call is served

#### Scenario: Day limit reached
- **WHEN** the per-day limit is 20 and 20 calls were already made today
- **THEN** the next call is refused even though the minute limit is not reached

#### Scenario: Limits off
- **WHEN** both limits are 0
- **THEN** no call is refused for being over a limit

#### Scenario: Unauthenticated calls do not count
- **WHEN** a call fails the signature check in strict mode
- **THEN** it is answered with 401 and the minute and day counts do not change

### Requirement: Limit answer
A refused call SHALL be answered with HTTP 429 and a `Retry-After` header holding the whole seconds until the refusing window ends (at least 1). The JSON body SHALL be `{"response": {"Kod": 100, "Informacja": "Przekroczono limit zapytań (<window>: <limit>)"}}`, where `<window>` is `limit minutowy` or `limit dzienny`. The refused call SHALL have no other effect.

#### Scenario: Minute refusal answer
- **WHEN** a create call is refused 20 seconds before the end of the minute by a per-minute limit of 100
- **THEN** the answer is HTTP 429 with `Retry-After` 20 and `Informacja` "Przekroczono limit zapytań (limit minutowy: 100)"
- **AND** no document is created

#### Scenario: Day refusal answer
- **WHEN** a call is refused by a per-day limit of 15000 at 23:00:00 on the mock's clock
- **THEN** the answer is HTTP 429 with `Retry-After` 3600 and `Informacja` "Przekroczono limit zapytań (limit dzienny: 15000)"

#### Scenario: KSeF send refused
- **WHEN** a KSeF send call is refused by the limit
- **THEN** the document's KSeF state and KSeF send counter do not change

### Requirement: Limits from the environment and at runtime
The mock SHALL read its default limits from `IFIRMA_MOCK_RATE_LIMIT` (per minute) and `IFIRMA_MOCK_RATE_LIMIT_DAY` (per day), both defaulting to 0. `POST /__mock/rate-limit` with `{"per_minute": n, "per_day": m}` SHALL set either limit at runtime, and `GET /__mock/rate-limit` SHALL report both limits and the counters. `POST /__mock/reset` SHALL restore the environment defaults.

#### Scenario: Limits changed at runtime
- **WHEN** `POST /__mock/rate-limit` is sent with `{"per_minute": 30}`
- **THEN** the per-minute limit is 30, the per-day limit is unchanged, and `GET /__mock/rate-limit` reports both

#### Scenario: Invalid value refused
- **WHEN** the body carries a negative number, a non-integer or an unknown key
- **THEN** the answer is 422 and the limits do not change

#### Scenario: Reset restores the environment
- **WHEN** the per-minute limit was set to 30 at runtime, the environment says 100, and `POST /__mock/reset` is sent
- **THEN** the per-minute limit is 100 again

#### Scenario: Test endpoints disabled
- **WHEN** `IFIRMA_MOCK_TEST_ENDPOINTS` is `false`
- **THEN** both rate-limit routes answer 404 and the limits keep their environment values

### Requirement: Next-window simulation
`POST /__mock/rate-limit/next-window` with `{"window": "minute"}` or `{"window": "day"}` SHALL clear the count of that window, as if the window had ended, without touching the total call counter or the other window.

#### Scenario: Day window cleared
- **WHEN** the per-day limit was reached and `{"window": "day"}` is posted
- **THEN** the next call is served and the day count restarts from it

#### Scenario: Unknown window
- **WHEN** the body names a window other than `minute` or `day`
- **THEN** the answer is 422 and no count changes

### Requirement: Limit counters
The mock SHALL report, through `GET /__mock/rate-limit` and the status page, the calls served in the current minute and today, the total of refused calls, and the highest number of calls seen in any single calendar minute since the last reset. `abonent/limit.json` SHALL report today's calls as `LimitWykorzystany` and the per-day limit (15000 when it is 0) as `LimitPrzyznany`.

#### Scenario: Refused calls counted
- **WHEN** three calls were refused by the minute limit
- **THEN** the refused-call total is 3

#### Scenario: Busiest minute
- **WHEN** 12 calls were made in one minute and 7 in the next
- **THEN** the busiest-minute count is 12

#### Scenario: Daily usage on the limit endpoint
- **WHEN** 40 calls were made today and the per-day limit is 15000
- **THEN** `abonent/limit.json` answers `LimitWykorzystany` 41 (the limit call included) and `LimitPrzyznany` 15000

### Requirement: Compose defaults follow iFirma
The compose stack SHALL run the mock with `IFIRMA_MOCK_RATE_LIMIT` 100 and `IFIRMA_MOCK_RATE_LIMIT_DAY` 15000 unless overridden, and SHALL set the mock's timezone to `Europe/Warsaw` so its calendar day matches the shop's.

#### Scenario: Stack started with defaults
- **WHEN** the stack is started without overriding the limit variables
- **THEN** `GET /__mock/rate-limit` reports a per-minute limit of 100 and a per-day limit of 15000
