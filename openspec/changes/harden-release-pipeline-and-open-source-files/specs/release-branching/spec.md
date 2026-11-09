# Spec Delta

## Purpose

Defines which branch each kind of release lands on, so minor and major versions get their own maintenance line and every version bump reaches the default branch through a reviewed pull request.

## ADDED Requirements

### Requirement: Minor and major releases get their own branch
A minor or major release SHALL be committed and tagged on a new branch named `release/<major>.<minor>` of the new version, cut from the dispatched ref. The default branch SHALL receive no release commit from such a run.

#### Scenario: Minor release
- **WHEN** a minor release from version `1.8.0` is run
- **THEN** branch `release/1.9` exists on the remote and holds the release commit and tag `v1.9.0`
- **AND** `master` has not moved

#### Scenario: Major release
- **WHEN** a major release from version `1.9.2` is run
- **THEN** branch `release/2.0` holds the release commit and tag `v2.0.0`

#### Scenario: Branch already exists
- **WHEN** the target `release/<major>.<minor>` branch or the target tag already exists on the remote
- **THEN** the run fails before pushing anything and says to merge or remove the existing release first

### Requirement: Version bumps return by pull request
After a release from a branch other than the default branch, the workflow SHALL open a pull request into the default branch that carries the version bump, titled with the version. The pull request SHALL be opened only after the atomic push succeeded.

#### Scenario: Pull request opened
- **WHEN** a minor release has been pushed to `release/1.9`
- **THEN** a pull request from `release/1.9` into `master` is open and names version `1.9.0`

#### Scenario: Pull request cannot be created
- **WHEN** the repository forbids Actions from creating pull requests
- **THEN** the release itself stays published and the log shows the exact command to open the pull request by hand

### Requirement: Patch releases keep their branch
A patch release SHALL stay on the dispatched ref. On the default branch it behaves as before. On a `release/*` branch it also opens a pull request into the default branch.

#### Scenario: Patch on master
- **WHEN** a patch release is run on `master`
- **THEN** the release commit and tag land on `master` and no branch or pull request is created

#### Scenario: Patch on a release branch
- **WHEN** a patch release is run on `release/1.9`
- **THEN** the commit and tag land on `release/1.9` and a pull request into `master` is opened

### Requirement: Release atomicity is preserved
Branch creation, bump commit and tag SHALL reach the remote in one atomic push after all verification and build steps passed, as the existing release requirement demands.

#### Scenario: Verification fails
- **WHEN** any check or the package build fails during a minor release
- **THEN** no `release/*` branch, commit or tag exists on the remote
