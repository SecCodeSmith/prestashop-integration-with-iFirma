# Graph Report - ifirma  (2026-11-04)

## Corpus Check
- 185 files · ~177,346 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 18 file(s) not represented in the graph (top: (none) 8, .tpl 5, .xml 1)

## Summary
- 3068 nodes · 6982 edges · 202 communities (131 shown, 71 thin omitted)
- Extraction: 87% EXTRACTED · 13% INFERRED · 0% AMBIGUOUS · INFERRED: 879 edges (avg confidence: 0.89)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `882f9296`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- main.py
- test_mock_enums.py
- test-stack/tools/check_license_headers.py
- package.json
- Release workflow
- AdminIfirmaController
- Ifirma
- openspec-archive-change skill
- test_mock_contractors.py
- IfirmaReceipt
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
- DataRequestServiceTest
- test_mock_extensions.py
- ADDED Requirements
- DuplicateMatcherTest
- ADDED Requirements
- ContractorServiceTest
- DuplicateGuardTest
- Order
- Requirement: Candidate detail verification
- IfirmaDeliveryService
- CreateReceiptTest
- Requirements
- Requirements
- IfirmaDataRequest
- Db
- Requirement: Automatic submission in the pipeline
- 1. Test harness (unit tests runnable without PrestaShop)
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
- .submitPending
- Requirements
- .substr
- .getInstance
- .resetAll
- ADDED Requirements
- ContractorReferenceTest
- Requirement: Eligibility
- Requirement: Request content and validation
- ApiClientTest
- AdminActionsTest
- Requirement: Approved data overrides the document payload
- IfirmaTestRegistryTrait
- IfirmaWiringFixture
- Requirement: Admin approves a request
- Requirement: CSRF protection
- Requirement: Customer cancels a request
- Requirement: Resolution resumes issuance
- Requirement: Submission creates a pending request
- Module smoke run
- ContractorImportTest
- UpgradeTest
- Spec Delta
- Requirement: Pending request holds issuance
- Requirement: Requests after issuance
- Test fixtures
- .objects
- Requirement: Candidate detail verification
- IfirmaContractorService
- IfirmaIssuancePipeline
- HarnessSmokeTest
- Requirement: Candidate selection
- Decisions
- .get
- Decisions
- ScriptedTransport
- .isIssued
- Requirements
- Requirement: Eligibility
- Requirement: Request content and validation
- IfirmaReceiptService
- Proposal
- Requirement: Approved data overrides the document payload
- Requirement: Admin approves a request
- Requirement: CSRF protection
- Requirement: Customer cancels a request
- Requirement: Resolution resumes issuance
- Requirement: Submission creates a pending request
- Requirement: Admin rejects a request
- Requirement: Audit trail
- Requirement: Pending request holds issuance
- PrestaShop iFirma integration module
- .link
- ifirma-mock FastAPI service
- Requirement: Contractor registration before issuance
- Requirement: Contractor registration before issuance
- Docker Compose test stack
- Configuration
- IfirmaReceiptService.php
- Requirement: Existing contractor matching
- Requirement: Existing contractor matching
- .strlen
- Requirement: Contractor import from a CSV export
- Requirement: Error-code mapping
- Requirement: Local rate guard
- Requirement: Payload length limits
- Requirement: Contractor import from a CSV export
- Requirement: Error-code mapping
- Requirement: Local rate guard
- Requirement: Payload length limits
- Requirement: Deterministic contractor identifier
- Requirement: Optional limit visibility
- Requirement: Request signing
- Requirement: Deterministic contractor identifier
- Requirement: Optional limit visibility
- Requirement: Request signing
- Requirement: Non-PDF body on PDF endpoint
- Requirement: Documented enumerations only
- Requirement: Documented enumerations only
- .l
- Module
- IssuancePipelineTest.php
- Spec Delta
- Requirement: Contractor attached by iFirma is learned
- Requirement: Contractor reference on documents
- Requirement: List calls always bounded
- ifirma-api-compliance Specification
- Requirement: Non-PDF body on PDF endpoint
- Requirement: Contractor reference on documents
- Requirement: List calls always bounded
- .approve
- store.py
- Requirement: Position VAT rates
- Requirement: Request timeouts
- IfirmaGuardResult
- Requirement: Created contractor read-back
- TranslationCoverageTest
- IfirmaSchema
- Tasks
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
- .getContext
- Tasks
- Document
- Proposal
- Decisions
- build_pdf
- Employee
- IfirmaContractorMapDb

