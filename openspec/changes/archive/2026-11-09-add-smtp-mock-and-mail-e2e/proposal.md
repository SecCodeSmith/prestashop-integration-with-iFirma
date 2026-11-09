# Proposal

## Why

Every e-mail the module sends (the receipt or invoice with its PDF, the optional blind copy, and the three data-request mails) goes through PrestaShop's `Mail::Send`, but nothing in the test stack ever receives one: the Docker smoke run sets `PS_MAIL_METHOD=3` ("never send"), so a "delivery sent" assertion only proves that `Mail::Send` returned true, not that a message with the right recipient, subject, body, attachment or blind copy left the shop, and the "shop mail system fails" branch of the delivery spec has never run end to end. A mail sink inside the existing iFirma mock closes that gap with the same conventions the mock already has (test endpoints, reset, status page, pytest).

## What Changes

- **SMTP sink in the iFirma mock (test-stack).** The `ifirma-mock` service also listens for SMTP on port 1025 (no authentication, no TLS, every recipient accepted) and stores each received message in memory: envelope sender and recipients, parsed headers, text and HTML parts, attachments, the raw bytes. Nothing is persisted to the state file; the mailbox is bounded (oldest messages dropped past 200).
- **Mailbox test endpoints.** `GET /__mock/mail` (newest first, with `to`, `cc`, `bcc` derived from the envelope, subject, attachment count), `GET /__mock/mail/{id}` (headers, text, html, attachments with name, type, size and sha256), `GET /__mock/mail/{id}/raw`, `GET /__mock/mail/{id}/attachments/{index}`, `DELETE /__mock/mail`. A new test switch `POST /__mock/mail/reject` makes the sink refuse the next messages with a 5xx reply at `DATA`, so the failure path can be exercised. All of them are guarded by `IFIRMA_MOCK_TEST_ENDPOINTS`, never count as API calls, and `POST /__mock/reset` empties the mailbox and clears the switch like the other switches.
- **Status page.** A "Mail" section lists the received messages (time, from, to, bcc, subject, attachments, link to the raw message), shows the SMTP port and the reject switch with its form, and offers a "Clear mailbox" button; `/__mock/status.json` carries the same data.
- **Compose and docs (test-stack).** `docker-compose.yml` publishes 1025, `.env.example` and the mock README document the sink, the PrestaShop SMTP settings that point at it (`PS_MAIL_METHOD=2`, server `ifirma-mock`, port 1025, encryption off, no user) and the endpoints; the mock version becomes 1.2.0 and the module pins the new submodule commit.
- **End-to-end e-mail checks in the module smoke run.** `tests/smoke/run.php` configures the shop to send through the sink instead of switching mail off, and a new scenario asserts on captured mail: the receipt delivery mail (customer address and name, shop sender, subject with the document number, text and HTML bodies with the number and the order reference, one `application/pdf` attachment named after the number whose bytes start with `%PDF`), the blind copy in mode `shop` (an envelope recipient that is not in the headers), no copy when the copy address equals the customer address, the manual resend carrying the copy, an SMTP refusal recorded as delivery `failed` with the document still issued, and the three data-request mails (merchant notification at submission, approved and rejected mails to the customer). Scenario C keeps its "delivery sent" check, which now proves a real SMTP transaction. The smoke README gains the scenario, the new environment overrides and the note that the shop stays pointed at the sink after a run.
- **Module docs.** architecture.md (mock modules, test endpoints, status page), README and troubleshooting mention the mail catcher.

## Capabilities

### New Capabilities

- `mock-smtp-sink`: the SMTP listener of the iFirma mock and its mailbox: what it accepts, what it records, the mailbox and attachment endpoints, the reject switch, bounds, how reset and the test-endpoint guard apply, and how the compose stack and a PrestaShop shop are wired to it.
- `email-e2e-verification`: the end-to-end checks of the module's outgoing e-mails on the Docker stack: which messages are verified, which facts of each message are asserted, and the failure-path check.

### Modified Capabilities

- `mock-status-page`: the page and its JSON twin gain the Mail section (received messages, SMTP port, reject switch form, clear action), and the reset action also empties the mailbox.

## Impact

- **test-stack (submodule, separate repository).** New `app/smtp.py` (aiosmtpd handler and controller started in the FastAPI lifespan), mailbox state in `app/store.py` (outside the persisted snapshot), new routes and `status_data()` keys in `app/main.py`, the Mail section in `app/ui.py`, `IFIRMA_MOCK_SMTP_PORT` in `app/config.py`, `aiosmtpd` in `requirements.txt`, `EXPOSE 1025` in the Dockerfile, port and env in `docker-compose.yml` and `.env.example`, a new pytest module `tests/test_mock_mail.py` plus status-page tests, README sections, version 1.2.0.
- **Module repository.** `tests/smoke/run.php` (SMTP configuration block, mailbox helpers, scenario J, mail assertions in scenario C), `tests/smoke/README.md`, `docs/architecture.md`, `docs/troubleshooting.md`, `README.md`, the `test-stack` submodule pointer. No module code, templates or translations change; the unit suite is untouched.
- **Dependencies.** `aiosmtpd` (pure Python, MIT) in the mock image. No new container.
- **Developer workflow.** After a smoke run the dev shop keeps sending through the sink, so e-mails triggered from the back office show up on the mock status page instead of being dropped; the local compose overrides in `build/tools/` (git-ignored) decide whether 1025 is published on the host for each stack.
