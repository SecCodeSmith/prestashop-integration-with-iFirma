# Spec Delta

## MODIFIED Requirements

### Requirement: Page shows configuration, persistence and counters
The status page SHALL show the effective settings (login, signed base URL, strict auth, per-minute and per-day rate limits with their environment defaults, strict list window, test endpoints), the persistence state (state file path or "memory only", time of the last write, whether the mock started clean) and the call counters (authenticated calls served in total, in the current minute and today, refused calls, the busiest minute, the granted limit).

#### Scenario: Memory-only mock
- **WHEN** the mock runs without a state file
- **THEN** the persistence block says the state is kept in memory only

#### Scenario: Persistent mock after a change
- **WHEN** the mock runs with a state file and a document was just created
- **THEN** the persistence block shows the file path and a last-write time not older than that change

#### Scenario: Limits and refusals shown
- **WHEN** the per-minute limit is 30 at runtime, the environment says 100, and two calls were refused
- **THEN** the page shows the per-minute limit 30 next to its environment default 100 and a refused-call count of 2

### Requirement: Page lists the stored documents and contractors
The status page SHALL list every stored document, newest first, with its identifier, type, number, contractor name and identifier, gross and paid amounts, currency, issue date, payment buckets, origin (module or external), KSeF status and number, the number of KSeF send calls it received, and a marker for deleted documents; and SHALL list every stored contractor with identifier, name, NIP and e-mail. Empty lists SHALL say so in words.

#### Scenario: Fresh mock
- **WHEN** no document and no contractor is stored
- **THEN** the page says there are no documents and no contractors instead of showing empty tables

#### Scenario: Documents listed newest first
- **WHEN** documents 1, 2 and 3 exist and document 2 was deleted
- **THEN** the page lists 3, 2, 1 in that order and marks 2 as deleted

#### Scenario: Values are escaped
- **WHEN** a stored contractor name contains `<script>alert(1)</script>`
- **THEN** the page shows that text literally and the browser executes nothing

#### Scenario: KSeF send calls shown
- **WHEN** invoice 7 received two KSeF send calls
- **THEN** the row of invoice 7 shows 2 KSeF send calls

### Requirement: Page shows and toggles the test switches
The status page SHALL show the current contractor create mode, the remaining PDF-not-ready calls, the legacy mode, the KSeF auto-send mode and the rate limits. For each of them it SHALL offer a form that has the same effect as the corresponding `/__mock/*` JSON endpoint and returns to the page. It SHALL also offer next-minute and next-day buttons with the effect of `POST /__mock/rate-limit/next-window`.

#### Scenario: Legacy mode switched on from the page
- **WHEN** the developer submits the legacy-mode form with "on"
- **THEN** `GET /__mock/legacy-mode` answers `{"enabled": true}` and the page shows legacy mode as on

#### Scenario: PDF-not-ready set from the page
- **WHEN** the developer submits the PDF-not-ready form with `2`
- **THEN** the next two authenticated PDF downloads answer Kod 404 and the page shows the remaining count

#### Scenario: KSeF auto-send set from the page
- **WHEN** the developer submits the KSeF auto-send form with `send`
- **THEN** `GET /__mock/ksef-auto` answers `{"mode": "send"}` and the page shows the mode

#### Scenario: Rate limits set from the page
- **WHEN** the developer submits the rate-limit form with a per-minute limit of 30 and a per-day limit of 500
- **THEN** `GET /__mock/rate-limit` reports 30 and 500

#### Scenario: Next day from the page
- **WHEN** the developer clicks the next-day button while the day limit is reached
- **THEN** the day count is zero and the next authenticated call is served

#### Scenario: Invalid form value
- **WHEN** the developer submits the rate-limit form with a negative per-minute limit
- **THEN** the page shows an error message and the limits do not change

## ADDED Requirements

### Requirement: JSON twin carries limits and KSeF auto-send
`GET /__mock/status.json` SHALL carry a `rate_limit` object (per-minute and per-day limits, their environment defaults, calls this minute, calls today, refused calls, busiest minute), `switches.ksef_auto` with the current mode, and `ksef_send_calls` on every document.

#### Scenario: Script reads the limits
- **WHEN** a smoke script requests `/__mock/status.json` after one call was refused by the minute limit
- **THEN** `rate_limit.rejected_total` is 1 and `rate_limit.per_minute` is the active limit

#### Scenario: Script reads a document's KSeF send calls
- **WHEN** a smoke script requests `/__mock/status.json` after invoice 7 was submitted once
- **THEN** the entry of invoice 7 under `documents` has `ksef_send_calls` 1
