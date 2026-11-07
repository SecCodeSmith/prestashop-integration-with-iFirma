# Spec Delta

## ADDED Requirements

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

## MODIFIED Requirements

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
