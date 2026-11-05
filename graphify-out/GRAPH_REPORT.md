# Graph Report - ifirma  (2026-11-05)

## Corpus Check
- 217 files · ~225,611 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 20 file(s) not represented in the graph (top: (none) 9, .tpl 6, .xml 1)

## Summary
- 3986 nodes · 9370 edges · 241 communities (164 shown, 77 thin omitted)
- Extraction: 85% EXTRACTED · 15% INFERRED · 0% AMBIGUOUS · INFERRED: 1387 edges (avg confidence: 0.89)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `05b77ee1`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- main.py
- CustomerContractorServiceTest
- test-stack/tools/check_license_headers.py
- package.json
- PrestaShop iFirma integration module
- AdminIfirmaController
- Ifirma
- openspec-archive-change skill
- test_mock_contractors.py
- DataRequestServiceTest
- iFirma Module Logo
- Project CLAUDE.md (graphify rules)
- ifirma-mock
- IfirmaApiClient
- Support (tests/Support)
- run.php
- ModuleHooksTest
- IssuancePipelineTest
- ADDED Requirements
- ADDED Requirements
- ADDED Requirements
- ADDED Requirements
- CustomerViewTest
- Stubs (tests/Stubs)
- .submitPending
- test_mock_extensions.py
- ADDED Requirements
- DuplicateMatcherTest
- ADDED Requirements
- ContractorServiceTest
- DuplicateGuardTest
- Order
- Requirement: Candidate detail verification
- IfirmaDeliveryService
- IfirmaReceipt
- Requirements
- Requirements
- IfirmaDataRequestTest
- Db
- Requirements
- IfirmaReceiptService
- IfirmaDataRequestService
- composer.json
- .updateValue
- Requirements
- KsefServiceTest
- Requirements
- PHPUnit\Framework\TestCase
- PayloadBuilderTest
- ApiClientErrorsTest
- Requirements
- ADDED Requirements
- seed
- Requirements
- PaymentMethodResolverTest
- ContractorReferenceTest
- .resetAll
- .shop
- Requirement: Created contractor read-back
- Requirement: Delivery copy settings
- test_mock_enums.py
- .l
- ADDED Requirements
- IfirmaDataRequest
- IfirmaTestRegistryTrait
- AdminControllerCustomerTest
- Requirements
- ADDED Requirements
- ApiClientTest
- Requirement: Created contractor read-back
- IfirmaMatchLabels
- Proposal
- Tasks
- ContractorImportTest
- Module
- Requirement: Contractor registration before issuance
- ADDED Requirements
- Requirement: PDF download retry in shop-mail delivery
- Test fixtures
- Requirement: Existing contractor matching
- Requirement: Candidate detail verification
- IfirmaContractorService
- IfirmaAdminActions
- DataRequestServiceTest.php
- Requirement: Candidate selection
- Requirement: Eligibility
- IfirmaCustomerContractorService
- ApiClientFactoryTest
- Decisions
- Requirement: Request content and validation
- Requirements
- Requirement: Eligibility
- Requirement: Request content and validation
- AdminActionsTest
- Requirement: Contractor step notes
- Requirement: Approved data overrides the document payload
- Requirement: Admin approves a request
- Requirement: CSRF protection
- Requirement: Customer cancels a request
- Requirement: Resolution resumes issuance
- Requirement: Submission creates a pending request
- Requirement: Admin rejects a request
- Requirement: Audit trail
- Requirement: Pending request holds issuance
- OpenSpec config (spec-driven schema)
- test_ifirma_mock.py
- Requirement: Contractor import from a CSV export
- Requirement: Error-code mapping
- Requirement: Contractor registration before issuance
- Requirement: Local rate guard
- .getInstance
- Requirement: Payload length limits
- Requirement: Approved data overrides the document payload
- Requirement: Existing contractor matching
- Requirement: Deterministic contractor identifier
- Requirement: Documented enumerations only
- Requirement: Optional limit visibility
- Requirement: Request signing
- Requirement: Contractor import from a CSV export
- Requirement: Error-code mapping
- Requirement: Local rate guard
- Requirement: Payload length limits
- Requirement: Admin approves a request
- Requirement: CSRF protection
- Requirement: Customer cancels a request
- Requirement: Deterministic contractor identifier
- Requirement: Optional limit visibility
- Requirement: Request signing
- Requirement: Resolution resumes issuance
- Requirement: Submission creates a pending request
- Requirement: Documented enumerations only
- Tasks
- Employee
- IssuancePipelineTest.php
- IfirmaDataRequestNotifierTest
- .objects
- .strlen
- Spec Delta
- Requirement: Contractor attached by iFirma is learned
- Requirement: Non-PDF body on PDF endpoint
- Requirement: Contractor reference on documents
- Requirement: List calls always bounded
- Requirement: Contractor reference on documents
- store.py
- Requirement: List calls always bounded
- Requirement: Request timeouts
- build-zip.mjs
- .postProcessConfiguration
- Requirement: Non-PDF body on PDF endpoint
- Spec Delta
- Requirement: Pending request holds issuance
- StatusSyncServiceTest
- Requirement: Remote lookup before issuance
- JSONResponse
- Any
- Requirement: Suspected duplicate blocks issuance
- Requirement: Admin resolution of suspected duplicates
- Requirement: Confirmed match adoption
- Requirement: Unknown contractor filter is conclusive
- Requirement: Unverifiable candidate stays suspected
- Requirement: Inconclusive guard
- Requirement: Local record precedence
- Tab
- _authorized
- Requirement: Requests after issuance
- ContractorLabelWordingTest
- Document
- .search
- config.py
- Decisions
- build_pdf
- IfirmaDataRequestNotifier
- .getLastError
- Decisions
- Decisions
- verify-package.mjs
- Tasks
- TranslationCoverageTest
- ObjectModel
- 1. Test harness (unit tests runnable without PrestaShop)
- Tasks
- .link
- Requirement: Releases are atomic
- IfirmaStatusSyncService
- scripts
- ScriptedTransport
- IfirmaSchema
- upgrade_module_1_1_0
- ErrorGuardTest
- Module smoke run
- Proposal
- Proposal
- IfirmaKsefService
- .unlink
- Proposal
- .loadModule
- Requirement: Test builds are uniquely identified
- IfirmaWiringFixture
- .setDefaultTransport
- IfirmaMatchLabelsTest
- ConfigurationKeysTest
- Proposal
- Tasks
- Design
- .substr
- Requirement: iFirma-mail channel
- buildClient