## God Nodes (most connected - your core abstractions)
1. `Stubs (tests/Stubs)` - 96 edges
2. `IfirmaReceipt` - 80 edges
3. `ModuleHooksTest` - 79 edges
4. `ContractorServiceTest` - 78 edges
5. `IfirmaApiClient` - 75 edges
6. `Order` - 72 edges
7. `IssuancePipelineTest` - 59 edges
8. `DuplicateGuardTest` - 55 edges
9. `IfirmaReceiptService` - 53 edges
10. `Ifirma` - 49 edges

## Surprising Connections (you probably didn't know these)
- `Context` --references--> `IfirmaApiClient`  [INFERRED]
  openspec/changes/archive/2026-11-03-fix-duplicate-guard-matching/design.md → classes/IfirmaApiClient.php
- `Context` --references--> `IfirmaApiClient`  [INFERRED]
  openspec/changes/ksef-b2b-submission/design.md → classes/IfirmaApiClient.php
- `D5. Lazy schema columns` --references--> `IfirmaSchema`  [INFERRED]
  openspec/changes/ksef-b2b-submission/design.md → classes/IfirmaSchema.php
- `6. Status sync and documentation` --references--> `IfirmaStatusSyncService`  [INFERRED]
  openspec/changes/ksef-b2b-submission/tasks.md → classes/IfirmaStatusSyncService.php
- `1. Test stubs for the customer page` --references--> `Context`  [INFERRED]
  openspec/changes/customer-ifirma-panel/tasks.md → tests/Stubs/Context.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **OpenSpec change lifecycle workflow** — _claude_skills_openspec_explore_skill, _claude_skills_openspec_propose_skill, _claude_skills_openspec_update_change_skill, _claude_skills_openspec_apply_change_skill, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill [EXTRACTED 0.95]
- **Local test stack services** — test_stack_docker_compose_db, test_stack_docker_compose_ifirma_mock, test_stack_docker_compose_prestashop [EXTRACTED 1.00]
- **Receipt creation and delivery flow** — readme_admin_ifirma_controller, classes_ifirmareceiptservice, classes_ifirmaapiclient, readme_receipt_front_controller [INFERRED 0.85]
- **Delta-to-main spec sync pipeline** — _claude_skills_openspec_sync_specs_skill_delta_specs, _claude_skills_openspec_sync_specs_skill_main_specs, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill_post_sync_verification, _claude_skills_openspec_archive_change_skill_archive_move [INFERRED 0.85]

## Communities (202 total, 71 thin omitted)

### Community 0 - "main.py"
Cohesion: 0.13
Nodes (10): _contractor_errors(), create_document(), _document_validation_error(), _has_invalid_enum(), health(), _ksef_date_ok(), _register_call(), reset_counters() (+2 more)

### Community 1 - "test_mock_enums.py"
Cohesion: 0.16
Nodes (31): all_documents(), assert_rejected(), assert_validation_rejected(), _clean_state(), document(), post_document(), sign(), test_absent_and_null_enum_fields_are_not_checked() (+23 more)

### Community 2 - "test-stack/tools/check_license_headers.py"
Cohesion: 0.14
Nodes (20): add_header(), has_marker(), insert_index(), main(), rel(), read_file(), render_header(), repo_root() (+12 more)

### Community 3 - "package.json"
Cohesion: 0.07
Nodes (25): author, description, devDependencies, archiver, files, homepage, license, name (+17 more)

### Community 4 - "Release workflow"
Cohesion: 0.21
Nodes (12): Module CI workflow, Build package (zip + tgz) job, tools/check_license_headers.py, Mock tests (test-stack) job, PHP lint & license headers job, Release workflow, Publish GitHub Release with zip and tgz, Version bump synced across package.json, ifirma.php, config.xml (+4 more)

### Community 5 - "AdminIfirmaController"
Cohesion: 0.06
Nodes (16): AdminIfirmaController, IfirmaCronModuleFrontController, IfirmaDatarequestModuleFrontController, IfirmaReceiptModuleFrontController, D8. Controller actions and access control, 1. Test stubs for the customer page, 2. Local link state and counts (D2, D4), 3. Registration check, search and ranking (D3, D6) (+8 more)

### Community 7 - "openspec-archive-change skill"
Cohesion: 0.18
Nodes (17): openspec-apply-change skill, openspec CLI, Store selection (--store flag), Task checkbox tracking (tasks.md), openspec-archive-change skill, Archive move to dated archive directory, openspec-explore skill, openspec-propose skill (+9 more)

### Community 8 - "test_mock_contractors.py"
Cohesion: 0.06
Nodes (60): AuthResult, _signed_url(), verify(), _env_bool(), Settings, create_receipt(), sign(), test_create_rejects_bad_hmac() (+52 more)

