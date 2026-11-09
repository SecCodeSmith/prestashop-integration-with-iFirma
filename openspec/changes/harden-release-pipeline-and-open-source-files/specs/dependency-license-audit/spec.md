# Spec Delta

## Purpose

Keeps an up-to-date inventory of every third-party library, tool and bundled asset with its licence, and stops copyleft (GNU GPL family) code from entering the shipped module or conflicting with the project's licence.

## ADDED Requirements

### Requirement: Licence inventory of all dependencies
The repository SHALL contain a generated inventory of every dependency in every manifest (PHP, Node, Python, container images) and every bundled asset (fonts, images, scripts), each with name, version, licence identifier and whether it ships in the module package.

#### Scenario: Inventory requested
- **WHEN** the inventory is generated
- **THEN** each dependency of `composer.lock`, `package-lock.json` and the mock's requirements appears with an SPDX licence identifier and a shipped yes/no flag

#### Scenario: Dependency without licence data
- **WHEN** a dependency declares no licence
- **THEN** it is listed as "unknown" and the check fails until the owner records the licence by hand

### Requirement: Copyleft is blocked in the shipped module
The installable module package SHALL contain no code or asset under GPL, AGPL or LGPL terms. CI SHALL fail when a dependency that ships, or is bundled into the package, carries such a licence.

#### Scenario: GPL library added to the package
- **WHEN** a pull request makes a GPL-licensed library part of the shipped files
- **THEN** the licence check fails and names the library and licence

#### Scenario: Development-only dependency
- **WHEN** a GPL tool is used only for development or CI and does not ship
- **THEN** the check reports it as a warning with its role and does not fail

### Requirement: Licences are compatible with the project licence
Every shipped dependency licence SHALL be on an allow-list of permissive licences (for example MIT, BSD, Apache-2.0, ISC) that permit redistribution inside a proprietary-licensed, source-available module. Any other licence SHALL fail the check until the owner decides.

#### Scenario: Unlisted licence
- **WHEN** a shipped dependency uses a licence not on the allow-list
- **THEN** the check fails and asks for an owner decision recorded in the documentation

### Requirement: Third-party notices are documented
The documentation SHALL list third-party components with their licences and keep their required notices, and the document SHALL state which components are development-only, which run only in the test stack, and which are only pulled as container images.

#### Scenario: Dependency added or changed
- **WHEN** a manifest changes
- **THEN** CI fails until the inventory and `docs/third-party-licenses.md` match the manifests

### Requirement: Licence audit runs in CI and on release
The licence check SHALL run on pull requests, on the weekly security schedule and in the release workflow before anything is pushed.

#### Scenario: Release with a new copyleft dependency
- **WHEN** a release is started and the audit finds a copyleft licence in a shipped component
- **THEN** the release stops with nothing pushed
