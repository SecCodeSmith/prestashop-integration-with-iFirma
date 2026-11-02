# Graph Report - ifirma  (2026-11-01)

## Corpus Check
- Corpus is ~30,239 words - fits in a single context window. You may not need a graph.

## Summary
- 294 nodes · 490 edges · 27 communities (9 shown, 18 thin omitted)
- Extraction: 94% EXTRACTED · 6% INFERRED · 0% AMBIGUOUS · INFERRED: 27 edges (avg confidence: 0.82)
- Token cost: 215,660 input · 0 output

## Community Hubs (Navigation)
- iFirma Mock FastAPI App
- Mock HMAC Auth & Config
- License Header Tooling
- NPM Build & Packaging Scripts
- Module Docs & Specs
- Receipt Model & Controllers
- Module Hooks & Installation
- OpenSpec Skill Workflow
- iFirma API Client & Receipt Service
- CI/CD Workflows
- Module Logo
- Graphify Project Rules
- Mock pyproject Metadata

## God Nodes (most connected - your core abstractions)
1. `Ifirma` - 24 edges
2. `IfirmaReceiptService` - 14 edges
3. `IfirmaApiClient` - 11 edges
4. `IfirmaReceipt` - 11 edges
5. `openspec-archive-change skill` - 11 edges
6. `AdminIfirmaController` - 10 edges
7. `_authorized()` - 10 edges
8. `sign()` - 9 edges
9. `PrestaShop iFirma integration module` - 9 edges
10. `openspec-apply-change skill` - 9 edges

## Surprising Connections (you probably didn't know these)
- `Endpoint parity with official iFirma API` --semantically_similar_to--> `iFirma API endpoints (fakturaparagon, fakturakraj, wplaty)`  [INFERRED] [semantically similar]
  test-stack/README.md → README.md
- `Mock tests (test-stack) job` --semantically_similar_to--> `pytest & license headers job`  [INFERRED] [semantically similar]
  .github/workflows/ci.yml → test-stack/.github/workflows/ci.yml
- `pytest & license headers job` --semantically_similar_to--> `tools/check_license_headers.py`  [INFERRED] [semantically similar]
  test-stack/.github/workflows/ci.yml → .github/workflows/ci.yml
- `rel()` --indirect_call--> `root()`  [INFERRED]
  tools/check_license_headers.py → test-stack/ifirma-mock/app/main.py
- `OpenSpec change: receipt-lifecycle-automation` --conceptually_related_to--> `PrestaShop iFirma integration module`  [INFERRED]
  openspec/changes/receipt-lifecycle-automation/.openspec.yaml → README.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Local test stack services** — test_stack_docker_compose_db, test_stack_docker_compose_ifirma_mock, test_stack_docker_compose_prestashop [EXTRACTED 1.00]
- **Receipt creation and delivery flow** — readme_admin_ifirma_controller, classes_ifirmareceiptservice, classes_ifirmaapiclient, readme_receipt_front_controller [INFERRED 0.85]
- **OpenSpec change lifecycle workflow** — _claude_skills_openspec_explore_skill, _claude_skills_openspec_propose_skill, _claude_skills_openspec_update_change_skill, _claude_skills_openspec_apply_change_skill, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill [EXTRACTED 0.95]
- **Delta-to-main spec sync pipeline** — _claude_skills_openspec_sync_specs_skill_delta_specs, _claude_skills_openspec_sync_specs_skill_main_specs, _claude_skills_openspec_sync_specs_skill, _claude_skills_openspec_archive_change_skill_post_sync_verification, _claude_skills_openspec_archive_change_skill_archive_move [INFERRED 0.85]

## Communities (27 total, 18 thin omitted)

### Community 0 - "iFirma Mock FastAPI App"
Cohesion: 0.10
Nodes (18): _authorized(), create_document(), download_pdf(), _error(), health(), list_documents(), _parse_body(), register_payment() (+10 more)

### Community 1 - "Mock HMAC Auth & Config"
Cohesion: 0.10
Nodes (14): AuthResult, _signed_url(), verify(), _env_bool(), Settings, create_receipt(), sign(), test_create_rejects_bad_hmac() (+6 more)

### Community 2 - "License Header Tooling"
Cohesion: 0.15
Nodes (18): add_header(), has_marker(), insert_index(), main(), read_file(), render_header(), repo_root(), tracked_files() (+10 more)

### Community 3 - "NPM Build & Packaging Scripts"
Cohesion: 0.07
Nodes (25): author, description, devDependencies, archiver, files, homepage, license, name (+17 more)

### Community 4 - "Module Docs & Specs"
Cohesion: 0.09
Nodes (21): OpenSpec change: receipt-lifecycle-automation, OpenSpec config (spec-driven schema), iFirma PrestaShop Module README, AdminIfirmaController, Feature: Create Receipt from order, iFirma API endpoints (fakturaparagon, fakturakraj, wplaty), PrestaShop iFirma integration module, Legal disclaimer & trademarks (IFIRMA SA) (+13 more)

### Community 5 - "Receipt Model & Controllers"
Cohesion: 0.14
Nodes (3): IfirmaReceipt, AdminIfirmaController, IfirmaReceiptModuleFrontController

### Community 7 - "OpenSpec Skill Workflow"
Cohesion: 0.18
Nodes (17): openspec-apply-change skill, openspec CLI, Store selection (--store flag), Task checkbox tracking (tasks.md), openspec-archive-change skill, Archive move to dated archive directory, openspec-explore skill, openspec-propose skill (+9 more)

### Community 9 - "CI/CD Workflows"
Cohesion: 0.21
Nodes (12): Module CI workflow, Build package (zip + tgz) job, tools/check_license_headers.py, Mock tests (test-stack) job, PHP lint & license headers job, Release workflow, Publish GitHub Release with zip and tgz, Version bump synced across package.json, ifirma.php, config.xml (+4 more)

### Community 10 - "Module Logo"
Cohesion: 0.50
Nodes (4): Glowing Green Hammer Icon, iFirma Module Logo, Matrix-style Binary Code Rain Background, PrestaShop Module Icon Usage

## Knowledge Gaps
- **18 isolated node(s):** `ifirma-mock`, `iFirma PrestaShop Module README`, `Legal disclaimer & trademarks (IFIRMA SA)`, `Project CLAUDE.md (graphify rules)`, `graphify knowledge graph workflow` (+13 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 102 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **18 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `IfirmaApiClient` connect `iFirma API Client & Receipt Service` to `Module Docs & Specs`, `Receipt Model & Controllers`?**
  _High betweenness centrality (0.054) - this node is a cross-community bridge._
- **What connects `ifirma-mock`, `iFirma PrestaShop Module README`, `Legal disclaimer & trademarks (IFIRMA SA)` to the rest of the system?**
  _18 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `iFirma Mock FastAPI App` be split into smaller, more focused modules?**
  _Cohesion score 0.09615384615384616 - nodes in this community are weakly interconnected._
- **Why does `ifirma-mock FastAPI service` connect `Module Docs & Specs` to `CI/CD Workflows`?**
  _High betweenness centrality (0.048) - this node is a cross-community bridge._
- **Should `Mock HMAC Auth & Config` be split into smaller, more focused modules?**
  _Cohesion score 0.10483870967741936 - nodes in this community are weakly interconnected._
- **Why does `Ifirma` connect `Module Hooks & Installation` to `Receipt Model & Controllers`?**
  _High betweenness centrality (0.045) - this node is a cross-community bridge._
- **Should `NPM Build & Packaging Scripts` be split into smaller, more focused modules?**
  _Cohesion score 0.06666666666666667 - nodes in this community are weakly interconnected._