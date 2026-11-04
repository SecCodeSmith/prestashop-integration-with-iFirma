# Proposal

## Why

There is no traceable way to get a test build of the module. CI always produces `ifirma-1.2.0.zip`, whatever commit it came from, so two zips installed on a staging shop cannot be told apart. The release workflow has never run (there are no tags), it skips PHPUnit, and it pushes the tag before it builds, so a failed build would leave a tag with no release. `ConfigurationKeysTest` hard-codes `'1.2.0'`, which means the first real version bump would turn the suite red.

Housekeeping debt also gets in the way of CI and review:
- `test-stack` is a gitlink with no `.gitmodules` and no remote, so the CI mock job always skips.
- 10 source headers misspell the author as "Berechowskir".

## What Changes

- **Test builds:** every CI run (push, pull request, manual dispatch) builds the installable zip only after lint and PHPUnit pass. It names the zip `ifirma-<version>+build.<run_number>.zip`, embeds a generated `ifirma/BUILD` manifest (version, build number, commit, date, channel), and uploads it as an Actions artifact. The module version in `ifirma.php` and `config.xml` is **not** changed by a test build. That keeps `ps_module.version` within its 8 characters and leaves upgrade-script selection untouched.
- **Release workflow hardening:** lint, licence check and PHPUnit run first. Then the version is bumped and synced and the zip is built locally. Only after all of that succeeds are the commit and tag pushed and the GitHub Release published. The release zip carries `channel: release` and no build suffix.
- **Version consistency test:** `ConfigurationKeysTest` asserts that `package.json`, `package-lock.json`, `ifirma.php` and `config.xml` agree with each other, instead of matching a literal version.
- **test-stack as a real submodule:** it is pushed to its own GitHub repository and registered in `.gitmodules`. CI checks submodules out, so the mock test job actually runs.
- **Cheap graph cache untracked:** the AST cache (about 21 MB), the stat index, the query stamp, the per-session hook files and the leftover temp backups under `graphify-out/cache/` leave git and are ignored. `graphify update .` rebuilds all of them without an LLM. The graph itself, its snapshots and the LLM-built `cache/semantic/` stay committed.
- **Header typo:** "Berechowskir" becomes "Berechowski" in 10 files. The licence-header checker rejects that misspelling from now on.
- **Local clutter removed:** the stray `scm-ifirma-1.1.0.tgz` and old `dist/` output. These are local only and already gitignored.

## Capabilities

### New Capabilities
- `module-packaging`: How installable module packages are built, identified and published. This covers both test builds and versioned releases, and the version-consistency guarantee between them.

### Modified Capabilities
<!-- none: no runtime behaviour of the module changes -->

## Impact

- `.github/workflows/ci.yml`, `.github/workflows/release.yml`
- `scripts/build-zip.mjs` (build metadata and file name). `scripts/sync-version.mjs` is unchanged.
- `tests/Unit/ConfigurationKeysTest.php`
- `.gitmodules` (new), `test-stack` gitlink, `.gitignore`
- `graphify-out/cache/` index entries for the cheap-to-rebuild parts only. The graph files, the snapshots and `cache/semantic/` are untouched.
- `tools/check_license_headers.py`, plus the headers of the 10 affected PHP files
- External: the public GitHub repository `SecCodeSmith/test-stack` holds the mock stack. It is public, so CI needs no token to check it out.
- No change to the merchant-facing module behaviour, database schema or configuration keys.
