# Tasks

## 1. Housekeeping

- [x] 1.1 Replace "Berechowskir" with "Berechowski" in the 11 affected files (12 occurrences):
  - The headers of ifirma.php, sql/install.php, sql/uninstall.php, controllers/admin/AdminIfirmaController.php, controllers/front/receipt.php, classes/IfirmaApiClient.php, IfirmaContractorService.php, IfirmaHttpTransport.php, IfirmaReceipt.php and IfirmaReceiptService.php.
  - `$this->author` in ifirma.php and `<author>` in config.xml.

  Verify that `git grep -n Berechowskir -- . ':!tools/check_license_headers.py' ':!openspec'` returns nothing. The checker's FORBIDDEN entry and this change's docs quote the typo on purpose.
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
- [x] 2.3 Add `submodules: true` to the checkout of the CI `mock` job (the only job that reads `test-stack`; the repository is public, so no token is needed), and turn the mock job's skip branch into a failure. Verify with actionlint, and with a local pytest run at the pinned commit, then on a CI run that the mock job runs pytest and fails if the folder is missing. Evidence:
  - actionlint exit 0, and locally pytest at `162353b` passed 130 tests with the mock licence check exiting 0.
  - CI run #6 (`afca41f`, run 41899843179) checked out `test-stack` at `162353b` from GitHub, and its pytest step ran 130 tests and passed.
  - The guard script taken from `ci.yml` exits 1 with the error annotation in a plain clone where `test-stack/` is empty, and exits 0 when the submodule is present.
- [x] 2.4 Update the clone/setup notes in `README.md` and `test-stack/README.md` (`--recurse-submodules`, `git submodule update --init`, and how to bump the pinned mock). Verify that the documented commands work from a fresh clone. Verified on scratch clones of the staged state: `--recurse-submodules`, `git submodule update --init`, and `git -C test-stack pull origin master` on the detached submodule HEAD. The `test-stack/README.md` link into the parent repo (dead on the public repo) is now plain text. Cloning from the GitHub URL works once this branch is pushed.

## 3. Version consistency test

- [x] 3.1 Rewrite `ConfigurationKeysTest::testVersionFilesAreBumped` and the version assertion in `testInstallSetsEveryKeyAndUninstallRemovesThem` to compare all four sources against `package.json`, with no literal version. Verify that PHPUnit passes, and that it fails with a message naming `config.xml` when that file's version is edited in a scratch copy.
- [x] 3.2 Verify that a dry-run `npm version patch --no-git-tag-version` on a scratch clone keeps PHPUnit green, then discard the scratch clone.

## 4. Test build packaging

- [x] 4.1 Extend `scripts/build-zip.mjs` to read `IFIRMA_BUILD_CHANNEL`, `IFIRMA_BUILD_NUMBER` and `IFIRMA_BUILD_COMMIT`, append an in-memory `ifirma/BUILD` JSON manifest, and name the zip per channel (design D1). Verify locally that `npm run build:zip` produces `dist/ifirma-1.2.0.zip` with `BUILD.channel == "local"`, and that the same command with test env vars produces `dist/ifirma-1.2.0+build.7.zip` with matching fields. Check by unzipping into the scratchpad.
- [x] 4.2 Add a package-layout check script (`scripts/verify-package.mjs`) that fails if any zip entry falls outside `ifirma/` or if a development path from the spec is present, and that validates `BUILD`. Wire it as `npm run verify:package`. Verify that it passes on the built zip and fails on a scratch zip containing `ifirma/tests/x.php`.
- [x] 4.3 Update the `ci.yml` build job: add `needs: [lint, phpunit, mock]`, set the test-channel env vars from `github.run_number` and the PR-head-or-sha commit, run `verify:package`, and use artifact name `ifirma-<version>+build.<n>` with `retention-days: 90`. Verify on a pushed branch that the artifact appears with the expected name and contents, and that a deliberately failing unit test on a scratch branch produces no artifact. Evidence:
  - master runs #6 (`afca41f`) and #7 (`958c1a6`) were green and published `ifirma-1.2.0+build.6` and `ifirma-1.2.0+build.7`, so the build number increases.
  - Throwaway branch `ci-gate-check` (`3d57fa8`, one deliberately failing test) got dispatched run #8 (41901207530). `lint` and `mock` passed, PHPUnit failed with exactly that test (1081 tests, 1 failure), the test-build job was skipped and no artifacts were published.
  - The branch was then deleted on GitHub and locally.
- [x] 4.4 Add `scripts/build-zip.mjs` env vars and the `BUILD` manifest to the README build section. Verify that the documented local command produces the documented file name.

## 5. Atomic release workflow

