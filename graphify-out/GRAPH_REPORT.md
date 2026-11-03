# Graph Report - ifirma  (2026-11-02)

## Corpus Check
- 162 files · ~130,116 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 18 file(s) not represented in the graph (top: (none) 8, .tpl 5, .xml 1)

## Summary
- 2355 nodes · 5127 edges · 153 communities (93 shown, 60 thin omitted)
- Extraction: 87% EXTRACTED · 13% INFERRED · 0% AMBIGUOUS · INFERRED: 653 edges (avg confidence: 0.88)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `bc043201`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- main.py
- test_ifirma_mock.py
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
- ScriptedTransport
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
- Tasks
- Customer
- Module
- CreateReceiptTest
- Requirements
- Requirements
- IfirmaDataRequest
- Db
- D10. Verification strategy without a live iFirma account
- IfirmaDataRequestService
- composer.json
- .objects
- Requirements
- auth.py
- Requirements
- PHPUnit\Framework\TestCase
- PayloadBuilderTest
- ApiClientErrorsTest
- Requirements
- ObjectModel
- .approve
- Requirements
- IfirmaPaymentMethodResolver
- .getInstance
- Address
- ADDED Requirements
- Tab
- Requirement: Eligibility
- Requirement: Request content and validation
- .resetAll
- Requirement: Approved data overrides the document payload
- OrderHistory
- IfirmaReceiptService
- Requirement: Admin approves a request
- Requirement: CSRF protection
- Requirement: Customer cancels a request
- Requirement: Resolution resumes issuance
- Requirement: Submission creates a pending request
- Module smoke run
- Spec Delta
- Requirement: Pending request holds issuance
- Requirement: Requests after issuance
- Test fixtures
- IfirmaContractorService
- .check
- IfirmaIssuancePipeline
- Configuration
- IssuancePipelineTest.php
- app/__init__.py
- Order
- _clean_state
- Requirements
- Requirement: Eligibility
- Requirement: Request content and validation
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
- ifirma-mock FastAPI service
- Requirement: Contractor registration before issuance
- Requirement: Contractor registration before issuance
- Docker Compose test stack
- HarnessSmokeTest
- IfirmaReceiptService.php
- Requirement: Existing contractor matching
- Requirement: Existing contractor matching
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
- Requirement: Request timeouts
- Requirement: Non-PDF body on PDF endpoint
- Requirement: Request timeouts

## God Nodes (most connected - your core abstractions)
1. `Stubs (tests/Stubs)` - 96 edges
2. `IfirmaReceipt` - 66 edges
3. `IfirmaApiClient` - 64 edges
4. `ContractorServiceTest` - 61 edges
5. `ModuleHooksTest` - 58 edges
6. `Order` - 54 edges
7. `Ifirma` - 49 edges
8. `DataRequestServiceTest` - 49 edges
9. `IssuancePipelineTest` - 48 edges
10. `Module` - 42 edges

## Surprising Connections (you probably didn't know these)
- `10. Status sync (spec: receipt-status-sync)` --references--> `IfirmaStatusSyncService`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/tasks.md → classes/IfirmaStatusSyncService.php
- `PrestaShop stubs for unit tests` --references--> `IfirmaApiClient`  [INFERRED]
  tests/Stubs/README.md → classes/IfirmaApiClient.php
- `Transport contract` --references--> `IfirmaApiClient`  [INFERRED]
  tests/Stubs/README.md → classes/IfirmaApiClient.php
- `9. Customer data-change requests (spec: invoice-data-change-request)` --references--> `IfirmaDataRequestService`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/tasks.md → classes/IfirmaDataRequestService.php
- `8. Delivery (spec: receipt-delivery)` --references--> `IfirmaDeliveryService`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/tasks.md → classes/IfirmaDeliveryService.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **OpenSpec change lifecycle workflow** — _claude_skills_openspec_explore_skill, _claude_skills_openspec_propose_skill, _claude_skills_openspec_update_change_skill, _claude_skills_openspec_apply_change_skill, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill [EXTRACTED 0.95]
- **Local test stack services** — test_stack_docker_compose_db, test_stack_docker_compose_ifirma_mock, test_stack_docker_compose_prestashop [EXTRACTED 1.00]
- **Receipt creation and delivery flow** — readme_admin_ifirma_controller, classes_ifirmareceiptservice, classes_ifirmaapiclient, readme_receipt_front_controller [INFERRED 0.85]
- **Delta-to-main spec sync pipeline** — _claude_skills_openspec_sync_specs_skill_delta_specs, _claude_skills_openspec_sync_specs_skill_main_specs, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill_post_sync_verification, _claude_skills_openspec_archive_change_skill_archive_move [INFERRED 0.85]

## Communities (153 total, 60 thin omitted)

