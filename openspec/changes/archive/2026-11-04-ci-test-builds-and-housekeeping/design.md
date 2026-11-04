# Design

## Context

The state before this change:
- `ci.yml` has four independent jobs: lint, phpunit, build and mock. `build` does not depend on the test jobs, and it always produces `dist/ifirma-<package.json version>.zip`.
- `release.yml` runs `php -l` and the licence check, then `npm version` (whose `version` lifecycle runs `scripts/sync-version.mjs`), pushes, builds, and publishes. It has never run.
- PrestaShop stores the installed module version in `ps_module.version VARCHAR(8)` and picks `upgrade/upgrade-X.Y.Z.php` files with `version_compare` against it. Any suffix in the module version gets truncated, and it also changes which upgrade scripts run. That is why build identity must live outside the module version (see proposal.md - What Changes).
- `test-stack/` is a standalone git repository with no remote (latest commit `c153d06`). Its README already says it is meant to be pushed as its own repository. The parent repository tracks it only as gitlink mode `160000`.
- The PHPUnit job checks the repository out into a folder named `ifirma`, because the test bootstrap derives `_PS_MODULE_DIR_` from that name.

## Goals / Non-Goals

**Goals:**
- One artifact per CI run whose origin can be read from the file name and from inside the zip.
- A release that either completes fully or leaves the remote untouched, up to the publish step.
- CI runs that are green only when the module tests and the mock tests have actually run.

**Non-Goals:**
- Showing the build number in the module's back office. This is a possible follow-up that would read `BUILD` if present.
- A rolling `test-latest` GitHub pre-release, or automatic deployment to a staging shop.
- Any change to `sync-version.mjs`, to the module runtime, or to the upgrade scripts.
- Code refactoring. That is covered by `refactor-clean-code-tda` and `refactor-srp-splits`.

## Decisions

### D1. Build metadata comes from environment variables read by `build-zip.mjs`
`build-zip.mjs` reads `IFIRMA_BUILD_CHANNEL` (`test` | `release`, default `local`), `IFIRMA_BUILD_NUMBER` and `IFIRMA_BUILD_COMMIT`. It writes `ifirma/BUILD` as JSON (`version`, `build`, `commit`, `builtAt`, `channel`), generated in memory with `archive.append`, so nothing is written to the working tree.
- The file name is `ifirma-<version>+build.<n>.zip` when the channel is `test`, and `ifirma-<version>.zip` otherwise.
- *Alternative:* a `BUILD` PHP constant patched into `ifirma.php`. Rejected, because it edits a tracked file during the build and changes the bytes of a shipped source file.
- *Alternative:* the build number in the module version (`1.2.0.57`). Rejected because of the 8-character limit and because upgrade scripts would be selected differently.

### D2. The build number is `github.run_number`
It only increases and needs no state. It is unique within `ci.yml`, the only workflow that makes test builds, so test build numbers are unique in the repository.

The commit is `github.event.pull_request.head.sha || github.sha`. It is set once, as the workflow-level `IFIRMA_BUILD_COMMIT`, and cut to 7 characters in `BUILD.commit`. Every CI job checks out that commit, so on a pull request the lint, the tests, the zip and `BUILD` all refer to the PR head and not to GitHub's synthetic merge commit.
- *Trade-off:* a pull request run tests the head as pushed, not its merge with the current base. The push to master after merging tests the merge itself.
- `BUILD.commit` lets a build be repeated from source. The zip bytes still differ between two builds of one commit, because `builtAt` differs.

### D3. CI gating with `needs:`
`build` gets `needs: [lint, phpunit, mock]`. Before the upload, `npm run verify:package -- --channel test` checks the zip. The upload step uses `retention-days: 90` and the artifact name `ifirma-<version>+build.<n>`. The tgz is still produced by `npm pack` under npm's own name and stays in the same artifact. Only the zip carries the build identity.

### D4. Release order: verify, bump locally, build, then push, then publish
1. Check out (no submodules: nothing in the release job reads `test-stack`). Run `composer install`, PHPUnit, `php -l` and the licence check.
2. Run `npm version <level>`. This creates the local commit and tag, but does not push them.
3. Run `IFIRMA_BUILD_CHANNEL=release npm run build` and `npm run verify:package -- --channel release`. Then upload the verified zip and tarball as the run's `release-files` artifact, so a failed step 5 can be finished by hand with the same bytes.
4. Run `git push --atomic --follow-tags`. The branch and the tag land together or not at all. A refused branch update (for example, master moved after the dispatch) therefore cannot leave an orphan tag, and a refused tag cannot leave an untagged release commit.
5. Run `gh release create`.
6. Run `gh workflow run ci.yml --ref v<version>`, which needs `permissions: actions: write`. Pushes made with `GITHUB_TOKEN` never trigger workflows, but `workflow_dispatch` does, so this is how the release commit gets a CI run and a test build. Added at the user's request on 2026-11-04, after release v1.3.0 needed a manual CI dispatch. It runs last, so a failed dispatch cannot block publishing.
A failure in steps 1 to 4 leaves the remote untouched. PHPUnit needs the `ifirma/` folder name, so the release job checks out into `ifirma` and uses `working-directory: ifirma`, as the CI phpunit job does.

