# Design

## Context

See proposal.md for motivation. Current state, observed:

- `release.yml` is a manual dispatch: verify → `npm version` (local commit + tag) → build → `git push --atomic --follow-tags` → `gh release create --generate-notes` → dispatch CI. It runs on whatever ref is dispatched, normally `master`. There are no `release/*` branches; tags `v1.3.2 … v1.7.0` exist.
- `ci.yml` has lint, PHP 7.2 lint, PHPUnit, diff-cover, mock tests and a test build. No vulnerability scanning exists; red-team audits are manual (CLAUDE.md).
- Shipped files come from the `files` allow-list in `package.json`, so root-level community files are already excluded; `verify-package.mjs` checks the zip layout.
- Module declares `ps_versions_compliancy` 8.0.0–9.99.99, has `index.php` in every shipped folder, and a 256×256 `logo.png`.
- PrestaShop Addons technical validation (devdocs techvalidation checklist, partner help center): online Validator first, then a manual install/review. Requirements used here: self-contained package, no post-install downloads, no core table changes, debug-mode clean, compliancy declared, documentation and support links in the ZIP and the module UI, valid zip with one module folder, English PDF documentation and product sheet for marketing validation, compatibility with the latest PrestaShop version.

Audit of the shipped code against those rules found:

| Finding | Where | State |
|---|---|---|
| No `_PS_VERSION_` guard | `classes/IfirmaDownloadThrottle.php` | fix |
| `@iconv` error suppression (also breaks CLAUDE.md) | `classes/IfirmaContractorImport.php:115` | fix |
| No documentation or support link on the configuration page; no PDF guide | module UI, package | fix link, owner writes PDF |
| `logo.png` is 256×256 (PrestaShop shows module logos at small size) | `logo.png` | verify against Validator, resize if flagged |
| Direct `curl_*` for the iFirma API | `classes/IfirmaHttpTransport.php:50` | accepted, documented (HTTPS only, certificate verification on) |
| Custom licence with a free SMB tier may conflict with how Addons sells and licenses modules | `LICENCE` | owner decision, cannot be settled in code |
| Marketing validation items (product sheet, screenshots, English PDF) | outside repo | owner action |

## Goals / Non-Goals

**Goals**
- Each new CI/release step is small, separately testable and fails safe; the release stays atomic.
- Checks live in scripts that run locally and in CI, with unit tests; workflows only call them.

**Non-Goals**
- Uploading the module to the official Validator or Addons, or choosing the marketplace licence: both are outward-facing owner actions.
- Rewriting the existing build or `module-packaging` behaviour.
- Auto-merging the release PR.

## Decisions

