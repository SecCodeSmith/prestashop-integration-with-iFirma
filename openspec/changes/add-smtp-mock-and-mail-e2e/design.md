# Design

## Context

See proposal.md - Why. Facts that shape the approach (read in this planning pass):

- **Where the module sends mail.** Three call sites, all `Mail::Send` with the module's own template folder (`_PS_MODULE_DIR_ . 'ifirma/mails/'`, `en` and `pl`): `classes/IfirmaDeliveryService.php::sendViaShopMail()` (template `receipt_issued`, subject `<shop name>: Invoice|Receipt <number>`, vars `{document_number}`, `{order_reference}`, `{firstname}`, `{lastname}`, `{shop_name}`, `{issue_date}`, attachment `['content' => pdf, 'name' => 'ifirma-<safe number>.pdf', 'mime' => 'application/pdf']`, 14th argument `$bcc` from `copyRecipient()`: `PS_SHOP_EMAIL` in mode `shop`, the employee's e-mail in mode `employee`, null when equal to the customer address ignoring case); `classes/IfirmaDataRequestNotifier.php::sendToMerchant()` (template `data_request_received`, to `IFIRMA_NOTIFY_EMAIL` else `PS_SHOP_EMAIL`, subject `Invoice data change request <reference>`) and `::sendToCustomer()` (templates `data_request_approved|rejected`, subjects "Your invoice data change was approved|rejected"). `deliver()` records `sent` only when `Mail::Send` returned true and `failed` with the message "The shop mail system refused to send the e-mail." when it returned false; `resend()` is `deliver()` with `manual`. Configuration keys: `IFIRMA_SEND_CHANNEL`, `IFIRMA_SEND_COPY_MODE`, `IFIRMA_SEND_COPY_EMPLOYEE`, `IFIRMA_NOTIFY_EMAIL`.
- **PrestaShop core, read in the two running containers (2026-11-07).** PS 8.1.7 `classes/Mail.php`: `Swift_SmtpTransport(PS_MAIL_SERVER, PS_MAIL_SMTP_PORT, PS_MAIL_SMTP_ENCRYPTION)` where `'off'` (case-insensitive) or unset becomes `false`; `setUsername`/`setPassword` are always called with `PS_MAIL_USER`/`PS_MAIL_PASSWD`; `catch (Swift_SwiftException)` logs `Swift Error:` with severity 3 and returns false; `addBcc()` per address after `Validate::isEmail`; attachments through `Swift_Attachment` with `setFilename($name)`; only an `empty()` check on server and port, no hostname validation. PS 9.1.4: Symfony Mailer `EsmtpTransport($server, $port, $isTls)` with `$isTls = false` for `'off'`/unset; `catch (ExceptionInterface)` logs `Mailer Error:` and returns false (transport exceptions implement it); `addBcc(new Address())`; `attach($content, $name, $mime)`; `PS_MAIL_TYPE` 3 = HTML plus the template's text part (9.1 adds type 4, auto text). Neither version reads a `PS_MAIL_DOMAIN`. Not verified in the vendor code: that both libraries skip `AUTH` for an empty username (both do by their documented behaviour; task 5.3 confirms it on the stack), and the exact `Validate::isEmail` regex.
- **Mock today.** `test-stack/ifirma-mock/app/`: `main.py` (FastAPI, `lifespan` calls `state.start(settings)`, every `/__mock/*` route starts with `_test_endpoints_disabled()`, `status_data()` feeds the page and `/__mock/status.json`, `set_switch()` handles the three switches, `mock_reset` calls `store.reset_all()`), `store.py` (module-level state under one `threading.Lock`, `_changed()` after every mutation feeds the persistence listener, `snapshot()`/`restore()` are the state-file contract), `state.py` (JSON file), `ui.py` (pure rendering with `html.escape`, `_switch_form()`, `render_page()` sections), `config.py` (`Settings` from `IFIRMA_MOCK_*`). Version `1.1.0` in `pyproject.toml` and the FastAPI `version`; `__init__.__version__` still says `1.0.0`. Dependencies: `fastapi`, `uvicorn[standard]`; dev: `pytest`, `httpx`. Tests use a module-level `TestClient(app)` (no lifespan) with an `autouse` fixture that calls `POST /__mock/reset`; `test_mock_state.py` uses `with TestClient(app)` where the lifespan matters. CI runs the suite in the test-stack repo and in this repo's `mock` job; new `.py` files need the MIT header (`test-stack/tools/check_license_headers.py`).
- **Stack.** `docker-compose.yml`: services `db`, `ifirma-mock` (8001, env `IFIRMA_MOCK_*`, volume `mock_data`), `prestashop` (module bind-mounted, demo data, customer `pub@prestashop.com`, `ADMIN_MAIL` default `admin@example.com`). Locally two stacks run from the same files with git-ignored overrides (`build/tools/local.yml`, PS 8.1 on 8099, project `ifirma-test-stack`; `build/tools/local91.yml`, PS 9.1.4 on 8091, project `ifirma-ps91`, mock published on 8002 through `ports: !override`). The mock has no bind mount: a code change needs `up -d --build --no-deps ifirma-mock` per project. Docker runs only inside WSL on this machine.
- **Smoke run.** `tests/smoke/run.php` (1015 lines): helpers `mockRequest()`, `httpRequest()` (cURL, JSON decode), `mockReset()` (exit 2 when the mock is down), `freshScenario()` (reset, clear the rate window, new order), scenarios A to I dispatched through a letter map filtered by `SMOKE_ONLY`, configuration block that writes the `IFIRMA_*` keys and `PS_MAIL_METHOD=3`. Scenario C submits a data request as the customer, holds, approves through `IfirmaAdminActions::approveRequest()` and asserts `delivery_status === 'sent'` "(PS_MAIL_METHOD 3)". `IfirmaAdminActions::resend($idOrder)` and `::rejectRequest($idRequest, $idEmployee, $reason)` exist. Runs as `docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/run.php` in each shop (about 90 checks).

