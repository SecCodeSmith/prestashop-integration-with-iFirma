# Graph Report - ifirma  (2026-11-01)

## Corpus Check
- 143 files · ~99,985 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 16 file(s) not represented in the graph (top: (none) 8, .tpl 4, .xml 1)

## Summary
- 1680 nodes · 3748 edges · 109 communities (50 shown, 59 thin omitted)
- Extraction: 84% EXTRACTED · 16% INFERRED · 0% AMBIGUOUS · INFERRED: 598 edges (avg confidence: 0.88)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `6565eab6`
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
- IfirmaReceiptService
- IfirmaReceipt
- iFirma Module Logo
- Project CLAUDE.md (graphify rules)
- ifirma-mock
- IfirmaApiClient
- Support (tests/Support)
- run.php
- .get
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
- Order
- ADDED Requirements
- IfirmaCustomerView
- DuplicateGuardTest
- Address
- AdminActionsTest
- Module
- store.py
- ApiClientTest
- .updateValue
- IfirmaDataRequest
- Db
- ScriptedTransport
- 1. Test harness (unit tests runnable without PrestaShop)
- IfirmaDataRequestService
- composer.json
- .objects
- Decisions
- auth.py
- .get
- PHPUnit\Framework\TestCase
- PayloadBuilderTest
- ApiClientErrorsTest
- .l
- ObjectModel
- .submitPending
- Proposal
- OrderHistory
- PrestaShop iFirma integration module
- CreateReceiptTest
- ADDED Requirements
- .hookDisplayOrderDetail
- Requirement: Eligibility
- Requirement: Request content and validation
- Tab
- .resetAll
- Requirement: Approved data overrides the document payload
- build_pdf
- .renderConfigForm
- Requirement: Admin approves a request
- Requirement: CSRF protection
- Requirement: Customer cancels a request
- Requirement: Resolution resumes issuance
- Requirement: Submission creates a pending request
- Module smoke run
- ifirma-mock FastAPI service
- Spec Delta
- Requirement: Pending request holds issuance
- Requirement: Requests after issuance
- Test fixtures
- Configuration
- .testTimeouts
- Docker Compose test stack
- .getContext
- IfirmaReceiptService.php

## God Nodes (most connected - your core abstractions)
1. `Stubs (tests/Stubs)` - 94 edges
2. `IfirmaReceipt` - 66 edges
3. `IfirmaApiClient` - 60 edges
4. `Order` - 51 edges
5. `DataRequestServiceTest` - 49 edges
6. `IssuancePipelineTest` - 48 edges
7. `ModuleHooksTest` - 44 edges
8. `Ifirma` - 41 edges
9. `Module` - 41 edges
10. `IfirmaDataRequest` - 36 edges

## Surprising Connections (you probably didn't know these)
- `10. Status sync (spec: receipt-status-sync)` --references--> `IfirmaStatusSyncService`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/tasks.md → classes/IfirmaStatusSyncService.php
- `Context` --references--> `IfirmaApiClient`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/design.md → classes/IfirmaApiClient.php
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

## Communities (109 total, 59 thin omitted)

### Community 0 - "main.py"
Cohesion: 0.15
Nodes (20): abonent_limit(), _authorized(), create_document(), document_detail(), download_pdf(), _error(), health(), _int_param() (+12 more)

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
Nodes (15): AdminIfirmaController, IfirmaDatarequestModuleFrontController, IfirmaReceiptModuleFrontController, 10. Status sync (spec: receipt-status-sync), 11. Customer visibility (spec: customer-receipt-visibility), 12. Integration verification and release readiness, 3. Mock extension (test-stack repository), 5. Payload compliance and order reference stamping (+7 more)

### Community 7 - "openspec-archive-change skill"
Cohesion: 0.18
Nodes (17): openspec-apply-change skill, openspec CLI, Store selection (--store flag), Task checkbox tracking (tasks.md), openspec-archive-change skill, Archive move to dated archive directory, openspec-explore skill, openspec-propose skill (+9 more)