### Community 9 - "IfirmaReceipt"
Cohesion: 0.11
Nodes (4): IfirmaReceipt, IfirmaStatusSyncService, 10. Status sync (spec: receipt-status-sync), StatusSyncServiceTest

### Community 10 - "iFirma Module Logo"
Cohesion: 0.50
Nodes (4): Glowing Green Hammer Icon, iFirma Module Logo, Matrix-style Binary Code Rain Background, PrestaShop Module Icon Usage

### Community 27 - "IfirmaApiClient"
Cohesion: 0.14
Nodes (3): IfirmaApiClient, D8. API client hardening: error mapping, request accounting, rate guard, 4. API client hardening (spec: ifirma-api-compliance)

### Community 28 - "Support (tests/Support)"
Cohesion: 0.12
Nodes (6): PrestaShop stubs for unit tests, Support (tests/Support), IfirmaTestController, IfirmaTestRedirect, IfirmaTestState, IfirmaTestModuleLoader

### Community 29 - "run.php"
Cohesion: 0.18
Nodes (35): assertSame(), assertTrue(), candidateIds(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}() (+27 more)

### Community 32 - "ADDED Requirements"
Cohesion: 0.05
Nodes (40): ADDED Requirements, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 33 - "ADDED Requirements"
Cohesion: 0.15
Nodes (13): ADDED Requirements, Requirement: Country fields on the buyer, Requirement: Kod 200 retry without DodatkoweUwagi, Requirement: Kod 201 retry for unknown contractor reference, Requirement: Position VAT rates, Requirement: Request timeouts, Scenario: Customer page sync, Scenario: Default call (+5 more)

### Community 34 - "ADDED Requirements"
Cohesion: 0.04
Nodes (47): ADDED Requirements, Purpose, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 35 - "ADDED Requirements"
Cohesion: 0.05
Nodes (42): ADDED Requirements, Purpose, Requirement: Admin resolution of suspected duplicates, Requirement: Candidate selection, Requirement: Confirmed match adoption, Requirement: Guard applies to all issuance paths, Requirement: Inconclusive guard, Requirement: Local record precedence (+34 more)

### Community 37 - "Stubs (tests/Stubs)"
Cohesion: 0.08
Nodes (6): Hook, Language, Stubs (tests/Stubs), Shop, Tools, Validate

### Community 39 - "test_mock_extensions.py"
Cohesion: 0.08
Nodes (55): _clean_state(), get_json(), ids(), listing(), post_json(), seed(), sign(), test_delete_hides_and_unknown_delete_404() (+47 more)

### Community 40 - "ADDED Requirements"
Cohesion: 0.06
Nodes (32): ADDED Requirements, Purpose, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 41 - "DuplicateMatcherTest"
Cohesion: 0.09
Nodes (6): IfirmaDuplicateMatcher, D5. Verification loop and unverifiable candidates, 2. Pure matcher rules (D1, D2, D3, D4, D6), D7. Link, unlink and the warning, 6. Duplicate guard (spec: receipt-duplicate-guard), DuplicateMatcherTest

### Community 42 - "ADDED Requirements"
Cohesion: 0.07
Nodes (29): ADDED Requirements, Purpose, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 43 - "ContractorServiceTest"
Cohesion: 0.10
Nodes (10): 1. Contractor read-back after a confirmed create (D1, D2), Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, Risks, What Changes (+2 more)

### Community 44 - "DuplicateGuardTest"
Cohesion: 0.13
Nodes (8): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why, DuplicateGuardTest

### Community 45 - "Order"
Cohesion: 0.13
Nodes (12): IfirmaDuplicateGuard, D1. Buyer match on list rows, before the detail step, D2. What identifies the buyer, D3. Sale date source and comparison, D4. Product line comparison, D6. Candidate summary and admin panel, D7. Test stack and fixtures, Decisions (+4 more)

### Community 46 - "Requirement: Candidate detail verification"
Cohesion: 0.06
Nodes (30): ADDED Requirements, MODIFIED Requirements, Requirement: Candidate detail verification, Requirement: Candidate selection, Requirement: Suspected duplicate blocks issuance, Requirement: Unverifiable candidate stays suspected, Scenario: Admin sees candidates, Scenario: All conditions met (+22 more)

### Community 47 - "IfirmaDeliveryService"
Cohesion: 0.08
Nodes (18): IfirmaDeliveryService, D1. Read-back sequence inside the create step, D3. Bounded in-request PDF retry in the delivery service, D4. Blind copy through the existing `Mail::Send` call, D5. Configuration fields and validation, D6. Test doubles and the mock, Decisions, Design (+10 more)