- [x] 5.1 Reorder `release.yml` per design D4: checkout into `ifirma/` (no submodules needed), composer install plus PHPUnit, then lint and licence, then `npm version` (local only), then `IFIRMA_BUILD_CHANNEL=release npm run build` and `verify:package`, then push, then `gh release create`. Verify with `actionlint` (or a careful review with the user if it is unavailable) that every step after the bump uses `working-directory: ifirma`.
- [x] 5.2 Document the release procedure and the recovery path for a failed publish step (re-run `gh release create v<x>`, or delete the tag) in the README. Verify with the user that the documented steps match the workflow before the first real release. The user confirmed on 2026-11-04 that the README "Releases" paragraph matches the intended procedure.
- [x] 5.3 Make the release start CI on its new tag (design D4 step 6, spec "Release commits get a CI run"): add `actions: write` and a final `gh workflow run ci.yml --ref v<version>` step to `release.yml`, and document it in the README. Verify with actionlint, then by dispatching `ci.yml` on tag `v1.3.0` through the API, which is the same call the new step makes. That run must pass and publish `ifirma-1.3.0+build.<n>` with `BUILD.commit` `f6ecd77`. The first workflow-made dispatch is confirmed on the next real release. Evidence: actionlint exit 0, and dispatching on `v1.3.0` produced CI run #10 (41904365815) on `f6ecd77`. All four jobs passed, and its artifact `ifirma-1.3.0+build.10` has `BUILD` {1.3.0, build 10, commit `f6ecd77`, channel `test`}.

## 6. Integration check

- [x] 6.1 Push the branch and confirm a fully green CI run: lint, phpunit, mock (actually executed) and build. Download the artifact and install the zip on the local test-stack PrestaShop (`wsl.exe docker compose`). Verify that the module reports version `1.2.0` and that `ifirma/BUILD` names the pushed commit. Evidence:
  - CI run #6 on `afca41f` was green in all four jobs, and the build job started only after the other three finished.
  - Artifact `ifirma-1.2.0+build.6` (expires 2027-02-02) contains `ifirma-1.2.0+build.6.zip` and `scm-ifirma-1.2.0.tgz`.
  - `verify-package` passes on the zip. `BUILD` = {version 1.2.0, build 6, commit `afca41f`, channel `test`}. Apart from `BUILD`, its 76 files match the `afca41f` source tree file for file.
  - The zip was not uploaded through the back office, because the local shop runs on a bind mount of the working tree and an upload would overwrite the checkout. Instead, the zip's own copy of the module was loaded under the running PrestaShop 8.1.7 (PHP 8.1.33) from `/tmp/zipmod/ifirma`. It reports `ifirma` 1.2.0, `config.xml` says 1.2.0, and the shop's installed version stays 1.2.0.
- [x] 6.2 Run `graphify update .` and `openspec validate ci-test-builds-and-housekeeping`, then commit the updated `graphify-out/` files together with the change; only the paths untracked in 1.4 stay out. Verify that both commands succeed, and that `git ls-files graphify-out/cache/semantic graphify-out/graph.json` still lists the semantic cache and the graph. Evidence:
  - `graphify update .` re-extracted code only ("no LLM needed") and rebuilt 3486 nodes and 7891 edges. `cost.json` is unchanged.
  - `openspec validate --strict` is valid.
  - `git ls-files` still lists `graph.json` and all 18 `cache/semantic` files, out of 33 tracked graphify files.
  - The saved LLM labels file was already empty and is unchanged; only the hub-derived pending names changed.

## 7. Review fixes

An adversarial review ran 4 reviewers, and a skeptic checked each finding. It confirmed 14 of 19 findings, none of them in shipped module behaviour.

- [x] 7.1 Make the release push atomic (`git push --atomic --follow-tags`), so a refused branch or tag update cannot leave an orphan tag or an untagged release commit. Verify with actionlint, and by replaying the push against a scratch bare remote whose master moved after the dispatch. The plain push must leave an orphan tag; the atomic push must leave the remote untouched. Evidence:
  - actionlint exit 0.
  - In the replay the runner had a local release commit plus annotated tag `v9.9.9`, and another clone had already pushed to master.
  - The plain push printed `[new tag] v9.9.9` and `! [rejected] master -> master (fetch first)`, exit 1. The remote then held the orphan tag `v9.9.9`.
  - The atomic push printed `! [rejected] v9.9.9 -> v9.9.9 (atomic push failed)`, exit 1. The remote had no tag, and master was unchanged.
