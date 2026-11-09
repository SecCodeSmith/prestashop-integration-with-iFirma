# Proposal

## Why

Releases publish only GitHub's auto-generated notes (a flat commit list), every release lands directly on the default branch, and CI has no vulnerability scanning beyond a manual red-team pass. The repository is source-available but has none of the community files (code of conduct, contributing, security policy) a public project is expected to carry. The module is also meant for the PrestaShop Addons marketplace, and nothing today checks it against the marketplace's technical validation rules. A first audit already found gaps (see design.md).

## What Changes

- Release workflow adds a short AI-written summary of the version, built from the commit subjects since the previous tag via GitHub Models (`GITHUB_TOKEN`, no extra secret). It is shown above the auto-generated notes and never blocks a release: on any failure the notes fall back to the plain commit list.
- A minor or major release no longer lands on `master`. The workflow cuts `release/<major>.<minor>`, puts the version bump commit and tag there, publishes from it and opens a pull request back to `master`. A patch run on a `release/*` branch also opens a PR to `master`. A patch run on `master` is unchanged.
- New security workflow, run on push, pull request and weekly: `composer audit`, `npm audit`, `pip-audit`, dependency review on PRs, a secret scan, Semgrep PHP/secret rules and a Trivy scan of the repo and the `test-stack` images. High/critical findings fail the run. Dependabot keeps the manifests and actions current. The release workflow runs the dependency audits before it bumps.
- Community files: `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md`, `SECURITY.md`, `SUPPORT.md`, `CHANGELOG.md`, issue and PR templates, `CODEOWNERS`, and a README "source-available" section. None of them ship in the module zip.
- Marketplace readiness: a checker script that applies the PrestaShop Addons technical-validation rules to the built zip, wired into CI and the release; `docs/marketplace-readiness.md` records the audit and the manual steps (official Validator run, English PDF guide, product sheet). The gaps the audit finds in the code are fixed.
- Licence audit of every library, tool and bundled asset, with a generated inventory and `docs/third-party-licenses.md`. CI and the release fail on a GNU GPL/AGPL/LGPL component in the shipped module and on any shipped licence outside a permissive allow-list. Current finding (read-only check of the lockfiles): no GPL-family licence in `composer.lock` (31 dev packages, MIT/BSD-3-Clause) or `package-lock.json` (MIT, ISC, Apache-2.0, BlueOak-1.0.0, BSD-3-Clause); none of these ship in the zip. The Python mock requirements and the container images (MariaDB/MySQL and Debian layers are GPL, but only run in the test stack and are never distributed with the module) still need the full check during apply.
- This feature's own PR: the apply phase ends by opening a PR of the current feature branch into `master` (the release workflow does not create it).

## Capabilities

### New Capabilities
- `release-notes-summary`: AI summary of a version in the release notes, with a safe fallback.
- `release-branching`: where minor, major and patch releases land, and the PR back to `master`.
- `ci-vulnerability-scanning`: dependency, secret, code and image vulnerability checks in CI, release gating and update automation.
- `community-health-files`: governance, contribution, security-reporting and support files for the source-available repository.
- `dependency-license-audit`: licence inventory, copyleft block and third-party notices.
- `marketplace-readiness`: automated and documented compliance with PrestaShop Addons technical validation.

### Modified Capabilities
<!-- None: module-packaging's existing requirements (atomic release, package layout) stay valid; release-branching extends them additively. -->

## Impact

- `.github/workflows/release.yml`, new `.github/workflows/security.yml`, `.github/dependabot.yml`, issue/PR templates, `CODEOWNERS`.
- New `scripts/release-summary.mjs` and `tools/check_marketplace_readiness.py` with tests; `scripts/verify-package.mjs` gains a check that community files are not shipped.
- Module code touched only where the audit finds a gap: missing `_PS_VERSION_` guard in `classes/IfirmaDownloadThrottle.php`, `@iconv` suppression in `classes/IfirmaContractorImport.php`, a documentation/support link in the configuration page, logo size.
- Docs: `docs/development.md` (release and security sections), new `docs/marketplace-readiness.md`, README.
- Repository settings the owner must enable: Actions may create PRs, GitHub Models access, private vulnerability reporting, branch protection on `master` and `release/*`.
- No runtime dependency is added to the shipped module.