### Community 49 - "Requirements"
Cohesion: 0.04
Nodes (47): Purpose, receipt-auto-issuance Specification, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 50 - "Requirements"
Cohesion: 0.05
Nodes (40): customer-receipt-visibility Specification, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 52 - "Db"
Cohesion: 0.10
Nodes (8): 1. KSeF API client and service, 2. Schema, 3. Pipeline, 4. Admin panel, manual action and customer view, 5. Mock, 6. Status sync and documentation, Tasks, Db

### Community 53 - "Requirement: Automatic submission in the pipeline"
Cohesion: 0.06
Nodes (30): ADDED Requirements, Purpose, Requirement: Automatic submission in the pipeline, Requirement: Manual submission, Requirement: Schema columns, Requirement: Status synchronisation, Requirement: Status visibility, Requirement: Submission mode (+22 more)

### Community 54 - "1. Test harness (unit tests runnable without PrestaShop)"
Cohesion: 0.09
Nodes (10): D10. Verification strategy without a live iFirma account, D3. Issue-and-send pipeline is one orchestrator with per-step state on the receipt row, 1. Test harness (unit tests runnable without PrestaShop), 8. Delivery (spec: receipt-delivery), Notes from the first real run (PrestaShop 8.1.7, 2026-11-01), Context, Link, Mail (+2 more)

### Community 56 - "composer.json"
Cohesion: 0.12
Nodes (15): config, platform, sort-packages, vendor-dir, description, license, name, php (+7 more)

### Community 58 - "Requirements"
Cohesion: 0.20
Nodes (10): Requirement: Contractor attached by iFirma is learned, Requirement: Country fields on the buyer, Requirement: Kod 200 retry without DodatkoweUwagi, Requirement: Kod 201 retry for unknown contractor reference, Requirements, Scenario: Contractor attached by iFirma is learned, Scenario: Long identifiers, Scenario: Optional object dropped once (+2 more)

### Community 60 - "Requirements"
Cohesion: 0.20
Nodes (9): Purpose, receipt-duplicate-guard Specification, Requirement: Guard applies to all issuance paths, Requirement: Order reference stamping, Requirements, Scenario: Both paths guarded, Scenario: Document created, Scenario: Guard disabled (+1 more)

### Community 64 - "Requirements"
Cohesion: 0.06
Nodes (32): Purpose, receipt-status-sync Specification, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 65 - "ADDED Requirements"
Cohesion: 0.04
Nodes (46): ADDED Requirements, Purpose, Requirement: Access control on panel actions, Requirement: Contractor link state, Requirement: Contractor search for an older contractor, Requirement: Document count from iFirma on request, Requirement: E-mail and phone ranking, Requirement: Live registration check (+38 more)

