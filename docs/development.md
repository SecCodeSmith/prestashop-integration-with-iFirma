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

### Guest requests, auto-accept and the smoke suite (1.6.0)

The Docker smoke suite (`tests/smoke/run.php`, described in [tests/smoke/README.md](../tests/smoke/README.md)) runs one lettered scenario per area. Since 1.6.0 it has three more:

| Letter | Scenario | What it proves |
|---|---|---|
| `O` | Guest one-time code | A guest order is identified with a wrong e-mail (nothing in the mail sink, neutral message), then with the right one; the six-digit code is read from the sink, a wrong code is refused, the right one opens the form; a safe change in mode `safe` is approved automatically, the customer approval mail and the merchant mail with the automatic line arrive, and the pipeline resumes. |
| `P` | Auto-accept keeps identity changes pending | In mode `safe` a request that changes the NIP stays pending, the hold is recorded and the merchant mail has no automatic line; in mode `none` every request stays pending as before. |
| `Q` | Stamp prefix and shop webpage | With a custom prefix and webpage the mock's document shows `Uwagi` as the stamp, a space and the webpage, and `NUMER_ZAMOWIENIA` as the bare stamp; a remote document with the legacy `PS-ORDER-` stamp is adopted, one with another shop's prefix is not; the delivery attachment and the customer download are named `<shop-domain>-<number>.pdf`. |

Since 1.8.0 two more cover the queue and KSeF-confirmed delivery (they need mock 1.4.0, which enforces iFirma's limits and simulates KSeF auto-send, and the module's cron runner):

| Letter | Scenario | What it proves |
|---|---|---|
| `R` | Burst issuance under the limits | 50 orders in a row each end with exactly one document; the module's cap queues the surplus and the cron drains it once a minute without the mock ever refusing a call; the mock's minute and day limits and the module's own day cap queue the orders, start the back-off and drain afterwards. About 8 to 10 minutes; run it alone. |
| `S` | KSeF-confirmed delivery | The module submits once, delivery waits and the customer's download answers "not found" until KSeF accepts, then one cron run delivers once; iFirma's own submission and the submitter `ifirma` mean no send call; a rejection keeps the delivery waiting; documents that do not go to KSeF are delivered at issuance. |

Run one scenario with `SMOKE_ONLY=<letter>`, for example `SMOKE_ONLY=O`. Scenario `N` (e-mails) also checks the inline logo and the shop link of the module's e-mails.

**Shared form partial.** The invoice-data fields of the customer form live in `views/templates/front/_datarequest_fields.tpl`. Both `datarequest.tpl` (logged-in customers) and `guestrequest.tpl` (guests) include it, so the field list, the messages and the pre-filling cannot drift apart.

**Guest code table.** `ifirma_guest_code` (with the shop's table prefix) holds the one-time codes: order, a salted SHA-256 hash of the code and its salt (never the code), creation and expiry time, the attempt count, the time it was used, an invalidated flag and a keyed hash of the client address for the per-address limit; the indexes `idx_order` and `idx_ip_date` serve the limits. It is created on install and by `upgrade/upgrade-1.6.0.php`, dropped on uninstall, and rows older than 24 hours are deleted whenever a code is issued (`IfirmaGuestCodeService`). Data requests approved by the auto-accept mode are marked in the new `auto_mode` column of `ifirma_data_request`.
