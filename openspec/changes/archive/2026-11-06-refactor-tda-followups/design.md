# Design

## Context

- The test-first protocol and the changed-line gate of `refactor-clean-code-tda` (its design D1 and D2) apply unchanged.
- The receipt intent methods of that change set fields only and never save; the caller keeps its `save()`. The new methods follow the same rule.
- PrestaShop has no autoloader for module classes. Each class file loads what it uses with `require_once`; for example, `IfirmaIssuancePipeline.php` loads `IfirmaPipelineResult.php`. A class constant or a static property default that names another class is resolved at runtime, so that class must be loaded by then.

## Goals / Non-Goals

**Goals:**
- Finish the follow-ups with no behaviour change: every stored field, write count, message and array shape stays byte-identical.

**Non-Goals:**
- Splitting methods or classes, which belongs to `refactor-srp-splits`.
- The KSeF empty-message bug, which belongs to `fix-ksef-failed-submission-recorded-as-sent`.

## Decisions

### D1. `markPaid()` and `markSuspected($reason)` mirror today's writes
Each sets exactly the fields that today's callers set for that outcome, as found by the characterisation, and nothing else.
- `IfirmaDuplicateGuard` chooses between paid and created at the call site, and that choice stays there: only the paid branch calls `markPaid()`.
- If callers set different field sets for the "same" outcome, the method takes the common part, and the call site keeps its extra assignment, as task 9.2 of the previous change did for `delivered_at`.
- *Alternative:* a generic `setStatus()`. Rejected, because it is a setter, not an intent.

### D2. One identifier length, loaded by its users
- `IfirmaContractorMap`, `IfirmaContractorImport` and `IfirmaCustomerContractorService` reference `IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH`.
- Each of those files requires `IfirmaSchema.php` at its top, so the constant resolves whichever file loads first: the module, a front controller, an upgrade script or a test.
- The ObjectModel `size` uses the constant expression. PHP 7.2 allows class constants in static property defaults.
- *Alternative:* keep the literals and add a test that they all equal the constant. Rejected, because it keeps four definitions of one fact.

### D3. `IfirmaContractorResolution::fallback($message, $note = null, $readback = null)`
A named constructor for the identifier-less fallback. The plain constructor stays for the other outcomes. Every positional `new IfirmaContractorResolution(null, …ACTION_FALLBACK…)` becomes `fallback(...)`, and the resulting objects must be identical: same getters, same `toArray()`.

### D4. One shared throwables provider
A trait in `tests/Support/` provides one `Exception` and one `Error`. The test classes that repeat a `throwables()` or `failures()` provider use it instead. Their test names and data-set names stay the same, so the suite keeps its counts.

## Risks / Trade-offs

- [A class constant naming `IfirmaSchema` is read before `IfirmaSchema.php` is loaded, for example in a front controller or an upgrade script] → Each user file requires it (D2). The PHP 7.2 lint, the whole suite, and the end-to-end smoke in the real PrestaShop container all run before the push.
- [An intent method silently widens a write] → D1 characterisation pins every field and write count first, and the gate requires every changed line covered.