## Goals / Non-Goals

**Goals:**

- A mail sink that behaves like a dumb relay (anything in, nothing out), lives in the process the stack already has, and is inspected the way the rest of the mock is inspected (`/__mock/*`, status page, pytest).
- Smoke assertions that read the actual bytes PrestaShop put on the wire: recipients from the SMTP envelope (the only place a blind copy is visible), subject, both bodies, the PDF attachment.
- The failure branch of the delivery spec ("Mail system failure") exercised end to end on both PrestaShop versions.
- No change to module code, templates, translations or the unit suite.

**Non-Goals:**

- Persisting captured mail across mock restarts, or a mailbox larger than a test session needs.
- Outbound relaying, TLS, `AUTH`, DKIM verification or a web mail client; the status page lists messages and links the raw source, nothing more.
- Running the smoke run in CI (it still needs the Docker stack).
- Verifying the iFirma-mail channel's e-mail content: iFirma sends that mail itself; the mock already records `POST {type}/send/{id}.json`.

## Decisions

### D1. The sink is an `aiosmtpd` controller inside the mock process

New `app/smtp.py`: a `MailboxHandler` (aiosmtpd `handle_DATA`, plus `handle_RCPT` accepting everything) and `start(port, host="0.0.0.0")` / `stop()` wrapping `aiosmtpd.controller.Controller` (own thread and event loop). `main.lifespan` calls `smtp.start(settings.smtp_port)` when the port is not `0` and `smtp.stop()` on shutdown. `config.Settings` gains `smtp_port: int = int(os.getenv("IFIRMA_MOCK_SMTP_PORT", "1025"))`. `requirements.txt` adds `aiosmtpd>=1.4,<2` (pure Python, Apache-2.0, no transitive native deps). The Dockerfile adds `EXPOSE 1025`; `docker-compose.yml` publishes `"1025:1025"` and sets `IFIRMA_MOCK_SMTP_PORT: ${IFIRMA_MOCK_SMTP_PORT:-1025}`; `.env.example` documents it. No TLS context is given to the controller, so the server never advertises `STARTTLS` (Symfony Mailer would use it opportunistically if advertised).

*Alternative:* a separate Mailpit/MailHog container. Rejected per the user's choice: a second service with its own reset and API, nothing on the mock status page, no reject switch for the failure branch.

*Alternative:* `smtpd` from the standard library. Rejected: removed in Python 3.12, which the image runs.

### D2. Mailbox state lives in the store, outside the persisted snapshot

