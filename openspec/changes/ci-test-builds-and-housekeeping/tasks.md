# Tasks

## 1. Housekeeping

- [x] 1.1 Replace "Berechowskir" with "Berechowski" in the 10 affected files (ifirma.php, sql/install.php, sql/uninstall.php, controllers/admin/AdminIfirmaController.php, controllers/front/receipt.php, classes/IfirmaApiClient.php, IfirmaContractorService.php, IfirmaHttpTransport.php, IfirmaReceipt.php, IfirmaReceiptService.php). Verify that `git grep -n Berechowskir` returns nothing.
- [x] 1.2 Add a `FORBIDDEN` strings rule to `tools/check_license_headers.py`, and document it in the docstring. Verify that the check passes on the tree, and fails with exit 1 on a scratch copy of a file that has the typo reintroduced.
- [x] 1.3 Record a graphify baseline before touching anything, saved under the scratchpad (not the repo):
  - `graph.json` node and edge counts.
  - `sha256` of `graph.json`, `manifest.json` and `cost.json`.
  - The list and count of files under `cache/semantic/`.
  - The output of `graphify query "version string release build pipeline"` and of `graphify check-update .`.
  Verify that the baseline file exists and records the on-disk counts. Recorded: 3322 nodes, 7640 links, 4 hyperedges, 18 semantic files, `check-update` clean.
- [x] 1.4 Untrack only the cheap-to-rebuild graphify cache (design D7). Add `graphify-out/cache/ast/`, `graphify-out/cache/stat-index.json`, `graphify-out/cache/last_query_stamp`, `graphify-out/cache/hook_sessions/` and `graphify-out/cache/.gfy-replace-bak-*.tmp` to `.gitignore`, then `git rm -r --cached` them. Keep the local files on disk. Verify that `git ls-files graphify-out` still lists `graph.json`, `GRAPH_REPORT.md`, `manifest.json`, the label files, `2026-11-01/` and every file under `cache/semantic/`.
- [x] 1.5 Graphify integrity gate. This must pass before 1.4 is committed; if it fails, revert 1.4 for the path responsible and record that path in design D7. Build two scratchpad copies of the files in the git index. Both carry the same working-tree source:
  - **control:** keeps the full cache.
  - **test:** has 1.4 applied, so it has no AST cache, stat index or session files.

Run `graphify update .` in both. A rebuild re-reads the current source, so the test copy is compared with the control copy, not with the committed counts. Verify all of the following:
  - Both updates complete with no LLM or API call, and the test copy's `cost.json` is unchanged from the 1.3 baseline.
  - `graphify check-update .` in the test copy reports no pending semantic re-extraction.
  - The test copy's node, link and hyperedge counts are equal to the control copy's.
  - The test copy's `cache/semantic/` file set and hashes are identical to the baseline.
  - `graphify query` returns the same start nodes in both copies.
  - The original working copy's `graph.json` hash is unchanged from the baseline.
- [x] 1.6 Delete the local `scm-ifirma-1.1.0.tgz` and `dist/` output (`npm run clean`). Verify that `git status` shows no change, since both are ignored.

## 2. test-stack submodule

- [x] 2.1 Create the GitHub repository for the mock stack with the user (name and visibility confirmed by the user), add it as the `origin` of `test-stack/`, and push its history. Verify that the remote shows commit `c153d06` on its default branch. The user did this: the repository is public `SecCodeSmith/test-stack`, and `master` is at `162353b`, which contains `c153d06`, confirmed with `git ls-remote`.
- [x] 2.2 Replace the gitlink with a registered submodule (`git rm --cached test-stack`, then `git submodule add <url> test-stack`), pinned at the pushed head `162353b`. Verify that `.gitmodules` exists and that a fresh `git clone --recurse-submodules` into the scratchpad populates `test-stack/ifirma-mock/`. Verified on a scratch commit of the staged state: both `--recurse-submodules` and `submodule update --init --depth 1` check out `162353b` from GitHub.
- [ ] 2.3 Add `submodules: true` to the checkout of the CI `mock` job (the only job that reads `test-stack`; the repository is public, so no token is needed), and turn the mock job's skip branch into a failure. Verify with actionlint, and with a local pytest run at the pinned commit, then on a CI run that the mock job runs pytest and fails if the folder is missing. Local evidence so far: actionlint exit 0; pytest at `162353b` 130 passed; the mock licence check exits 0; a scratch plain clone without submodule init leaves `test-stack/` empty, which is what the guard rejects. Remaining: one CI run on a pushed branch.
- [x] 2.4 Update the clone/setup notes in `README.md` and `test-stack/README.md` (`--recurse-submodules`, `git submodule update --init`, and how to bump the pinned mock). Verify that the documented commands work from a fresh clone. Verified on scratch clones of the staged state: `--recurse-submodules`, `git submodule update --init`, and `git -C test-stack pull origin master` on the detached submodule HEAD. The `test-stack/README.md` link into the parent repo (dead on the public repo) is now plain text. Cloning from the GitHub URL works once this branch is pushed.