- [x] 7.2 Harden the package guard.
  - `build-zip.mjs` skips nested `node_modules/`, `.git/` and `tests/` folders and `.DS_Store`, `Thumbs.db` and `.env*` files, and fails when an allow-listed path is missing.
  - `verify-package.mjs` rejects those paths at any depth, requires `ifirma.php`, `config.xml`, `index.php` and `LICENCE`, takes `--channel <channel>`, and prints its own message when `dist/` does not exist.

  Verify with a scratch matrix:
  - Nested junk is filtered at build time and rejected in crafted zips.
  - A missing folder fails the build.
  - A wrong channel fails.
  - No `dist/` gives the plain message.
  - `npm run verify:package -- --channel test` passes the flag through.

  Evidence: all 27 scratch cases met their expectations.
  - Seven planted files were left out of the zip and verify passed: `views/js/node_modules/...`, `classes/tests/...`, `views/.git/HEAD`, `classes/.env`, `classes/.env.local`, `views/.DS_Store` and `classes/Thumbs.db`.
  - Crafted zips were each rejected with the right message: nested `node_modules`, `tests` and `.git` paths, `.env`, `.DS_Store`, a missing `config.xml`, top-level `tests/`, and an entry outside `ifirma/`.
  - With `controllers/` removed, the build exited 1 with `allow-listed path(s) missing: controllers` and wrote no zip.
  - `--channel release` on a local or test zip fails, and `--channel bogus` and a missing value exit 1.
  - With no `dist/`, verify prints the plain message.
  - `npm run verify:package -- --channel test` passes the flag through.
  - A release-channel build of the repo has the same 86 entries as the published `ifirma-1.3.0.zip`, so the filter drops nothing that used to ship.
- [x] 7.3 Assert the channel where it matters:
  - `ci.yml` runs `verify:package -- --channel test`.
  - `release.yml` runs `verify:package -- --channel release` and uploads the verified files as the `release-files` artifact before the push.
  - The README and the workflow header document the recovery: publish the artifact's files, or rebuild at the tag with `IFIRMA_BUILD_CHANNEL=release`. Deleting the tag releases the next version instead.

  Verify with actionlint. The release path is first exercised by the next release.
- [x] 7.4 Check out the commit that `BUILD` names in every CI job. `IFIRMA_BUILD_COMMIT` is set at workflow level and used as each checkout's `ref`, so pull request runs test and build the PR head rather than GitHub's merge commit. Verify with actionlint. The pull request path is first exercised by the first pull request.
- [x] 7.5 Make `testVersionMismatchNamesTheLaggingSource` hermetic, with minimal fixtures instead of copies of the real files. Verify that PHPUnit passes. Also verify on a scratch copy where only `package.json` is bumped: the self-test must still pass while `testVersionSourcesAgree` fails and names the lagging sources. Evidence:
  - The full suite gives `OK (1080 tests, 3896 assertions)` in the PrestaShop 8.1 container.
  - Mutations ran on a container copy of the module:
    - `package.json` alone at 1.3.1: the self-test passes. `testVersionSourcesAgree` fails, listing both package-lock fields, `ifirma.php` and `config.xml`, and the install test fails.
    - `config.xml` alone at 0.0.1: only `testVersionSourcesAgree` fails, naming `config.xml <version>`.
    - All four sources at 1.3.1: `OK (3 tests, 96 assertions)`.
- [x] 7.6 Correct the documentation drift the review found:
  - the checker's "identical engine" comments and exit-status docstring;
  - task 1.1's file count and check;
  - the proposal's scope and Impact list;
  - design D2 (BUILD and `builtAt`, uniqueness), D4 and D7 (484 AST files);
  - the dry-run advice and the rollback note;
  - the README examples.

  Verify with `openspec validate --strict` and the licence check.
- [x] 7.7 Run the mock's `pytest` without a second `-q`, so the CI log shows the pass count. Verify with actionlint.
- [ ] 7.8 Confirm on the CI run of the review-fix commit:
  - all four jobs pass;
  - the mock log shows the pass count;
  - every job checked out the pushed commit;
  - `verify:package -- --channel test` passed;
  - the artifact `ifirma-1.3.0+build.<n>` has `BUILD.commit` equal to the pushed commit.

## Workflow follow-up

- First real release: done by the user on 2026-11-04 as a `minor` bump, giving **v1.3.0**.
  - Release run #1 (41902343769) ran verify (PHPUnit, lint, licence), then the local bump, commit and tag, then build and verify, then the push, then the publish, in that order.
  - Bot commit `f6ecd77` changes only the four version files. Tag `v1.3.0` points to `f6ecd77`.
  - The GitHub Release has `ifirma-1.3.0.zip` and `scm-ifirma-1.3.0.tgz`. The zip passes `verify-package`, and its `BUILD` is {1.3.0, channel `release`, build null, commit `f6ecd77`}.
  - The bot push does not trigger CI (GITHUB_TOKEN), so CI #9 was dispatched by hand on `f6ecd77`. It was green, with artifact `ifirma-1.3.0+build.9`.
- Archive the change once the release has been verified.
- Open (needs a commit in `test-stack` and a submodule bump): port the FORBIDDEN handling into the mock's copy of the checker, with an empty FORBIDDEN tuple, so that both engines are identical again. Until then, the mock copy's own comment claims they are identical.