### Community 9 - "IfirmaReceipt"
Cohesion: 0.05
Nodes (12): IfirmaReceipt, IfirmaSchema, IfirmaStatusSyncService, discoverFixtures(), bqSQL(), pSQL(), {closure#1}(), HarnessSmokeTest (+4 more)

### Community 10 - "iFirma Module Logo"
Cohesion: 0.50
Nodes (4): Glowing Green Hammer Icon, iFirma Module Logo, Matrix-style Binary Code Rain Background, PrestaShop Module Icon Usage

### Community 27 - "IfirmaApiClient"
Cohesion: 0.13
Nodes (4): IfirmaApiClient, D8. API client hardening: error mapping, request accounting, rate guard, 4. API client hardening (spec: ifirma-api-compliance), PrestaShop stubs for unit tests

### Community 28 - "Support (tests/Support)"
Cohesion: 0.13
Nodes (5): Support (tests/Support), IfirmaTestController, IfirmaTestRedirect, IfirmaTestState, IfirmaTestModuleLoader

### Community 29 - "run.php"
Cohesion: 0.21
Nodes (26): assertSame(), assertTrue(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), createOrder() (+18 more)

### Community 32 - "ADDED Requirements"
Cohesion: 0.05
Nodes (40): ADDED Requirements, Purpose, Requirement: Account block, Requirement: Admin-only data stays admin-only, Requirement: Customer-language rendering, Requirement: Download access control, Requirement: Held state, Requirement: Issued state (+32 more)

### Community 33 - "ADDED Requirements"
Cohesion: 0.05
Nodes (39): ADDED Requirements, Purpose, Requirement: Deterministic contractor identifier, Requirement: Error-code mapping, Requirement: List calls always bounded, Requirement: Local rate guard, Requirement: Non-PDF body on PDF endpoint, Requirement: Optional limit visibility (+31 more)

### Community 34 - "ADDED Requirements"
Cohesion: 0.05
Nodes (38): ADDED Requirements, Purpose, Requirement: Document type selection, Requirement: Failures never break the order status change, Requirement: Hold on pending data-change request, Requirement: Manual issuance uses the same pipeline, Requirement: No blind retry after unknown outcome, Requirement: Payment registration unchanged (+30 more)

### Community 35 - "ADDED Requirements"
Cohesion: 0.05
Nodes (38): ADDED Requirements, Purpose, Requirement: Admin resolution of suspected duplicates, Requirement: Candidate selection, Requirement: Confirmed match adoption, Requirement: Guard applies to all issuance paths, Requirement: Inconclusive guard, Requirement: Local record precedence (+30 more)

### Community 37 - "Stubs (tests/Stubs)"
Cohesion: 0.09
Nodes (4): Hook, Stubs (tests/Stubs), Tools, Validate

### Community 39 - "test_mock_extensions.py"
Cohesion: 0.15
Nodes (30): _clean_state(), get_json(), ids(), listing(), post_json(), seed(), sign(), test_delete_hides_and_unknown_delete_404() (+22 more)

### Community 40 - "ADDED Requirements"
Cohesion: 0.06
Nodes (32): ADDED Requirements, Purpose, Requirement: Cron endpoint authentication, Requirement: Cron selection and budget, Requirement: Missing-document detection, Requirement: On-demand admin refresh, Requirement: Opportunistic customer sync, Requirement: Paid transition (+24 more)

### Community 41 - "Order"
Cohesion: 0.05
Nodes (9): IfirmaDeliveryService, IfirmaDuplicateGuard, IfirmaDuplicateMatcher, IfirmaGuardResult, IfirmaIssuancePipeline, IfirmaPipelineResult, 6. Duplicate guard (spec: receipt-duplicate-guard), Order (+1 more)

### Community 42 - "ADDED Requirements"
Cohesion: 0.07
Nodes (29): ADDED Requirements, Purpose, Requirement: Adopted external documents are not auto-delivered, Requirement: Configuration warning about customer e-mails, Requirement: Delivery disabled, Requirement: Delivery failure does not undo issuance, Requirement: Delivery follows issuance, Requirement: Fallback for unsupported types (+21 more)

### Community 45 - "Address"
Cohesion: 0.09
Nodes (5): Address, Country, Currency, Customer, IfirmaTestRegistryTrait

### Community 48 - "store.py"
Cohesion: 0.11
Nodes (11): root(), _row(), add_payment(), all_documents(), compute_gross(), create(), Document, get_active() (+3 more)

### Community 51 - "IfirmaDataRequest"
Cohesion: 0.12
Nodes (3): IfirmaDataRequest, 2. Data model, configuration and upgrade, IfirmaDataRequestTest

### Community 53 - "ScriptedTransport"
Cohesion: 0.27
Nodes (3): Transport contract, ScriptedTransport, ScriptedTransportTest

### Community 54 - "1. Test harness (unit tests runnable without PrestaShop)"
Cohesion: 0.10
Nodes (9): D10. Verification strategy without a live iFirma account, D3. Issue-and-send pipeline is one orchestrator with per-step state on the receipt row, 1. Test harness (unit tests runnable without PrestaShop), 8. Delivery (spec: receipt-delivery), Context, Language, Link, Mail (+1 more)

### Community 55 - "IfirmaDataRequestService"
Cohesion: 0.14
Nodes (4): IfirmaDataRequestException, IfirmaDataRequestService, {closure#10}(), {closure#9}()

### Community 56 - "composer.json"
Cohesion: 0.12
Nodes (15): config, platform, sort-packages, vendor-dir, description, license, name, php (+7 more)

### Community 57 - ".objects"
Cohesion: 0.14
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 58 - "Decisions"
Cohesion: 0.12
Nodes (14): Context, D1. Duplicate detection = list window + identity + confirmation by stamped reference, D2. Deterministic 15-char contractor identifier, D4. Delivery channel: shop mail with PDF attachment by default, iFirma `send` optional, D5. Monitoring = polling in three modes, one list call per batch, throttled, D6. Customer data-change request = separate table, payload override, hold on auto-issuance, D7. Customer visibility is a state machine rendered from receipt + request rows, D9. Data model and upgrade (+6 more)

### Community 59 - "auth.py"
Cohesion: 0.17
Nodes (5): AuthResult, _signed_url(), verify(), _env_bool(), Settings

### Community 62 - "PayloadBuilderTest"
Cohesion: 0.11
Nodes (4): PayloadBuilderTest, TranslationCoverageTest, ifirma_list_files(), ifirma_scan()

### Community 66 - ".submitPending"
Cohesion: 0.16
Nodes (8): {closure#1}(), {closure#11}(), {closure#12}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}()

### Community 67 - "Proposal"
Cohesion: 0.25
Nodes (7): Capabilities, Impact, Modified Capabilities, New Capabilities, Proposal, What Changes, Why

### Community 69 - "PrestaShop iFirma integration module"
Cohesion: 0.25
Nodes (8): OpenSpec change: receipt-lifecycle-automation, OpenSpec config (spec-driven schema), iFirma PrestaShop Module README, AdminIfirmaController, Feature: Create Receipt from order, PrestaShop iFirma integration module, Legal disclaimer & trademarks (IFIRMA SA), Feature: Update status (register payment)

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
Cohesion: 0.25
Nodes (8): Environment overrides, Known limitations, Module smoke run, Notes from the first real run (PrestaShop 8.1.7, 2026-11-01), Prerequisites, Reading the output, Run, Upgrade test 1.0.x -> 1.1.0

### Community 87 - "ifirma-mock FastAPI service"
Cohesion: 0.29
Nodes (4): iFirma API endpoints (fakturaparagon, fakturakraj, wplaty), Endpoint parity with official iFirma API, ifirma-mock FastAPI service, pytest suite / sign() helper as auth spec

### Community 90 - "Requirement: Pending request holds issuance"
Cohesion: 0.67
Nodes (3): Requirement: Pending request holds issuance, Scenario: Manual create during hold, Scenario: Trigger status reached during hold

### Community 91 - "Requirement: Requests after issuance"
Cohesion: 0.67
Nodes (3): Requirement: Requests after issuance, Scenario: Adopted document, Scenario: Issued document

### Community 103 - "Docker Compose test stack"
Cohesion: 0.38
Nodes (6): Docker Compose test stack, db service (MariaDB 11.4), ifirma-mock service, prestashop service (latest), iFirma MockAPI test-stack README, Point module at mock (base URL, login, key)

### Community 106 - "IfirmaReceiptService.php"
Cohesion: 0.33
Nodes (3): ps_ifirma_receipt table, controllers/front/receipt.php secured PDF controller, Feature: Return connection (customer PDF download)

## Knowledge Gaps
- **18 isolated node(s):** `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background`, `PrestaShop Module Icon Usage`, `graphify knowledge graph workflow` (+13 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 484 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **59 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Stubs (tests/Stubs)` connect `Stubs (tests/Stubs)` to `AdminIfirmaController`, `Ifirma`, `IfirmaReceipt`, `IfirmaApiClient`, `Support (tests/Support)`, `DataRequestServiceTest`, `Order`, `Address`, `Module`, `.updateValue`, `Db`, `1. Test harness (unit tests runnable without PrestaShop)`, `IfirmaDataRequestService`, `PayloadBuilderTest`, `ObjectModel`, `OrderHistory`, `.hookDisplayOrderDetail`, `Tab`, `.resetAll`, `.renderConfigForm`, `Configuration`, `.getContext`?**
  _High betweenness centrality (0.066) - this node is a cross-community bridge._
- **Are the 93 inferred relationships involving `Stubs (tests/Stubs)` (e.g. with `.fields()` and `Address`) actually correct?**
  _`Stubs (tests/Stubs)` has 93 INFERRED edges - model-reasoned connections that need verification._
- **What connects `ifirma-mock`, `Glowing Green Hammer Icon`, `Matrix-style Binary Code Rain Background` to the rest of the system?**
  _18 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `test-stack/tools/check_license_headers.py` be split into smaller, more focused modules?**
  _Cohesion score 0.13636363636363635 - nodes in this community are weakly interconnected._
- **Why does `IfirmaReceipt` connect `IfirmaReceipt` to `.l`, `ObjectModel`, `CustomerViewTest`, `AdminIfirmaController`, `CreateReceiptTest`, `IfirmaReceiptService`, `Order`, `.hookDisplayOrderDetail`, `DuplicateGuardTest`, `AdminActionsTest`, `.updateValue`, `IfirmaDataRequest`, `1. Test harness (unit tests runnable without PrestaShop)`, `.objects`, `IfirmaApiClient`, `.get`?**
  _High betweenness centrality (0.038) - this node is a cross-community bridge._
- **Are the 36 inferred relationships involving `IfirmaReceipt` (e.g. with `.ensureRow()` and `.hookDisplayOrderDetail()`) actually correct?**
  _`IfirmaReceipt` has 36 INFERRED edges - model-reasoned connections that need verification._
- **Should `package.json` be split into smaller, more focused modules?**
  _Cohesion score 0.06666666666666667 - nodes in this community are weakly interconnected._