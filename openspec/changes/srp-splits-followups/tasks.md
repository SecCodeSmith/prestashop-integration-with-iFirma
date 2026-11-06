# Tasks

Same protocol as the refactor changes:

1. Characterise the touched lines, with tests that pass on unchanged code.
2. Change.
3. The whole PHPUnit suite passes.
4. `diff-cover` reports 100% of changed shipped lines.

The mock is guarded by its pytest suite instead, and the upgrade scripts by running them on the test stack. Each task is one commit straight on master; a characterisation commit may come first. CI repeats the gate after the push. No merchant-visible behaviour changes.

## 1. Upgrade test from 1.0.0

- [x] 1.1 Add the legacy test switch to the mock (design D2): `POST /__mock/legacy-mode {"enabled": true|false}` and `GET /__mock/legacy-mode`, gated by the test endpoints and cleared by `POST /__mock/reset`. While it is on, `create_document()` accepts any `RodzajPodpisuOdbiorcy`, any `StawkaVat`, and creates an unknown nested `Kontrahent.Identyfikator` as a contractor. Add pytest tests in `test-stack/ifirma-mock/tests/`:
  - each relaxation is off by default and on when switched;
  - every other rule still applies when it is on;
  - the reset clears it;
  - the endpoint answers 404 with the test endpoints disabled.

  Verify that the whole mock suite passes, and rebuild the mock container (`docker compose up -d --build --no-deps ifirma-mock`).
- [x] 1.2 Make `tests/smoke/upgrade-phase1.php` turn the switch on after its mock reset and before issuing with 1.0.0, and off again after. Verify on the 8.1 test stack, with an unchanged 1.0.0 tree (`git worktree add <dir> 6565eab`) mounted, that phase 1 ends `PHASE1_OK`.
- [x] 1.3 Make `tests/smoke/upgrade-phase2.php` finish the upgrade with `Module::upgradeModuleVersion('ifirma', $m->version)`, as design D1 describes. It asserts:
  - that `ps_module.version` equals `$m->version`;
  - that `number_upgraded` equals the number of `upgrade/upgrade-X.Y.Z.php` files with a version above 1.0.0 and at most `$m->version`;
  - the old checks.

  Update its header comment. Verify on the stack, after 1.2 and the cleanup of 1.4, that phase 2 ends `PHASE2_OK`.
- [x] 1.4 Add `tests/smoke/upgrade-cleanup.php` (design D3). It drops `<prefix>ifirma_data_request` and `<prefix>ifirma_contractor_map`, deletes every `IFIRMA_%` setting except the 11 keys that `git show 6565eab:ifirma.php` defines (listed in the script), and prints what it removed. Add `test-stack/docker-compose.upgrade-1.0.0.yml`, which mounts the 1.0.0 tree from an environment variable. Rewrite the README section as "Upgrade test from 1.0.0", with the exact command of every step.

  Verify by following the README word for word on the 8.1 stack: phase 1 `PHASE1_OK`, cleanup, phase 2 `PHASE2_OK`. Then put the normal mount back, restore the shop's settings, and run `tests/smoke/run.php`: 90 passed, 1 skipped, and its 91 check lines equal to the pre-change run. Record the run in the README's "Verified runs" table.

## 2. Payload builder hardening

- [x] 2.1 Pin, on unchanged code, what no test pins in `IfirmaPayloadBuilder::buildInvoicePayload()` (design D4):
  - a gross unit price of 123.456789 becomes 123.46;
  - the VAT rate after `snapVatRate()` and the 4-decimal rounding: a rate near each snap boundary, and one that does not snap;
  - a shipping price of 0.5 adds the shipping line, and 0.004 adds none;
  - an order without detail rows gives the positions it gives today.

  Verify that the new tests pass on unchanged code and kill a mutant of each line. Commit them alone.
- [ ] 2.2 Split `buildInvoicePayload()` into private steps: product positions, shipping position, document header, the buyer's NIP and DodatkoweUwagi. Verify:
  - the method and every new step are 40 lines or fewer;
  - the 56 golden payloads and the 2.1 pins are equal;
  - the suite is green, the PHP 7.2 lint passes, and diff-cover is at 100%.
- [ ] 2.3 Split `buildContractor()` into the buyer source (the approved request or the invoice address), the country, and the contractor array. Verify the same as 2.2, plus the ContractorServiceTest and AdminActionsTest cases that use the contractor array.

## 3. Test infrastructure

- [x] 3.1 Make `AdminOrderPanelGoldenTest` derive the admin-panel tests from `ModuleHooksTest.php`, minus an explicit exempt list (today `testAdminPanelRendersOncePerRequest` and `testAdminPanelTemplateShowsTheLinkedContractorRow`), instead of its hand-kept list of 15 names (design D5). Verify the test is green, and that adding a throwaway panel test to a copy of ModuleHooksTest makes it fail.
- [x] 3.2 Move the setup, `order()` and `enableSync()` that CreateReceiptGoldenTest and CreateReceiptOutcomeTest share into a trait in `tests/Support/`, and leave CreateReceiptTest untouched. Verify that the suite keeps the same number of tests and assertions, and that `phpunit --list-tests` is identical before and after.
- [x] 3.3 Add a test that maps every file in `tests/fixtures/payloads/` back to its golden test: the naming rule `<class>-<test>[.<variant>].json` read in reverse, with the method's existence checked by reflection. Verify it is green for the 56 fixtures, and fails for a throwaway file named after a missing test.

## 4. Integration check

- [ ] 4.1 Run the gate over the whole change against its base, the PHP 7.2 lint, the licence check, the mock suite, `tests/smoke/run.php` (the 91 lines equal to the pre-change run) and the PDF download check. Then run `graphify update .` and `openspec validate srp-splits-followups --strict`, commit, push, and confirm that all six CI jobs pass with the coverage job at 100%.

## Workflow follow-up

- Archive the change after review. There is no spec sync, because `skip_specs` is set.
