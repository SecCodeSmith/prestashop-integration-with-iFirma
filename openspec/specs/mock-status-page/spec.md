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
The status page SHALL offer a reset action with the same effect as `POST /__mock/reset` (documents, contractors, switches, counters, the persisted state, the mailbox and the reject switch), after which the page shows the empty state.

#### Scenario: Reset clicked
- **WHEN** the developer confirms the reset action
- **THEN** the page lists no documents, no contractors and no messages, the switches are at their defaults and the counters are zero

### Requirement: JSON twin of the page
`GET /__mock/status.json` SHALL return the data the page shows (settings including the SMTP port, persistence, counters, switches including the reject switch, documents, contractors, and `mail` as the mailbox listing without bodies) as JSON, with documents in the `faktury.json` row shape plus `deleted` and `created_by`, so scripts and the smoke checks can read the same facts.

#### Scenario: Script reads the state
- **WHEN** a smoke script requests `/__mock/status.json` after seeding one document
- **THEN** the answer has one entry under `documents` with its `FakturaId`, `PelnyNumer`, `created_by` and `deleted: false`

#### Scenario: Script reads the mailbox
- **WHEN** a smoke script requests `/__mock/status.json` after one message was received
- **THEN** the answer has one entry under `mail` with the same fields as `GET /__mock/mail`, `switches.mail_reject` is `false` and `settings.smtp_port` is the listening port

### Requirement: Page traffic is not API traffic
Requests to the status page, its JSON twin and its actions SHALL NOT count as authenticated API calls: they SHALL NOT change `LimitWykorzystany` and SHALL NOT be subject to the simulated rate limit.

#### Scenario: Watching the page while testing
- **WHEN** the page is reloaded ten times while the simulated rate limit is `5`
- **THEN** the next authenticated `/iapi/*` call is served normally and the served-call counter is unchanged by the reloads

### Requirement: Page shows the mailbox
The status page SHALL show the SMTP port (or that the listener is off), the reject switch with a form that has the same effect as `POST /__mock/mail/reject`, and the received messages newest first with time, envelope sender, `to`, `bcc`, subject, attachment count and a link to the raw message; an empty mailbox SHALL say so in words. A "Clear mailbox" action SHALL have the same effect as `DELETE /__mock/mail` and return to the page.

#### Scenario: Empty mailbox
- **WHEN** no message has been received
- **THEN** the Mail section says the mailbox is empty and shows the SMTP port and the reject switch as off

#### Scenario: Messages listed
- **WHEN** two messages were received, the second one with a blind-copy recipient and one attachment
- **THEN** the page lists the second message first with its `to`, its `bcc`, its subject, `1` attachment and a link to `/__mock/mail/{id}/raw`

#### Scenario: Reject switched from the page
- **WHEN** the developer submits the reject form with "on"
- **THEN** `GET /__mock/mail/reject` answers `{"enabled": true}` and the page shows the switch as on

#### Scenario: Mailbox cleared from the page
- **WHEN** the developer clicks "Clear mailbox"
- **THEN** the page says the mailbox is empty and `GET /__mock/mail` lists nothing

#### Scenario: Subject is escaped
- **WHEN** a received message has the subject `<img src=x onerror=alert(1)>`
- **THEN** the page shows that text literally and the browser executes nothing

### Requirement: JSON twin carries limits and KSeF auto-send
`GET /__mock/status.json` SHALL carry a `rate_limit` object (per-minute and per-day limits, their environment defaults, calls this minute, calls today, refused calls, busiest minute), `switches.ksef_auto` with the current mode, and `ksef_send_calls` on every document.

#### Scenario: Script reads the limits
- **WHEN** a smoke script requests `/__mock/status.json` after one call was refused by the minute limit
- **THEN** `rate_limit.rejected_total` is 1 and `rate_limit.per_minute` is the active limit

#### Scenario: Script reads a document's KSeF send calls
- **WHEN** a smoke script requests `/__mock/status.json` after invoice 7 was submitted once
- **THEN** the entry of invoice 7 under `documents` has `ksef_send_calls` 1
