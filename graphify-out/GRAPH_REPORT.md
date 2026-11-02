# Graph Report - ifirma  (2026-11-02)

## Corpus Check
- 155 files · ~117,746 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 16 file(s) not represented in the graph (top: (none) 8, .tpl 4, .xml 1)

## Summary
- 2156 nodes · 4524 edges · 125 communities (66 shown, 59 thin omitted)
- Extraction: 86% EXTRACTED · 14% INFERRED · 0% AMBIGUOUS · INFERRED: 627 edges (avg confidence: 0.88)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `5e48bdc2`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- main.py
- test_ifirma_mock.py
- test-stack/tools/check_license_headers.py
- package.json
- PrestaShop iFirma integration module
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
- .updateValue
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
- Tasks
- ADDED Requirements
- ContractorServiceTest
- DuplicateGuardTest
- Currency
- AdminActionsTest
- Module
- CreateReceiptTest
- Requirements
- Requirements
- IfirmaDataRequest
- Db
- ScriptedTransport
- 1. Test harness (unit tests runnable without PrestaShop)
- IfirmaDataRequestService
- composer.json
- Requirements
- auth.py
- Requirements
- PHPUnit\Framework\TestCase
- PayloadBuilderTest
- ApiClientErrorsTest
- Requirements
- ObjectModel
- .submitPending
- Requirements
- IfirmaPaymentMethodResolver
- .parseMap
- .resetAll
- ADDED Requirements
- Tab
- Requirement: Eligibility
- Requirement: Request content and validation
- .renderConfigForm
- Shop
- Requirement: Approved data overrides the document payload
- OrderHistory
- Order
- Requirement: Admin approves a request
- Requirement: CSRF protection
- Requirement: Customer cancels a request
- Requirement: Resolution resumes issuance
- Requirement: Submission creates a pending request
- Module smoke run
- .loadModule
- Spec Delta
- Requirement: Pending request holds issuance
- Requirement: Requests after issuance
- Test fixtures
- config.py
- IssuancePipelineTest.php
- _clean_state
- _clean_state
- .objects
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

## God Nodes (most connected - your core abstractions)
1. `Stubs (tests/Stubs)` - 93 edges
2. `IfirmaReceipt` - 66 edges
3. `IfirmaApiClient` - 64 edges
4. `Order` - 53 edges
5. `DataRequestServiceTest` - 49 edges
6. `IssuancePipelineTest` - 48 edges
7. `ModuleHooksTest` - 47 edges
8. `Ifirma` - 44 edges
9. `Module` - 42 edges
10. `IfirmaReceiptService` - 37 edges

## Surprising Connections (you probably didn't know these)
- `10. Status sync (spec: receipt-status-sync)` --references--> `IfirmaStatusSyncService`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/tasks.md → classes/IfirmaStatusSyncService.php
- `Context` --references--> `IfirmaApiClient`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/design.md → classes/IfirmaApiClient.php
- `PrestaShop stubs for unit tests` --references--> `IfirmaApiClient`  [INFERRED]
  tests/Stubs/README.md → classes/IfirmaApiClient.php
- `Transport contract` --references--> `IfirmaApiClient`  [INFERRED]
  tests/Stubs/README.md → classes/IfirmaApiClient.php
- `9. Customer data-change requests (spec: invoice-data-change-request)` --references--> `IfirmaDataRequestService`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/tasks.md → classes/IfirmaDataRequestService.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **OpenSpec change lifecycle workflow** — _claude_skills_openspec_explore_skill, _claude_skills_openspec_propose_skill, _claude_skills_openspec_update_change_skill, _claude_skills_openspec_apply_change_skill, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill [EXTRACTED 0.95]
- **Local test stack services** — test_stack_docker_compose_db, test_stack_docker_compose_ifirma_mock, test_stack_docker_compose_prestashop [EXTRACTED 1.00]
- **Receipt creation and delivery flow** — readme_admin_ifirma_controller, classes_ifirmareceiptservice, classes_ifirmaapiclient, readme_receipt_front_controller [INFERRED 0.85]
- **Delta-to-main spec sync pipeline** — _claude_skills_openspec_sync_specs_skill_delta_specs, _claude_skills_openspec_sync_specs_skill_main_specs, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill_post_sync_verification, _claude_skills_openspec_archive_change_skill_archive_move [INFERRED 0.85]

