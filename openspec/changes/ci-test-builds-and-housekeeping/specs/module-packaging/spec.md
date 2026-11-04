# Spec Delta

## Purpose

Defines how installable packages of the iFirma module are built, identified and published. It covers both per-commit test builds and versioned releases, so every zip installed on a shop can be traced to its source.

## ADDED Requirements

### Requirement: Installable package layout
The installable package SHALL be a zip archive whose files sit under a single top-level `ifirma/` folder. It SHALL contain only the module runtime files, from an explicit allow-list, and never development or test assets.

#### Scenario: Package contents
- **WHEN** a package is built
- **THEN** every entry in the zip starts with `ifirma/`
- **AND** the zip contains none of `tests/`, `test-stack/`, `tools/`, `scripts/`, `.github/`, `node_modules/`, `openspec/` or `graphify-out/`

### Requirement: Test builds are uniquely identified
Every CI build SHALL produce a package whose file name carries the module version, a build number that only increases, and that is unique per repository. The package SHALL include an `ifirma/BUILD` manifest that records the module version, the build number, the short commit hash, the UTC build time and the channel `test`.

#### Scenario: Two consecutive CI runs on the same version
- **WHEN** CI builds the module twice while the module version is `1.2.0`
- **THEN** the two packages have different file names of the form `ifirma-1.2.0+build.<n>.zip`
- **AND** each package's `ifirma/BUILD` names the commit it was built from

#### Scenario: Locally built package
- **WHEN** a developer runs the build without CI build metadata
- **THEN** the package is named `ifirma-<version>.zip` and its `ifirma/BUILD` has channel `local`, with no build number

### Requirement: Test builds do not alter the module version
A test build SHALL NOT change the module version declared in the module class, in `config.xml` or in `package.json`, and SHALL NOT create commits or tags.

#### Scenario: Installing a test build
- **WHEN** a test build of version `1.2.0` is installed on a shop
- **THEN** the module reports version `1.2.0`
- **AND** the repository history is unchanged by the build

### Requirement: Test builds only from a passing tree
A test build SHALL be produced only after the PHP syntax check, the licence-header check and the unit test suite have passed for the same commit. CI SHALL publish the build as a downloadable artifact kept for 90 days.

#### Scenario: Unit test failure
- **WHEN** a unit test fails on a commit
- **THEN** no package artifact is published for that commit

#### Scenario: Passing commit
- **WHEN** all checks pass on a commit
- **THEN** the CI run exposes a downloadable artifact containing that commit's package

### Requirement: Releases are atomic
A release SHALL run the syntax check, the licence-header check and the unit tests, bump the version, and build the package before it pushes anything. If any of these steps fails, the remote repository SHALL receive no commit, no tag and no release.

#### Scenario: Build failure during release
- **WHEN** the package build fails during a release
- **THEN** no version tag or release commit exists on the remote

#### Scenario: Successful release
- **WHEN** a release of level `patch` succeeds from version `1.2.0`
- **THEN** the remote has tag `v1.2.1` and a release whose attached package is `ifirma-1.2.1.zip`, with `ifirma/BUILD` channel `release`

### Requirement: Version sources stay consistent
The module version declared in `package.json`, `package-lock.json`, the module class and `config.xml` SHALL be identical. The unit test suite SHALL fail when they differ, without depending on any particular version value.

#### Scenario: Version bumped consistently
- **WHEN** all four sources are bumped to the same new version
- **THEN** the unit test suite passes with no test edits

#### Scenario: One source left behind
- **WHEN** `config.xml` declares a different version from `package.json`
- **THEN** the unit test suite fails, naming the mismatching source

### Requirement: Mock API tests run in CI
CI SHALL check out the mock API test stack and run its test suite on every run. A missing checkout SHALL be a failure, not a silent skip.

#### Scenario: Fresh CI checkout
- **WHEN** CI runs on a fresh clone
- **THEN** the mock API tests run and their result decides whether the CI run passes
