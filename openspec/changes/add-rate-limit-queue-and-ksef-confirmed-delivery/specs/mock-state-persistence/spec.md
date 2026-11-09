# Spec Delta

## MODIFIED Requirements

### Requirement: State survives a restart
When a state file is configured, the mock SHALL write its complete state to that file after every change and SHALL load it when it starts, so that documents (with their KSeF state and KSeF send calls), contractors, the identifier sequences, the test switches (including the KSeF auto-send mode and the runtime rate limits), the served-call counter, the refused-call counter and the current day's call count are the same after a restart as before it. The per-minute count SHALL NOT be persisted.

#### Scenario: Issued document outlives a restart
- **WHEN** a document was created through `POST /iapi/fakturakraj.json` (or seeded through `POST /__mock/documents`) and the mock process or container is restarted
- **THEN** `GET /iapi/faktury.json?dataOd=…` lists the document with the same `FakturaId` and `PelnyNumer`
- **AND** `GET /iapi/{type}/{id}.pdf` and `GET /iapi/{type}/{id}.json` answer for it as before the restart

#### Scenario: Identifier sequences continue
- **WHEN** two documents and one generated contractor (`K000001`) exist and the mock restarts
- **THEN** the next created document gets identifier `3`, never `1` or `2` again
- **AND** the next generated contractor identifier is `K000002`

#### Scenario: Contractors and switches are kept
- **WHEN** a contractor was stored and legacy mode was switched on before a restart
- **THEN** after the restart `GET /iapi/kontrahenci/id/{identifier}.json` answers Kod 0 with that contractor
- **AND** `GET /__mock/legacy-mode` answers `{"enabled": true}`

#### Scenario: Served-call counter continues
- **WHEN** the mock served N authenticated `/iapi/*` calls before a restart
- **THEN** after the restart `GET /iapi/abonent/limit.json` reports a `LimitWykorzystany` that continues from N instead of starting at zero

#### Scenario: Payments and deletions are kept
- **WHEN** a payment was registered for a document and another document was deleted through `DELETE /__mock/documents/{id}` before a restart
- **THEN** after the restart the first document still reports the paid amount and the second one stays absent from lists and detail

#### Scenario: Day count and limits are kept
- **WHEN** 40 calls were served today, the per-day limit was set to 500 at runtime and the KSeF auto-send mode is `send`, and the mock restarts on the same day
- **THEN** the day count continues from 40, the per-day limit is 500 and the KSeF auto-send mode is `send`

#### Scenario: Day count from an earlier day
- **WHEN** the state file holds a day count of 40 for yesterday and the mock starts today
- **THEN** the day count starts from zero

#### Scenario: State file without the new keys
- **WHEN** the mock starts with a version 1 state file written before this change
- **THEN** it loads the file, starts with a day count of zero, the environment limits, KSeF auto-send `off` and zero KSeF send calls per document
