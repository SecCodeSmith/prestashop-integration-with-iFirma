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

**Releases.** Actions -> *Release* -> *Run workflow* with `patch`, `minor` or `major`, from `master` or a `release/*`
branch (any other branch or a tag is refused). The workflow has two jobs. `verify` holds a read-only token and is the
only place where dependency code runs (`npm ci --ignore-scripts`, `composer install --no-scripts`, `pip install`);
`release` holds the write token and installs nothing, because the build and release scripts use only Node and Python
built-ins. Neither job leaves credentials in `.git/config`; the push step receives the token explicitly. The workflow runs PHPUnit, lint, the licence-header check, the tool tests and the dependency and licence audits
(`composer audit`, `npm audit`, `tools/check_dependency_licenses.py`), then asks `scripts/release-plan.mjs` where the
release lands, bumps and syncs the version (`package.json`, `package-lock.json`, `ifirma.php`, `config.xml`) into a
local commit + tag `v<version>`, builds `ifirma-<version>.zip` (channel `release`), verifies it
(`verify:package`, `tools/check_marketplace_readiness.py`), writes the release notes summary, keeps the verified zip,
tarball and summary as the run's `release-files` artifact, and only then pushes and publishes the GitHub Release. A
failure before the push leaves the remote untouched, and the push is atomic: if the remote refuses a ref, none lands.

*Where a release lands.*

| bump | from | result |
|---|---|---|
| `minor`, `major` | `master` or `release/*` | new branch `release/<major>.<minor>` holds the release commit and the tag; the dispatched branch does not move; a pull request `release/X.Y` -> `master` carries the bump |
| `patch` | `master` | commit and tag on `master`, no pull request |
| `patch` | `release/*` | commit and tag on that branch, plus a pull request into `master` |

The run refuses, before bumping, when the target `release/X.Y` branch or the tag already exists on the remote (for
example while the previous release pull request is still open): merge or remove it first. The pull request step runs
after publishing and never fails the release; if the repository forbids Actions from creating pull requests
(*Settings -> Actions -> General -> Allow GitHub Actions to create and approve pull requests*) the log prints the
`gh pr create` command to run by hand. Protect `master` and `release/*` with branch protection so the bump is
reviewed.

*Release notes.* `scripts/release-summary.mjs` sends only the commit subjects since the previous tag (newest 100,
control characters stripped) to GitHub Models with the workflow's `GITHUB_TOKEN` (`models: read`) and publishes a
short summary above GitHub's generated notes. The answer and the subjects are treated as untrusted: HTML is escaped,
mentions, markdown links and bare URLs are defused and the summary is cut to 120 words. If the call fails, times out or no token is available, the release carries the
plain list of subjects and the log names the reason; the release is never blocked. The model comes from the
repository variable `RELEASE_SUMMARY_MODEL` (default `openai/gpt-4.1-mini`).

If only the publish step fails, the tag already exists: download and unpack the run's `release-files` artifact and
publish those files by hand from that folder,

```bash
gh release create v<version> --title v<version> --generate-notes dist/ifirma-<version>.zip scm-ifirma-<version>.tgz
```

To rebuild the files instead, check out the tag and run `npm ci`, `IFIRMA_BUILD_CHANNEL=release npm run build` and
`npm run verify:package -- --channel release`; they land at the same paths.

After publishing, the workflow starts CI on the new tag, so the release commit gets its own CI run and test build
(`ifirma-<version>+build.<n>`). Pushes made with the workflow's built-in token do not trigger CI on their own. If only
that last step fails, start CI by hand: Actions -> *CI* -> *Run workflow* on tag `v<version>`.

Tool tests (no PHP needed): `python -m unittest discover -s tests/tools -p "test_*.py"` and
`node --test tests/tools/*.test.mjs`. The zip is written by `scripts/zip-writer.mjs`, not by a dependency: every
`archiver` release carries an unfixable `minimatch` advisory, so it was replaced on 2026-11-09.

### Security scanning

`.github/workflows/security.yml` runs on every pull request, every push to `master` and weekly (Monday 04:17 UTC):
`composer audit`, `npm audit --audit-level=moderate`, `pip-audit` on the mock requirements, dependency review (pull
requests), a gitleaks scan of the tree and history, Semgrep (PHP and secrets rules, ERROR severity), the licence
audit, and Trivy on the repository (vulnerabilities, secrets, configuration; HIGH and CRITICAL). The container images
of the test stack are scanned report-only because they never ship; `tools/check_pinned_images.py` fails on an image
without a pinned tag. The build refuses a symbolic link inside the package tree, so a link cannot pull a file
from outside the module into the published zip. Dependabot (`.github/dependabot.yml`) proposes updates for Composer, npm, pip, Docker and the
workflow actions. A high or critical finding fails the run and is fixed in its own `fix-vuln:` commit.

