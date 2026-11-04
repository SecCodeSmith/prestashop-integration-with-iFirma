# Design

## Context

See proposal.md "Why" for the report that motivates this change. Current state that shapes the approach:

- `IfirmaContractorService::ensure()` resolves the contractor in five steps (stored mapping, module identifier `PS-C{id}`, NIP search, name search, create). The create step, `createAndGetIdentifier()`, reads `Wynik` of the create answer as a scalar, then calls `fetch()` (`GET kontrahenci/id/{id}.json`, which expects `Wynik` as a one-element list) for the sent and the reported identifier, once each, immediately. When neither answers it sets an English `lastSearchError` and `ensure()` records action `fallback`. The search helpers `findByNip()` and `findByName()` already implement the only matching rules the spec allows (exact NIP, then name confirmed by e-mail or postcode) but run only before the create. The service holds nothing but the API client and has no translation facility; `IfirmaReceiptService` builds it with `new IfirmaContractorService($this->client)`, and `IfirmaAdminActions` with `IfirmaContractorService::build($module)`.
- The contractor outcome is stored as `_contractor` (`action`, `message`, `matched_by`, `identifier`, optional `document_identifier`) inside `request_payload`, and `ifirma.php::contractorActionLabel()` renders the translated action label with the raw `message` appended only for `fallback`. The attached contractor learned after issuance (`learnDocumentContractor()`) already covers the "document issued without reference" path.
- Issuance and delivery run synchronously inside `hookActionOrderStatusPostUpdate`; there is no queue and no retry state on `ifirma_receipt`. `IfirmaDeliveryService::sendViaShopMail()` calls `IfirmaReceiptService::fetchPdf()` once; `IfirmaApiClient::getBinary()` reports the failure class through `getLastKod()`, `getLastHttpCode()`, `isRateLimited()` and `lastRequestWasSent()`. The same `fetchPdf()` serves the customer download in `controllers/front/receipt.php`. `IfirmaDeliveryService` already takes an injectable clock; nothing in the module sleeps.
- `Mail::Send` is called with eleven arguments (through `templatePath`); PrestaShop's signature continues with `die`, `idShop`, `bcc`, `replyTo`, `replyToName`. The test stub records every argument, including `bcc`. Core offers no CC parameter.
- The configuration page is one `HelperForm` with `postProcessConfiguration()` collecting errors and saving nothing when any exists; defaults live in `IfirmaSchema::configurationDefaults()` and drive install, uninstall and `ConfigurationKeysTest`. Translations are md5-keyed entries in `translations/pl.php`, generated from `tools/pl_translations_map.php`, and `TranslationCoverageTest` fails on any untranslated `l()` string in `ifirma.php`, `classes/`, `controllers/` and templates.
- The mock (`test-stack/`, separate repository) stores a created contractor under the sent identifier and answers `Wynik` with it; its PDF endpoint has no failure injection; test-only endpoints live under `/__mock/` and `POST /__mock/reset` clears state.

## Goals / Non-Goals

**Goals:**
- Reference the contractor iFirma actually stored, whatever identifier it carries, before issuing; fall back only when it is genuinely not findable.
- Make every contractor-step note self-explanatory and Polish in the Polish back office, and record iFirma's read-back answers so the next incident can be diagnosed from the panel.
- Absorb a PDF that is not ready immediately after issuance, within the ten-second bound the merchant proposed, without changing the pipeline contract or the receipt schema.
- Let the merchant receive a copy of each shop-mail delivery without touching the customer-facing template.
- Keep every new behaviour unit-testable without real waits and reproducible in the Docker stack.

**Non-Goals:**
- A queued or cron-driven delivery retry (would need attempt columns and cron changes; see Risks).
- Retrying the customer-page PDF download or the document-number lookup.
- A visible CC, a second e-mail, or a copy for the iFirma-mail channel.
- Showing the copy recipient on the order panel (no schema change in this change).
- Changing the pre-create matching rules, the duplicate guard, or the `learnDocumentContractor()` behaviour.

## Decisions

### D1. Read-back sequence inside the create step

**Choice.** `createAndGetIdentifier(array $contractor, array $hints = array())` runs: (1) candidates = unique non-empty values of the sent `Identyfikator`, the scalar `Wynik`, `Wynik.Identyfikator` when `Wynik` is an object, and a top-level `Identyfikator` of the answer; `fetch()` each; (2) on no hit, one wait of `READBACK_WAIT_SECONDS` (2) through an injectable sleeper, then `fetch()` each candidate again; (3) on no hit, `findByNip($hints)` then `findByName($hints)` (the existing private helpers, with the hints `ensure()` already completes); (4) a hit returns its identifier and sets `assignedIdentifier` when it differs from the sent one; nothing returns `false`. `fetch()` also accepts `Wynik` as a single object with `Identyfikator`. `ensure()` passes its `$hints` to the create step and links the returned identifier with match type `module` as today.