`store.py` gains `_mail: collections.deque(maxlen=200)`, `_next_mail_id`, `_mail_reject: bool`, and functions `mail_add(record) -> id`, `mail_all()` (newest first), `mail_get(id)`, `mail_clear()`, `set_mail_reject(bool)`, `mail_reject()`. They take the existing lock but do **not** call `_changed()`: the mailbox and the switch are excluded from `_snapshot_unlocked()`/`restore()` on purpose (spec "Mailbox is bounded and memory only"), so a received mail never rewrites the state file. `_clear_unlocked()` empties the mailbox and clears the switch, so `reset_all()` (and therefore `POST /__mock/reset` and the page's reset) covers them. `reset_test_switches()` also clears `_mail_reject`.

The record stored per message (built in `smtp.py` with `email.message_from_bytes(data, policy=email.policy.default)`):

```json
{"id": 1, "received_at": "2026-11-07T10:00:00+00:00", "size": 48213,
 "envelope": {"mail_from": "shop@example.test", "rcpt_tos": ["pub@prestashop.com", "admin@example.com"]},
 "from": "shop@example.test", "to": ["pub@prestashop.com"], "cc": [], "bcc": ["admin@example.com"],
 "subject": "My Shop: Invoice 1/2026", "date": "Fri, 07 Nov 2026 10:00:00 +0000",
 "headers": {"From": "...", "To": "...", "Subject": "...", "Message-ID": "...", "Content-Type": "..."},
 "text": "...", "html": "<html>...",
 "attachments": [{"filename": "ifirma-1-2026.pdf", "content_type": "application/pdf", "size": 1532, "sha256": "..."}],
 "_raw": "<bytes, never serialised>", "_attachment_bytes": ["<bytes>"]}
```

`to`/`cc` come from the decoded headers (`getaddresses`, lower-cased addresses only); `bcc` = envelope recipients not in `to`+`cc`, order kept. `text`/`html` are the first `text/plain` and `text/html` parts that are not attachments; attachments are the parts with `Content-Disposition: attachment` (or a filename and no disposition header); parts with disposition `inline` or a `Content-ID` are listed under `inline` (filename, content type, content id, size, sha256, served at `/__mock/mail/{id}/inline/{index}`) and never counted as attachments, because PrestaShop embeds the shop logo that way in every HTML mail (found by the first real run of scenario N). The public JSON (`_public(record)`) drops the `_` keys; the listing additionally drops `headers`, `text`, `html` and replaces `attachments` with its count.

### D3. Mailbox routes and the reject switch

In `main.py`, each guarded by `_test_endpoints_disabled()` and none calling `register_call()`:

| Route | Answer |
|---|---|
| `GET /__mock/mail` | `{"mail": [listing...]}` newest first |
| `GET /__mock/mail/{id}` | full public record, 404 `{"detail": "Not Found"}` when unknown |
| `GET /__mock/mail/{id}/raw` | `Response(raw, media_type="message/rfc822")` |
| `GET /__mock/mail/{id}/attachments/{index}` | bytes with the attachment's content type and `Content-Disposition: attachment; filename=...`; 404 when the index is out of range |
| `DELETE /__mock/mail` | `{"cleared": true}` |
| `GET /__mock/mail/reject` | `{"enabled": bool}` |
| `POST /__mock/mail/reject` body `{"enabled": bool}` | same shape; a body without a boolean `enabled` answers 422 like the other switches |

The reject switch is read by `MailboxHandler.handle_DATA`: when on it returns `"554 5.7.1 Rejected by the ifirma-mock test switch"` and stores nothing (the SMTP client raises; PrestaShop's `Mail::Send` catches, logs and returns false). `set_switch()` gains `"mail-reject"` so the page form reuses it. `GET /` adds `"smtp_port"` and `"mailbox": "/__mock/mail"`.

### D4. Status page: a Mail section, the JSON twin, the reset

`status_data()` adds `settings.smtp_port`, `switches.mail_reject` and `mail` (the listing from `store.mail_all()`). `ui.py` gains `_mail(items, port, reject)` rendering: the port line ("SMTP listener on port 1025" or "SMTP listener off"), the reject form through the existing `_switch_form("mail-reject", ...)` posting to `/__mock/ui/switch/mail-reject`, a table (time, from, to, bcc, subject, attachments, raw link to `/__mock/mail/{id}/raw`) or the sentence "The mailbox is empty.", and a "Clear mailbox" button posting to the new `POST /__mock/ui/mail/clear` (303 back with `msg=Mailbox cleared`). The section sits between "Contractors" and "Reset" in `render_page()`. The reset text on the page mentions the mailbox. Every value goes through `_e()`.

### D5. How the shop is pointed at the sink

The smoke run writes, next to the `IFIRMA_*` block and replacing the `PS_MAIL_METHOD=3` line:

```php
Configuration::updateValue('PS_MAIL_METHOD', 2);
Configuration::updateValue('PS_MAIL_SERVER', $smtpHost);        // IFIRMA_SMTP_HOST, default ifirma-mock
Configuration::updateValue('PS_MAIL_SMTP_PORT', $smtpPort);     // IFIRMA_SMTP_PORT, default 1025
Configuration::updateValue('PS_MAIL_SMTP_ENCRYPTION', 'off');
Configuration::updateValue('PS_MAIL_USER', '');
Configuration::updateValue('PS_MAIL_PASSWD', '');
Configuration::updateValue('PS_MAIL_TYPE', 3);                  // HTML + the template's text part
```

`PS_MAIL_TYPE` is written explicitly because its default was not found in core and the assertions need both parts. The settings stay after the run (as `PS_MAIL_METHOD=3` did), which is the desired state of a dev shop: back-office mail lands on the status page. The README tells how to go back (`PS_MAIL_METHOD=3`, or the back-office Advanced Parameters > E-mail page).

The host name `ifirma-mock` is the compose service name, so it resolves from the PrestaShop container of each stack; the port never needs to be published for the smoke run. The two local overrides may map host port 1025 as they like (the 9.1 override already uses `ports: !override` for the mock), which is the developer's business, not the repo's.

### D6. Smoke run: helpers, scenario J, additions to scenario C

Helpers next to `mockRequest()`:

- `mailbox()` returns the listing (`GET /__mock/mail`), `mailGet($id)`, `mailClear()` (`DELETE /__mock/mail`), `mailReject($on)`; `mailCheck()` runs once before the scenarios: `GET /__mock/mail` must answer 200, otherwise `[FAIL] mail sink` naming the host and `exit(2)` ("Sink unreachable").
- `freshScenario()` calls `mailClear()` after `mockReset()` (reset already empties it, the explicit call keeps the intent visible).
- `assertMail(array $mail, array $expected)` compares `to`, `bcc`, subject substring, body substrings and attachment facts, printing one `[ OK ]`/`[FAIL]` per fact so the summary names what differed.

Scenario **N. E-mail delivery through the SMTP sink** (letter `N`; J to M were taken by the 1.5.0 bulk-runner scenarios that landed on master during this change):

1. Copy mode `none`: issue through the pipeline, read `mailbox()`, expect exactly one message; `to == [customer e-mail]`, `bcc == []`, `from` = `PS_SHOP_EMAIL`, subject contains `ifirma_number`, `text` and `html` contain the number and `IfirmaPayloadBuilder::buildOrderReference($order)`, one attachment `ifirma-<safe>.pdf` / `application/pdf`; download `/__mock/mail/{id}/attachments/0` and assert the first 5 bytes are `%PDF-` and the length equals the listed size.
2. Copy mode `shop` (set `IFIRMA_SEND_COPY_MODE=shop`, `PS_SHOP_EMAIL` already differs from `pub@prestashop.com`): `$actions->resend($order->id)`; a second message with the same `to`, subject and attachment name, `bcc == [strtolower(PS_SHOP_EMAIL)]`, `delivered_at` renewed.
3. Copy equal to the customer: temporarily set `PS_SHOP_EMAIL` to the customer address (upper-cased to prove the case-insensitive rule), resend, expect `rcpt_tos` of length 1 and `bcc == []`; restore `PS_SHOP_EMAIL`.
4. Refusal: fresh order in the same scenario (`createOrder()` again, mock not reset), `mailReject(true)`, run the pipeline; the receipt is issued (`ifirma_number` not empty), `delivery_status === 'failed'`, `delivery_error !== ''`, `mailbox()` unchanged; `mailReject(false)`, `resend`, `delivery_status === 'sent'`, `delivery_error === ''`, one new message. Restore `IFIRMA_SEND_COPY_MODE=none`.

Scenario **C** additions (the data-request mails): after `submit()`, one message to the merchant address (`IFIRMA_NOTIFY_EMAIL` or `PS_SHOP_EMAIL`) whose subject contains the reference and whose text contains `Smoke Sp. z o.o.`; after `approveRequest()`, the mailbox holds the approved mail to the customer (subject "Your invoice data change was approved", body with the reference) and the delivery mail, order not asserted; then a second request on a new order is submitted and `rejectRequest($id, 1, 'Smoke rejection reason')` runs: one message to the customer with the rejected subject, the reference and the reason in the body. The existing "delivery recorded as sent" label drops its "(PS_MAIL_METHOD 3)" suffix.

Both shops send synchronously inside `Mail::Send`, so no polling is needed; `mailbox()` is read right after the call. The README table gains the N row, the C row its mail facts, the limitations paragraph replaces the "Mail is switched off" bullet, the overrides table gains `IFIRMA_SMTP_HOST` and `IFIRMA_SMTP_PORT`, and the "Verified runs" table gets the new row once both shops pass.

**Findings of the first real runs (2026-11-07, folded into the implementation):** PrestaShop prefixes every subject with `[<shop name>] `, so subject checks are substring checks; `validateOrder()` in `createOrder()` sends the order-confirmation and payment mails through the same sink, so scenario N clears the mailbox after the order is created and counts relative to that, and scenario C finds its mails by subject and recipient; the templates and the notifier print `$order->reference`, not `buildOrderReference()`, so the bodies are checked against the plain reference; the second order of the refusal step runs with `bypass_guard` because the duplicate guard would flag it as the same buyer and amount; the shop logo arrives as an inline `cid:` image in every HTML mail, hence the `inline` list of D2. Scenario letters J to M were taken by the 1.5.0 smoke scenarios that landed on master meanwhile, so the e-mail scenario is N.

### D7. Mock tests

New `tests/test_mock_mail.py`: a module-scoped fixture starts `smtp.start(port=0, host="127.0.0.1")` (ephemeral port read back from the controller) and stops it at the end, independent of the FastAPI lifespan; messages are sent with `smtplib.SMTP` and built with `email.message.EmailMessage` (text + HTML + a PDF attachment, an RFC 2047 subject, two `RCPT TO` with one missing from `To`). Tests cover every scenario of `mock-smtp-sink`: plain session, no credentials, listener disabled (`settings.smtp_port = 0` with `with TestClient(app)` starts no controller), multipart parsing, bcc derivation, encoded subject, listing order and fields, detail fields, attachment download and sha256, 404s, clear, reject (`smtplib.SMTPDataError` with code 554, mailbox unchanged), switch read-back, the 200 cap (send 201 small messages), restart emptiness (`with TestClient(app)` twice with a `tmp_path` state file: documents restored, mailbox empty), reset, test endpoints disabled (404 on every mail route while SMTP still stores), not API traffic, root answer. `tests/test_mock_status.py` gains the five page scenarios and the two modified ones (reset also clears mail, JSON twin carries `mail`, `switches.mail_reject`, `settings.smtp_port`).

### D8. Order of work, ownership, versions

Two repositories, so two commit series: groups 1-3 in `test-stack` (push, CI green), then the module repository bumps the submodule pointer and lands groups 4-6. The mock version becomes `1.2.0` in `pyproject.toml`, the FastAPI `version` and `__init__.__version__` (fixing the stale `1.0.0`). Parallel agents: group 1 (`smtp.py`, `store.py` mailbox, `config.py`) and group 2 (routes, `ui.py`, `status_data()`) touch disjoint regions and can run together once the store function names above are fixed; group 3 (stack files, README) is independent; group 4 (smoke run) can be written against this design and verified only after both mocks are rebuilt; group 5 is the lead's integration run on both shops; group 6 is docs.

## Risks / Trade-offs

- [Symfony Mailer (PS 9.1) negotiates `STARTTLS` opportunistically] → the controller is started without a TLS context, so `STARTTLS` is never advertised; task 5.3 runs the scenario on 9.1 to prove it.
- [Either mailer attempts `AUTH` with an empty username] → documented behaviour of both libraries is to skip it; if 5.3 shows an `AUTH` attempt, the sink gains an `AUTH` handler that accepts anything (aiosmtpd supports `auth_required=False` with a permissive authenticator) rather than the shop getting credentials.
- [`Validate::isEmail` rejects a demo or shop address] → the addresses used (`pub@prestashop.com`, `admin@example.com`) are ordinary; the run prints the `[FAIL]` with PrestaShop's log line (`Swift Error:` / `Mailer Error:`) if it happens.
- [aiosmtpd's thread calls into `store` concurrently with request handlers] → the store already serialises everything under one `threading.Lock`; the mailbox functions take it too.
- [The mailbox cap hides a message in a long manual session] → 200 is far above a smoke run (about 10 messages); the status page shows the count so a truncated session is visible.
- [Host port 1025 collides when both local stacks publish it] → only the base compose file publishes it; the 9.1 override already replaces the mock's `ports` list and simply does not add 1025.
- [A stale mock on one stack after the test-stack bump] → the smoke run's `mailCheck()` exits with code 2 and names the host, instead of 404-failing ten assertions (the pattern seen with `/__mock/pdf-not-ready` on 2026-11-07).
- [`PS_MAIL_TYPE` written by the run changes the dev shop's mail layout] → it is the same value PrestaShop ships with in practice; the README notes it with the other settings the run writes.
