# Tasks

Test-first throughout (CLAUDE.md): each code task starts with the failing test. Haiku-sized, one file or concern each. Release and security fixes land as separate commits; any real vulnerability found goes in its own `fix-vuln:` commit.

## 1. Module fixes found by the audit

- [x] 1.1 Test: shipped PHP has no `@` suppression and every non-index, non-translation class has the `_PS_VERSION_` guard (unit test over `git ls-files`/package list); see it fail
- [x] 1.2 Add the `_PS_VERSION_` guard to `classes/IfirmaDownloadThrottle.php`
- [x] 1.3 Replace `@iconv` in `classes/IfirmaContractorImport.php:115` with an explicit check and typed failure; add a unit test for invalid input
- [x] 1.4 Test then implement: configuration page shows documentation and support links (constants, no new Configuration key); update Polish translations and the golden fixture only for this deliberate change
- [x] 1.5 Run the full suite and the diff-cover gate

## 2. Marketplace readiness checker

- [x] 2.1 Write fixtures (tiny good and bad fake zips) and tests for each rule: folder without `index.php`, missing guard, forbidden constructs, bad top-level folder, missing `config.xml` fields, missing logo, outbound URL not on the iFirma host
- [x] 2.2 Implement `tools/check_marketplace_readiness.py` (reads the zip, exit 1 with a list of findings)
- [x] 2.3 Check `logo.png` against the Validator guidance; resize only if flagged
- [x] 2.4 Wire it into `ci.yml` after `verify:package` and into `release.yml` before the push step
- [x] 2.5 Add a community-file denylist to `scripts/verify-package.mjs` with a test that a zip containing `CODE_OF_CONDUCT.md` fails
- [x] 2.6 Write `docs/marketplace-readiness.md` (every requirement, state, evidence, owner actions: official Validator run, English PDF guide, product sheet, licence decision, curl justification)

## 3. Release summary

- [x] 3.1 Write `node --test` cases for `scripts/release-summary.mjs` with an injected fetch: success, 4xx/5xx, timeout, empty range, first release without tag, truncation, hostile subject; see them fail
- [x] 3.2 Implement the script (tag range, subject sanitising, delimited prompt, escaped 120-word output, always exit 0 with fallback file)
- [x] 3.3 Add `permissions: models: read` and the summary step to `release.yml`; publish with `--notes-file` plus `--generate-notes`
- [x] 3.4 Add `node --test` to CI

## 4. Release branching and PR

- [x] 4.1 Extract the version-bump and branch-name calculation into a small script with tests (patch/minor/major, branch name, existing branch or tag detection)
- [x] 4.2 Update `release.yml`: pre-check remote branch/tag, cut `release/X.Y` for minor/major, atomic push of branch and tag, open the PR into `master` after publishing (non-fatal with printed fallback command), PR also for patch on `release/*`
- [x] 4.3 Dry-run on a throwaway branch and a scratch fork or with `act`-style local run of the script paths; record the evidence
- [x] 4.4 Update `module-packaging` docs in `docs/development.md` (release section, branch model, settings the owner must enable)

## 5. Vulnerability scanning in CI

- [x] 5.1 Create `.github/workflows/security.yml` with composer, npm, pip, dependency-review, gitleaks, Semgrep and Trivy jobs, SHA-pinned actions, weekly cron
- [x] 5.2 Add a check that compose files under `test-stack/` use pinned tags (test, then script)
- [x] 5.3 Add `.github/dependabot.yml` for composer, npm, pip, github-actions and docker
- [x] 5.4 Add the dependency audits to the `release.yml` verify step
- [x] 5.5 Run every scanner locally or in a draft run; triage findings, fix highs/criticals each in its own `fix-vuln:` commit with a regression test
- [x] 5.6 Document the security workflow and the accepted-risk rule in `docs/development.md`

## 5b. Dependency licence audit

- [x] 5b.1 Tests with fixture lockfiles: GPL shipped (fails), GPL dev-only (warns), unknown licence (fails), unlisted licence (fails), all permissive (passes), stale doc (fails)
- [x] 5b.2 Implement `tools/check_dependency_licenses.py` (composer.lock, package-lock.json, Python metadata from a clean venv incl. transitive deps, role tags)
- [x] 5b.3 Run it for real: resolve the mock's Python dependencies and record licences; list container images with tag and licence; list bundled assets (CSS, JS, mail templates, logo) and their origin
- [x] 5b.4 Generate `docs/third-party-licenses.md` (inventory, roles, aggregation note for GPL images, required notices) and link it from README and `docs/development.md`
- [x] 5b.5 Add the check to `ci.yml`/`security.yml` (PR + weekly) and to the `release.yml` verify step
- [x] 5b.6 Report any GPL-family hit to the owner with its role before deciding; a shipped GPL component is a stop-and-ask, not an auto-fix

## 6. Community health files

- [x] 6.1 `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1, attribution, contact)
- [x] 6.2 `CONTRIBUTING.md` (TDD loop, OpenSpec flow, commit style, DCO sign-off, inbound = outbound licence)
- [x] 6.3 `SECURITY.md` and `SUPPORT.md`
- [x] 6.4 `CHANGELOG.md` seeded from tags `v1.3.2` to the current version, Keep a Changelog form
- [x] 6.5 `.github/ISSUE_TEMPLATE/` (bug form, feature form, `config.yml` pointing security reports to the policy), `PULL_REQUEST_TEMPLATE.md`, `CODEOWNERS`
- [x] 6.6 README: source-available licence section (free tier, Enterprise, not OSI), link to the new files
- [x] 6.7 Grep the diff for secrets and real e-mail addresses other than the already-public contact

## 7. Finish

- [x] 7.1 Full suite, diff-cover gate, `npm run build` + `verify:package` + readiness check on the built zip
- [x] 7.2 Red-team pass on the workflow changes (token permissions, injection through commit text, PR creation path) per CLAUDE.md; security-sensitive, so one read-only reviewer
- [x] 7.3 `openspec validate harden-release-pipeline-and-open-source-files`, `graphify update .`
- [ ] 7.4 Open the PR of `feat/rate-limit-queue-ksef-delivery` into `master` with the summary of both changes (after asking, since it is outward-facing)

## Notes from the apply

- 2.5: the community-file denylist is in `tools/check_marketplace_readiness.py` (reads the zip, tested), not in `verify-package.mjs`.
- 5.2: `test-stack/docker-compose.yml` now uses `PS_IMAGE_TAG` (default `9.1.4-apache`); the change is in the submodule working tree and must be committed in the test-stack repository.
- 5.5: `archiver` removed (unfixable minimatch advisory) and replaced by `scripts/zip-writer.mjs`; Trivy's root-user finding on the mock image is report-only.
- 7.2: report in `build/red-team/2026-11-09-1.8.0-release-pipeline.md` (local); no high or critical finding; fixes in `fix-vuln:` commits.
- 7.4 stays open: opening the pull request is outward-facing and waits for the owner.
- Added on the owner's request: `smoke.yml` matrix over every supported PrestaShop version; actions by version tag, latest majors.