**Why.** The three plausible causes of the report are covered in order of cost: the answer-shape and reported-identifier cases cost nothing extra; the delayed read-back costs one request only when the first pass fails; the search reuses the matching rules the spec already declares safe (exact NIP; name confirmed by e-mail or postcode) and is what finds a contractor iFirma stored under its own identifier such as `IFI1909556979`. Linking as `module` keeps the panel semantics ("the module's contractor for this customer").

**Alternatives.** Searching before any by-identifier read-back: rejected, the identifier path is cheaper and is the documented one. Several waits with backoff: rejected, the create step runs inside the status-change request and the delivery retry already spends up to ten seconds. Treating the `Wynik` identifier as authoritative without read-back: rejected, the observed `Wynik` echoed the sent identifier that could not be read back.

### D2. Structured notes translated at render time

**Choice.** `ensure()` records, next to `identifier`/`action`/`message`, a `note` array: `code` plus `params`. Codes: `lookup_failed` (identifier, error), `update_failed` (identifier, error), `create_rejected` (error), `readback_failed` (tried: list of `identifier` + `answer`; search: `none`, `ambiguous` or `error` with the message), `assigned` (identifier). `answer` is "Kod N: Informacja", "empty result" or the transport error, built from the client's last Kod/error after each `fetch()`. `encodeRequest()` stores `note` and `assigned_identifier` under `_contractor`; `message` keeps the English sentence for diagnostics and older panels. `ifirma.php::contractorActionLabel()` renders the code with `$this->l()` templates (`sprintf`) and falls back to the raw `message` when no `note` exists; the `created` label gains the `assigned` note. `IfirmaContractorService::getLastAction()` exposes the same array, and `IfirmaAdminActions` keeps using it.

**Why.** Translating at render time gives the note the language of the admin reading it, not of whoever triggered the run (a payment webhook has no back-office language), needs no module reference in the service, and leaves every existing record readable. The structured `tried` list is exactly the evidence missing from the current report.

**Alternatives.** Passing the module into the service and translating at store time: rejected for the language problem above. Only improving the English sentence: rejected, the merchant reads a Polish panel.

### D3. Bounded in-request PDF retry in the delivery service

**Choice.** `IfirmaDeliveryService::fetchPdfWithRetry(IfirmaReceipt)` wraps `fetchPdf()`: up to `PDF_ATTEMPTS` (3) attempts with `PDF_RETRY_WAITS` (3 s, 7 s) between them, through a sleeper injected as a new optional constructor argument (default `sleep`). A failure is retryable unless `!$client->lastRequestWasSent()` (local rate guard, missing key) or `getLastKod()` is one of 400, 401, 402, 403, 499, 500. The final message is `Could not download the PDF after %1$d attempts: %2$s` (translated through the module). `sendViaShopMail()` is the only caller, so automatic delivery, the shop-mail fallback and **Send again** all retry; the customer download keeps `fetchPdf()` untouched. `IfirmaIssuancePipeline::build()` is unchanged (default sleeper); unit tests construct the service with a recording sleeper.

**Why.** The merchant's own bound was ten seconds and the failure was observed right after creation, which points at read-after-write latency on the iFirma side; a short in-request retry fixes that case without new columns, cron work or a second trigger path. Classifying by the client's recorded Kod keeps permanent errors (wrong key, forbidden) from wasting ten seconds and keeps the rate guard honest. Waiting in the hook is acceptable because it only happens on the failure path and the request already performs several iFirma calls.

**Alternatives.** A cron-driven retry with `delivery_attempts` and `next_attempt_at` columns: deferred; it is the right design if the PDF turns out to take minutes, and nothing here precludes it. Retrying inside `fetchPdf()`: rejected, the customer download would stall. A flat `sleep(10)` before the first attempt: rejected, it would slow every delivery including the ones that already work.

### D4. Blind copy through the existing `Mail::Send` call

**Choice.** `IfirmaDeliveryService::copyRecipient(Order, Customer)` resolves the address from `IFIRMA_SEND_COPY_MODE`: `shop` reads `PS_SHOP_EMAIL`; `employee` loads `new Employee((int) IFIRMA_SEND_COPY_EMPLOYEE)` and requires a loaded, active object with a valid e-mail. A resolved address equal to the customer address (case-insensitive) or failing `Validate::isEmail` yields no copy; a skip writes `PrestaShopLogger::addLog('iFirma: delivery copy skipped for order #N (reason)', 2)`. `sendViaShopMail()` passes the address as the `bcc` argument (position 14), keeping `die` false and `idShop` null as today. The copy applies wherever `sendViaShopMail()` runs and nowhere else.

**Why.** PrestaShop's mail layer validates and supports `bcc` (string or array) and offers no CC; a blind copy also keeps the employee address away from the customer. One send keeps a single outcome to record, and validating the address before the call prevents a corrupted-parameter refusal from failing the customer delivery.

