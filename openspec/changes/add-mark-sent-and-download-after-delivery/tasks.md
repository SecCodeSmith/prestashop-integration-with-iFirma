# Tasks

Rules for every group (CLAUDE.md): test first and show the red run before the code; one concern per commit; no test, check or scenario is deleted, skipped or weakened; a golden fixture changes only with a deliberate behaviour change that the commit explains; no version string is edited by hand; the unit suite runs with `composer test` from the scratchpad PHP toolchain or the test-stack container; each step appends one line to `build/progress.log`.

## Pull requests

One pull request per task group (CLAUDE.md, "Pull requests"), stacked in the order below. "Mark as sent" lands before the customer rule, so a shop can restore downloads at the moment the rule starts. At most three slices are open at a time.

| # | Branch | Task group | What it adds | Reachable after the merge |
|---|--------|------------|--------------|---------------------------|
| 0 | `docs/sent-rule-plan` | none | proposal, design, specs and tasks of this change | docs only |
| 1 | `feat/sent-rule-1-mark-sent` | 2 | "Mark as sent" in the delivery service, the admin action and the order panel | yes: new back-office button |
| 2 | `feat/sent-rule-2-bulk` | 3 | bulk "Mark as sent" in the Documents list | yes |
| 3 | `feat/sent-rule-3-customer` | 4 | the definition of "sent to the customer", customer pages, the download check, the channel warning, upgrade notes | yes: customers download only sent documents |

Group 1 (prerequisites) and group 5 (integration checks on the tip of slice 3) are not pull requests; the `fix-vuln` commits of 5.4 get their own.

## 1. Prerequisites