1. **AI summary through GitHub Models with `GITHUB_TOKEN`** (chosen by the owner) over a vendor API key: no new secret to store or leak; needs `permissions: models: read`. The step runs `scripts/release-summary.mjs`, which takes the tag range, sends only commit subjects (capped, newest first), and writes `summary.md`. The HTTP client is an injected `fetch`, so a `node --test` file exercises success, error, timeout, empty range and injection text without network. The model id is a repository variable `RELEASE_SUMMARY_MODEL` with a default. The release body is `summary + "\n\n" + generated notes`, passed with `gh release create --notes-file` and `--generate-notes` combined (GitHub appends generated notes after the file text). The step is `continue-on-error`-equivalent: the script always exits 0 and writes the fallback commit list itself. Alternative: a hosted Claude API key; rejected for now because of the extra secret, easy to add later behind the same script interface.
2. **Untrusted commit text.** Subjects are stripped of control characters, length-limited and wrapped in a delimited block with a system instruction that the block is data. The answer is HTML-escaped, truncated to 120 words and written to a file, never echoed through `::` workflow commands or interpolated into `run:` shell.
3. **Branch strategy: bump on a release branch, PR back.** For minor/major the workflow computes the new version, creates `release/<X.Y>` from the dispatched ref, then runs the existing `npm version` there. `git push --atomic origin release/X.Y vX.Y.Z` keeps branch, commit and tag together. After publishing, `gh pr create --base master --head release/X.Y` carries the bump to `master`. Pre-check before any bump: remote branch or tag exists → fail. This also makes a collision visible when an earlier release PR is still unmerged (`master` still carries the old version). Alternative: keep pushing to `master` and only create the branch afterwards; rejected because the user asked for a separate branch and review of the bump. Patch on `master` stays as today to keep hot-fix speed.
4. **PR creation after publish, non-fatal.** The tag and release already exist at that point, so a refused PR (setting "allow Actions to create PRs" off) logs the exact `gh pr create` command and exits 0.
5. **One `security.yml`, scripts-free where a maintained tool exists.** Jobs: `composer-audit`, `npm-audit` (`--audit-level=moderate`), `pip-audit` on `test-stack/ifirma-mock/requirements*.txt`, `dependency-review` (PR only), `secrets` (gitleaks CLI binary, full history), `semgrep` (OSS CLI, `p/php`, `p/secrets`, `--severity ERROR`), `trivy` (fs scan with vuln, secret and config scanners, HIGH/CRITICAL, plus a check that compose files pin tags). Triggers: pull_request, push to default branch, weekly cron, dispatch. Actions referenced by version tag (owner's rule: tags, never commit hashes); Dependabot (composer, npm, pip, github-actions, docker) keeps them current. Alternatives: CodeQL (no PHP support); Psalm taint analysis (heavier setup, can follow later); commercial gitleaks-action (licence needed for orgs).
6. **No ignore lists without an expiry note.** Consistent with CLAUDE.md: an accepted advisory is recorded in `docs/marketplace-readiness.md`/the red-team report with reason and expiry date; the workflows carry no silent ignore files.
7. **Release gating by reuse, not duplication.** The release `verify` step calls the same `composer audit` and `npm audit` commands and `tools/check_marketplace_readiness.py` on the built zip before the push step. CI also runs the readiness checker after `verify:package`.
8. **Marketplace checker as a Python tool** (`tools/check_marketplace_readiness.py`), beside `check_license_headers.py`, so it is unit-testable with pytest-style fixtures of tiny fake zips. Rules map to the spec: structure, guards, forbidden constructs, outbound call inventory, docs/support link present. It reads the zip, not the tree, so it checks what ships. Translation files are exempt from the guard rule, matching PrestaShop core practice.
9. **Community files are plain repo files**, Contributor Covenant 2.1 (CC BY 4.0, attributed), SECURITY.md pointing to GitHub private vulnerability reporting plus the existing contact address from `LICENCE`. `CONTRIBUTING.md` states contributions come under the repository licence (inbound = outbound) and uses a DCO sign-off; the owner can swap in a CLA later. `verify-package.mjs` gets a denylist entry set so these never ship even if the allow-list changes.
10. **Docs-link in module UI** goes through `IfirmaSettings`/the existing configuration presenter as a read-only constant URL, not a new `Configuration` key.

11. **Licence audit as `tools/check_dependency_licenses.py`.** It reads `composer.lock` and `package-lock.json` (licence fields), the Python requirements (installed metadata via `importlib.metadata` in a clean venv, transitive included) and a hand-kept `docs/third-party-licenses.md` table for container images and bundled assets. Each entry is tagged `ships` / `dev` / `test-stack`. Rules: GPL/AGPL/LGPL on `ships` fails; on `dev` or `test-stack` warns; shipped licences must be on a permissive allow-list (MIT, BSD-2/3, Apache-2.0, ISC, BlueOak-1.0.0, 0BSD); unknown fails. It writes the inventory table, and CI fails when the committed doc differs from the regenerated one. Alternative: `pip-licenses`/`license-checker` per ecosystem; kept as inputs only, since one policy in one place is simpler to test. Current repo state: nothing ships from any manifest (the module has no runtime dependencies), so the shipped set is the module's own files and assets (CSS, JS, mail templates, `logo.png`, screenshots in `docs/img` are not shipped).
12. **GPL in container images is aggregation, not linkage.** MariaDB/MySQL, Debian and similar GPL layers run as separate services in `test-stack` and are not distributed with the module; the doc says so and records the image tag and licence. PrestaShop core is OSL 3.0/AFL 3.0, which is not GPL.

## Risks / Trade-offs

- [GitHub Models unavailable or rate-limited] → fallback commit list, summary is optional by spec.
- [Prompt injection via commit subject] → data-only prompt, escaped plain-text output, no command channel.
- [Release PR left unmerged, next release collides] → pre-check fails fast with a clear message.
- [Scanner noise blocks PRs] → severity thresholds, weekly run separate from required PR checks at first; promote to required once clean.
- [Semgrep registry rules need network and may change] → pin the CLI version; rules by registry name documented.
- [Custom licence may not be accepted by Addons] → recorded as owner decision; no code can resolve it.
- [Logo resize changes branding] → only if the Validator flags it.

## Migration Plan

1. Land scripts and tests, then workflows on this feature branch; run the new security workflow and CI on the PR.
2. Owner enables the repository settings listed in proposal.md before the first release that uses them.
3. First minor release after merge exercises branching; do a dry run with a patch on a throwaway `release/*` branch first.
4. Rollback: revert the workflow commits; scripts are inert without the workflows; module code fixes are small and independent.

## Open Questions

- Which default model id to use for the summary (decided at apply time from what GitHub Models lists).
- Whether to make the security job a required status check immediately or after one clean week.