### Community 66 - ".submitPending"
Cohesion: 0.16
Nodes (8): {closure#1}(), {closure#11}(), {closure#12}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}()

### Community 67 - "Requirements"
Cohesion: 0.07
Nodes (29): Purpose, receipt-delivery Specification, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 68 - ".substr"
Cohesion: 0.09
Nodes (5): IfirmaCustomerView, IfirmaPaymentMethodResolver, PaymentMethodResolverTest, ifirma_list_files(), ifirma_scan()

### Community 69 - ".getInstance"
Cohesion: 0.13
Nodes (5): 2. Data model, configuration and upgrade, discoverFixtures(), bqSQL(), pSQL(), SchemaTest

### Community 70 - ".resetAll"
Cohesion: 0.08
Nodes (3): Address, Country, Customer

### Community 71 - "ADDED Requirements"
Cohesion: 0.29
Nodes (7): ADDED Requirements, Requirement: Admin rejects a request, Requirement: Audit trail, Scenario: Cancelled request, Scenario: Reject with reason, Scenario: Reject without reason, Scenario: Resolved request with edits

### Community 73 - "Requirement: Eligibility"
Cohesion: 0.33
Nodes (6): Requirement: Eligibility, Scenario: Document already exists, Scenario: Eligible customer, Scenario: Feature disabled, Scenario: Order of another customer, Scenario: Pending request already exists

### Community 74 - "Requirement: Request content and validation"
Cohesion: 0.33
Nodes (6): Requirement: Request content and validation, Scenario: Company without NIP, Scenario: Malformed NIP, Scenario: Missing postcode or city, Scenario: Private person, Scenario: Valid company request

### Community 77 - "Requirement: Approved data overrides the document payload"
Cohesion: 0.40
Nodes (5): Requirement: Approved data overrides the document payload, Scenario: Audit and address untouched, Scenario: Company override, Scenario: No approved request, Scenario: Private person override

### Community 78 - "IfirmaTestRegistryTrait"
Cohesion: 0.12
Nodes (4): changeState(), OrderHistory, OrderState, IfirmaTestRegistryTrait

### Community 81 - "Requirement: Admin approves a request"
Cohesion: 0.50
Nodes (4): Requirement: Admin approves a request, Scenario: Approve unchanged, Scenario: Approve with edits, Scenario: Invalid edit

### Community 82 - "Requirement: CSRF protection"
Cohesion: 0.50
Nodes (4): Requirement: CSRF protection, Scenario: Missing token on submission, Scenario: Valid token, Scenario: Wrong token on cancellation

### Community 83 - "Requirement: Customer cancels a request"
Cohesion: 0.50
Nodes (4): Requirement: Customer cancels a request, Scenario: Another customer's request, Scenario: Cancel a resolved request, Scenario: Cancel own pending request

### Community 84 - "Requirement: Resolution resumes issuance"
Cohesion: 0.50
Nodes (4): Requirement: Resolution resumes issuance, Scenario: Automatic issuance disabled, Scenario: Trigger status already reached, Scenario: Trigger status not yet reached

### Community 85 - "Requirement: Submission creates a pending request"
Cohesion: 0.50
Nodes (4): Requirement: Submission creates a pending request, Scenario: Default notification address, Scenario: Merchant notification, Scenario: Successful submission

### Community 86 - "Module smoke run"
Cohesion: 0.22
Nodes (9): Contractor linking check, Environment overrides, Known limitations, Module smoke run, Prerequisites, Reading the output, Run, Upgrade test 1.0.x -> 1.1.0 (+1 more)

### Community 87 - "ContractorImportTest"
Cohesion: 0.06
Nodes (31): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why, ADDED Requirements (+23 more)

### Community 90 - "Requirement: Pending request holds issuance"
Cohesion: 0.67
Nodes (3): Requirement: Pending request holds issuance, Scenario: Manual create during hold, Scenario: Trigger status reached during hold

### Community 91 - "Requirement: Requests after issuance"
Cohesion: 0.67
Nodes (3): Requirement: Requests after issuance, Scenario: Adopted document, Scenario: Issued document

### Community 101 - ".objects"
Cohesion: 0.22
Nodes (9): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+1 more)

### Community 102 - "Requirement: Candidate detail verification"
Cohesion: 0.22
Nodes (9): Requirement: Candidate detail verification, Scenario: Extra positions are tolerated, Scenario: Monthly sale date format, Scenario: Name normalisation, Scenario: Reference wins over verification, Scenario: Same buyer, same products, different sale date, Scenario: Same buyer, same sale date, different product, Scenario: Same buyer, same sale date, different quantity (+1 more)

### Community 103 - "IfirmaContractorService"
Cohesion: 0.10
Nodes (7): IfirmaContractorService, Context, Design, Goals / Non-Goals, Migration Plan, Risks / Trade-offs, D2. Structured notes translated at render time

### Community 104 - "IfirmaIssuancePipeline"
Cohesion: 0.17
Nodes (3): IfirmaIssuancePipeline, IfirmaPipelineResult, 7. Issuance pipeline (spec: receipt-auto-issuance)

### Community 106 - "Requirement: Candidate selection"
Cohesion: 0.22
Nodes (9): Requirement: Candidate selection, Scenario: All conditions met, Scenario: Amount outside tolerance, Scenario: Different currency, Scenario: Document of another buyer excluded, Scenario: Draft excluded, Scenario: Linked customer identifier, Scenario: Matched by name (+1 more)

### Community 109 - "Decisions"
Cohesion: 0.14
Nodes (13): Context, D1. Default mode is `b2b`, D2. The `ksef` step is non-blocking, D3. B2B detection uses the stored request payload, then the invoice address, D4. `WYSLANA` marker is kept until iFirma reports a status, D5. Lazy schema columns, D6. Mock behaviour and what is unverified, D7. Admin state and customer visibility are derived, not stored (+5 more)

### Community 111 - "Decisions"
Cohesion: 0.09
Nodes (21): Context, D11. Payment method follows the order, D12. Register the contractor before issuing (production finding, 2026-11-02), D13. Link customers to existing contractors (merchant finding, 2026-11-02), D14. Reference contractors through IdentyfikatorKontrahenta (live-API finding, 2026-11-02), D15. Unknown contractor in the list filter is not an error (live-API finding, 2026-11-02), D16. Only documented enumeration values (live-API finding, 2026-11-02), D17. fakturakraj only, PRC-only positions (merchant decision and live-API table review, 2026-11-02) (+13 more)

