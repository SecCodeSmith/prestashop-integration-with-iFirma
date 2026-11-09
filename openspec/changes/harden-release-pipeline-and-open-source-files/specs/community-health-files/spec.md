# Spec Delta

## Purpose

Gives the source-available repository the governance, contribution, security-reporting and support documents visitors and GitHub expect, and states honestly what the licence does and does not allow.

## ADDED Requirements

### Requirement: Code of conduct
The repository SHALL contain a code of conduct based on the Contributor Covenant, with a named contact for reports and a statement of what is enforced and how.

#### Scenario: Visitor looks for conduct rules
- **WHEN** a visitor opens the repository
- **THEN** `CODE_OF_CONDUCT.md` is present at the root and GitHub lists it in the community profile

### Requirement: Contribution guide
The repository SHALL contain a contribution guide that explains the test-first workflow, the OpenSpec change process, the commit conventions and the licence terms contributions are made under.

#### Scenario: Contributor wants to send a fix
- **WHEN** a contributor reads `CONTRIBUTING.md`
- **THEN** it tells them how to run the tests, that a failing test must come first, how to propose a change and that contributions fall under the repository licence

### Requirement: Private vulnerability reporting policy
The repository SHALL contain a security policy that names the supported versions, a private reporting channel, the expected response times and the rule that vulnerabilities are not reported in public issues.

#### Scenario: Researcher finds a flaw
- **WHEN** a researcher opens `SECURITY.md`
- **THEN** it gives a private channel and the first-response time and says not to open a public issue

### Requirement: Support and changelog
The repository SHALL contain a support document stating where to ask questions and what is supported, and a changelog in Keep a Changelog form that covers every released version.

#### Scenario: Version released
- **WHEN** a release is prepared
- **THEN** `CHANGELOG.md` has a section for that version with its date

### Requirement: Issue and pull request templates
The repository SHALL provide a bug report form, a feature request form, a configuration that sends security reports to the policy, a pull request template with the definition-of-done checklist and a code owners file.

#### Scenario: User files a bug
- **WHEN** a user opens a new issue
- **THEN** they must choose a form that asks for module, PrestaShop and PHP versions and offers no field for secrets

### Requirement: Licence status is stated honestly
The README SHALL state that the project is source-available under the SecCodeSmith Commercial License and not open source in the OSI sense, with who may use it free of charge and who needs a paid licence.

#### Scenario: Reader checks the licence
- **WHEN** a reader opens the README
- **THEN** a licence section states the SMB and non-commercial free tier, the Enterprise requirement and links to `LICENCE`

### Requirement: Community files stay out of the module package
The files of this capability SHALL NOT be part of the installable zip or the npm tarball.

#### Scenario: Package built
- **WHEN** a package is built and verified
- **THEN** the package check fails if the zip contains any of these files
