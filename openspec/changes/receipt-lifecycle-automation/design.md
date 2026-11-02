# Design

## Context

See proposal.md - Why. Relevant current state (observed in the code):

- One `ifirma_receipt` row per order; `status` is `created` / `paid` / `error`; `isIssued()` = has `ifirma_id` and not `error`. A failed attempt reuses the same row.
- `hookActionOrderStatusPostUpdate` issues on a single configured status (`IFIRMA_AUTO_CREATE_STATE`) with only a local duplicate check; nothing is sent to the customer.
- `IfirmaApiClient` signs `url-without-query + user + keyName + body` with HMAC-SHA1; `post()`/`get()` return decoded JSON or `null`; HTTP >= 400 only sets `lastError`. No request accounting, no error-code mapping.
- `buildInvoicePayload()` sets `Kontrahent.Identyfikator` to 40 characters of the e-mail (documented max is 15), writes no `Uwagi`, and `fetchDocumentNumber()` passes `dataDo` but the generic list helper does not enforce it.
- Front: `receipt` controller streams the PDF for the logged-in owner by token; `displayOrderDetail` renders only when a document is issued.
- No PHP tests; CI = `php -l` + license headers + the mock's pytest. `test-stack/` is a separate git repository (nested, not a submodule) containing a FastAPI mock with HMAC verification and a docker-compose PrestaShop.

iFirma API facts that constrain the design (verified against api.ifirma.pl on 2026-11-01):

- `GET faktury.json` params: `dataOd` (required), `dataDo` (defaults to +30 days), `kontrahent` (contractor identifier), `nipKontrahenta`, `typ` (e.g. `prz_faktura_kraj`, `prz_faktura_paragon`), `status` (`oplacone`, `nieoplacone`, `oplaconeCzesciowo`, `przeterminowane`), `strona`, `iloscNaStronie` (default 20), `kwotaOd`, `kwotaDo`. Rows: `FakturaId`, `PelnyNumer`, `Rodzaj`, `KontrahentNazwa`, `IdentyfikatorKontrahenta`, `NIPKontrahenta`, `DataWystawienia`, `Brutto`, `Waluta`, `Zaplacono`, `TerminPlatnosci`, `CzyWyslano`, `CzySzkic`, `RodzajPowiazanegoPrzychodu`, `NumerPowiazanegoPrzychodu`, `KsefNumer`, `KsefStatus`. No search by number, notes or order reference; no total count.
- `GET {fakturakraj|fakturaparagon}/{id}.json` exists but its shape is undocumented.
- Create payload: `Kontrahent.Identyfikator` <= 15 chars (null = auto-generated), `Uwagi` <= 1000, `DodatkoweUwagi {Typ: NUMER_ZAMOWIENIA|NUMER_UMOWY|WARUNKI_TRANSPORTU, Data?, Info}`. No `NumerZamowienia` field.
- `POST {type}/send/{id}.json` (`Tekst` <= 1000, `SkrzynkaEmailOdbiorcy`, `SzablonEmail`, `SkrzynkaEmail`, `Przelew`, `Pobranie`) is documented for `fakturakraj` and seven other types, **not** for `fakturaparagon`. Response undocumented.
- No plain-receipt (paragon) endpoint; no edit/delete of issued documents; corrections only via `fakturakraj/korekta/{id}.json`. No webhooks. Limits: 100 requests/min, 15,000/day; `GET abonent/limit.json` (key `abonent`) reports usage. Business errors come as HTTP 200 with non-zero `Kod` (100, 101, 102, 200, 201, 202, 400, 401, 402, 403, 499, 500).
- iFirma sells its own PrestaShop integrator that imports orders and, after payment, issues the invoice, marks it paid and e-mails it. Its documents carry no documented source marker.

Constraints: PrestaShop 1.7.0 - 9.x, PHP 7.2 - 8.x (no typed properties, `array()` style kept), no Composer autoload at runtime (explicit `require_once`), no cron inside PrestaShop, module must stay packageable by the existing allow-list zip script.

## Goals / Non-Goals

**Goals:**
- Never create a second iFirma document for an order when one can be detected, including documents the module did not create.
- Make the trigger-status path a complete, idempotent pipeline (guard -> issue -> deliver) whose every step records its outcome on the receipt row.
- Keep the customer informed at every stage from the order page, and let them intervene before issuance.
- Stay within iFirma's rate limits and documented payload constraints.
- Make the behaviour testable without a live iFirma account.

