# Spec Delta

## Purpose

Catches every e-mail a test shop sends, so the stack can prove what left PrestaShop (recipients, subject, body, attachments, blind copies) and can simulate a mail server that refuses, without any message ever reaching a real mailbox.

## ADDED Requirements

### Requirement: SMTP listener accepts any message
The mock SHALL listen for SMTP on a configurable port (default 1025) next to its HTTP port, SHALL accept any sender and any recipient without authentication or TLS, and SHALL answer every accepted message with a 250 reply so that a PrestaShop shop configured with `PS_MAIL_METHOD=2`, server `ifirma-mock`, port 1025, encryption off and no user sends through it.

#### Scenario: Plain SMTP session
- **WHEN** a client connects to the SMTP port, sends `EHLO`, `MAIL FROM`, two `RCPT TO` and a `DATA` block
- **THEN** every command is accepted and the message is stored once with both envelope recipients

#### Scenario: No credentials needed
- **WHEN** a client sends a message without `AUTH` and without `STARTTLS`
- **THEN** the message is accepted

#### Scenario: Listener disabled
- **WHEN** the SMTP port setting is `0`
- **THEN** no SMTP listener is started, the HTTP mock works as before and the mailbox endpoints answer with an empty mailbox

### Requirement: Received messages are recorded in full
For each accepted message the mock SHALL record the time of receipt, the envelope sender, the envelope recipients, the raw message bytes, and the parsed form: `From`, `To`, `Cc`, `Subject` and `Date` headers (decoded), the text part, the HTML part, every attachment (disposition `attachment`) with file name, content type, size and SHA-256, and every inline part (embedded images) separately. Recipients present in the envelope but in neither `To` nor `Cc` SHALL be reported as `bcc`.

#### Scenario: Multipart message with a PDF attachment
- **WHEN** a multipart/mixed message with a text part, an HTML part and one `application/pdf` attachment named `ifirma-1-2026.pdf` is received
- **THEN** the stored message has `subject`, `text` and `html` filled, and one attachment with `filename` `ifirma-1-2026.pdf`, `content_type` `application/pdf`, its byte size and SHA-256

#### Scenario: Inline image is not an attachment
- **WHEN** a message embeds the shop logo as an inline part with a `Content-ID` referenced from the HTML and also carries one PDF attachment
- **THEN** `attachments` lists only the PDF and the logo is reported under `inline` with its file name, content type, content id, size and SHA-256

#### Scenario: Blind copy derived from the envelope
- **WHEN** a message is received with `RCPT TO` `customer@example.test` and `shop@example.test` while its `To` header names only `customer@example.test`
- **THEN** the stored message reports `to` `["customer@example.test"]` and `bcc` `["shop@example.test"]`

#### Scenario: Encoded subject
- **WHEN** the `Subject` header is RFC 2047 encoded UTF-8 (for example a Polish shop name)
- **THEN** the stored `subject` is the decoded text

### Requirement: Mailbox endpoints
The mock SHALL expose the mailbox as test endpoints: `GET /__mock/mail` (messages newest first, without bodies), `GET /__mock/mail/{id}` (the full parsed message), `GET /__mock/mail/{id}/raw` (the raw bytes as `message/rfc822`), `GET /__mock/mail/{id}/attachments/{index}` (the attachment bytes with its content type) and `DELETE /__mock/mail` (empties the mailbox). An unknown message id or attachment index SHALL answer HTTP 404.

#### Scenario: Listing after two messages
- **WHEN** two messages were received and `GET /__mock/mail` is requested
- **THEN** the answer lists two entries, the later one first, each with `id`, `received_at`, `from`, `to`, `cc`, `bcc`, `subject`, `attachments` (count) and `size`

#### Scenario: Reading one message
- **WHEN** `GET /__mock/mail/{id}` is requested for a stored message
- **THEN** the answer carries the listing fields plus `headers`, `text`, `html`, `envelope` (`mail_from`, `rcpt_tos`) and `attachments` as a list of `{filename, content_type, size, sha256}`