There is no silent ignore list. Accepted risks are recorded with a reason and a review date: the two gitleaks
false-positive paths in `.gitleaks.toml` (graphify cache hashes, fake `IFIRMA_KEY_*` test values; accepted
2026-11-09, review by 2027-05-09) and the licence exception in `docs/third-party-licenses.md`. Known report-only
finding: the mock image runs as root (Trivy AVD-DS-0002, `test-stack/ifirma-mock/Dockerfile`); it is a local test
service that never ships, to be fixed in the test-stack repository, review by 2027-05-09.

Licences: [third-party-licenses.md](third-party-licenses.md). PrestaShop Addons: [marketplace-readiness.md](marketplace-readiness.md).

### Testing every supported PrestaShop version

The test stack image is pinned (`PS_IMAGE_TAG`, default `9.1.4-apache`). `.github/workflows/smoke.yml` (weekly and on
demand) boots the stack for PrestaShop 8.0.5, 8.1.7, 8.2.2, 9.0.3 and 9.1.4 and runs the smoke scenarios against the
mock, except scenario R, which takes 8 to 10 minutes: the workflow skips it on purpose with `SMOKE_SKIP=R` (everything
else runs, including `A2`) and it is run alone with `SMOKE_ONLY=R`. Locally:
`PS_IMAGE_TAG=8.2.2-apache docker compose -f test-stack/docker-compose.yml up -d --build`.

Each job waits until the shop's first-boot install has finished and the front page answers 200 (20 minute limit, the
shop log is printed on failure), then checks that the shop reports the version of its matrix tag, and only then runs
the scenarios. Where the evidence is: the job summary lists the failed checks (`[FAIL]` lines) and the totals, and the
artifact `smoke-output-<tag>` holds the full output of each job, also for a green run; keys and tokens are masked to
their last four characters. Scenario selection by name (`SMOKE_ONLY=A,A2,B`, `SMOKE_SKIP=R`) and the check floor
(`tests/smoke/check-floor.json`, the minimum number of executed checks per scenario, and how to raise it) are described
in [tests/smoke/README.md](../tests/smoke/README.md).

Rule: a red smoke run is fixed at its cause, with a unit test that reproduces the defect first. No check is removed,
skipped or loosened, and no scenario or matrix entry is dropped, to turn a run green.

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

Run one scenario with `SMOKE_ONLY=<letter>`, for example `SMOKE_ONLY=O`, or several by name with `SMOKE_ONLY=A,A2,B`. Scenario `N` (e-mails) also checks the inline logo and the shop link of the module's e-mails, and, in the 1.10.0 release, the automatic delivery retry end to end against the mail sink (the due time is moved in the database, nothing waits). Scenario `D` has a second section that checks the module's request counts against the mock's, and the refusals by the module's own minute cap (429 with `Retry-After` for the owner's download, "The status was not refreshed:" for the employee's refresh, no call reaching the mock).

**What a scenario may assume.** Every scenario starts on a freshly reset mock, with no record of an earlier scenario waiting for KSeF or queued, and with the module's rate counters at zero. The counters are raised with raw SQL by the module, so a scenario writes and reads them only through `smokeWriteSetting()`, `smokeReadSetting()` and `smokeClearRateWindow()` (`Configuration::updateValue()` of an unchanged cached value writes nothing). The sink is read per document (`deliveryMailCountFor()`): a document number alone is ambiguous after a mock reset. Details and the reasons are in [tests/smoke/README.md](../tests/smoke/README.md), "What a scenario may assume at its start".

**The check floor.** `tests/smoke/check-floor.json` is the smallest number of checks each scenario executes on any supported shop. A run that finds fewer, a scenario without an entry, or no floor file at all, is red. It is raised or created from a green run with `SMOKE_WRITE_FLOOR=1`, taking the minimum over the shops (fresh 8.1.7 and 9.1.4 and the development shops); R's entry comes from R's own run.

**Shared form partial.** The invoice-data fields of the customer form live in `views/templates/front/_datarequest_fields.tpl`. Both `datarequest.tpl` (logged-in customers) and `guestrequest.tpl` (guests) include it, so the field list, the messages and the pre-filling cannot drift apart.

**Guest code table.** `ifirma_guest_code` (with the shop's table prefix) holds the one-time codes: order, a salted SHA-256 hash of the code and its salt (never the code), creation and expiry time, the attempt count, the time it was used, an invalidated flag and a keyed hash of the client address for the per-address limit; the indexes `idx_order` and `idx_ip_date` serve the limits. It is created on install and by `upgrade/upgrade-1.6.0.php`, dropped on uninstall, and rows older than 24 hours are deleted whenever a code is issued (`IfirmaGuestCodeService`). Data requests approved by the auto-accept mode are marked in the new `auto_mode` column of `ifirma_data_request`.
