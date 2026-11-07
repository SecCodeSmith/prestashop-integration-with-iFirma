# mock-status-page Specification

## Purpose
Gives the developer a browser view of the mock iFirma API: what it holds, how it is configured and how much it has served, with quick actions to seed, alter or wipe its state without typing `curl` commands.

## Requirements

### Requirement: Status page is served with the test endpoints
The mock SHALL serve an HTML status page at `GET /__mock/status` without any authentication, and SHALL answer HTTP 404 for the page and all its actions when the test endpoints are disabled, exactly like the other `/__mock/*` routes. The JSON answer of `GET /` SHALL name the page's path.

#### Scenario: Page available
- **WHEN** the test endpoints are enabled and a browser opens `/__mock/status`
- **THEN** the mock answers HTTP 200 with an HTML document

#### Scenario: Test endpoints disabled
- **WHEN** `IFIRMA_MOCK_TEST_ENDPOINTS=false` and `/__mock/status`, `/__mock/status.json` or any page action is requested
- **THEN** the mock answers HTTP 404

#### Scenario: Root answer points at the page
- **WHEN** `GET /` is requested
- **THEN** its JSON keeps the existing keys and adds the path of the status page

### Requirement: Page shows configuration, persistence and counters
The status page SHALL show the effective settings (login, signed base URL, strict auth, rate limit, strict list window, test endpoints), the persistence state (state file path or "memory only", time of the last write, whether the mock started clean) and the call counters (authenticated calls served in total and in the current minute, the granted limit).

#### Scenario: Memory-only mock
- **WHEN** the mock runs without a state file
- **THEN** the persistence block says the state is kept in memory only

#### Scenario: Persistent mock after a change
- **WHEN** the mock runs with a state file and a document was just created
- **THEN** the persistence block shows the file path and a last-write time not older than that change

### Requirement: Page lists the stored documents and contractors
The status page SHALL list every stored document, newest first, with its identifier, type, number, contractor name and identifier, gross and paid amounts, currency, issue date, payment buckets, origin (module or external), KSeF status and number, and a marker for deleted documents; and SHALL list every stored contractor with identifier, name, NIP and e-mail. Empty lists SHALL say so in words.

#### Scenario: Fresh mock
- **WHEN** no document and no contractor is stored
- **THEN** the page says there are no documents and no contractors instead of showing empty tables

#### Scenario: Documents listed newest first
- **WHEN** documents 1, 2 and 3 exist and document 2 was deleted
- **THEN** the page lists 3, 2, 1 in that order and marks 2 as deleted

#### Scenario: Values are escaped
- **WHEN** a stored contractor name contains `<script>alert(1)</script>`
- **THEN** the page shows that text literally and the browser executes nothing

### Requirement: Page shows and toggles the test switches
The status page SHALL show the current contractor create mode, the remaining PDF-not-ready calls and the legacy mode, and SHALL offer a form for each that has the same effect as the corresponding `/__mock/*` JSON endpoint and returns to the page.

#### Scenario: Legacy mode switched on from the page
- **WHEN** the developer submits the legacy-mode form with "on"
- **THEN** `GET /__mock/legacy-mode` answers `{"enabled": true}` and the page shows legacy mode as on

#### Scenario: PDF-not-ready set from the page
- **WHEN** the developer submits the PDF-not-ready form with `2`
- **THEN** the next two authenticated PDF downloads answer Kod 404 and the page shows the remaining count

### Requirement: Seed a sample document from the page
The status page SHALL offer a form that creates a sample document (document type `fakturaparagon` or `fakturakraj`, contractor name, optional contractor identifier, NIP and e-mail, gross amount, paid amount) through the same rules as `POST /__mock/documents`, and SHALL return to the page showing the new row.

#### Scenario: Sample receipt seeded
- **WHEN** the developer submits the form with type `fakturaparagon`, contractor "Jan Kowalski", gross `123.45` and paid `0`
- **THEN** a document appears at the top of the list with number `<id>/<year>`, gross `123.45`, paid `0.00` and the bucket `nieoplacone`
- **AND** `GET /iapi/faktury.json?dataOd=…` lists the same document

#### Scenario: Invalid amount
- **WHEN** the gross field is not a number
- **THEN** nothing is stored and the page shows an error message naming the field

### Requirement: Row actions mirror the JSON helpers
Each listed document SHALL offer KSeF accept, KSeF reject and delete actions with the same outcome as `POST /__mock/ksef/accept/{id}`, `POST /__mock/ksef/reject/{id}` and `DELETE /__mock/documents/{id}`, returning to the page.

#### Scenario: KSeF accepted from the page
- **WHEN** the developer clicks "KSeF accept" on document 1
- **THEN** the page shows `PRZYJETA` and a KSeF number for document 1
- **AND** `GET /iapi/faktury.json?dataOd=…` reports the same `KsefStatus` and `KsefNumer`

#### Scenario: Document deleted from the page
- **WHEN** the developer clicks "Delete" on document 1
- **THEN** document 1 is marked deleted on the page and disappears from `faktury.json` and the detail endpoint

### Requirement: Reset from the page
The status page SHALL offer a reset action with the same effect as `POST /__mock/reset` (documents, contractors, switches, counters and the persisted state), after which the page shows the empty state.

#### Scenario: Reset clicked
- **WHEN** the developer confirms the reset action
- **THEN** the page lists no documents and no contractors, the switches are at their defaults and the counters are zero

### Requirement: JSON twin of the page
`GET /__mock/status.json` SHALL return the data the page shows (settings, persistence, counters, switches, documents, contractors) as JSON, with documents in the `faktury.json` row shape plus `deleted` and `created_by`, so scripts and the smoke checks can read the same facts.

#### Scenario: Script reads the state
- **WHEN** a smoke script requests `/__mock/status.json` after seeding one document
- **THEN** the answer has one entry under `documents` with its `FakturaId`, `PelnyNumer`, `created_by` and `deleted: false`

### Requirement: Page traffic is not API traffic
Requests to the status page, its JSON twin and its actions SHALL NOT count as authenticated API calls: they SHALL NOT change `LimitWykorzystany` and SHALL NOT be subject to the simulated rate limit.

#### Scenario: Watching the page while testing
- **WHEN** the page is reloaded ten times while the simulated rate limit is `5`
- **THEN** the next authenticated `/iapi/*` call is served normally and the served-call counter is unchanged by the reloads