## God Nodes (most connected - your core abstractions)
1. `IfirmaReceipt` - 112 edges
2. `DataRequestServiceTest` - 96 edges
3. `ModuleHooksTest` - 95 edges
4. `Stubs (tests/Stubs)` - 95 edges
5. `IssuancePipelineTest` - 80 edges
6. `ContractorServiceTest` - 78 edges
7. `Order` - 77 edges
8. `IfirmaApiClient` - 76 edges
9. `IfirmaDataRequest` - 68 edges
10. `CustomerContractorServiceTest` - 64 edges

## Surprising Connections (you probably didn't know these)
- `Why` --references--> `IfirmaAdminActions`  [INFERRED]
  openspec/changes/refactor-clean-code-tda/proposal.md → classes/IfirmaAdminActions.php
- `Context` --references--> `IfirmaApiClient`  [INFERRED]
  openspec/changes/archive/2026-11-03-fix-duplicate-guard-matching/design.md → classes/IfirmaApiClient.php
- `Context` --references--> `IfirmaApiClient`  [INFERRED]
  openspec/changes/archive/2026-11-04-ksef-b2b-submission/design.md → classes/IfirmaApiClient.php
- `10. IfirmaDataRequest approve/reject (Tier 2)` --references--> `IfirmaDataRequest`  [INFERRED]
  openspec/changes/refactor-clean-code-tda/tasks.md → classes/IfirmaDataRequest.php
- `D2. Steps return explicit continue or stop, not shared flags` --references--> `IfirmaPipelineResult`  [INFERRED]
  openspec/changes/refactor-srp-splits/design.md → classes/IfirmaPipelineResult.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **OpenSpec change lifecycle workflow** — _claude_skills_openspec_explore_skill, _claude_skills_openspec_propose_skill, _claude_skills_openspec_update_change_skill, _claude_skills_openspec_apply_change_skill, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill [EXTRACTED 0.95]
- **Local test stack services** — test_stack_docker_compose_db, test_stack_docker_compose_ifirma_mock, test_stack_docker_compose_prestashop [EXTRACTED 1.00]
- **Receipt creation and delivery flow** — readme_admin_ifirma_controller, classes_ifirmareceiptservice, classes_ifirmaapiclient, readme_receipt_front_controller [INFERRED 0.85]
- **Delta-to-main spec sync pipeline** — _claude_skills_openspec_sync_specs_skill_delta_specs, _claude_skills_openspec_sync_specs_skill_main_specs, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill_post_sync_verification, _claude_skills_openspec_archive_change_skill_archive_move [INFERRED 0.85]

## Communities (241 total, 77 thin omitted)

### Community 0 - "main.py"
Cohesion: 0.13
Nodes (10): _contractor_errors(), create_document(), _document_validation_error(), _has_invalid_enum(), health(), _ksef_date_ok(), _register_call(), reset_counters() (+2 more)

### Community 1 - "CustomerContractorServiceTest"
Cohesion: 0.06
Nodes (6): 2. Local link state and counts (D2, D4), 3. Registration check, search and ranking (D3, D6), 4. Remote document count (D5), 7. Documentation, Tasks, CustomerContractorServiceTest

### Community 2 - "test-stack/tools/check_license_headers.py"
Cohesion: 0.13
Nodes (22): add_header(), forbidden_hits(), has_marker(), insert_index(), main(), rel(), read_file(), render_header() (+14 more)

### Community 3 - "package.json"
Cohesion: 0.17
Nodes (11): author, description, devDependencies, archiver, files, homepage, license, name (+3 more)

### Community 4 - "PrestaShop iFirma integration module"
Cohesion: 0.06
Nodes (31): Module CI workflow, Build package (zip + tgz) job, tools/check_license_headers.py, Mock tests (test-stack) job, PHP lint & license headers job, Release workflow, Publish GitHub Release with zip and tgz, Version bump synced across package.json, ifirma.php, config.xml (+23 more)

### Community 5 - "AdminIfirmaController"
Cohesion: 0.05
Nodes (11): IfirmaHttpStatus, AdminIfirmaController, IfirmaCronModuleFrontController, IfirmaDatarequestModuleFrontController, IfirmaReceiptModuleFrontController, D8. Controller actions and access control, 6. HTTP status line (Tier 1), AdminController (+3 more)

### Community 6 - "Ifirma"
Cohesion: 0.07
Nodes (3): Ifirma, 2. API-client factory (Tier 1), HelperForm

### Community 7 - "openspec-archive-change skill"
Cohesion: 0.18
Nodes (17): openspec-apply-change skill, openspec CLI, Store selection (--store flag), Task checkbox tracking (tasks.md), openspec-archive-change skill, Archive move to dated archive directory, openspec-explore skill, openspec-propose skill (+9 more)

### Community 8 - "test_mock_contractors.py"
Cohesion: 0.11
Nodes (46): by_id(), _clean_state(), contractor(), create_contractor(), document(), get_json(), listing(), receipt() (+38 more)

### Community 10 - "iFirma Module Logo"
Cohesion: 0.50
Nodes (4): Glowing Green Hammer Icon, iFirma Module Logo, Matrix-style Binary Code Rain Background, PrestaShop Module Icon Usage

### Community 27 - "IfirmaApiClient"
Cohesion: 0.14
Nodes (3): IfirmaApiClient, D8. API client hardening: error mapping, request accounting, rate guard, 4. API client hardening (spec: ifirma-api-compliance)

### Community 28 - "Support (tests/Support)"
Cohesion: 0.15
Nodes (5): PrestaShop stubs for unit tests, Support (tests/Support), IfirmaTestController, IfirmaTestMailTrait, IfirmaTestRedirect

