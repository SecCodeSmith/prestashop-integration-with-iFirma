# mock-state-persistence Specification

## Purpose
Keeps the state of the mock iFirma API (documents, contractors, identifier sequences, test switches and the served-call counter) across restarts of the mock process or container, so the test shops that reference those documents keep working, while a deliberate clean start stays one argument away.

## Requirements

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

### Requirement: Memory-only mode without a state file
When no state file is configured, the mock SHALL keep its state in memory only, write nothing to disk and start empty, exactly as before this capability existed.

#### Scenario: Test suite and ad-hoc runs
- **WHEN** the mock runs without the state-file setting (the pytest suite, a plain `uvicorn` start)
- **THEN** no state file is created anywhere
- **AND** a restart starts with no documents, no contractors and the default switches

### Requirement: Durable writes and tolerant loading
The mock SHALL write the state file so that a crash during a write never leaves a half-written file in place, and SHALL start with an empty state when the file is missing, unreadable or of an unknown format version, keeping an unreadable file aside and logging why.

#### Scenario: First start on an empty volume
- **WHEN** the state file does not exist yet
- **THEN** the mock starts empty, serves requests and creates the file at the first change

#### Scenario: Corrupt or foreign file
- **WHEN** the state file is not valid JSON or declares a format version the mock does not know
- **THEN** the mock starts empty, renames the file aside with a suffix instead of overwriting it, and logs a warning naming the file

#### Scenario: Write failure is visible
- **WHEN** the state file cannot be written (directory missing or read-only)
- **THEN** the request that caused the change still gets its normal answer
- **AND** the failure is logged on every attempt so it does not stay silent

### Requirement: Clean-start argument
The mock SHALL discard the stored state before loading anything when the clean-start setting is on at start-up, and SHALL read that setting at start-up only.

#### Scenario: Clean start discards the stored state
- **WHEN** the mock starts with the clean-start setting on and the state file holds documents
- **THEN** the mock starts with no documents, no contractors, the default switches and a zero call counter
- **AND** the state file afterwards reflects that empty state

#### Scenario: Clean start without a file
- **WHEN** the mock starts with the clean-start setting on and no state file exists
- **THEN** the mock starts empty without an error

#### Scenario: Stack brought up clean
- **WHEN** the developer runs `make up-clean` (or `IFIRMA_MOCK_CLEAN_START=true docker compose up -d --force-recreate ifirma-mock`) in `test-stack/`
- **THEN** the recreated mock container starts empty
- **AND** the stack files document that the container keeps the flag until it is recreated, so a plain `docker compose restart ifirma-mock` would start clean again

#### Scenario: Stack brought up with its memory
- **WHEN** the developer runs `make up` (or `docker compose up -d --build`) without the clean-start variable
- **THEN** the mock container starts with the state stored on its volume

### Requirement: Reset clears the persisted state
`POST /__mock/reset` SHALL clear the stored state together with the in-memory state, so a restart right after a reset starts empty.

#### Scenario: Reset then restart
- **WHEN** `POST /__mock/reset` is called and the mock restarts
- **THEN** `GET /iapi/faktury.json?dataOd=…` lists no documents and the identifier sequences start at 1

### Requirement: State file lives on a volume in the compose stack
The compose stack SHALL store the mock's state file on a named volume, so that rebuilding or recreating the mock service keeps the state, and `make destroy` (`docker compose down -v`) removes it with the other volumes.

#### Scenario: Rebuild after a code change
- **WHEN** the developer runs `docker compose up -d --build --no-deps ifirma-mock` after editing the mock
- **THEN** the documents the shops reference are still served by the new container

#### Scenario: Stack destroyed
- **WHEN** the developer runs `make destroy`
- **THEN** the next `make up` starts the mock with an empty state

### Requirement: State file format is versioned
The state file SHALL carry a format version so that a later mock can recognise an older file and either migrate it or treat it as unknown; the file SHALL be plain JSON that a developer can read and edit.

#### Scenario: Inspecting the file
- **WHEN** a developer opens the state file
- **THEN** it is UTF-8 JSON with a top-level version number and readable sections for documents, contractors, sequences, switches and counters