- [x] 1.1 Choose the base and record the baseline: `master` once pull request #3 is merged, otherwise `feat/api-usage-delivery-retry-status-filter`; run `composer test`. Verify: the base commit and the test and assertion counts are in `build/progress.log`.
- [x] 1.2 Ask the owner to check on a real iFirma account what sets `CzyWyslano`. Result of 2026-11-11: an invoice e-mailed by the shop already reads true, so the flag proves no delivery; D1 and the specs were revised with the owner the same day (the module's own state only). Verify: the Context, D1 and the Open Questions of `design.md` say so.
- [ ] 1.3 Before archiving: `add-rate-limit-queue-and-ksef-confirmed-delivery` is archived, and the MODIFIED "Issued state" of this change is compared with the archived text. Verify: `openspec validate add-mark-sent-and-download-after-delivery --strict` passes without "Archive would refuse this delta".

## 2. Mark as sent: service, action, order panel

- [x] 2.1 Red then green: `IfirmaDeliveryService::markSent()` with the channel constant `CHANNEL_MANUAL` and its label in `describeDelivery()` ("Marked as sent on <time> by an employee"). Tests in the delivery test classes: a failed and a skipped delivery become `sent` / `manual` with the time of the injected clock and an empty error; the retry time is cleared, so the retry job selects nothing; a document awaiting KSeF leaves the waiting selection; an already sent document is refused and keeps its time and channel; a document that is not issued or is missing in iFirma is refused; `ScriptedTransport` saw no request and the mail stub sent nothing; one log entry holds the record id and the employee id and no customer data. Verify: `composer test --filter 'DeliveryServiceTest|DeliveryRetryJobTest'` passes with the new cases.
- [x] 2.2 Red then green: `IfirmaReceiptActions::markSent()` reached through `IfirmaAdminActions`, `MarkSent` in `AdminIfirmaController::$levels` at the manage level (not in `$readOnlyActions`) and `ajaxProcessMarkSent()` through `handle()`. Tests: success envelope with the new delivery text; "already sent" and "not found" answers. Negative tests: an issue-level employee gets 403, a GET gets 405, a request without the module token is refused before the permission check; each leaves the delivery state unchanged. Verify: `composer test --filter 'AdminActionsTest|AdminActionsAnswersTest|AdminControllerPermissionTest|AdminPostOnlyActionsTest'` passes.
- [x] 2.3 Red then green: the order panel shows the button "Mark as sent" for the manage level when the document is issued and not recorded as sent, asks for confirmation, and repeats the KSeF warning of "Send again" for a waiting document; the admin script posts the action with the token like "Send again"; every value is escaped. Update `tests/fixtures/admin-order-panel/golden.json` deliberately. Verify: `composer test --filter 'AdminOrderPanel'` passes and screenshots of the panel before and after marking, on PrestaShop 8.1 and 9.x, are saved under `build/shots/`.
- [x] 2.4 Spawn one read-only security reviewer (Opus) with the diff of 2.1 to 2.3: POST only, token, manage level, the record belongs to the posted order, no e-mail and no request can be triggered, nothing personal in the log. Apply any fix and re-run the tests. Verify: the verdict with file:line evidence is in `build/progress.log`.
- [x] 2.5 Smoke: scenario N (e-mail delivery) gets a step that makes a delivery fail, marks the document as sent and checks the state, an empty mail sink and no new request at the mock; scenario L (permissions) gets `MarkSent` for an employee with and without the manage level and over GET. Update `tests/smoke/README.md`. Verify: N and L pass on the 8.1 and 9.x shops.
- [x] 2.6 Docs and translations: `docs/usage.md` ("Mark as sent": when to use it, what it records, that nothing is sent), the permissions table of `docs/configuration.md`, the Polish texts. Verify: `composer test --filter TranslationCoverageTest` passes.

## 3. Bulk mark as sent

- [x] 3.1 Red then green: `IfirmaBulkRunner::KIND_MARK_SENT`: each selected document ends as "sent" or "skipped" with a reason (already sent, not issued, missing in iFirma); no request is sent; a reached request limit and an authentication pause do not stop the run; the audit entry carries the employee id and the counts. Verify: the bulk runner test classes pass with the new cases and their existing cases are unchanged.
- [x] 3.2 Red then green: the Documents list offers the bulk action "Mark as sent" for the manage level only, and the controller refuses a posted run without it. Verify: the documents-list and permission test classes pass; a negative test posts the run as an issue-level employee and finds no document changed.
- [x] 3.3 Smoke: scenario J (bulk actions) marks a mixed selection and checks the summary and the states. Update `tests/smoke/README.md`. Verify: J passes on the 8.1 and 9.x shops.
- [x] 3.4 Docs and translations: `docs/usage.md` (bulk actions) and the Polish texts. Verify: `composer test --filter TranslationCoverageTest` passes.

## 4. Customers download only sent documents

- [ ] 4.1 Red then green: `IfirmaCustomerDeliveryGate` with `isSentState()` on plain values and `isSent(IfirmaReceipt)`; `IfirmaCustomerView` calls it in place of its own expression. Verify: `composer test --filter CustomerDeliveryGateTest` passes with one case per state: sent through each channel, marked as sent, failed, skipped, none, awaiting KSeF; `CustomerViewTest` proves that iFirma's flag changes none of them, and its cases that expected "sent" from the flag alone state the new expectation in a commit that explains it.
- [ ] 4.2 Red then green: `IfirmaCustomerView` sets the download link only for a sent document, on the order page and in the account block. New cases in `CustomerViewTest` and `ModuleCustomerHooksTest`: failed, skipped, never e-mailed and adopted-not-sent documents render "not yet sent" and no link; a document marked as sent renders the link; a document that only iFirma reports as sent renders "not yet sent" and no link. An existing case that expects a link for a document that was not sent states the new expectation in a commit that explains it. Verify: both classes pass.
- [ ] 4.3 Red then green: `controllers/front/receipt.php` refuses a document that is not sent with "not found", in place of the KSeF-wait check and before every limit (and before the stored copy, when `add-document-pdf-store` is merged). New cases in `FrontControllerEndingsTest`, `DownloadDayCapFrontTest` and `DownloadBudgetFrontTest`: failed, skipped and never e-mailed documents get "not found" with no request and no limit counted; the answer equals the one of a wrong token; a customer at the day cap gets "not found", not 429; after "Mark as sent" the PDF is returned; with only iFirma's flag set the answer is "not found". Existing cases that download a document set its delivery state to sent in their arrangement. Verify: the three classes and `KsefDeliveryGateTest` pass.
- [ ] 4.4 Red then green: the description of the send channel in the configuration form carries the warning about "none". Update the settings golden fixture deliberately if it holds the description. Verify: `composer test --filter 'ModuleHooksTest|SettingsGoldenTest|TranslationCoverageTest'` passes.
- [ ] 4.5 Spawn one read-only security reviewer (Opus) with the diff of 4.1 to 4.3: is the sent check made before any limit, any fetch and any stored copy; can any customer-facing path (order page, account block, guest page, download) still give a link or bytes for a document that was not sent; does the refusal differ from a wrong token. Apply any fix and re-run the tests. Verify: the verdict with file:line evidence is in `build/progress.log`.
- [ ] 4.6 Smoke: scenario E (order page) shows no link before delivery and the link after it; scenario Q (customer download) gets "not found" for a failed delivery and the PDF after "Mark as sent"; scenarios M, D and S deliver their documents first and keep their assertions. Update `tests/smoke/README.md`. Verify: E, Q, M, D and S pass on the 8.1 and 9.x shops.
- [ ] 4.7 Docs: `docs/usage.md` (when a customer can download), `docs/configuration.md` (the channel "none"), `docs/troubleshooting.md` ("a customer cannot download a document": not sent; what to do), `docs/upgrading.md` (the new rule, which documents lose the download, how to restore it with Send again, Mark as sent or the bulk action). Verify: the four sections exist and agree with the scenarios of the delta specs.

## 5. Integration checks

- [ ] 5.1 Run the full unit suite and the coverage gate. Verify: `composer test` is green with more tests than the baseline of 1.1 and none removed; `composer coverage` plus the diff-cover gate reports every changed line covered.
- [ ] 5.2 Run the full smoke run on the matrix shops. Verify: every lettered scenario passes on each shop and `tests/smoke/check-floor.json` is raised, not lowered.
- [ ] 5.3 Package and headers. Verify: `npm run check:license` passes for the new files and `npm run verify:package` shows the new class in the zip and no development file.
- [ ] 5.4 Red-team pass (new admin action, changed access rule on a customer endpoint): six attacker agents in parallel, one per dimension, with weight on getting a link or PDF bytes for a document that was not sent through any customer path, on `MarkSent` without the manage level, over GET or without a token, and on the bulk run. The manager reproduces every high and critical finding; each accepted finding is fixed test-first in its own `fix-vuln:` commit. Verify: `build/red-team/<date>-<version>.md` exists and lists no open finding of severity high or critical.
- [ ] 5.5 Trace the scenarios. Verify: `build/progress.log` holds a list "scenario -> test or smoke step" for every scenario of the four delta specs with none left without a test, and `openspec validate add-mark-sent-and-download-after-delivery --strict` passes.
- [ ] 5.6 Refresh the graph in the main checkout. Verify: `graphify update .` finishes and `graphify query "when can a customer download a document"` names `IfirmaCustomerDeliveryGate`.

## Workflow follow-up

- Archive this change after `add-rate-limit-queue-and-ksef-confirmed-delivery` is archived and the review requirements are met.
- The version, the tag and the release notes come from the Release workflow; the notes name the changed customer rule and list the `fix-vuln` commits of 5.4.
- Follow-up outside this change: whether the status sync records the module's "sent" state when iFirma reports a document as sent.