**Non-Goals:**
- Issuing fiscal receipts (paragon) - no API exists; the module keeps the `fakturaparagon` / `fakturakraj` choice.
- Editing, cancelling or correcting an issued document (no API for edit/delete; `korekta` is a later change).
- Multiple trigger statuses, time-delayed issuance, or a built-in scheduler - PrestaShop has no cron; the module exposes an endpoint for an external one.
- Changing the customer's stored PrestaShop address when a data request is approved (see D6).
- KSeF submission; the module only displays `KsefStatus` / `KsefNumer` when the list returns them.

## Decisions

### D1. Duplicate detection = list window + identity + confirmation by stamped reference

**Choice.** Every document the module creates is stamped with an order reference `PS-ORDER-{id_order}-{order.reference}` in `Uwagi` and in `DodatkoweUwagi {Typ: "NUMER_ZAMOWIENIA", Info: <reference>}`. Before issuing, the guard calls `faktury.json` with `dataOd = order.date_add - 1 day`, `dataDo = today`, `iloscNaStronie = 100`, paging through `strona` until a short page, filtered by `kontrahent = <our identifier>` first; if no rows and the order has a NIP, a second query by `nipKontrahenta`; finally, if the window is small (<= configured `IFIRMA_DUPLICATE_WINDOW_DAYS`, default 60), an unfiltered query on the same window. Candidate rows are those with `CzySzkic = false`, `Rodzaj` in the two types the module can produce, `Waluta` equal to the order currency and `|Brutto - order.total_paid_tax_incl| <= tolerance` (default 0.01). For each candidate the guard fetches `{type}/{FakturaId}.json` and searches the serialized response for the order reference token (shape is undocumented, so the match is a substring search over the JSON text, not a field path). Outcomes:

- reference found -> **confirmed match**: adopt the document (store `ifirma_id`, `ifirma_number`, `document_type` from `Rodzaj`, `source = external`, `status` from `Zaplacono`), no creation.
- candidates exist but none confirmed -> **suspected duplicate**: write a receipt row with `status = duplicate_suspected`, `duplicate_candidates` JSON (id, number, date, gross, contractor), no creation; admin resolves with *Link* (adopt the chosen candidate) or *Issue anyway* (bypass once).
- no candidates -> proceed.
- list call fails -> guard is **inconclusive**: auto-issuance aborts and records `last_error`; manual issuance shows the error and offers *Issue anyway*.

**Why.** iFirma offers no search by order reference and no source marker, so identity must come from what the module controls (the identifier it sends and the reference it stamps) plus the only comparable fields in list rows (gross, contractor, date). Reading the detail JSON for the reference is the only way to make a positive match; the substring search keeps the undocumented shape from breaking the guard. Blocking rather than skipping on "amount+contractor match but no reference" is deliberate: a pre-1.1.0 module document or an integrator document looks exactly like that, and a wrong "proceed" costs the merchant a duplicate invoice in their books.

**Alternatives.** (a) Trust the local table only - fails the stated requirement. (b) Match on amount+date only - too many false positives for shops with repeated prices; used only as the "suspected" tier. (c) Use `{type}/list.json?limit=N` - response undocumented and no contractor filter. (d) Rely on `Kontrahent.Identyfikator` alone - external sources use their own identifiers.

### D2. Deterministic 15-char contractor identifier (see D12 for registration)

**Choice.** `Kontrahent.Identyfikator = 'PS-C' . id_customer` (e.g. `PS-C12345`, <= 15 chars for any realistic id; guests still have an `id_customer`). Private persons therefore get a stable identifier too, and the list filter `kontrahent=` becomes usable for the guard.

**Why.** The current value (40 chars of e-mail) exceeds the documented maximum and is not filterable; a per-customer key also matches how iFirma deduplicates contractors (identifier, then NIP). Alternative: let iFirma auto-generate (`null`) - loses the filter.

### D3. Issue-and-send pipeline is one orchestrator with per-step state on the receipt row