### Community 112 - "ScriptedTransport"
Cohesion: 0.20
Nodes (4): IfirmaHttpTransport, Transport contract, ScriptedTransport, ScriptedTransportTest

### Community 114 - "Requirements"
Cohesion: 0.29
Nodes (6): invoice-data-change-request Specification, Purpose, Requirement: Requests after issuance, Requirements, Scenario: Adopted document, Scenario: Issued document

### Community 115 - "Requirement: Eligibility"
Cohesion: 0.33
Nodes (6): Requirement: Eligibility, Scenario: Document already exists, Scenario: Eligible customer, Scenario: Feature disabled, Scenario: Order of another customer, Scenario: Pending request already exists

### Community 116 - "Requirement: Request content and validation"
Cohesion: 0.33
Nodes (6): Requirement: Request content and validation, Scenario: Company without NIP, Scenario: Malformed NIP, Scenario: Missing postcode or city, Scenario: Private person, Scenario: Valid company request

### Community 117 - "IfirmaReceiptService"
Cohesion: 0.13
Nodes (3): IfirmaReceiptService, Context, 2. Receipt note storage and order panel (D2)

### Community 118 - "Proposal"
Cohesion: 0.25
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

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

### Community 128 - "PrestaShop iFirma integration module"
Cohesion: 0.25
Nodes (8): OpenSpec change: receipt-lifecycle-automation, OpenSpec config (spec-driven schema), iFirma PrestaShop Module README, AdminIfirmaController, Feature: Create Receipt from order, PrestaShop iFirma integration module, Legal disclaimer & trademarks (IFIRMA SA), Feature: Update status (register payment)

### Community 130 - "ifirma-mock FastAPI service"
Cohesion: 0.29
Nodes (4): iFirma API endpoints (fakturaparagon, fakturakraj, wplaty), Endpoint parity with official iFirma API, ifirma-mock FastAPI service, pytest suite / sign() helper as auth spec

### Community 131 - "Requirement: Contractor registration before issuance"
Cohesion: 0.29
Nodes (7): Requirement: Contractor registration before issuance, Scenario: Billing data changed since the last document, Scenario: Contractor API unavailable, Scenario: Contractor sync disabled, Scenario: First document for a customer, Scenario: Identifier rejected by iFirma, Scenario: Unchanged contractor

### Community 132 - "Requirement: Contractor registration before issuance"
Cohesion: 0.29
Nodes (7): Requirement: Contractor registration before issuance, Scenario: Billing data changed since the last document, Scenario: Contractor API unavailable, Scenario: Contractor sync disabled, Scenario: First document for a customer, Scenario: Identifier rejected by iFirma, Scenario: Unchanged contractor

### Community 133 - "Docker Compose test stack"
Cohesion: 0.38
Nodes (6): Docker Compose test stack, db service (MariaDB 11.4), ifirma-mock service, prestashop service (latest), iFirma MockAPI test-stack README, Point module at mock (base URL, login, key)

### Community 135 - "IfirmaReceiptService.php"
Cohesion: 0.33
Nodes (3): ps_ifirma_receipt table, controllers/front/receipt.php secured PDF controller, Feature: Return connection (customer PDF download)

### Community 136 - "Requirement: Existing contractor matching"
Cohesion: 0.33
Nodes (6): Requirement: Existing contractor matching, Scenario: Ambiguous name match, Scenario: Business customer matched by NIP, Scenario: Mapped contractor disappeared, Scenario: Private person matched by name and e-mail, Scenario: Stored mapping wins

### Community 137 - "Requirement: Existing contractor matching"
Cohesion: 0.33
Nodes (6): Requirement: Existing contractor matching, Scenario: Ambiguous name match, Scenario: Business customer matched by NIP, Scenario: Mapped contractor disappeared, Scenario: Private person matched by name and e-mail, Scenario: Stored mapping wins

### Community 139 - "Requirement: Contractor import from a CSV export"
Cohesion: 0.40
Nodes (5): Requirement: Contractor import from a CSV export, Scenario: Import links customers, Scenario: Mapping visible per order, Scenario: Re-import is idempotent, Scenario: Unusable file

### Community 140 - "Requirement: Error-code mapping"
Cohesion: 0.40
Nodes (5): Requirement: Error-code mapping, Scenario: HTTP error without body, Scenario: Invalid hash, Scenario: Invalid user, Scenario: Terms not accepted and permissions