## Communities (125 total, 59 thin omitted)

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

### Community 4 - "PrestaShop iFirma integration module"
Cohesion: 0.06
Nodes (33): Module CI workflow, Build package (zip + tgz) job, tools/check_license_headers.py, Mock tests (test-stack) job, PHP lint & license headers job, Release workflow, Publish GitHub Release with zip and tgz, Version bump synced across package.json, ifirma.php, config.xml (+25 more)

### Community 5 - "AdminIfirmaController"
Cohesion: 0.10
Nodes (5): AdminIfirmaController, IfirmaCronModuleFrontController, IfirmaDatarequestModuleFrontController, IfirmaReceiptModuleFrontController, ModuleFrontController

### Community 7 - "openspec-archive-change skill"
Cohesion: 0.18
Nodes (17): openspec-apply-change skill, openspec CLI, Store selection (--store flag), Task checkbox tracking (tasks.md), openspec-archive-change skill, Archive move to dated archive directory, openspec-explore skill, openspec-propose skill (+9 more)

### Community 8 - "test_mock_contractors.py"
Cohesion: 0.16
Nodes (33): by_id(), contractor(), create_contractor(), get_json(), listing(), receipt(), send_json(), sign() (+25 more)

### Community 9 - "IfirmaReceipt"
Cohesion: 0.06
Nodes (9): IfirmaReceipt, IfirmaSchema, IfirmaStatusSyncService, {closure#1}(), HarnessSmokeTest, SchemaTest, StatusSyncServiceTest, UpgradeTest (+1 more)

### Community 10 - "iFirma Module Logo"
Cohesion: 0.50
Nodes (4): Glowing Green Hammer Icon, iFirma Module Logo, Matrix-style Binary Code Rain Background, PrestaShop Module Icon Usage

### Community 27 - "IfirmaApiClient"
Cohesion: 0.05
Nodes (13): IfirmaApiClient, IfirmaContractorService, D8. API client hardening: error mapping, request accounting, rate guard, Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal (+5 more)

### Community 28 - "Support (tests/Support)"
Cohesion: 0.16
Nodes (5): PrestaShop stubs for unit tests, Support (tests/Support), IfirmaTestController, IfirmaTestRedirect, IfirmaTestState

### Community 29 - "run.php"
Cohesion: 0.11
Nodes (29): IfirmaAdminActions, What it covers, assertSame(), assertTrue(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+21 more)

### Community 32 - "ADDED Requirements"
Cohesion: 0.05
Nodes (40): ADDED Requirements, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 33 - "ADDED Requirements"
Cohesion: 0.04
Nodes (48): ADDED Requirements, Purpose, Requirement: Contractor registration before issuance, Requirement: Country fields on the buyer, Requirement: Deterministic contractor identifier, Requirement: Error-code mapping, Requirement: List calls always bounded, Requirement: Local rate guard (+40 more)

### Community 34 - "ADDED Requirements"
Cohesion: 0.04
Nodes (47): ADDED Requirements, Purpose, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 35 - "ADDED Requirements"
Cohesion: 0.05
Nodes (38): ADDED Requirements, Purpose, Requirement: Admin resolution of suspected duplicates, Requirement: Candidate selection, Requirement: Confirmed match adoption, Requirement: Guard applies to all issuance paths, Requirement: Inconclusive guard, Requirement: Local record precedence (+30 more)

### Community 36 - "CustomerViewTest"
Cohesion: 0.09
Nodes (16): D11. Payment method follows the order, D12. Register the contractor before issuing (production finding, 2026-11-02), D1. Duplicate detection = list window + identity + confirmation by stamped reference, D2. Deterministic 15-char contractor identifier (see D12 for registration), D4. Delivery channel: shop mail with PDF attachment by default, iFirma `send` optional, D5. Monitoring = polling in three modes, one list call per batch, throttled, D6. Customer data-change request = separate table, payload override, hold on auto-issuance, D7. Customer visibility is a state machine rendered from receipt + request rows (+8 more)

### Community 37 - "Stubs (tests/Stubs)"
Cohesion: 0.09
Nodes (4): Hook, Stubs (tests/Stubs), Tools, Validate

### Community 39 - "test_mock_extensions.py"
Cohesion: 0.16
Nodes (29): get_json(), ids(), listing(), post_json(), seed(), sign(), test_delete_hides_and_unknown_delete_404(), test_detail_requires_auth_and_pdf_route_still_works() (+21 more)

### Community 40 - "ADDED Requirements"
Cohesion: 0.06
Nodes (32): ADDED Requirements, Purpose, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 41 - "Tasks"
Cohesion: 0.09
Nodes (13): IfirmaDuplicateMatcher, 10. Status sync (spec: receipt-status-sync), 11. Customer visibility (spec: customer-receipt-visibility), 12. Integration verification and release readiness, 13. Payment method follows the order, 14. Contractor registration before issuance, 3. Mock extension (test-stack repository), 5. Payload compliance and order reference stamping (+5 more)

### Community 42 - "ADDED Requirements"
Cohesion: 0.07
Nodes (29): ADDED Requirements, Purpose, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 45 - "Currency"
Cohesion: 0.12
Nodes (3): Currency, OrderState, IfirmaTestRegistryTrait

### Community 47 - "Module"
Cohesion: 0.07
Nodes (5): AdminController, Employee, Module, ModuleAdminController, Smarty

### Community 49 - "Requirements"
Cohesion: 0.04
Nodes (47): Purpose, receipt-auto-issuance Specification, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment method follows the order (+39 more)

### Community 50 - "Requirements"
Cohesion: 0.05
Nodes (40): customer-receipt-visibility Specification, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 51 - "IfirmaDataRequest"
Cohesion: 0.17
Nodes (4): IfirmaDataRequest, 2. Data model, configuration and upgrade, bqSQL(), pSQL()

### Community 53 - "ScriptedTransport"
Cohesion: 0.36
Nodes (3): Transport contract, ScriptedTransport, ScriptedTransportTest

### Community 54 - "1. Test harness (unit tests runnable without PrestaShop)"
Cohesion: 0.10
Nodes (8): D10. Verification strategy without a live iFirma account, D3. Issue-and-send pipeline is one orchestrator with per-step state on the receipt row, 1. Test harness (unit tests runnable without PrestaShop), Configuration, Context, Language, Link, PrestaShopLogger

### Community 56 - "composer.json"
Cohesion: 0.12
Nodes (15): config, platform, sort-packages, vendor-dir, description, license, name, php (+7 more)

### Community 58 - "Requirements"
Cohesion: 0.04
Nodes (48): ifirma-api-compliance Specification, Purpose, Requirement: Contractor registration before issuance, Requirement: Country fields on the buyer, Requirement: Deterministic contractor identifier, Requirement: Error-code mapping, Requirement: List calls always bounded, Requirement: Local rate guard (+40 more)

### Community 59 - "auth.py"
Cohesion: 0.27
Nodes (3): AuthResult, _signed_url(), verify()

### Community 60 - "Requirements"
Cohesion: 0.05
Nodes (38): Purpose, receipt-duplicate-guard Specification, Requirement: Admin resolution of suspected duplicates, Requirement: Candidate selection, Requirement: Confirmed match adoption, Requirement: Guard applies to all issuance paths, Requirement: Inconclusive guard, Requirement: Local record precedence (+30 more)

### Community 61 - "PHPUnit\Framework\TestCase"
Cohesion: 0.09
Nodes (3): ConfigurationKeysTest, {closure#1}(), IfirmaDataRequestTest

### Community 62 - "PayloadBuilderTest"
Cohesion: 0.08
Nodes (5): IfirmaCustomerView, PayloadBuilderTest, TranslationCoverageTest, ifirma_list_files(), ifirma_scan()

### Community 64 - "Requirements"
Cohesion: 0.06
Nodes (32): Purpose, receipt-status-sync Specification, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 66 - ".submitPending"
Cohesion: 0.12
Nodes (10): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+2 more)

### Community 67 - "Requirements"
Cohesion: 0.07
Nodes (29): Purpose, receipt-delivery Specification, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 70 - ".resetAll"
Cohesion: 0.10
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

### Community 79 - "Order"
Cohesion: 0.05
Nodes (9): IfirmaDeliveryService, IfirmaDuplicateGuard, IfirmaGuardResult, IfirmaIssuancePipeline, IfirmaPipelineResult, IfirmaReceiptService, Context, 7. Issuance pipeline (spec: receipt-auto-issuance) (+1 more)

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
Cohesion: 0.15
Nodes (11): 8. Delivery (spec: receipt-delivery), Environment overrides, Known limitations, Module smoke run, Notes from the first real run (PrestaShop 8.1.7, 2026-11-01), Prerequisites, Reading the output, Run (+3 more)

### Community 90 - "Requirement: Pending request holds issuance"
Cohesion: 0.67
Nodes (3): Requirement: Pending request holds issuance, Scenario: Manual create during hold, Scenario: Trigger status reached during hold

### Community 91 - "Requirement: Requests after issuance"
Cohesion: 0.67
Nodes (3): Requirement: Requests after issuance, Scenario: Adopted document, Scenario: Issued document

### Community 113 - ".objects"
Cohesion: 0.33
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

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

## Knowledge Gaps
- **18 isolated node(s):** `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background`, `PrestaShop Module Icon Usage`, `Point module at mock (base URL, login, key)` (+13 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 729 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **59 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Stubs (tests/Stubs)` connect `Stubs (tests/Stubs)` to `AdminIfirmaController`, `IfirmaReceipt`, `Support (tests/Support)`, `.updateValue`, `DataRequestServiceTest`, `Currency`, `Module`, `IfirmaDataRequest`, `Db`, `1. Test harness (unit tests runnable without PrestaShop)`, `IfirmaDataRequestService`, `PayloadBuilderTest`, `ObjectModel`, `.resetAll`, `Tab`, `.renderConfigForm`, `Shop`, `OrderHistory`, `Order`, `Module smoke run`, `.uninstall`, `.postProcessConfiguration`?**
  _High betweenness centrality (0.052) - this node is a cross-community bridge._
- **Are the 92 inferred relationships involving `Stubs (tests/Stubs)` (e.g. with `.fields()` and `Address`) actually correct?**
  _`Stubs (tests/Stubs)` has 92 INFERRED edges - model-reasoned connections that need verification._
- **What connects `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background` to the rest of the system?**
  _18 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `main.py` be split into smaller, more focused modules?**
  _Cohesion score 0.0640218878248974 - nodes in this community are weakly interconnected._
- **Why does `Order` connect `Order` to `AdminIfirmaController`, `Ifirma`, `run.php`, `.updateValue`, `IssuancePipelineTest`, `CustomerViewTest`, `Stubs (tests/Stubs)`, `DataRequestServiceTest`, `DuplicateGuardTest`, `Currency`, `AdminActionsTest`, `CreateReceiptTest`, `1. Test harness (unit tests runnable without PrestaShop)`, `IfirmaDataRequestService`, `.canRequest`, `PayloadBuilderTest`, `IfirmaPaymentMethodResolver`, `.resetAll`, `OrderHistory`, `Module smoke run`?**
  _High betweenness centrality (0.032) - this node is a cross-community bridge._
- **Are the 36 inferred relationships involving `IfirmaReceipt` (e.g. with `.ensureRow()` and `.hookDisplayOrderDetail()`) actually correct?**
  _`IfirmaReceipt` has 36 INFERRED edges - model-reasoned connections that need verification._
- **Should `test-stack/tools/check_license_headers.py` be split into smaller, more focused modules?**
  _Cohesion score 0.13636363636363635 - nodes in this community are weakly interconnected._