## 3. Version consistency test

- [x] 3.1 Rewrite `ConfigurationKeysTest::testVersionFilesAreBumped` and the version assertion in `testInstallSetsEveryKeyAndUninstallRemovesThem` to compare all four sources against `package.json`, with no literal version. Verify that PHPUnit passes, and that it fails with a message naming `config.xml` when that file's version is edited in a scratch copy.
- [x] 3.2 Verify that a dry-run `npm version patch --no-git-tag-version` on a scratch clone keeps PHPUnit green, then discard the scratch clone.

## 4. Test build packaging

- [x] 4.1 Extend `scripts/build-zip.mjs` to read `IFIRMA_BUILD_CHANNEL`, `IFIRMA_BUILD_NUMBER` and `IFIRMA_BUILD_COMMIT`, append an in-memory `ifirma/BUILD` JSON manifest, and name the zip per channel (design D1). Verify locally that `npm run build:zip` produces `dist/ifirma-1.2.0.zip` with `BUILD.channel == "local"`, and that the same command with test env vars produces `dist/ifirma-1.2.0+build.7.zip` with matching fields. Check by unzipping into the scratchpad.
- [x] 4.2 Add a package-layout check script (`scripts/verify-package.mjs`) that fails if any zip entry falls outside `ifirma/` or if a development path from the spec is present, and that validates `BUILD`. Wire it as `npm run verify:package`. Verify that it passes on the built zip and fails on a scratch zip containing `ifirma/tests/x.php`.
- [ ] 4.3 Update the `ci.yml` build job: add `needs: [lint, phpunit, mock]`, set the test-channel env vars from `github.run_number` and the PR-head-or-sha commit, run `verify:package`, and use artifact name `ifirma-<version>+build.<n>` with `retention-days: 90`. Verify on a pushed branch that the artifact appears with the expected name and contents, and that a deliberately failing unit test on a scratch branch produces no artifact.
- [x] 4.4 Add `scripts/build-zip.mjs` env vars and the `BUILD` manifest to the README build section. Verify that the documented local command produces the documented file name.

## 5. Atomic release workflow

- [x] 5.1 Reorder `release.yml` per design D4: checkout into `ifirma/` (no submodules needed), composer install plus PHPUnit, then lint and licence, then `npm version` (local only), then `IFIRMA_BUILD_CHANNEL=release npm run build` and `verify:package`, then push, then `gh release create`. Verify with `actionlint` (or a careful review with the user if it is unavailable) that every step after the bump uses `working-directory: ifirma`.
- [ ] 5.2 Document the release procedure and the recovery path for a failed publish step (re-run `gh release create v<x>`, or delete the tag) in the README. Verify with the user that the documented steps match the workflow before the first real release.

## 6. Integration check

- [ ] 6.1 Push the branch and confirm a fully green CI run: lint, phpunit, mock (actually executed) and build. Download the artifact and install the zip on the local test-stack PrestaShop (`wsl.exe docker compose`). Verify that the module reports version `1.2.0` and that `ifirma/BUILD` names the pushed commit.
- [ ] 6.2 Run `graphify update .` and `openspec validate ci-test-builds-and-housekeeping`, then commit the updated `graphify-out/` files together with the change; only the paths untracked in 1.4 stay out. Verify that both commands succeed, and that `git ls-files graphify-out/cache/semantic graphify-out/graph.json` still lists the semantic cache and the graph.

## Workflow follow-up

- First real release (`workflow_dispatch`, `patch`) after the user reviews `release.yml`.
- Archive the change once the release has been verified.