### Community 0 - "main.py"
Cohesion: 0.06
Nodes (49): abonent_limit(), _attach_contractor(), _authorized(), contractor_by_id(), contractor_create(), _contractor_errors(), contractor_replace(), contractor_search_route() (+41 more)

### Community 1 - "test_ifirma_mock.py"
Cohesion: 0.24
Nodes (9): create_receipt(), sign(), test_create_rejects_bad_hmac(), test_create_returns_string_identifier_without_number(), test_download_pdf_duplicate_variant(), test_download_pdf_returns_pdf_bytes(), test_list_exposes_number_under_wynik(), test_register_payment_updates_status() (+1 more)

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
Cohesion: 0.07
Nodes (7): AdminIfirmaController, IfirmaCronModuleFrontController, IfirmaDatarequestModuleFrontController, IfirmaReceiptModuleFrontController, AdminController, ModuleAdminController, ModuleFrontController

### Community 7 - "openspec-archive-change skill"
Cohesion: 0.18
Nodes (17): openspec-apply-change skill, openspec CLI, Store selection (--store flag), Task checkbox tracking (tasks.md), openspec-archive-change skill, Archive move to dated archive directory, openspec-explore skill, openspec-propose skill (+9 more)

### Community 8 - "test_mock_contractors.py"
Cohesion: 0.14
Nodes (34): by_id(), contractor(), create_contractor(), get_json(), listing(), receipt(), send_json(), sign() (+26 more)

### Community 9 - "IfirmaReceipt"
Cohesion: 0.06
Nodes (4): IfirmaReceipt, IfirmaStatusSyncService, AdminActionsTest, StatusSyncServiceTest

### Community 10 - "iFirma Module Logo"
Cohesion: 0.50
Nodes (4): Glowing Green Hammer Icon, iFirma Module Logo, Matrix-style Binary Code Rain Background, PrestaShop Module Icon Usage

### Community 27 - "IfirmaApiClient"
Cohesion: 0.05
Nodes (7): IfirmaApiClient, Context, D8. API client hardening: error mapping, request accounting, rate guard, 4. API client hardening (spec: ifirma-api-compliance), ApiClientRateGuardTest, ApiClientTest, DeliveryServiceTest

### Community 28 - "ScriptedTransport"
Cohesion: 0.06
Nodes (10): PrestaShop stubs for unit tests, Support (tests/Support), Transport contract, IfirmaTestController, IfirmaTestRedirect, IfirmaTestState, IfirmaWiringFixture, IfirmaTestModuleLoader (+2 more)

### Community 29 - "run.php"
Cohesion: 0.11
Nodes (28): IfirmaAdminActions, What it covers, assertSame(), assertTrue(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+20 more)

### Community 32 - "ADDED Requirements"
Cohesion: 0.05
Nodes (40): ADDED Requirements, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 33 - "ADDED Requirements"
Cohesion: 0.22
Nodes (8): ADDED Requirements, Purpose, Requirement: Country fields on the buyer, Requirement: List calls always bounded, Scenario: Guard lookup, Scenario: Number lookup, Scenario: Polish address, Spec Delta

### Community 34 - "ADDED Requirements"
Cohesion: 0.04
Nodes (47): ADDED Requirements, Purpose, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 35 - "ADDED Requirements"
Cohesion: 0.05
Nodes (38): ADDED Requirements, Purpose, Requirement: Admin resolution of suspected duplicates, Requirement: Candidate selection, Requirement: Confirmed match adoption, Requirement: Guard applies to all issuance paths, Requirement: Inconclusive guard, Requirement: Local record precedence (+30 more)

### Community 36 - "CustomerViewTest"
Cohesion: 0.07
Nodes (24): D11. Payment method follows the order, D12. Register the contractor before issuing (production finding, 2026-11-02), D13. Link customers to existing contractors (merchant finding, 2026-11-02), D1. Duplicate detection = list window + identity + confirmation by stamped reference, D2. Deterministic 15-char contractor identifier (see D12 for registration), D4. Delivery channel: shop mail with PDF attachment by default, iFirma `send` optional, D5. Monitoring = polling in three modes, one list call per batch, throttled, D6. Customer data-change request = separate table, payload override, hold on auto-issuance (+16 more)

### Community 37 - "Stubs (tests/Stubs)"
Cohesion: 0.09
Nodes (4): Hook, Stubs (tests/Stubs), Tools, Validate

### Community 39 - "test_mock_extensions.py"
Cohesion: 0.17
Nodes (29): get_json(), ids(), listing(), post_json(), seed(), sign(), test_delete_hides_and_unknown_delete_404(), test_detail_requires_auth_and_pdf_route_still_works() (+21 more)