### Community 141 - "Requirement: Local rate guard"
Cohesion: 0.40
Nodes (5): Requirement: Local rate guard, Scenario: Cap reached, Scenario: New minute, Scenario: Transient error not persisted, Scenario: Under the cap

### Community 142 - "Requirement: Payload length limits"
Cohesion: 0.40
Nodes (5): Requirement: Payload length limits, Scenario: Long contractor name, Scenario: Long line name, Scenario: Multibyte text, Scenario: Value within limits

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

### Community 147 - "Requirement: Deterministic contractor identifier"
Cohesion: 0.50
Nodes (4): Requirement: Deterministic contractor identifier, Scenario: Excessive length, Scenario: Guest checkout, Scenario: Registered customer

### Community 148 - "Requirement: Optional limit visibility"
Cohesion: 0.50
Nodes (4): Requirement: Optional limit visibility, Scenario: Key configured, Scenario: Key not configured, Scenario: Limit request fails

### Community 149 - "Requirement: Request signing"
Cohesion: 0.50
Nodes (4): Requirement: Request signing, Scenario: Invalid key, Scenario: Query string excluded, Scenario: Valid key

### Community 150 - "Requirement: Deterministic contractor identifier"
Cohesion: 0.50
Nodes (4): Requirement: Deterministic contractor identifier, Scenario: Excessive length, Scenario: Guest checkout, Scenario: Registered customer

### Community 151 - "Requirement: Optional limit visibility"
Cohesion: 0.50
Nodes (4): Requirement: Optional limit visibility, Scenario: Key configured, Scenario: Key not configured, Scenario: Limit request fails

### Community 152 - "Requirement: Request signing"
Cohesion: 0.50
Nodes (4): Requirement: Request signing, Scenario: Invalid key, Scenario: Query string excluded, Scenario: Valid key

### Community 153 - "Requirement: Non-PDF body on PDF endpoint"
Cohesion: 0.67
Nodes (3): Requirement: Non-PDF body on PDF endpoint, Scenario: JSON error body, Scenario: Unparseable body

### Community 154 - "Requirement: Documented enumerations only"
Cohesion: 0.50
Nodes (4): Requirement: Documented enumerations only, Scenario: Default signature type, Scenario: Invalid value never reaches iFirma, Scenario: Merchant choice

### Community 155 - "Requirement: Documented enumerations only"
Cohesion: 0.50
Nodes (4): Requirement: Documented enumerations only, Scenario: Default signature type, Scenario: Invalid value never reaches iFirma, Scenario: Merchant choice

### Community 163 - "Requirement: Contractor attached by iFirma is learned"
Cohesion: 0.67
Nodes (3): Requirement: Contractor attached by iFirma is learned, Scenario: Contractor attached by iFirma is learned, Scenario: Long identifiers

### Community 164 - "Requirement: Contractor reference on documents"
Cohesion: 0.67
Nodes (3): Requirement: Contractor reference on documents, Scenario: Business buyer NIP, Scenario: Resolved contractor is referenced at the top level

### Community 165 - "Requirement: List calls always bounded"
Cohesion: 0.67
Nodes (3): Requirement: List calls always bounded, Scenario: Guard lookup, Scenario: Number lookup

### Community 167 - "Requirement: Non-PDF body on PDF endpoint"
Cohesion: 0.67
Nodes (3): Requirement: Non-PDF body on PDF endpoint, Scenario: JSON error body, Scenario: Unparseable body

### Community 168 - "Requirement: Contractor reference on documents"
Cohesion: 0.67
Nodes (3): Requirement: Contractor reference on documents, Scenario: Business buyer NIP, Scenario: Resolved contractor is referenced at the top level

### Community 169 - "Requirement: List calls always bounded"
Cohesion: 0.67
Nodes (3): Requirement: List calls always bounded, Scenario: Guard lookup, Scenario: Number lookup

### Community 171 - "store.py"
Cohesion: 0.10
Nodes (11): mock_reset(), contractor_create_mode(), contractor_search(), contractors_reset(), mark_deleted(), pdf_not_ready_consume(), _renamed_contractor_identifier(), reset() (+3 more)

### Community 172 - "Requirement: Position VAT rates"
Cohesion: 0.67
Nodes (3): Requirement: Position VAT rates, Scenario: Rounding noise, Scenario: Zero-rated line

### Community 173 - "Requirement: Request timeouts"
Cohesion: 0.67
Nodes (3): Requirement: Request timeouts, Scenario: Customer page sync, Scenario: Default call