### Community 29 - "run.php"
Cohesion: 0.17
Nodes (39): assertSame(), assertTrue(), candidateIds(), changeState(), {closure#1}(), {closure#10}(), {closure#2}(), {closure#3}() (+31 more)

### Community 31 - "IssuancePipelineTest"
Cohesion: 0.08
Nodes (5): IfirmaIssuancePipeline, IfirmaPipelineResult, 9. IfirmaReceipt intent methods (Tier 2), IfirmaTestState, IssuancePipelineTest

### Community 32 - "ADDED Requirements"
Cohesion: 0.04
Nodes (47): ADDED Requirements, Purpose, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 33 - "ADDED Requirements"
Cohesion: 0.05
Nodes (42): ADDED Requirements, Purpose, Requirement: Admin resolution of suspected duplicates, Requirement: Candidate selection, Requirement: Confirmed match adoption, Requirement: Guard applies to all issuance paths, Requirement: Inconclusive guard, Requirement: Local record precedence (+34 more)

### Community 34 - "ADDED Requirements"
Cohesion: 0.05
Nodes (40): ADDED Requirements, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 35 - "ADDED Requirements"
Cohesion: 0.06
Nodes (32): ADDED Requirements, Purpose, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 36 - "CustomerViewTest"
Cohesion: 0.10
Nodes (3): IfirmaCustomerView, D6. Customer data-change request = separate table, payload override, hold on auto-issuance, CustomerViewTest

### Community 37 - "Stubs (tests/Stubs)"
Cohesion: 0.07
Nodes (6): Hook, Stubs (tests/Stubs), Shop, Smarty, Tools, Validate

### Community 38 - ".submitPending"
Cohesion: 0.09
Nodes (8): {closure#1}(), {closure#2}(), {closure#28}(), {closure#29}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#9}()

### Community 39 - "test_mock_extensions.py"
Cohesion: 0.13
Nodes (27): _clean_state(), get_json(), ids(), listing(), test_delete_hides_and_unknown_delete_404(), test_detail_returns_stamped_notes(), test_detail_unknown_and_deleted_are_404_envelope(), test_limit_counts_calls() (+19 more)

### Community 40 - "ADDED Requirements"
Cohesion: 0.07
Nodes (29): ADDED Requirements, Purpose, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 41 - "DuplicateMatcherTest"
Cohesion: 0.10
Nodes (5): IfirmaDuplicateMatcher, D5. Verification loop and unverifiable candidates, 6. Duplicate guard (spec: receipt-duplicate-guard), 6. Customer page panel, hook and upgrade (D1, D3, D8, D9), DuplicateMatcherTest

### Community 42 - "ADDED Requirements"
Cohesion: 0.08
Nodes (23): ADDED Requirements, Purpose, Requirement: Manual submission, Requirement: Schema columns, Requirement: Status synchronisation, Requirement: Status visibility, Requirement: Submission mode, Scenario: Accepted document is never re-sent (+15 more)

### Community 43 - "ContractorServiceTest"
Cohesion: 0.05
Nodes (45): 1. Contractor read-back after a confirmed create (D1, D2), Context, D1. Default mode is `b2b`, D2. The `ksef` step is non-blocking, D3. B2B detection uses the stored request payload, then the invoice address, D4. `WYSLANA` marker is kept until iFirma reports a status, D5. Lazy schema columns, D6. Mock behaviour and what is unverified (+37 more)

### Community 44 - "DuplicateGuardTest"
Cohesion: 0.12
Nodes (8): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why, DuplicateGuardTest

### Community 45 - "Order"
Cohesion: 0.13
Nodes (8): IfirmaDuplicateGuard, D2. What identifies the buyer, D4. Product line comparison, 1. Shared order helpers (D2, D3, D4), 3. Guard integration (D5), D3. Split ReceiptService along existing static seams, seedPositionsFor(), Order

### Community 46 - "Requirement: Candidate detail verification"
Cohesion: 0.06
Nodes (30): ADDED Requirements, MODIFIED Requirements, Requirement: Candidate detail verification, Requirement: Candidate selection, Requirement: Suspected duplicate blocks issuance, Requirement: Unverifiable candidate stays suspected, Scenario: Admin sees candidates, Scenario: All conditions met (+22 more)

### Community 47 - "IfirmaDeliveryService"
Cohesion: 0.09
Nodes (13): IfirmaDeliveryService, D1. Read-back sequence inside the create step, D3. Bounded in-request PDF retry in the delivery service, D4. Blind copy through the existing `Mail::Send` call, D5. Configuration fields and validation, D6. Test doubles and the mock, Decisions, 3. PDF download retry in shop-mail delivery (D3) (+5 more)

### Community 48 - "IfirmaReceipt"
Cohesion: 0.12
Nodes (7): IfirmaReceipt, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), IfirmaReceiptTest, IfirmaReceiptWriteSpy

### Community 49 - "Requirements"
Cohesion: 0.04
Nodes (47): Purpose, receipt-auto-issuance Specification, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 50 - "Requirements"
Cohesion: 0.05
Nodes (40): customer-receipt-visibility Specification, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 51 - "IfirmaDataRequestTest"
Cohesion: 0.14
Nodes (7): IfirmaDataRequestException, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), IfirmaDataRequestTest

### Community 53 - "Requirements"
Cohesion: 0.08
Nodes (23): ksef-submission Specification, Purpose, Requirement: Manual submission, Requirement: Schema columns, Requirement: Status synchronisation, Requirement: Status visibility, Requirement: Submission mode, Requirements (+15 more)

### Community 54 - "IfirmaReceiptService"
Cohesion: 0.08
Nodes (8): IfirmaReceiptService, Context, Design, Goals / Non-Goals, Migration Plan, Open Questions, Risks / Trade-offs, CreateReceiptTest