### Community 40 - "ADDED Requirements"
Cohesion: 0.06
Nodes (32): ADDED Requirements, Purpose, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 41 - "DuplicateMatcherTest"
Cohesion: 0.15
Nodes (3): IfirmaDuplicateMatcher, 6. Duplicate guard (spec: receipt-duplicate-guard), DuplicateMatcherTest

### Community 42 - "ADDED Requirements"
Cohesion: 0.07
Nodes (29): ADDED Requirements, Purpose, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 45 - "Tasks"
Cohesion: 0.18
Nodes (10): 10. Status sync (spec: receipt-status-sync), 11. Customer visibility (spec: customer-receipt-visibility), 12. Integration verification and release readiness, 13. Payment method follows the order, 14. Contractor registration before issuance, 15. Existing contractor linking, 3. Mock extension (test-stack repository), 5. Payload compliance and order reference stamping (+2 more)

### Community 47 - "Module"
Cohesion: 0.06
Nodes (4): Currency, Employee, Module, Smarty

### Community 49 - "Requirements"
Cohesion: 0.04
Nodes (47): Purpose, receipt-auto-issuance Specification, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 50 - "Requirements"
Cohesion: 0.05
Nodes (40): customer-receipt-visibility Specification, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 51 - "IfirmaDataRequest"
Cohesion: 0.15
Nodes (5): IfirmaDataRequest, 2. Data model, configuration and upgrade, bqSQL(), pSQL(), IfirmaDataRequestTest

### Community 54 - "D10. Verification strategy without a live iFirma account"
Cohesion: 0.11
Nodes (8): D10. Verification strategy without a live iFirma account, D3. Issue-and-send pipeline is one orchestrator with per-step state on the receipt row, 8. Delivery (spec: receipt-delivery), Notes from the first real run (PrestaShop 8.1.7, 2026-11-01), Context, Link, Mail, PrestaShopLogger

### Community 56 - "composer.json"
Cohesion: 0.12
Nodes (15): config, platform, sort-packages, vendor-dir, description, license, name, php (+7 more)

### Community 57 - ".objects"
Cohesion: 0.24
Nodes (9): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+1 more)

### Community 58 - "Requirements"
Cohesion: 0.22
Nodes (8): ifirma-api-compliance Specification, Purpose, Requirement: Country fields on the buyer, Requirement: List calls always bounded, Requirements, Scenario: Guard lookup, Scenario: Number lookup, Scenario: Polish address

### Community 59 - "auth.py"
Cohesion: 0.16
Nodes (5): AuthResult, _signed_url(), verify(), _env_bool(), Settings

### Community 60 - "Requirements"
Cohesion: 0.05
Nodes (38): Purpose, receipt-duplicate-guard Specification, Requirement: Admin resolution of suspected duplicates, Requirement: Candidate selection, Requirement: Confirmed match adoption, Requirement: Guard applies to all issuance paths, Requirement: Inconclusive guard, Requirement: Local record precedence (+30 more)

### Community 64 - "Requirements"
Cohesion: 0.06
Nodes (32): Purpose, receipt-status-sync Specification, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 66 - ".approve"
Cohesion: 0.12
Nodes (10): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#6}() (+2 more)

### Community 67 - "Requirements"
Cohesion: 0.07
Nodes (29): Purpose, receipt-delivery Specification, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 68 - "IfirmaPaymentMethodResolver"
Cohesion: 0.11
Nodes (3): IfirmaHttpTransport, IfirmaPaymentMethodResolver, PaymentMethodResolverTest

### Community 69 - ".getInstance"
Cohesion: 0.06
Nodes (9): IfirmaContractorMap, IfirmaSchema, discoverFixtures(), IfirmaContractorMapDb, ContractorImportTest, ContractorMapTest, SchemaTest, UpgradeTest (+1 more)

### Community 70 - "Address"
Cohesion: 0.11
Nodes (4): 9. Customer data-change requests (spec: invoice-data-change-request), Address, Country, IfirmaTestRegistryTrait

### Community 71 - "ADDED Requirements"
Cohesion: 0.29
Nodes (7): ADDED Requirements, Requirement: Admin rejects a request, Requirement: Audit trail, Scenario: Cancelled request, Scenario: Reject with reason, Scenario: Reject without reason, Scenario: Resolved request with edits

### Community 73 - "Requirement: Eligibility"
Cohesion: 0.33
Nodes (6): Requirement: Eligibility, Scenario: Document already exists, Scenario: Eligible customer, Scenario: Feature disabled, Scenario: Order of another customer, Scenario: Pending request already exists

### Community 74 - "Requirement: Request content and validation"
Cohesion: 0.33
Nodes (6): Requirement: Request content and validation, Scenario: Company without NIP, Scenario: Malformed NIP, Scenario: Missing postcode or city, Scenario: Private person, Scenario: Valid company request

### Community 76 - ".resetAll"
Cohesion: 0.08
Nodes (3): HelperForm, OrderState, Shop