### Community 175 - "IfirmaGuardResult"
Cohesion: 0.18
Nodes (6): IfirmaGuardResult, Context, Design, Goals / Non-Goals, Migration Plan, Risks / Trade-offs

### Community 176 - "Requirement: Created contractor read-back"
Cohesion: 0.10
Nodes (19): ADDED Requirements, Requirement: Contractor step notes, Requirement: Created contractor read-back, Scenario: Attached contractor still shown, Scenario: Business contractor stored under an identifier iFirma assigned, Scenario: Contractor visible after a short wait, Scenario: Create rejected, Scenario: Created under an assigned identifier (+11 more)

### Community 179 - "Tasks"
Cohesion: 0.33
Nodes (5): 4. Admin panel (D6), 5. Test stack mock and smoke checks (D7; separate repository), 6. Documentation, 7. Integration checks, Tasks

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

### Community 192 - ".getContext"
Cohesion: 0.12
Nodes (3): createOrder(), Currency, Smarty

### Community 193 - "Tasks"
Cohesion: 0.13
Nodes (14): 11. Customer visibility (spec: customer-receipt-visibility), 12. Integration verification and release readiness, 13. Payment method follows the order, 14. Contractor registration before issuance, 15. Existing contractor linking, 16. Contractor reference on documents, 17. Guard contractor filter, 18. Documented enumerations (+6 more)

### Community 194 - "Document"
Cohesion: 0.18
Nodes (7): _find_for_ksef(), _row(), add_payment(), compute_gross(), create(), Document, parse_date()

### Community 195 - "Proposal"
Cohesion: 0.25
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

### Community 197 - "Decisions"
Cohesion: 0.29
Nodes (7): D2. New `IfirmaCustomerContractorService`, D3. Registration check loaded by AJAX, D4. Local counts in SQL, D5. Remote count: yearly windows, union by `FakturaId`, budget of 20, D6. Search: phrases, merge, rank, D9. Version 1.2.0, Decisions

## Knowledge Gaps
- **18 isolated node(s):** `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background`, `PrestaShop Module Icon Usage`, `graphify knowledge graph workflow` (+13 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1023 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **71 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Stubs (tests/Stubs)` connect `Stubs (tests/Stubs)` to `.link`, `AdminIfirmaController`, `Configuration`, `Ifirma`, `Support (tests/Support)`, `Module`, `DataRequestServiceTest`, `Order`, `IfirmaDeliveryService`, `Db`, `1. Test harness (unit tests runnable without PrestaShop)`, `IfirmaDataRequestService`, `.updateValue`, `Tab`, `.getContext`, `.substr`, `.getInstance`, `.resetAll`, `Employee`, `.hookDisplayOrderDetail`, `IfirmaTestRegistryTrait`, `ContractorImportTest`?**
  _High betweenness centrality (0.073) - this node is a cross-community bridge._
- **Are the 95 inferred relationships involving `Stubs (tests/Stubs)` (e.g. with `.link()` and `.fields()`) actually correct?**
  _`Stubs (tests/Stubs)` has 95 INFERRED edges - model-reasoned connections that need verification._
- **What connects `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background` to the rest of the system?**
  _18 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `main.py` be split into smaller, more focused modules?**
  _Cohesion score 0.12648221343873517 - nodes in this community are weakly interconnected._
- **Why does `IfirmaApiClient` connect `IfirmaApiClient` to `ifirma-mock FastAPI service`, `AdminIfirmaController`, `Ifirma`, `IfirmaReceipt`, `Support (tests/Support)`, `run.php`, `ModuleHooksTest`, `IssuancePipelineTest`, `Module`, `.approve`, `IfirmaDeliveryService`, `IfirmaGuardResult`, `.updateValue`, `ApiClientErrorsTest`, `.getContext`, `.hookDisplayOrderDetail`, `Decisions`, `.resetAll`, `ContractorReferenceTest`, `ApiClientTest`, `IfirmaWiringFixture`, `IfirmaContractorService`, `Decisions`, `.get`, `Decisions`, `ScriptedTransport`, `.isIssued`, `IfirmaReceiptService`?**
  _High betweenness centrality (0.045) - this node is a cross-community bridge._
- **Are the 41 inferred relationships involving `IfirmaReceipt` (e.g. with `.ensureRow()` and `.hookDisplayOrderDetail()`) actually correct?**
  _`IfirmaReceipt` has 41 INFERRED edges - model-reasoned connections that need verification._
- **Should `test-stack/tools/check_license_headers.py` be split into smaller, more focused modules?**
  _Cohesion score 0.13636363636363635 - nodes in this community are weakly interconnected._