### D5. A version-consistency test instead of a fixed version
`ConfigurationKeysTest` reads the version from `package.json` and asserts that `package-lock.json` (both the top-level `version` and `packages[''].version`), `config.xml` CDATA, and the loaded module's `$version` are all equal to it. Each assertion names the mismatching file in its failure message.

### D6. test-stack becomes a real submodule
1. The user created the **public** repository `SecCodeSmith/test-stack` and pushed the history (head `162353b`, which contains the old gitlink `c153d06`).
2. Run `git rm --cached test-stack`, then `git submodule add https://github.com/SecCodeSmith/test-stack.git test-stack`. Because the folder is an existing repository, this registers it at its current head, `162353b`.
3. Only the CI `mock` job checks out with `submodules: true`, because it is the only job that reads `test-stack`. Lint and the licence check skip the gitlink, and PHPUnit, the build and the release never touch it. The repository is public, so no token is needed.
4. The mock job's "skip if missing" branch becomes a hard failure.

*Alternative:* fold the files into this repository. Rejected by the user, because the mock has its own CI and MIT licence and is meant to stay standalone.

### D7. Keep the graph, drop only the cache that is cheap to rebuild
The graph stays tracked: `graph.json`, `GRAPH_REPORT.md`, `graph.html`, `manifest.json`, `cost.json`, the label and root files, the dated snapshots (`2026-11-01/`), and `cache/semantic/`. The semantic cache holds LLM output, which is expensive to rebuild.

Only cache content that `graphify update .` rebuilds without an LLM, or that is purely session or temp state, leaves git. Each of these gets a `.gitignore` entry and a `git rm --cached`:
- `graphify-out/cache/ast/`: about 21 MB and 484 files, rebuilt from source on CPU only.
- `graphify-out/cache/stat-index.json` and `graphify-out/cache/last_query_stamp`: rebuilt indexes and timestamps.
- `graphify-out/cache/hook_sessions/`: state for individual Claude sessions, never shared.
- `graphify-out/cache/.gfy-replace-bak-*.tmp`: leftover temp backups.

The rule: if rebuilding a path needs an LLM call, it stays tracked.
- *Alternative:* untrack the whole cache. Rejected, because the semantic cache would then cost API spend on every clone.
- *Alternative:* keep everything. Rejected, because 21 MB of AST cache changes with every `graphify update`, and hook-session files churn on each Claude session.
- The package allow-list (spec "Installable package layout") already keeps all of `graphify-out/` out of the zip and the tgz.

### D8. The header typo becomes a checker failure
`check_license_headers.py` gains a `FORBIDDEN` tuple (`"Berechowskir"`). Any tracked policed file that contains a forbidden string fails the check, so the typo cannot come back through copy-paste. Both copies of the checker share one engine: the mock's copy in `test-stack` (since `00edaf5`) has an empty FORBIDDEN tuple. The forbidden scan skips the checker's own file, because in `test-stack` the checker is itself a policed `.py` file that lists the strings.

## Risks / Trade-offs

- [`gh release create` fails after the push, leaving a tag without a release] → The run keeps the verified files as its `release-files` artifact, and the README documents publishing them by hand with `gh release create v<x>`. Deleting the tag instead abandons that version: its release commit is already on master, so the next run releases the version after it.
- [The submodule repository is made private later] → CI would then need a read token on the mock job's checkout (`token: ${{ secrets.TEST_STACK_TOKEN }}`). Until that is added, the mock job fails loudly instead of skipping.
- [`+` in the file name trips a tool that does not URL-encode] → GitHub artifact names allow `+`. The zip's internal folder is still `ifirma/`, which is what PrestaShop's upload checks.
- [Removing the AST cache or the stat index makes graphify think the semantic extraction is stale, which would trigger an LLM run] → Tasks 1.3 to 1.5 record a baseline, then gate the untracking on a fresh-clone rebuild with no LLM call, unchanged node and edge counts, an identical semantic cache and an unchanged `cost.json`. If any check fails, the offending path stays tracked.
- [Bumping the submodule now requires a commit in both repositories] → This is the normal submodule trade-off. `test-stack/README.md` already describes it as standalone.

## Migration Plan

1. Land the housekeeping first: typo, checker rule, cheap graph cache untracked. These are independent and have low risk.
2. Create the mock repository and convert the gitlink to a submodule. Verify that the CI mock job runs.
3. Change `build-zip.mjs` and `ci.yml`, then confirm that one CI run produces `ifirma-1.2.0+build.<n>.zip` with a correct `BUILD`.
4. Change `release.yml`. A dry run needs a fork: on a branch of this repository the workflow would push a real tag and publish a real release. Otherwise, review it with the user before the first real release. (Done: the user reviewed it and released v1.3.0.)

Rollback: most of the change landed in one commit (`afca41f`), followed by the submodule bump (`958c1a6`) and the review fixes. Revert them newest first. Converting the submodule back is `git submodule deinit` plus restoring the gitlink.

## Open Questions

- The licence notice says `SecCodeSmith Commercial License v1.3`, but its SPDX line says `...-Commercial-1.2`. This change does not touch it because it is a licensing decision, but someone should confirm which one is right.