### Community 77 - "Requirement: Approved data overrides the document payload"
Cohesion: 0.40
Nodes (5): Requirement: Approved data overrides the document payload, Scenario: Audit and address untouched, Scenario: Company override, Scenario: No approved request, Scenario: Private person override

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

### Community 90 - "Requirement: Pending request holds issuance"
Cohesion: 0.67
Nodes (3): Requirement: Pending request holds issuance, Scenario: Manual create during hold, Scenario: Trigger status reached during hold

### Community 91 - "Requirement: Requests after issuance"
Cohesion: 0.67
Nodes (3): Requirement: Requests after issuance, Scenario: Adopted document, Scenario: Issued document

### Community 101 - "IfirmaContractorService"
Cohesion: 0.05
Nodes (6): IfirmaContractorImport, IfirmaContractorService, IfirmaCustomerView, TranslationCoverageTest, ifirma_list_files(), ifirma_scan()

### Community 104 - "IfirmaIssuancePipeline"
Cohesion: 0.20
Nodes (3): IfirmaIssuancePipeline, IfirmaPipelineResult, 7. Issuance pipeline (spec: receipt-auto-issuance)

### Community 114 - "Requirements"
Cohesion: 0.29
Nodes (6): invoice-data-change-request Specification, Purpose, Requirement: Requests after issuance, Requirements, Scenario: Adopted document, Scenario: Issued document

### Community 115 - "Requirement: Eligibility"
Cohesion: 0.33
Nodes (6): Requirement: Eligibility, Scenario: Document already exists, Scenario: Eligible customer, Scenario: Feature disabled, Scenario: Order of another customer, Scenario: Pending request already exists

### Community 116 - "Requirement: Request content and validation"
Cohesion: 0.33
Nodes (6): Requirement: Request content and validation, Scenario: Company without NIP, Scenario: Malformed NIP, Scenario: Missing postcode or city, Scenario: Private person, Scenario: Valid company request

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

### Community 154 - "Requirement: Request timeouts"
Cohesion: 0.67
Nodes (3): Requirement: Request timeouts, Scenario: Customer page sync, Scenario: Default call

### Community 155 - "Requirement: Non-PDF body on PDF endpoint"
Cohesion: 0.67
Nodes (3): Requirement: Non-PDF body on PDF endpoint, Scenario: JSON error body, Scenario: Unparseable body

### Community 156 - "Requirement: Request timeouts"
Cohesion: 0.67
Nodes (3): Requirement: Request timeouts, Scenario: Customer page sync, Scenario: Default call

## Knowledge Gaps
- **18 isolated node(s):** `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background`, `PrestaShop Module Icon Usage`, `graphify knowledge graph workflow` (+13 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 773 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **60 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Stubs (tests/Stubs)` connect `Stubs (tests/Stubs)` to `AdminIfirmaController`, `Ifirma`, `IfirmaApiClient`, `ScriptedTransport`, `DataRequestServiceTest`, `Customer`, `Module`, `IfirmaDataRequest`, `Db`, `D10. Verification strategy without a live iFirma account`, `IfirmaDataRequestService`, `ObjectModel`, `.getInstance`, `Address`, `Tab`, `.resetAll`, `OrderHistory`, `IfirmaContractorService`, `Configuration`, `Order`?**
  _High betweenness centrality (0.058) - this node is a cross-community bridge._
- **Are the 95 inferred relationships involving `Stubs (tests/Stubs)` (e.g. with `.link()` and `.fields()`) actually correct?**
  _`Stubs (tests/Stubs)` has 95 INFERRED edges - model-reasoned connections that need verification._
- **What connects `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background` to the rest of the system?**
  _18 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `main.py` be split into smaller, more focused modules?**
  _Cohesion score 0.0640218878248974 - nodes in this community are weakly interconnected._
- **Why does `IfirmaApiClient` connect `IfirmaApiClient` to `ifirma-mock FastAPI service`, `CustomerViewTest`, `IfirmaContractorService`, `AdminIfirmaController`, `.check`, `Ifirma`, `IfirmaReceipt`, `Address`, `DataRequestServiceTest`, `.resetAll`, `IssuancePipelineTest`, `Module`, `IfirmaReceiptService`, `ScriptedTransport`, `run.php`, `ApiClientErrorsTest`?**
  _High betweenness centrality (0.039) - this node is a cross-community bridge._
- **Are the 36 inferred relationships involving `IfirmaReceipt` (e.g. with `.ensureRow()` and `.hookDisplayOrderDetail()`) actually correct?**
  _`IfirmaReceipt` has 36 INFERRED edges - model-reasoned connections that need verification._
- **Should `test-stack/tools/check_license_headers.py` be split into smaller, more focused modules?**
  _Cohesion score 0.13636363636363635 - nodes in this community are weakly interconnected._