**Alternatives.** A second `Mail::Send` to the copy address: rejected (two SMTP sends, two outcomes, duplicated template handling). Adding the address to `$to`: rejected (visible to the customer, and `toName` arrays are awkward in core). A free-text copy address: not requested; the two modes map to the merchant's words ("shop email" / "my user").

### D5. Configuration fields and validation

**Choice.** Two keys in `IfirmaSchema::configurationDefaults()`: `IFIRMA_SEND_COPY_MODE` = `none`, `IFIRMA_SEND_COPY_EMPLOYEE` = `0`. `IfirmaDeliveryService::copyModes()` returns the allowed list and `getCopyMode()` normalises stored values (missing or unknown reads as `none`). The form gains, after `IFIRMA_SEND_TEXT`, a select for the mode and a select for the employee built from `Employee::getEmployees(true)` (`id_employee`, `firstname lastname`) with a leading "choose" row of id 0; `postProcessConfiguration()` normalises the mode, and when it is `employee` requires the posted id to be in that active list, adding an error that names the field otherwise; both values are saved with the other keys and read back in `fields_value`. Form strings are translated through the map tool.

**Why.** It follows the existing allow-list and error-collection pattern (`IFIRMA_SEND_CHANNEL`, `IFIRMA_NOTIFY_EMAIL`), the employee list is the back office's own notion of "my user", and validating against active employees at save time catches the common mistake while D4 still guards against later deactivation.

**Alternatives.** One combined select listing "shop" plus every employee: rejected, a renamed or removed employee would then corrupt the mode value. Storing the employee e-mail instead of the id: rejected, it would silently go stale.

### D6. Test doubles and the mock

**Choice.** Unit tests: `tests/Stubs/Employee.php` gains a registry (`register()`, constructor loading `id`, `firstname`, `lastname`, `email`, `active`) and static `getEmployees($activeOnly = true)`; `tests/Stubs/PrestaShopLogger.php` keeps a readable log; delivery and pipeline tests inject a sleeper closure that records requested seconds; contractor tests inject the same for the read-back wait. Mock (test stack): `POST /__mock/contractor-mode` with `{"create": "rename"}` makes `contractor_create` store the record under a generated identifier `IFI` + digits of the sent identifier + sequence while the create answer echoes the sent identifier in `Wynik` (the live behaviour observed); `POST /__mock/pdf-not-ready` with `{"calls": N}` makes the next N PDF downloads answer `Kod` 404 "Nie znaleziono dokumentu" and `GET` returns the remaining count; `/__mock/reset` restores both. Smoke `run.php` gains scenario F (PDF not ready twice, delivery still sent) and scenario G (rename mode, document references the `IFI` identifier, mapping stored as `module`, panel note names the assigned identifier).

**Why.** Real waits would make the suite slow and flaky; recording the requested seconds asserts the schedule exactly. The two mock switches reproduce the reported run so the end-to-end path (hook, pipeline, contractor step, delivery) is exercised in Docker, not only in unit tests.

## Risks / Trade-offs

- [The status-change request can take up to 12 s longer] → only on failure paths (2 s contractor wait, 10 s PDF waits); the normal path is unchanged; README states the bound.
- [The post-create search links a contractor that is not the one just created] → it applies the same confirmation rules the spec already requires before a create (exact NIP; name plus e-mail or postcode); ambiguity falls back to a document without reference, which iFirma resolves itself.
- [The real cause of the failed read-back stays unknown] → the `readback_failed` note records iFirma's answer per identifier; if the answer shape is something new, the panel shows it and the note is the input for the next change.
- [A PDF that takes minutes to render still fails] → the admin keeps **Send again**; a cron-driven retry (D3 alternative) can be added later without changing the delivery contract.
- [`Mail::Send` rejects or ignores `bcc` on a PrestaShop version in the supported range] → the address is validated before the call so a corrupted-parameter refusal cannot happen; task 4.1 verifies the core signature in the Docker 8.1.7 and 9.1.4 containers before relying on it.
- [Updated installs lack the two new keys] → readers normalise a missing value to `none`/`0`; no upgrade script is needed and fresh installs get the defaults.
- [New translatable strings break `TranslationCoverageTest`] → every string is added to `tools/pl_translations_map.php` and `translations/pl.php` is regenerated in the same task.

## Migration Plan

1. Ship as a module update (no schema change). Nothing changes for merchants who do not enable a copy mode.
2. After the update, open any order recorded as *tryb awaryjny* and click **Create Receipt** only if no document was issued; issued documents keep their old note (English sentence) until re-issued, which is not needed.
3. Rollback: reinstall the previous module version; `_contractor.note` and `assigned_identifier` are ignored by the old panel, and the two configuration keys are harmless leftovers.

## Open Questions

- What iFirma answers to `GET kontrahenci/id/PS-C1909.json` right after the create for this account (Kod 208, empty `Wynik`, or something else). It does not change the approach: the sequence covers each case, and the new note records the answer after deployment.