**Choice.** A new service runs the trigger path and the manual "Create" path through the same steps: `hold check -> duplicate guard -> create -> number lookup -> deliver`. Each step writes to the receipt row: `status` (`duplicate_suspected`, `created`, `paid`, `error`, `unknown`), `source` (`module` / `external`), `delivery_status` (`none`, `sent`, `failed`, `skipped`), `delivery_channel`, `delivered_at`, `delivery_error`, `last_error`. The trigger hook only invokes the orchestrator; failures are logged with `PrestaShopLogger` and never throw into PrestaShop's status update.

**Create is never retried blindly.** If the create POST returns no parseable response (timeout, HTTP error without body), the row is marked `status = unknown` with the error; the next attempt (auto or manual) must pass the duplicate guard, which will find the document if iFirma actually created it. Validation errors (`Kod` 200/201) are safe to retry after fixing the payload; a `201` whose message names `DodatkoweUwagi` triggers one retry without that object (feature is new in 2026 and may be gated per account).

**Why.** A single orchestrator removes the current drift between manual and automatic paths and makes the hold/guard rules impossible to bypass accidentally. Recording per-step state lets both panels render the lifecycle without re-deriving it.

### D4. Delivery channel: shop mail with PDF attachment by default, iFirma `send` optional

**Choice.** `IFIRMA_SEND_CHANNEL` in `{shop_mail, ifirma_mail, none}`, default `shop_mail`. `shop_mail` fetches the PDF (`fetchPdf`) and sends it with `Mail::Send` using module mail templates (`mails/{iso}/receipt_issued.{html,txt}`) in the order's language, to the order customer's e-mail, from the shop. `ifirma_mail` calls `POST {type}/send/{id}.json` with `SkrzynkaEmailOdbiorcy = customer e-mail` and a configurable `Tekst`; it is only used when `document_type` is in the documented send list (`fakturakraj`), otherwise the module falls back to `shop_mail` and records `delivery_channel = shop_mail_fallback`. A successful iFirma send is confirmed by `Kod = 0` (response shape undocumented; any other response is a failure). An admin *Send again* action re-runs delivery.

**Why.** Shop mail works for both document types, is branded and translatable, and can be exercised against the mock. iFirma send keeps `CzyWyslano` consistent in iFirma's own UI, which some merchants rely on, so it stays available where documented. Alternative: always iFirma send - unavailable for `fakturaparagon`, the default type.

### D5. Monitoring = polling in three modes, one list call per batch, throttled

**Choice.** A sync service refreshes receipts from `faktury.json` rows (never from detail GETs, to save budget): `remote_paid_amount = Zaplacono`, `remote_sent = CzyWyslano`, `remote_draft = CzySzkic`, `ksef_status`, `ksef_number`, `linked_document = NumerPowiazanegoPrzychodu`, `remote_missing` when the row for `FakturaId` is absent from its `DataWystawienia` day, `last_sync_at`, `sync_error`. `status` becomes `paid` when `Zaplacono >= Brutto - tolerance`. Modes:

- **On demand**: admin *Refresh from iFirma* button (one list call for the document's issue date).
- **Opportunistic**: when the owning customer opens the order detail page and `last_sync_at` is older than `IFIRMA_SYNC_INTERVAL_MIN` (default 15 min); at most one list call per page view, with a short 5 s timeout; failures are silent to the customer.
- **Bulk**: front controller `cron` guarded by `IFIRMA_CRON_TOKEN` (generated at install/upgrade, shown in the config form) selects non-terminal receipts (`status != paid` or `remote_sent = 0` or `last_sync_at` older than 24 h), groups them by issue date, and spends at most `IFIRMA_CRON_MAX_CALLS` (default 40) list calls per run. Responds with a JSON summary; wrong token -> 403; documented for external cron (`*/15 * * * *`).

**Why.** No webhooks exist. Grouping by issue date lets one list call update many receipts. The limits (100/min, 15,000/day) are shared with every other API consumer the merchant runs, so the module caps its own usage per run and per minute (see D8). Alternative: detail GET per receipt - burns the limit linearly.

### D6. Customer data-change request = separate table, payload override, hold on auto-issuance

**Choice.** New table `ifirma_data_request` (`id_ifirma_data_request`, `id_order`, `id_customer`, `status` in `pending`/`approved`/`rejected`/`cancelled`, `requested_data` JSON (as submitted), `approved_data` JSON (final data after optional admin edits, null until approved), `customer_note`, `admin_note`, `resolved_by` employee id, `date_add`, `date_upd`, `date_resolved`). Requested fields: `want_invoice_for_company` (bool), `company`, `vat_number`, `firstname`, `lastname`, `address1`, `address2`, `postcode`, `city`, `id_country`. Rules enforced server-side: customer must own the order, order must have no issued document (`isIssued()` false and no `external` adoption), at most one `pending` request per order, feature switch `IFIRMA_ALLOW_DATA_REQUEST` on, CSRF token on the form. Submission notifies the merchant (`IFIRMA_NOTIFY_EMAIL`, default shop e-mail) with the diff against the current invoice address. Admin resolves in the order panel: *Approve* (optionally editing the fields) or *Reject* (reason required); the customer receives a decision e-mail. An approved request becomes the **payload override**: `buildInvoicePayload()` builds `Kontrahent` from its `approved_data` instead of the PrestaShop invoice address, and the override is recorded in `request_payload`. While a `pending` request exists the orchestrator's hold check stops auto-issuance (`last_error = "held: data request #N pending"`); the admin panel's manual *Create* shows the hold and requires the request to be resolved first. On resolution, if auto-issue is enabled and the order's history already contains the trigger status, the orchestrator runs immediately. The customer can cancel their own pending request.

**Why.** Storing an override instead of mutating the `ps_address` row avoids changing data shared with other orders and keeps an audit trail of exactly what was sent to iFirma. A dedicated table (rather than JSON on the receipt row) supports the request lifecycle independently of whether a receipt row exists yet. Alternatives: (a) edit the order's invoice address via `Address` - side effects on other orders and on PrestaShop's own invoice; (b) free-text request only - cannot be applied automatically, admin would retype.

### D7. Customer visibility is a state machine rendered from receipt + request rows

**Choice.** `displayOrderDetail` always renders a block (when the module is configured) with one of: `not_issued` (with the trigger status name when auto-issue is on, plus the request CTA when allowed), `held` (pending request #N, with cancel option), `verifying` (covers `duplicate_suspected`, `unknown`, inconclusive guard - neutral wording, internal details are not exposed), `issued` (number, issue date, paid/unpaid from remote fields, "sent on <date>" or "not yet sent", download link), `missing` ("no longer available, contact the shop"). The account block lists issued documents plus a count of orders with pending requests. All wording is translatable and never exposes `last_error` text to customers.

**Why.** The requirement is that customers can follow the receipt, not only download it. Hiding internal error detail prevents leaking iFirma messages or merchant configuration problems.

### D8. API client hardening: error mapping, request accounting, rate guard

**Choice.** `IfirmaApiClient` gains: `getDetail($type, $id)` returning raw JSON text and decoded array; `send($type, $id, array $body)`; `getLimit()` (only when the `abonent` key is set); a `Kod -> message` map for 100, 101, 102, 200, 201, 202, 400, 401, 402, 403, 499, 500 surfaced through `getLastError()`; a per-process request counter and a minute-window counter persisted in `Configuration` (`IFIRMA_RATE_WINDOW = "<yyyymmddHHMM>:<count>"`) that refuses new calls above `IFIRMA_RATE_MAX_PER_MIN` (default 80, below the documented 100 to leave room for other consumers) with a distinct `rate_limited` error that callers treat as transient (no `error` status written to receipts). All list calls always include `dataDo`. The HTTP transport is injectable so tests can script responses.

**Why.** The documented limits are per user across all clients; refusing locally is cheaper and more predictable than discovering HTTP 429 behaviour that iFirma does not document. Mapped codes turn "Kod 403" into "invalid hash - check the API key" for the merchant.

### D9. Data model and upgrade

New columns on `ifirma_receipt`: `source VARCHAR(16) DEFAULT 'module'`, `order_reference VARCHAR(64)`, `issue_date DATE NULL` (iFirma `DataWystawienia`, used as the sync lookup key), `delivery_status VARCHAR(16) DEFAULT 'none'`, `delivery_channel VARCHAR(32)`, `delivered_at DATETIME NULL`, `delivery_error TEXT NULL`, `remote_paid_amount DECIMAL(20,6) NULL`, `remote_sent TINYINT(1) DEFAULT 0`, `remote_draft TINYINT(1) DEFAULT 0`, `remote_missing TINYINT(1) DEFAULT 0`, `ksef_status VARCHAR(32)`, `ksef_number VARCHAR(64)`, `linked_document VARCHAR(128)`, `last_sync_at DATETIME NULL`, `sync_error TEXT NULL`, `duplicate_candidates TEXT NULL`, `id_data_request INT UNSIGNED NULL`. New table per D6. Module version -> 1.1.0 with `upgrade/upgrade-1.1.0.php` (idempotent: column existence checked via `information_schema` before `ALTER TABLE ... ADD COLUMN`, `CREATE TABLE IF NOT EXISTS`, new config defaults, cron token generation). `sql/install.php` creates the final schema for fresh installs; `sql/uninstall.php` drops both tables.

### D10. Verification strategy without a live iFirma account

- **PHPUnit (CI, no PrestaShop):** `tests/bootstrap.php` defines `_PS_VERSION_` and minimal stubs (`Configuration` backed by an array, `Db` fake returning scripted rows, `ObjectModel` with in-memory save, `Order`, `Address`, `Customer`, `Country`, `Module`, `Tools`, `Validate`, `Mail` capturing sends, `PrestaShopLogger`, `Context`, `Link`). Pure logic is extracted into testable classes: duplicate matcher (rows + order -> outcome), payload builder constraints, status mapper, request state machine, rate guard, auth header (vector identical to the mock's `sign()`). The HTTP layer is injected so tests replace cURL with scripted responses. Dev-only `composer.json` (PHPUnit 9 on PHP 8.2 in CI); the zip allow-list already excludes `tests/`, `composer.*`, `vendor/`.
- **Mock (test-stack repo):** `faktury.json` honours `dataOd`/`dataDo`/`kontrahent`/`nipKontrahenta`/`typ`/`status`/`strona`/`iloscNaStronie`; `GET {type}/{id}.json` returns the stored payload plus `Identyfikator`, `PelnyNumer`, `Zaplacono`; `POST {type}/send/{id}.json` for `fakturakraj` (sets `CzyWyslano`, records recipient) and `Kod 200` for unsupported types; `GET abonent/limit.json`; test-only `POST /__mock/documents` (seed an "external" document with arbitrary contractor/amount/notes) and `DELETE /__mock/documents/{id}` (simulate deletion); optional `IFIRMA_MOCK_RATE_LIMIT` to return a failure after N calls/min. pytest covers each.
- **Smoke (docker):** `tests/smoke/run.php` executed with `docker compose exec prestashop php modules/ifirma/tests/smoke/run.php` after bootstrapping `config/config.inc.php`; it configures the module for the mock, creates an order via PrestaShop classes, seeds an external duplicate in the mock, asserts the guard adopts/blocks correctly, resolves, issues, verifies delivery (`PS_MAIL_METHOD = 3` so `Mail::Send` succeeds without SMTP), runs the cron endpoint and checks sync fields. Exit code non-zero on any assertion failure; documented in `test-stack/README.md`.

### D11. Payment method follows the order

**Choice.** `SposobZaplaty` is resolved per order by a small resolver: mode `IFIRMA_PAYMENT_METHOD_MODE` (`order`, default, or `fixed`); in `order` mode the order's payment module technical name (`Order::$module`) is looked up in the merchant map `IFIRMA_PAYMENT_METHOD_MAP` (`module=CODE` lines, trailing `*` wildcard), then in a built-in map (`ps_wirepayment=PRZ`, `ps_checkpayment=CZK`, `ps_cashondelivery=POB`, `paypal*=PAL`, `payu*=ALG`, `przelewy24*`/`p24*=P24`, `tpay*=TPA`, `dotpay*=DOT`, card gateways such as `stripe*`/`adyen*`/`braintree*`/`sumup*=KAR`, other online gateways `=ELE`), then by keywords in the order's payment label (Polish and English), and finally falls back to `IFIRMA_PAYMENT_METHOD`, which keeps its current meaning as the fixed/fallback code and now offers all 14 documented iFirma codes.

**Why.** iFirma documents the payment method per invoice and merchants reconcile by it; one fixed code mislabels cash-on-delivery and card sales. Module names are stable identifiers, labels are the fallback because merchants rename them. Alternatives: (a) map by `payment` label only - breaks on renames and translations; (b) ask the merchant to map every module by hand - the built-in map covers the common Polish gateways so most shops need no map at all.

### D12. Register the contractor before issuing (production finding, 2026-11-02)

**Choice.** iFirma rejects a document whose `Kontrahent.Identyfikator` does not name an existing contractor (`Kod` 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze"); it only auto-creates the contractor when the identifier is null. The module therefore registers the contractor first: `GET kontrahenci/id/PS-C{id}.json`; missing -> `POST kontrahenci.json` with the billing data; present but shop-owned fields differ -> `PUT kontrahenci/PS-C{id}.json` with the stored record merged with the shop-owned fields (the PUT replaces the whole record, so merchant-maintained fields are carried over through a documented-field whitelist); any contractor-API failure, or a 201 "contractor not found" on the create itself, falls back to a null identifier for that document. `IFIRMA_CONTRACTOR_SYNC` (default on) disables the whole step. The buyer block now sends `Kraj` as the country name and `KodKraju` as the ISO code, as documented.

**Why.** One stable contractor per customer keeps the duplicate guard's `kontrahent=` filter effective and keeps iFirma's contractor list clean; the null-identifier fallback guarantees issuance never blocks on the contractor API. Alternative: always send null (simplest) - creates a new contractor per document and weakens the guard; kept as the opt-out.

## Risks / Trade-offs

- [Undocumented detail JSON may not include `Uwagi`/`DodatkoweUwagi`] -> Reference confirmation would never succeed and every amount match would block as "suspected". Mitigation: first implementation task captures a real detail response (merchant sandbox or a 1 PLN production test) and fixes the matcher; the substring search tolerates any nesting; admin *Link* keeps the flow unblocked meanwhile.
- [Integrator documents with different gross (e.g. shipping handled differently) escape the amount filter] -> Not detected; duplicate possible. Mitigation: configurable amount tolerance (`IFIRMA_DUPLICATE_AMOUNT_TOLERANCE`, default 0.01) and the README caveat telling merchants not to run both automations on the same account.
- [`DodatkoweUwagi` rejected on some accounts] -> Create fails with `Kod 201`. Mitigation: one retry without the object (D3), reference still present in `Uwagi`.
- [`fakturaparagon` send endpoint unknown] -> Delivery via iFirma would fail for the default type. Mitigation: channel restricted to documented types; fallback to shop mail; documented.
- [Rate limit shared with other API clients of the merchant] -> Local guard cannot see other consumers' usage. Mitigation: conservative local cap, optional `abonent/limit.json` display in the config form, cron call cap.
- [Opportunistic sync on customer page adds latency] -> One list call could stall the page. Mitigation: dedicated 5 s timeout for opportunistic sync, interval throttle, failures swallowed.
- [Payload override diverges from PrestaShop's own invoice address] -> The PrestaShop PDF invoice and the iFirma document may show different buyer data. Mitigation: admin panel shows the override prominently; README documents that the override applies only to iFirma documents.
- [Hold with no request resolution] -> Receipt never issued. Mitigation: pending requests listed in the admin order panel with age; merchant e-mail on submission; cron summary reports count of held orders.
- [Upgrade on large `ifirma_receipt` tables] -> `ALTER TABLE` locks. Acceptable (one row per order with a document); upgrade is idempotent so a retry is safe.
- [Test stubs drift from real PrestaShop behaviour] -> Unit tests pass while the module fails in PrestaShop. Mitigation: smoke run in the Docker stack is part of the release checklist; stubs only cover what the tests need.

## Migration Plan

1. Ship as module version 1.1.0; PrestaShop runs `upgrade/upgrade-1.1.0.php` on module upgrade (columns, table, config defaults, cron token).
2. Existing receipts keep `source = module`, `order_reference = NULL`; the guard treats them as confirmed by their stored `ifirma_id` (local record wins).
3. Merchants review new settings: send channel (default shop mail - **documents start being e-mailed on the next trigger**; the config form warns about it and `IFIRMA_SEND_CHANNEL = none` disables), data-request switch (default on), cron token and schedule.
4. Rollback: reinstalling 1.0.0 leaves the extra columns/table in place (harmless); 1.0.0 code ignores them.

## Open Questions

- Exact JSON shape of `GET {type}/{id}.json` (captured during implementation; affects only the matcher's internals, not the spec).
- Whether iFirma's `send` response carries `Kod`; until confirmed the module treats any response without `Kod = 0` as a failure and lets the admin resend.
- Whether the merchant wants the merchant-notification e-mail to also go to the order's assigned employee - defaults to the shop e-mail; a later change can add routing.