#### Scenario: Attachment download
- **WHEN** `GET /__mock/mail/{id}/attachments/0` is requested for the PDF message
- **THEN** the body is the PDF bytes, the `Content-Type` is `application/pdf` and the SHA-256 of the body equals the listed one

#### Scenario: Unknown message
- **WHEN** `GET /__mock/mail/999` or `GET /__mock/mail/1/attachments/7` is requested for ids that do not exist
- **THEN** the mock answers HTTP 404

#### Scenario: Mailbox emptied
- **WHEN** `DELETE /__mock/mail` is called after messages were received
- **THEN** `GET /__mock/mail` lists nothing and the next received message gets a new id

### Requirement: Reject switch simulates a refusing mail server
The mock SHALL offer `POST /__mock/mail/reject` with a body `{"enabled": true|false}` and `GET /__mock/mail/reject` reporting it. While enabled, the SMTP listener SHALL answer the `DATA` command of every message with a permanent 5xx reply and store nothing; when disabled, delivery continues normally.

#### Scenario: Message refused
- **WHEN** the switch is enabled and a client sends a complete message
- **THEN** the `DATA` command is answered with a 5xx reply, the client's send fails, and the mailbox stays unchanged

#### Scenario: Switch read back and cleared
- **WHEN** the switch was enabled and then `POST /__mock/mail/reject` is called with `{"enabled": false}`
- **THEN** `GET /__mock/mail/reject` answers `{"enabled": false}` and the next message is stored

### Requirement: Mailbox is bounded and memory only
The mailbox SHALL live in memory only (never in the state file), SHALL keep at most 200 messages, dropping the oldest when a new one arrives beyond that, and SHALL start empty on every start of the mock.

#### Scenario: Oldest message dropped
- **WHEN** 201 messages have been received
- **THEN** `GET /__mock/mail` lists 200 entries and the first received message is absent

#### Scenario: Restart empties the mailbox
- **WHEN** messages were received and the mock process restarts with a state file configured
- **THEN** the documents are restored from the state file and the mailbox is empty

### Requirement: Reset and test-endpoint guard apply to the mailbox
`POST /__mock/reset` SHALL also empty the mailbox and disable the reject switch. Every mailbox route and the reject switch SHALL answer HTTP 404 when the test endpoints are disabled, and none of them SHALL count as an authenticated API call.

#### Scenario: Reset clears the mailbox and the switch
- **WHEN** messages were received, the reject switch is enabled and `POST /__mock/reset` is called
- **THEN** `GET /__mock/mail` lists nothing and `GET /__mock/mail/reject` answers `{"enabled": false}`

#### Scenario: Test endpoints disabled
- **WHEN** `IFIRMA_MOCK_TEST_ENDPOINTS=false` and any `/__mock/mail*` route is requested
- **THEN** the mock answers HTTP 404, while the SMTP listener keeps accepting messages

#### Scenario: Mailbox reads are not API traffic
- **WHEN** `GET /__mock/mail` is requested ten times
- **THEN** `LimitWykorzystany` reported by `GET /iapi/abonent/limit.json` is unchanged

### Requirement: Stack wiring and documentation
The compose stack SHALL publish the SMTP port of the mock on the host, the root JSON answer of the mock SHALL name the SMTP port and the mailbox path, and the stack documentation SHALL state the PrestaShop settings that route shop mail to the sink and the endpoints to read it back.

#### Scenario: Shop mail lands in the sink
- **WHEN** the PrestaShop container has `PS_MAIL_METHOD=2`, `PS_MAIL_SERVER=ifirma-mock`, `PS_MAIL_SMTP_PORT=1025`, `PS_MAIL_SMTP_ENCRYPTION=off` and an empty `PS_MAIL_USER`, and the shop sends any e-mail
- **THEN** the message appears in `GET /__mock/mail` on the mock

#### Scenario: Root answer names the sink
- **WHEN** `GET /` is requested
- **THEN** its JSON keeps the existing keys and adds the SMTP port and the mailbox path
