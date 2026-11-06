# Proposal

## Why

The final check of `refactor-srp-splits` (task 10.1, archived 2026-11-06) showed that the upgrade test from 1.0.0 no longer runs as written.
- Phase 2 asserts that the database records version 1.1.0. Since `upgrade-1.2.0.php` exists, it records 1.2.0, so the run ends `PHASE2_FAILED 1`.
- Phase 1 cannot issue its document with unchanged 1.0.0. The test-stack mock now validates like the live API and rejects three things 1.0.0 sends.
- The README's cleanup step misses a table and does not say which settings to delete.

The reviewers of the same change also left optional hardening:
- two payload builder methods that are still above the 40-line goal;
- three small gaps in the test infrastructure.

The owner approved planning both parts together on 2026-11-06.

## What Changes

- **Upgrade test tooling** (`tests/smoke/`, `test-stack/`):
  - Phase 2 finishes the upgrade the way PrestaShop does, recording the module's own version. It then asserts that version, and that one upgrade script ran for every version above 1.0.0.
  - The mock gets a legacy test switch, gated like its other test endpoints and cleared by its reset. The switch relaxes exactly the three rules 1.0.0 breaks: the BRK signature type, a VAT rate outside 0, 5, 8 and 23%, and an unknown contractor identifier.
  - Phase 1 turns the switch on, so the real 1.0.0 code issues its document and writes its own receipt row.
  - The manual cleanup becomes a script. It drops both tables 1.0.0 does not know and deletes every `IFIRMA_*` setting except the 11 that 1.0.0 defines.
  - A compose override mounts the 1.0.0 tree.
  - The README describes the whole run from 1.0.0 to the current version.
- **Payload builder hardening** (pure refactor):
  - First pin what no test pins yet: the 2-decimal rounding of the gross unit price, the VAT rate after snapping and rounding, a shipping price of 0.5 and one that rounds to zero, and an order without detail rows.
  - Then split `IfirmaPayloadBuilder::buildInvoicePayload()` (76 lines) and `buildContractor()` (53 lines) into steps of 40 lines or fewer. The 56 golden payloads stay equal.
- **Test tidy-ups:**
  - The admin-panel golden test finds the panel tests of ModuleHooksTest itself, instead of keeping a hand-written list.
  - One trait holds the setup that CreateReceiptGoldenTest and CreateReceiptOutcomeTest repeat. CreateReceiptTest stays untouched.
  - A test fails when a payload fixture no longer belongs to a golden test.
- The module's behaviour does not change: no change to anything a merchant sees, the schema, a configuration key, a hook or a translation string.

## Capabilities

### New Capabilities
<!-- none: test tooling and a pure refactor, skip_specs is set -->

### Modified Capabilities
<!-- none -->

## Impact

- Test tooling: `tests/smoke/upgrade-phase1.php`, `upgrade-phase2.php`, a new cleanup script, `tests/smoke/README.md`, the mock (`test-stack/ifirma-mock/app/main.py` and `store.py`, with pytest tests), and a compose override under `test-stack/`.
- Shipped code: `classes/IfirmaPayloadBuilder.php` only, split behind the same public methods.
- Tests: new pins for the payload builder, `tests/Unit/AdminOrderPanelGoldenTest.php`, a scaffolding trait under `tests/Support/`, and an orphan-fixture check for `tests/fixtures/payloads/`.
- The change lands straight on master behind the same local gate as the refactor changes: the whole PHPUnit suite with pcov, then diff-cover at 100% of changed lines. For the mock, its pytest suite. CI repeats both.