### Community 55 - "IfirmaDataRequestService"
Cohesion: 0.13
Nodes (4): IfirmaDataRequestService, 11. Data-request notifier (Tier 2), {closure#6}(), {closure#7}()

### Community 56 - "composer.json"
Cohesion: 0.12
Nodes (16): config, platform, sort-packages, vendor-dir, description, license, name, php (+8 more)

### Community 57 - ".updateValue"
Cohesion: 0.08
Nodes (14): IfirmaDeliveryResult, D10. `IfirmaDataRequestNotifier`, D1. Test-first protocol per refactor task, D2. Coverage tooling: pcov plus Cobertura plus diff-cover, D3. PHP 7.2 compatibility lint, D4. API-client factory: `IfirmaApiClient::fromConfiguration($baseUrl = null)`, D5. AdminActions error guard: `private function guard(callable $action)`, D7. Constants, not enums (+6 more)

### Community 58 - "Requirements"
Cohesion: 0.12
Nodes (15): ifirma-api-compliance Specification, Purpose, Requirement: Contractor attached by iFirma is learned, Requirement: Country fields on the buyer, Requirement: Kod 200 retry without DodatkoweUwagi, Requirement: Kod 201 retry for unknown contractor reference, Requirement: Position VAT rates, Requirements (+7 more)

### Community 60 - "Requirements"
Cohesion: 0.20
Nodes (9): Purpose, receipt-duplicate-guard Specification, Requirement: Guard applies to all issuance paths, Requirement: Order reference stamping, Requirements, Scenario: Both paths guarded, Scenario: Document created, Scenario: Guard disabled (+1 more)

### Community 61 - "PHPUnit\Framework\TestCase"
Cohesion: 0.07
Nodes (3): {closure#1}(), {closure#1}(), PipelineResultTest

### Community 64 - "Requirements"
Cohesion: 0.06
Nodes (32): Purpose, receipt-status-sync Specification, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 65 - "ADDED Requirements"
Cohesion: 0.04
Nodes (46): ADDED Requirements, Purpose, Requirement: Access control on panel actions, Requirement: Contractor link state, Requirement: Contractor search for an older contractor, Requirement: Document count from iFirma on request, Requirement: E-mail and phone ranking, Requirement: Live registration check (+38 more)

### Community 66 - "seed"
Cohesion: 0.14
Nodes (28): post_json(), seed(), sign(), test_detail_requires_auth_and_pdf_route_still_works(), test_limit_without_abonent_key_is_rejected(), test_pdf_not_ready_answers_404_envelope_then_serves_pdf(), test_send_defaults_to_contractor_email(), test_send_success_marks_sent_and_records_recipient() (+20 more)

### Community 67 - "Requirements"
Cohesion: 0.08
Nodes (25): Purpose, receipt-delivery Specification, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+17 more)

### Community 70 - ".resetAll"
Cohesion: 0.09
Nodes (4): Address, Country, Customer, sendToCustomer()

### Community 71 - ".shop"
Cohesion: 0.11
Nodes (22): Requirement: Copy of the shop-mail delivery, Scenario: Copy address equals the customer address, Scenario: Copy mode none, Scenario: Copy to an employee, Scenario: Copy to the shop mailbox, Scenario: Employee no longer usable, Scenario: iFirma-mail channel unaffected, Scenario: Manual resend carries the copy (+14 more)

### Community 72 - "Requirement: Created contractor read-back"
Cohesion: 0.10
Nodes (19): ADDED Requirements, Requirement: Contractor step notes, Requirement: Created contractor read-back, Scenario: Attached contractor still shown, Scenario: Business contractor stored under an identifier iFirma assigned, Scenario: Contractor visible after a short wait, Scenario: Create rejected, Scenario: Created under an assigned identifier (+11 more)

### Community 73 - "Requirement: Delivery copy settings"
Cohesion: 0.12
Nodes (15): ADDED Requirements, Requirement: Delivery copy settings, Requirement: PDF download retry in shop-mail delivery, Scenario: Authentication error is not retried, Scenario: Customer download keeps a single attempt, Scenario: Defaults after install, Scenario: Employee list offered, Scenario: Employee mode without an employee refused (+7 more)

### Community 74 - "test_mock_enums.py"
Cohesion: 0.17
Nodes (31): all_documents(), assert_rejected(), assert_validation_rejected(), _clean_state(), document(), post_document(), sign(), test_absent_and_null_enum_fields_are_not_checked() (+23 more)

### Community 75 - ".l"
Cohesion: 0.22
Nodes (15): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#2}() (+7 more)

### Community 76 - "ADDED Requirements"
Cohesion: 0.08
Nodes (23): ADDED Requirements, Purpose, Requirement: Installable package layout, Requirement: Mock API tests run in CI, Requirement: Release commits get a CI run, Requirement: Test builds are uniquely identified, Requirement: Test builds do not alter the module version, Requirement: Test builds only from a passing tree (+15 more)

### Community 78 - "IfirmaTestRegistryTrait"
Cohesion: 0.10
Nodes (4): Currency, OrderHistory, OrderState, IfirmaTestRegistryTrait

### Community 79 - "AdminControllerCustomerTest"
Cohesion: 0.10
Nodes (4): Access, AdminControllerCustomerTest, IfirmaTestCapturingAdminController, IfirmaTestJsonResponse

### Community 80 - "Requirements"
Cohesion: 0.10
Nodes (19): module-packaging Specification, Purpose, Requirement: Installable package layout, Requirement: Mock API tests run in CI, Requirement: Release commits get a CI run, Requirement: Test builds do not alter the module version, Requirement: Test builds only from a passing tree, Requirement: Version sources stay consistent (+11 more)

### Community 81 - "ADDED Requirements"
Cohesion: 0.15
Nodes (13): ADDED Requirements, Requirement: Country fields on the buyer, Requirement: Kod 200 retry without DodatkoweUwagi, Requirement: Kod 201 retry for unknown contractor reference, Requirement: Position VAT rates, Requirement: Request timeouts, Scenario: Customer page sync, Scenario: Default call (+5 more)

### Community 83 - "Requirement: Created contractor read-back"
Cohesion: 0.18
Nodes (11): Requirement: Created contractor read-back, Scenario: Business contractor stored under an identifier iFirma assigned, Scenario: Contractor visible after a short wait, Scenario: Create rejected, Scenario: Nothing can be found, Scenario: Private person stored under an identifier iFirma assigned, Scenario: Read-back fails at transport level, Scenario: Reported identifier differs from the sent one (+3 more)

### Community 84 - "IfirmaMatchLabels"
Cohesion: 0.33
Nodes (3): IfirmaMatchLabels, D6. One label map: `IfirmaMatchLabels`, 4. Match labels (Tier 1)

### Community 85 - "Proposal"
Cohesion: 0.25
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

### Community 86 - "Tasks"
Cohesion: 0.22
Nodes (8): 1. Housekeeping, 2. test-stack submodule, 3. Version consistency test, 4. Test build packaging, 5. Atomic release workflow, 6. Integration check, Tasks, Workflow follow-up

### Community 88 - "Module"
Cohesion: 0.14
Nodes (3): D1. Hook `displayAdminCustomers`, rendered from `ifirma.php`, Module, upgrade_module_1_2_0()

### Community 89 - "Requirement: Contractor registration before issuance"
Cohesion: 0.29
Nodes (7): Requirement: Contractor registration before issuance, Scenario: Billing data changed since the last document, Scenario: Contractor API unavailable, Scenario: Contractor sync disabled, Scenario: First document for a customer, Scenario: Identifier rejected by iFirma, Scenario: Unchanged contractor

### Community 90 - "ADDED Requirements"
Cohesion: 0.29
Nodes (7): ADDED Requirements, Requirement: Admin rejects a request, Requirement: Audit trail, Scenario: Cancelled request, Scenario: Reject with reason, Scenario: Reject without reason, Scenario: Resolved request with edits

### Community 91 - "Requirement: PDF download retry in shop-mail delivery"
Cohesion: 0.29
Nodes (7): Requirement: PDF download retry in shop-mail delivery, Scenario: Authentication error is not retried, Scenario: Customer download keeps a single attempt, Scenario: Manual resend retries the same way, Scenario: PDF never ready, Scenario: PDF ready on the second attempt, Scenario: Rate guard refusal is not retried

### Community 101 - "Requirement: Existing contractor matching"
Cohesion: 0.33
Nodes (6): Requirement: Existing contractor matching, Scenario: Ambiguous name match, Scenario: Business customer matched by NIP, Scenario: Mapped contractor disappeared, Scenario: Private person matched by name and e-mail, Scenario: Stored mapping wins

### Community 102 - "Requirement: Candidate detail verification"
Cohesion: 0.22
Nodes (9): Requirement: Candidate detail verification, Scenario: Extra positions are tolerated, Scenario: Monthly sale date format, Scenario: Name normalisation, Scenario: Reference wins over verification, Scenario: Same buyer, same products, different sale date, Scenario: Same buyer, same sale date, different product, Scenario: Same buyer, same sale date, different quantity (+1 more)

### Community 103 - "IfirmaContractorService"
Cohesion: 0.13
Nodes (7): IfirmaContractorService, D2. Structured notes translated at render time, Context, Design, Goals / Non-Goals, Migration Plan, Risks / Trade-offs

### Community 104 - "IfirmaAdminActions"
Cohesion: 0.15
Nodes (8): IfirmaAdminActions, 5. Link, unlink and register actions (D2, D7, D8), 8. Integration check against the mock, 3. AdminActions error guard (Tier 1), D4. AdminActions split behind a facade, What Changes, 8. Admin actions split, What it covers

### Community 105 - "DataRequestServiceTest.php"
Cohesion: 0.06
Nodes (37): approvalVars(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#14}(), {closure#15}(), {closure#17}(), {closure#18}() (+29 more)

### Community 106 - "Requirement: Candidate selection"
Cohesion: 0.22
Nodes (9): Requirement: Candidate selection, Scenario: All conditions met, Scenario: Amount outside tolerance, Scenario: Different currency, Scenario: Document of another buyer excluded, Scenario: Draft excluded, Scenario: Linked customer identifier, Scenario: Matched by name (+1 more)

### Community 109 - "Requirement: Eligibility"
Cohesion: 0.33
Nodes (6): Requirement: Eligibility, Scenario: Document already exists, Scenario: Eligible customer, Scenario: Feature disabled, Scenario: Order of another customer, Scenario: Pending request already exists

### Community 110 - "IfirmaCustomerContractorService"
Cohesion: 0.11
Nodes (4): {closure#1}(), IfirmaCustomerContractorService, 5. Outcome and status constants (Tier 1), 1. Small seams

### Community 112 - "Decisions"
Cohesion: 0.10
Nodes (19): D11. Payment method follows the order, D12. Register the contractor before issuing (production finding, 2026-11-02), D13. Link customers to existing contractors (merchant finding, 2026-11-02), D14. Reference contractors through IdentyfikatorKontrahenta (live-API finding, 2026-11-02), D15. Unknown contractor in the list filter is not an error (live-API finding, 2026-11-02), D16. Only documented enumeration values (live-API finding, 2026-11-02), D17. fakturakraj only, PRC-only positions (merchant decision and live-API table review, 2026-11-02), D1. Duplicate detection = list window + identity + confirmation by stamped reference (+11 more)

### Community 113 - "Requirement: Request content and validation"
Cohesion: 0.33
Nodes (6): Requirement: Request content and validation, Scenario: Company without NIP, Scenario: Malformed NIP, Scenario: Missing postcode or city, Scenario: Private person, Scenario: Valid company request

### Community 114 - "Requirements"
Cohesion: 0.29
Nodes (6): invoice-data-change-request Specification, Purpose, Requirement: Requests after issuance, Requirements, Scenario: Adopted document, Scenario: Issued document

### Community 115 - "Requirement: Eligibility"
Cohesion: 0.33
Nodes (6): Requirement: Eligibility, Scenario: Document already exists, Scenario: Eligible customer, Scenario: Feature disabled, Scenario: Order of another customer, Scenario: Pending request already exists

### Community 116 - "Requirement: Request content and validation"
Cohesion: 0.33
Nodes (6): Requirement: Request content and validation, Scenario: Company without NIP, Scenario: Malformed NIP, Scenario: Missing postcode or city, Scenario: Private person, Scenario: Valid company request

### Community 118 - "Requirement: Contractor step notes"
Cohesion: 0.33
Nodes (6): Requirement: Contractor step notes, Scenario: Attached contractor still shown, Scenario: Created under an assigned identifier, Scenario: Created under the sent identifier, Scenario: Fallback after a confirmed create, Scenario: Fallback before the create

### Community 119 - "Requirement: Approved data overrides the document payload"
Cohesion: 0.40
Nodes (5): Requirement: Approved data overrides the document payload, Scenario: Audit and address untouched, Scenario: Company override, Scenario: No approved request, Scenario: Private person override

### Community 120 - "Requirement: Admin approves a request"
Cohesion: 0.50
Nodes (4): Requirement: Admin approves a request, Scenario: Approve unchanged, Scenario: Approve with edits, Scenario: Invalid edit

### Community 121 - "Requirement: CSRF protection"
Cohesion: 0.50
Nodes (4): Requirement: CSRF protection, Scenario: Missing token on submission, Scenario: Valid token, Scenario: Wrong token on cancellation

### Community 122 - "Requirement: Customer cancels a request"
Cohesion: 0.50
Nodes (4): Requirement: Customer cancels a request, Scenario: Another customer's request, Scenario: Cancel a resolved request, Scenario: Cancel own pending request

### Community 123 - "Requirement: Resolution resumes issuance"
Cohesion: 0.50
Nodes (4): Requirement: Resolution resumes issuance, Scenario: Automatic issuance disabled, Scenario: Trigger status already reached, Scenario: Trigger status not yet reached

### Community 124 - "Requirement: Submission creates a pending request"
Cohesion: 0.50
Nodes (4): Requirement: Submission creates a pending request, Scenario: Default notification address, Scenario: Merchant notification, Scenario: Successful submission

### Community 125 - "Requirement: Admin rejects a request"
Cohesion: 0.67
Nodes (3): Requirement: Admin rejects a request, Scenario: Reject with reason, Scenario: Reject without reason

### Community 126 - "Requirement: Audit trail"
Cohesion: 0.67
Nodes (3): Requirement: Audit trail, Scenario: Cancelled request, Scenario: Resolved request with edits

### Community 127 - "Requirement: Pending request holds issuance"
Cohesion: 0.67
Nodes (3): Requirement: Pending request holds issuance, Scenario: Manual create during hold, Scenario: Trigger status reached during hold

### Community 129 - "test_ifirma_mock.py"
Cohesion: 0.24
Nodes (9): create_receipt(), sign(), test_create_rejects_bad_hmac(), test_create_returns_string_identifier_without_number(), test_download_pdf_duplicate_variant(), test_download_pdf_returns_pdf_bytes(), test_list_exposes_number_under_wynik(), test_register_payment_updates_status() (+1 more)

### Community 130 - "Requirement: Contractor import from a CSV export"
Cohesion: 0.40
Nodes (5): Requirement: Contractor import from a CSV export, Scenario: Import links customers, Scenario: Mapping visible per order, Scenario: Re-import is idempotent, Scenario: Unusable file

### Community 131 - "Requirement: Error-code mapping"
Cohesion: 0.40
Nodes (5): Requirement: Error-code mapping, Scenario: HTTP error without body, Scenario: Invalid hash, Scenario: Invalid user, Scenario: Terms not accepted and permissions

### Community 132 - "Requirement: Contractor registration before issuance"
Cohesion: 0.29
Nodes (7): Requirement: Contractor registration before issuance, Scenario: Billing data changed since the last document, Scenario: Contractor API unavailable, Scenario: Contractor sync disabled, Scenario: First document for a customer, Scenario: Identifier rejected by iFirma, Scenario: Unchanged contractor

### Community 133 - "Requirement: Local rate guard"
Cohesion: 0.40
Nodes (5): Requirement: Local rate guard, Scenario: Cap reached, Scenario: New minute, Scenario: Transient error not persisted, Scenario: Under the cap

### Community 134 - ".getInstance"
Cohesion: 0.12
Nodes (5): D7. Removing runtime schema checks is a gated, last step, discoverFixtures(), bqSQL(), pSQL(), SchemaTest

### Community 135 - "Requirement: Payload length limits"
Cohesion: 0.40
Nodes (5): Requirement: Payload length limits, Scenario: Long contractor name, Scenario: Long line name, Scenario: Multibyte text, Scenario: Value within limits

### Community 136 - "Requirement: Approved data overrides the document payload"
Cohesion: 0.40
Nodes (5): Requirement: Approved data overrides the document payload, Scenario: Audit and address untouched, Scenario: Company override, Scenario: No approved request, Scenario: Private person override

### Community 137 - "Requirement: Existing contractor matching"
Cohesion: 0.33
Nodes (6): Requirement: Existing contractor matching, Scenario: Ambiguous name match, Scenario: Business customer matched by NIP, Scenario: Mapped contractor disappeared, Scenario: Private person matched by name and e-mail, Scenario: Stored mapping wins

### Community 139 - "Requirement: Deterministic contractor identifier"
Cohesion: 0.50
Nodes (4): Requirement: Deterministic contractor identifier, Scenario: Excessive length, Scenario: Guest checkout, Scenario: Registered customer

### Community 140 - "Requirement: Documented enumerations only"
Cohesion: 0.50
Nodes (4): Requirement: Documented enumerations only, Scenario: Default signature type, Scenario: Invalid value never reaches iFirma, Scenario: Merchant choice

### Community 141 - "Requirement: Optional limit visibility"
Cohesion: 0.50
Nodes (4): Requirement: Optional limit visibility, Scenario: Key configured, Scenario: Key not configured, Scenario: Limit request fails

### Community 142 - "Requirement: Request signing"
Cohesion: 0.50
Nodes (4): Requirement: Request signing, Scenario: Invalid key, Scenario: Query string excluded, Scenario: Valid key

### Community 143 - "Requirement: Contractor import from a CSV export"
Cohesion: 0.40
Nodes (5): Requirement: Contractor import from a CSV export, Scenario: Import links customers, Scenario: Mapping visible per order, Scenario: Re-import is idempotent, Scenario: Unusable file

### Community 144 - "Requirement: Error-code mapping"
Cohesion: 0.40
Nodes (5): Requirement: Error-code mapping, Scenario: HTTP error without body, Scenario: Invalid hash, Scenario: Invalid user, Scenario: Terms not accepted and permissions

### Community 145 - "Requirement: Local rate guard"
Cohesion: 0.40
Nodes (5): Requirement: Local rate guard, Scenario: Cap reached, Scenario: New minute, Scenario: Transient error not persisted, Scenario: Under the cap

### Community 146 - "Requirement: Payload length limits"
Cohesion: 0.40
Nodes (5): Requirement: Payload length limits, Scenario: Long contractor name, Scenario: Long line name, Scenario: Multibyte text, Scenario: Value within limits

### Community 147 - "Requirement: Admin approves a request"
Cohesion: 0.50
Nodes (4): Requirement: Admin approves a request, Scenario: Approve unchanged, Scenario: Approve with edits, Scenario: Invalid edit

### Community 148 - "Requirement: CSRF protection"
Cohesion: 0.50
Nodes (4): Requirement: CSRF protection, Scenario: Missing token on submission, Scenario: Valid token, Scenario: Wrong token on cancellation

### Community 149 - "Requirement: Customer cancels a request"
Cohesion: 0.50
Nodes (4): Requirement: Customer cancels a request, Scenario: Another customer's request, Scenario: Cancel a resolved request, Scenario: Cancel own pending request

### Community 150 - "Requirement: Deterministic contractor identifier"
Cohesion: 0.50
Nodes (4): Requirement: Deterministic contractor identifier, Scenario: Excessive length, Scenario: Guest checkout, Scenario: Registered customer

### Community 151 - "Requirement: Optional limit visibility"
Cohesion: 0.50
Nodes (4): Requirement: Optional limit visibility, Scenario: Key configured, Scenario: Key not configured, Scenario: Limit request fails

### Community 152 - "Requirement: Request signing"
Cohesion: 0.50
Nodes (4): Requirement: Request signing, Scenario: Invalid key, Scenario: Query string excluded, Scenario: Valid key

### Community 153 - "Requirement: Resolution resumes issuance"
Cohesion: 0.50
Nodes (4): Requirement: Resolution resumes issuance, Scenario: Automatic issuance disabled, Scenario: Trigger status already reached, Scenario: Trigger status not yet reached

### Community 154 - "Requirement: Submission creates a pending request"
Cohesion: 0.50
Nodes (4): Requirement: Submission creates a pending request, Scenario: Default notification address, Scenario: Merchant notification, Scenario: Successful submission

### Community 155 - "Requirement: Documented enumerations only"
Cohesion: 0.50
Nodes (4): Requirement: Documented enumerations only, Scenario: Default signature type, Scenario: Invalid value never reaches iFirma, Scenario: Merchant choice

### Community 156 - "Tasks"
Cohesion: 0.20
Nodes (7): IfirmaHttpTransport, 10. IfirmaDataRequest approve/reject (Tier 2), 13. Contractor resolution object (Tier 2), 14. Integration check, 1. Safety net, Tasks, Workflow follow-up

### Community 163 - ".objects"
Cohesion: 0.10
Nodes (11): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+3 more)

### Community 166 - "Requirement: Contractor attached by iFirma is learned"
Cohesion: 0.67
Nodes (3): Requirement: Contractor attached by iFirma is learned, Scenario: Contractor attached by iFirma is learned, Scenario: Long identifiers

### Community 167 - "Requirement: Non-PDF body on PDF endpoint"
Cohesion: 0.67
Nodes (3): Requirement: Non-PDF body on PDF endpoint, Scenario: JSON error body, Scenario: Unparseable body

### Community 168 - "Requirement: Contractor reference on documents"
Cohesion: 0.67
Nodes (3): Requirement: Contractor reference on documents, Scenario: Business buyer NIP, Scenario: Resolved contractor is referenced at the top level

### Community 169 - "Requirement: List calls always bounded"
Cohesion: 0.67
Nodes (3): Requirement: List calls always bounded, Scenario: Guard lookup, Scenario: Number lookup

### Community 170 - "Requirement: Contractor reference on documents"
Cohesion: 0.67
Nodes (3): Requirement: Contractor reference on documents, Scenario: Business buyer NIP, Scenario: Resolved contractor is referenced at the top level

### Community 171 - "store.py"
Cohesion: 0.10
Nodes (11): mock_reset(), contractor_create_mode(), contractor_search(), contractors_reset(), mark_deleted(), pdf_not_ready_consume(), _renamed_contractor_identifier(), reset() (+3 more)

### Community 172 - "Requirement: List calls always bounded"
Cohesion: 0.67
Nodes (3): Requirement: List calls always bounded, Scenario: Guard lookup, Scenario: Number lookup

### Community 173 - "Requirement: Request timeouts"
Cohesion: 0.67
Nodes (3): Requirement: Request timeouts, Scenario: Customer page sync, Scenario: Default call

### Community 174 - "build-zip.mjs"
Cohesion: 0.15
Nodes (11): archive, buildInfo(), CHANNELS, DIRS, FILES, gitCommit(), info, missing (+3 more)

### Community 175 - ".postProcessConfiguration"
Cohesion: 0.15
Nodes (11): 8. Payment map serialiser (Tier 1), Context, D1. Same test-first protocol as `refactor-clean-code-tda` D1, D2. Steps return explicit continue or stop, not shared flags, D5. Module-class extractions keep `l()` in `ifirma.php`, D6. Pure cores, thin I/O shells, Decisions, Design (+3 more)

### Community 176 - "Requirement: Non-PDF body on PDF endpoint"
Cohesion: 0.67
Nodes (3): Requirement: Non-PDF body on PDF endpoint, Scenario: JSON error body, Scenario: Unparseable body

### Community 178 - "Requirement: Pending request holds issuance"
Cohesion: 0.67
Nodes (3): Requirement: Pending request holds issuance, Scenario: Manual create during hold, Scenario: Trigger status reached during hold

### Community 180 - "Requirement: Remote lookup before issuance"
Cohesion: 0.33
Nodes (6): Requirement: Remote lookup before issuance, Scenario: Fallback to NIP, Scenario: Filter by contractor identifier, Scenario: Multiple pages, Scenario: Unfiltered query within the duplicate window, Scenario: Window too large for unfiltered query

### Community 181 - "JSONResponse"
Cohesion: 0.26
Nodes (16): ksef_send(), mock_delete_document(), mock_get_contractor_mode(), mock_get_pdf_not_ready(), mock_ksef_accept(), mock_ksef_reject(), mock_seed_contractor(), mock_seed_document() (+8 more)

### Community 182 - "Any"
Cohesion: 0.16
Nodes (10): _attach_contractor(), contractor_create(), _find_top_level_contractor(), contractor_create(), contractor_find_by_nip(), contractor_identifier(), contractor_update(), contractor_upsert() (+2 more)

### Community 183 - "Requirement: Suspected duplicate blocks issuance"
Cohesion: 0.40
Nodes (5): Requirement: Suspected duplicate blocks issuance, Scenario: Admin sees candidates, Scenario: Candidates without reference, Scenario: Customer view, Scenario: Older candidate records

### Community 184 - "Requirement: Admin resolution of suspected duplicates"
Cohesion: 0.50
Nodes (4): Requirement: Admin resolution of suspected duplicates, Scenario: Bypass is one-time, Scenario: Issue anyway, Scenario: Link a candidate

### Community 185 - "Requirement: Confirmed match adoption"
Cohesion: 0.50
Nodes (4): Requirement: Confirmed match adoption, Scenario: Adopted document is fully paid, Scenario: Presentation of adopted document, Scenario: Reference found in candidate

### Community 186 - "Requirement: Unknown contractor filter is conclusive"
Cohesion: 0.50
Nodes (4): Requirement: Unknown contractor filter is conclusive, Scenario: Contractor unknown to iFirma, Scenario: Linked customer, Scenario: Other failures stay inconclusive

### Community 187 - "Requirement: Unverifiable candidate stays suspected"
Cohesion: 0.50
Nodes (4): Requirement: Unverifiable candidate stays suspected, Scenario: Candidates beyond the detail limit, Scenario: Detail request fails, Scenario: Detail without positions

### Community 188 - "Requirement: Inconclusive guard"
Cohesion: 0.67
Nodes (3): Requirement: Inconclusive guard, Scenario: Automatic issuance, Scenario: Manual issuance

### Community 189 - "Requirement: Local record precedence"
Cohesion: 0.67
Nodes (3): Requirement: Local record precedence, Scenario: Order already has a receipt, Scenario: Previous attempt ended in error

### Community 191 - "_authorized"
Cohesion: 0.29
Nodes (13): abonent_limit(), _authorized(), contractor_by_id(), contractor_replace(), contractor_search_route(), document_detail(), download_pdf(), _error() (+5 more)

### Community 192 - "Requirement: Requests after issuance"
Cohesion: 0.67
Nodes (3): Requirement: Requests after issuance, Scenario: Adopted document, Scenario: Issued document

### Community 194 - "Document"
Cohesion: 0.18
Nodes (7): _find_for_ksef(), _row(), add_payment(), compute_gross(), create(), Document, parse_date()

### Community 195 - ".search"
Cohesion: 0.15
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

### Community 196 - "config.py"
Cohesion: 0.16
Nodes (5): AuthResult, _signed_url(), verify(), _env_bool(), Settings

### Community 197 - "Decisions"
Cohesion: 0.29
Nodes (7): D2. New `IfirmaCustomerContractorService`, D3. Registration check loaded by AJAX, D4. Local counts in SQL, D5. Remote count: yearly windows, union by `FakturaId`, budget of 20, D6. Search: phrases, merge, rank, D9. Version 1.2.0, Decisions

### Community 199 - "IfirmaDataRequestNotifier"
Cohesion: 0.10
Nodes (5): IfirmaDataRequestNotifier, 8. Delivery (spec: receipt-delivery), Notes from the first real run (PrestaShop 8.1.7, 2026-11-01), Mail, HarnessSmokeTest

### Community 201 - "Decisions"
Cohesion: 0.22
Nodes (9): D1. Build metadata comes from environment variables read by `build-zip.mjs`, D2. The build number is `github.run_number`, D3. CI gating with `needs:`, D4. Release order: verify, bump locally, build, then push, then publish, D5. A version-consistency test instead of a fixed version, D6. test-stack becomes a real submodule, D7. Keep the graph, drop only the cache that is cheap to rebuild, D8. The header typo becomes a checker failure (+1 more)

### Community 202 - "Decisions"
Cohesion: 0.12
Nodes (11): IfirmaGuardResult, Context, D1. Buyer match on list rows, before the detail step, D3. Sale date source and comparison, D6. Candidate summary and admin panel, D7. Test stack and fixtures, Decisions, Design (+3 more)

### Community 203 - "verify-package.mjs"
Cohesion: 0.20
Nodes (9): args, CHANNELS, DEV_PATHS, flag, NESTED_DEV_DIRS, readZip(), REQUIRED, verify() (+1 more)

### Community 204 - "Tasks"
Cohesion: 0.20
Nodes (9): 10. Integration check, 2. Contractor resolution steps, 3. Pure cores for counting and import, 4. Duplicate guard, 5. Receipt creation, 6. Issuance pipeline, 9. Runtime schema checks (gated), Tasks (+1 more)

### Community 207 - "1. Test harness (unit tests runnable without PrestaShop)"
Cohesion: 0.08
Nodes (14): D10. Verification strategy without a live iFirma account, 1. Test harness (unit tests runnable without PrestaShop), 1. Test stubs for the customer page, Context, Design, Goals / Non-Goals, Migration Plan, Risks / Trade-offs (+6 more)

### Community 208 - "Tasks"
Cohesion: 0.11
Nodes (16): 10. Status sync (spec: receipt-status-sync), 11. Customer visibility (spec: customer-receipt-visibility), 12. Integration verification and release readiness, 13. Payment method follows the order, 14. Contractor registration before issuance, 15. Existing contractor linking, 16. Contractor reference on documents, 17. Guard contractor filter (+8 more)

### Community 209 - ".link"
Cohesion: 0.14
Nodes (3): IfirmaContractorMap, 7. Schema helper (Tier 1), ContractorMapTest

### Community 210 - "Requirement: Releases are atomic"
Cohesion: 0.14
Nodes (12): Requirement: Releases are atomic, Scenario: Build failure during release, Scenario: Publish failure, Scenario: Push refused, Scenario: Successful release, Requirement: Releases are atomic, Scenario: Build failure during release, Scenario: Publish failure (+4 more)

### Community 212 - "scripts"
Cohesion: 0.22
Nodes (9): scripts, build, build:tgz, build:zip, check:license, clean, postversion, verify:package (+1 more)

### Community 213 - "ScriptedTransport"
Cohesion: 0.27
Nodes (3): Transport contract, ScriptedTransport, ScriptedTransportTest

### Community 218 - "Module smoke run"
Cohesion: 0.20
Nodes (9): Contractor linking check, Environment overrides, Known limitations, Module smoke run, Prerequisites, Reading the output, Run, Upgrade test 1.0.x -> 1.1.0 (+1 more)

### Community 219 - "Proposal"
Cohesion: 0.25
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

### Community 220 - "Proposal"
Cohesion: 0.25
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

### Community 223 - "Proposal"
Cohesion: 0.29
Nodes (6): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, Why

### Community 225 - "Requirement: Test builds are uniquely identified"
Cohesion: 0.50
Nodes (4): Requirement: Test builds are uniquely identified, Scenario: Locally built package, Scenario: Pull request build, Scenario: Two consecutive CI runs on the same version

### Community 233 - "Proposal"
Cohesion: 0.25
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

### Community 234 - "Tasks"
Cohesion: 0.29
Nodes (6): 2. Pure matcher rules (D1, D2, D3, D4, D6), 4. Admin panel (D6), 5. Test stack mock and smoke checks (D7; separate repository), 6. Documentation, 7. Integration checks, Tasks

### Community 235 - "Design"
Cohesion: 0.29
Nodes (6): Context, Design, Goals / Non-Goals, Migration Plan, Open Questions, Risks / Trade-offs

### Community 238 - "Requirement: iFirma-mail channel"
Cohesion: 0.50
Nodes (4): Requirement: iFirma-mail channel, Scenario: iFirma send returns an error, Scenario: iFirma send succeeds, Scenario: Long message text

## Knowledge Gaps
- **18 isolated node(s):** `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background`, `PrestaShop Module Icon Usage`, `graphify knowledge graph workflow` (+13 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1276 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **77 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Stubs (tests/Stubs)` connect `Stubs (tests/Stubs)` to `AdminIfirmaController`, `.getInstance`, `Ifirma`, `DataRequestServiceTest`, `Support (tests/Support)`, `Employee`, `.objects`, `Order`, `.postProcessConfiguration`, `Db`, `IfirmaDataRequestService`, `.updateValue`, `Tab`, `.resetAll`, `IfirmaDataRequestNotifier`, `.shop`, `.l`, `IfirmaTestRegistryTrait`, `AdminControllerCustomerTest`, `1. Test harness (unit tests runnable without PrestaShop)`, `ObjectModel`, `ContractorImportTest`, `Module`, `.substr`, `ApiClientFactoryTest`?**
  _High betweenness centrality (0.070) - this node is a cross-community bridge._
- **Are the 53 inferred relationships involving `IfirmaReceipt` (e.g. with `.ensureRow()` and `.hookDisplayOrderDetail()`) actually correct?**
  _`IfirmaReceipt` has 53 INFERRED edges - model-reasoned connections that need verification._
- **What connects `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background` to the rest of the system?**
  _18 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `main.py` be split into smaller, more focused modules?**
  _Cohesion score 0.12648221343873517 - nodes in this community are weakly interconnected._
- **Why does `ContractorServiceTest` connect `ContractorServiceTest` to `ContractorLabelWordingTest`, `.strlen`, `.resetAll`, `ContractorServiceTest.php`, `.updateValue`, `PHPUnit\Framework\TestCase`?**
  _High betweenness centrality (0.051) - this node is a cross-community bridge._
- **Are the 94 inferred relationships involving `Stubs (tests/Stubs)` (e.g. with `.fields()` and `Access`) actually correct?**
  _`Stubs (tests/Stubs)` has 94 INFERRED edges - model-reasoned connections that need verification._
- **Should `CustomerContractorServiceTest` be split into smaller, more focused modules?**
  _Cohesion score 0.059298245614035086 - nodes in this community are weakly interconnected._