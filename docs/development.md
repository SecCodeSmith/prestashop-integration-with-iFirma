# Development guide

Unit tests, package builds, the test stack submodule, releases and dev dependencies.

[Back to README](../README.md)

For how the module is built, see [Architecture](architecture.md).

## Development

Unit tests run without PrestaShop:

```bash
composer install
tests/vendor/bin/phpunit
```

The tests load lightweight stubs of the PrestaShop classes the module uses (`Configuration`, `Db`, `ObjectModel`, `Order`, `Mail`, `Context`, ...) from `tests/Stubs/`, so module classes can be exercised in memory: the database is scripted per query, configuration and mail are recorded, and the HTTP layer can be replaced with `tests/Support/ScriptedTransport.php`. Call `IfirmaTestState::resetAll()` in `setUp()`; the stub API is documented in `tests/Stubs/README.md`. Tests, `vendor/` and `composer.*` are never shipped in the module package.

Other checks:

```bash
npm run build                              # installable zip in dist/ + npm tarball in the repo root
npm run verify:package                     # layout + ifirma/BUILD check of every zip in dist/
npm run verify:package -- --channel test   # ... and require that BUILD channel (CI and releases do this)
python tools/check_license_headers.py      # every source file must carry the license header
```

`python tools/check_license_headers.py` also fails on known header typos listed in its `FORBIDDEN` tuple.

### Test stack (git submodule)

The mock iFirma API and the Docker test stack live in the `test-stack/` git submodule
(<https://github.com/SecCodeSmith/test-stack>, public, MIT licensed). This repo pins one commit of it.

```bash
# fresh clone; the folder must be named ifirma (the test bootstrap resolves _PS_MODULE_DIR_ from it)
git clone --recurse-submodules https://github.com/SecCodeSmith/prestashop-integration-with-iFirma.git ifirma

# existing clone
git submodule update --init
```

To update the pinned mock, commit and push the change in the `test-stack` repo first. Then, in the module repo:

```bash
git -C test-stack pull origin master
git add test-stack
git commit -m "Update test-stack submodule"
```

CI's `mock` job checks the submodule out and fails if it is missing.

### Builds and their identity

Every package carries an `ifirma/BUILD` manifest (JSON: `version`, `build`, `commit`, `builtAt`, `channel`), generated
at build time and never written to the working tree. The module version in `ifirma.php` / `config.xml` is **not**
changed by a test build, so PrestaShop's `ps_module.version` (8 characters) and upgrade-script selection behave
exactly as for the matching release. `scripts/build-zip.mjs` reads:

| Variable | Values | Effect |
|---|---|---|
| `IFIRMA_BUILD_CHANNEL` | `local` (default), `test`, `release` | `test` names the zip `ifirma-<version>+build.<n>.zip` |
| `IFIRMA_BUILD_NUMBER` | positive integer, required for `test` | CI uses `github.run_number` |
| `IFIRMA_BUILD_COMMIT` | commit hash (cut to 7 chars) | defaults to `git rev-parse HEAD` |

```bash
npm run build:zip                                                    # dist/ifirma-<version>.zip, channel local
IFIRMA_BUILD_CHANNEL=test IFIRMA_BUILD_NUMBER=7 npm run build:zip    # dist/ifirma-<version>+build.7.zip
```

**Test builds.** CI (`.github/workflows/ci.yml`) builds a test package on every push, pull request and manual run,
only after lint, PHPUnit and the mock tests pass. Download it from the run's *Artifacts* section
(`ifirma-<version>+build.<n>`, kept 90 days).

**Releases.** Actions -> *Release* -> *Run workflow* with `patch`, `minor` or `major`. The workflow runs PHPUnit, lint
and the licence check, bumps and syncs the version (`package.json`, `package-lock.json`, `ifirma.php`, `config.xml`)
into a local commit + tag `v<version>`, builds and verifies `ifirma-<version>.zip` (channel `release`), keeps the
verified zip and tarball as the run's `release-files` artifact, and only then pushes and publishes the GitHub Release.
A failure before the push leaves the remote untouched, and the push is atomic: if the remote refuses the commit or the
tag, neither lands. If only the publish step fails, the tag already exists: download and unpack the run's
`release-files` artifact and publish those files by hand from that folder,

```bash
gh release create v<version> --title v<version> --generate-notes dist/ifirma-<version>.zip scm-ifirma-<version>.tgz
```

To rebuild the files instead, check out the tag and run `npm ci`, `IFIRMA_BUILD_CHANNEL=release npm run build` and
`npm run verify:package -- --channel release`; they land at the same paths. Deleting the tag and running the workflow
again does not redo that version: its release commit is already on `master`, so the next run releases the version
after it.

After publishing, the workflow starts CI on the new tag, so the release commit gets its own CI run and test build
(`ifirma-<version>+build.<n>`). Pushes made with the workflow's built-in token do not trigger CI on their own. If only
that last step fails, start CI by hand: Actions -> *CI* -> *Run workflow* on tag `v<version>`.

### Dev dependencies and PrestaShop

PrestaShop loads `modules/*/vendor/autoload.php` at boot and Composer prepends its autoloader, so dev
dependencies installed in a module root shadow PrestaShop's own classes (observed: PHPUnit's `nikic/php-parser` v5
breaking PrestaShop 9's module parser, and test stubs replacing `Module`). Composer therefore installs into
`tests/vendor/` (`config.vendor-dir`), a path PrestaShop never loads. Do not move it back, and never deploy a
`vendor/` directory; the release zip and the npm tarball exclude `tests/`.
