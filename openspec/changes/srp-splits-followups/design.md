# Design

## Context

See proposal.md, Why. The facts that shape the approach:
- `upgrade-phase1.php` issues through the 1.0.0 code itself, `IfirmaReceiptService::build($m)->createReceipt($order)`. The receipt row 1.0.0 writes is what phase 2 upgrades.
- The mock (`test-stack/ifirma-mock/app/main.py`) checks every new document in `create_document()`:
  - an enum check: `RodzajPodpisuOdbiorcy` must be OUP, UPO, BPO or BWO, otherwise Kod 200;
  - `StawkaVat` must be exactly 0, 0.05, 0.08 or 0.23, otherwise Kod 201;
  - a non-empty `Kontrahent.Identyfikator` must name a known contractor, otherwise Kod 201.

  1.0.0 sends BRK, a rate of 0.2301 and an e-mail as the identifier.
- The mock already has test switches: `/__mock/contractor-mode` and `/__mock/pdf-not-ready`. They are gated by `IFIRMA_MOCK_TEST_ENDPOINTS` (404 when off), held as module state in `store.py`, and cleared by `POST /__mock/reset`.
- `upgrade-phase2.php` drives `Module::needUpgrade`, `initUpgradeModule` and `runUpgradeModule`, then reads `ps_module.version`. PrestaShop's own flows (`ModuleDataUpdater::upgrade`, `ModuleManager::upgradeMigration`) also call `Module::upgradeModuleVersion($name, $module->version)` afterwards; the script does not.
- `IfirmaPayloadBuilder::buildInvoicePayload()` and `buildContractor()` are guarded by 56 golden payload fixtures (`tests/Support/IfirmaGoldenPayloads.php`), but four behaviours of the first are unpinned: the gross unit price rounding (`round(..., 2)`), the VAT rate after `snapVatRate()` and `round($rate / 100, 4)`, the shipping line (`> 0` after rounding), and an order without detail rows.

## Goals / Non-Goals

**Goals:**
- The documented upgrade run from 1.0.0 to the current version passes as written, `PHASE1_OK` then `PHASE2_OK`, with unchanged 1.0.0 code and without hand-written SQL.
- Every method of `IfirmaPayloadBuilder` is 40 lines or fewer, with byte-identical payloads.
- Test infrastructure that notices when it falls out of date: a new admin-panel test, an orphan fixture.

**Non-Goals:**
- Changing the mock's default validation. The legacy switch is off by default and only the upgrade test turns it on.
- Upgrade paths other than from 1.0.0. They are covered by UpgradeTest (`refactor-srp-splits` 9.1).
- Automating the container remount: the README keeps the steps, and the compose override makes them short.

## Decisions

### D1. Phase 2 finishes the upgrade like PrestaShop does
After `runUpgradeModule()`, phase 2 calls `Module::upgradeModuleVersion('ifirma', $m->version)`, as the back office and the module manager do. It then asserts:
- `ps_module.version` equals `$m->version`;
- `number_upgraded` equals the number of `upgrade/upgrade-X.Y.Z.php` files whose version is above 1.0.0 and at most `$m->version`, counted from the folder.

The assertions follow future upgrade scripts and versions without edits.
- *Alternative:* assert the last script's version (1.2.0). Rejected: it describes a half-finished upgrade that no real shop is left in.

### D2. A legacy test switch in the mock, not a seeded document
`POST /__mock/legacy-mode {"enabled": true|false}` and `GET /__mock/legacy-mode` sit behind the same gate as the other test endpoints, and `reset_test_switches()` clears it. While it is on, `create_document()`:
- accepts any `RodzajPodpisuOdbiorcy`;
- accepts any `StawkaVat`;
- creates an unknown nested `Kontrahent.Identyfikator` as a contractor instead of rejecting it, the same way the empty-identifier path does.

Every other rule stays.

Phase 1 turns the switch on after its `/__mock/reset` and before issuing. Phase 2 does not need it: the upgraded module sends valid requests.

- *Alternative:* seed the old document through `POST /__mock/documents` and insert the 1.0.0 receipt row by hand. Rejected: phase 1 would no longer run 1.0.0's own write path, which is the point of the test.
- *Alternative:* patch 1.0.0 in a scratch copy, as the final check of `refactor-srp-splits` did. Rejected: it tests code no shop ever ran.

### D3. The cleanup is a script with an explicit allow-list
`tests/smoke/upgrade-cleanup.php` runs as www-data in the shop between the phases. It:
- drops `<prefix>ifirma_data_request` and `<prefix>ifirma_contractor_map`, through `_DB_PREFIX_`;
- deletes every `IFIRMA_%` configuration row except the 11 keys 1.0.0 defines, listed literally in the script and taken from `git show 6565eab:ifirma.php`;
- prints what it removed.

A compose override `test-stack/docker-compose.upgrade-1.0.0.yml` mounts the 1.0.0 tree, with its path taken from an environment variable. The README section becomes "Upgrade test from 1.0.0" with exact commands for each step.

### D4. Pins first, then the payload builder split
Same protocol as `refactor-srp-splits` D1:
1. Characterisation tests pin the four unpinned behaviours on unchanged code: a gross unit price of 123.456789, rates near a snap boundary and one that does not snap, shipping 0.5 and 0.004, and an order without detail rows.
2. Split `buildInvoicePayload()` into private steps: product positions, shipping position, document header, the buyer's NIP and DodatkoweUwagi.
3. Split `buildContractor()` into: buyer source (the approved request or the invoice address), country, and the contractor array.

The golden payloads stay byte-identical. Public signatures do not change. The one `l()` call keeps its explicit domain `ifirmareceiptservice`.

### D5. Test infrastructure that notices drift
- **AdminOrderPanelGoldenTest** derives the panel tests from `ModuleHooksTest.php`'s source: every test method that renders the admin order panel, minus an explicit list of the panel tests that assign no fixture (today `testAdminPanelRendersOncePerRequest` and `testAdminPanelTemplateShowsTheLinkedContractorRow`). A new panel test then fails the check until the golden covers it or it joins the exempt list.
- **`IfirmaCreateReceiptScenario`** (tests/Support) holds the shared setup, `order()` and `enableSync()` of CreateReceiptGoldenTest and CreateReceiptOutcomeTest. Outcome keeps its extra builder line in its own setUp().
- **An orphan-fixture test** maps every file in `tests/fixtures/payloads/` back to a golden test, with the naming rule of `goldenFixtureName()` read in reverse: `<class>-<test>[.<variant>].json`. It checks by reflection that the method exists, and fails when a file has no golden test. A mapping, not a "read during the run" registry, so the result does not depend on test order.

## Risks / Trade-offs

- [The legacy switch hides a real validation bug] → Off by default, gated with the other test endpoints, cleared by every reset, and pytest pins both states. Only phase 1 turns it on.
- [The cleanup allow-list drifts from 1.0.0] → It is taken from 1.0.0's own code at a fixed commit, which never changes.
- [The source scan in AdminOrderPanelGoldenTest matches too much or too little] → The exempt list is explicit, and a mutation check proves a new panel test is caught.
- [The split changes a payload] → 56 golden payloads plus the new pins, a differential run against the old builder, and the 100% changed-line gate.

## Migration Plan

Test tooling and a pure refactor: no data migration and no install change. Each task lands straight on master as its own commit after the local gate, and CI repeats it. The mock change needs `docker compose up -d --build --no-deps ifirma-mock` on the test stack; its pytest suite runs in CI's mock job. Rollback is a revert of the task commit.
