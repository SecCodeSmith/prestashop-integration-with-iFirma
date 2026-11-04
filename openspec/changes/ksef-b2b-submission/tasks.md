# Tasks

Conventions for every task: keep PHP 7.2-compatible syntax (`array()`, no typed properties), add the module license header to every new `.php`/`.tpl` file, run `git ls-files '*.php' | xargs -n1 php -l` after PHP edits, and run `graphify update .` after code changes. `test-stack/` is a separate git repository: its changes are committed there.

## 1. KSeF API client and service

- [x] 1.1 Add `IfirmaApiClient::sendToKsef($type, $id, $dataWysylki = null)` that posts `{"DataWysylki": null}` to `{type}/ksef/send/{id}.json` with the `faktura` key; verify a PHPUnit test with `ScriptedTransport` asserts the path, the key and the body.
- [x] 1.2 Create `classes/IfirmaKsefService.php` with mode normalisation (`b2b`, `all`, `off`; any other value becomes `b2b`); verify PHPUnit tests cover the three valid values and an unknown stored value.
- [x] 1.3 Add B2B detection (`Kontrahent.NIP` from the stored request payload, then the invoice address NIP, empty or whitespace is private); verify PHPUnit tests cover payload NIP, address fallback and whitespace-only NIP.
- [x] 1.4 Add the submission method returning `ok` only for `Kod` 0 and `failed` with the mapped message otherwise (including unparseable responses); verify PHPUnit tests cover `Kod` 0, a non-zero `Kod` and a `null` response.
- [x] 1.5 Add the `IFIRMA_KSEF_MODE` configuration key with default `b2b` to install, uninstall and the config form; verify a PHPUnit test asserts install sets the key and uninstall removes it.

## 2. Schema

- [x] 2.1 Add `ksef_sent_at` (DATETIME NULL) and `ksef_error` (TEXT NULL) to `IfirmaSchema` as lazily added columns checked against `information_schema.COLUMNS`; verify a PHPUnit test asserts the first run issues `ALTER TABLE` and the second run issues none.
- [x] 2.2 Declare both fields in `IfirmaReceipt::$definition`; verify a PHPUnit test asserts the definition contains both fields.
- [x] 2.3 Add both columns to `upgrade/upgrade-1.1.0.php` with the same idempotent check; verify a PHPUnit test runs the upgrade twice against the `Db` stub and asserts no second `ALTER TABLE`.

## 3. Pipeline

- [x] 3.1 Add the `ksef` step between `number` and `deliver` in `IfirmaIssuancePipeline`, recording `skipped` with reason (disabled, private buyer, already in KSeF, unsupported type), `ok` or `failed`; verify PHPUnit tests cover each outcome and the step order.
- [x] 3.2 Make a KSeF failure non-blocking: delivery still runs, outcome stays `issued`, result message contains `KSeF: <error>`, receipt gets `ksef_status` `BLAD` and `ksef_error`; verify a PHPUnit test asserts delivery is called after a failed KSeF step.
- [x] 3.3 Record `ksef_status` `WYSLANA` and `ksef_sent_at` on success; verify a PHPUnit test asserts both fields after an `ok` step.
- [x] 3.4 Run the Docker smoke flow with KSeF mode `b2b` against the mock: one B2B order submitted, one private order skipped; verify the smoke output shows both outcomes.

## 4. Admin panel, manual action and customer view

- [x] 4.1 Add the manual action to `IfirmaAdminActions` that submits regardless of mode and refuses documents with an accepted state or no iFirma id; verify PHPUnit tests cover the forced submission in `off` mode and the refused accepted document.
- [x] 4.2 Add the ajax action to `AdminIfirmaController` for **Send to KSeF**; verify a PHPUnit test asserts the action is registered and returns the derived state.
- [x] 4.3 Add the KSeF row to the admin panel template showing one of: not sent (with reason), sent at (awaiting iFirma), accepted (with number), error (with message); verify a PHPUnit test covers the state derivation for each of the four states.
- [x] 4.4 Show the KSeF number on the customer order page when present and never show `ksef_error`; verify a PHPUnit test asserts the view model contains the number and no error text.

## 5. Mock

- [x] 5.1 Add `POST /iapi/{doc_type}/ksef/send/{id}.json` to the test-stack mock returning `Kod` 0 and marking the document as submitted; verify a pytest case asserts the response and the stored state.
- [x] 5.2 Add `/__mock/ksef/accept/{id}` (sets `KsefStatus` `PRZYJETA` and `KsefNumer`) and `/__mock/ksef/reject/{id}` (next send returns a non-zero `Kod`); verify pytest cases for both and that the list endpoint returns the values.
- [x] 5.3 Keep the mock changes inside the `test-stack/` repository (not this one) and confirm the test-stack pytest suite exits 0; verify the pytest run output.

## 6. Status sync and documentation

- [x] 6.1 Update `IfirmaStatusSyncService` so `WYSLANA` is kept while `KsefStatus` is empty, `KsefNumer` is stored whenever present, and a non-empty status overwrites the marker; verify PHPUnit tests cover the three rules in the status synchronisation requirement.
- [x] 6.2 Add the README section "KSeF (e-invoicing)" after "Delivery", covering the setting, the pipeline step, the panel row and button, the customer page, the meaning of `WYSLANA`, `PRZYJETA` and `BLAD`, and the warning that submissions cannot be withdrawn; verify the section exists with `grep -n "## KSeF (e-invoicing)" README.md`.
- [x] 6.3 Add `IFIRMA_KSEF_MODE` (default `b2b`) to the README configuration table and a troubleshooting entry for `KSeF: <error>` (check the iFirma account KSeF authorisation, retry with the button, refresh status); verify both entries exist with `grep -n "IFIRMA_KSEF_MODE" README.md` and `grep -n "KSeF: <error>" README.md`.
- [x] 6.4 Run `openspec validate ksef-b2b-submission --strict` from the repo root and confirm it passes; verify the command exits 0.
