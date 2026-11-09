# Spec Delta

## Purpose

Keeps the module's installable package in line with the PrestaShop Addons technical validation rules, checking automatically what can be checked and documenting what only the marketplace can confirm.

## ADDED Requirements

### Requirement: Package passes the marketplace structure rules
Every built package SHALL pass an automated check of the structural rules: a single top-level folder named after the module, an `index.php` in every folder, a `config.xml` with name, version, author and tab, a logo, a declared PrestaShop compatibility range and no development, VCS or OS junk files.

#### Scenario: Folder without index file
- **WHEN** a packaged folder has no `index.php`
- **THEN** the check fails and names the folder

#### Scenario: Compatible package
- **WHEN** a package follows all structural rules
- **THEN** the check passes and CI continues to the test build

### Requirement: The package tree contains no symbolic links
The package build SHALL refuse a symbolic link anywhere in the shipped tree, so no file from outside the module can enter the published package.

#### Scenario: Link to a file outside the module
- **WHEN** a shipped folder contains a symbolic link
- **THEN** the build fails, names the link and writes no package

### Requirement: Shipped PHP follows the marketplace code rules
Every shipped PHP file other than an `index.php` SHALL start with the PrestaShop `_PS_VERSION_` guard unless it is a translation file, and the shipped code SHALL contain no `eval`, no `base64_decode` of code, no debug output (`var_dump`, `print_r`, `die`) and no `@` error suppression.

#### Scenario: Missing guard
- **WHEN** a shipped class file lacks the `_PS_VERSION_` guard
- **THEN** the check fails and names the file

#### Scenario: Error suppression
- **WHEN** shipped code prefixes a call with `@`
- **THEN** the check fails and names the file and line

### Requirement: Module is self-contained
The module SHALL NOT download code, templates or other executable content after installation, and any outbound network call SHALL go only to the iFirma API over HTTPS.

#### Scenario: Outbound call inventory
- **WHEN** the readiness check lists network calls in shipped code
- **THEN** every one targets the configured iFirma API host over HTTPS and none fetches executable content

### Requirement: Documentation and support links are reachable
The package SHALL include user documentation, and the module's configuration page SHALL link to that documentation and to the support page.

#### Scenario: Administrator opens configuration
- **WHEN** an administrator opens the module configuration
- **THEN** a documentation link and a support link are shown

### Requirement: Readiness status is documented
`docs/marketplace-readiness.md` SHALL list every marketplace requirement with its state (met, fixed, open, owner action), the evidence, and the manual steps that only the owner can do, namely the official Validator run, the English PDF guide, the product sheet and the licence decision for the marketplace.

#### Scenario: Owner prepares a submission
- **WHEN** the owner opens the document
- **THEN** each open item names who must act and what to do, with no item silently marked met

### Requirement: Release is gated on the readiness check
The release workflow SHALL run the readiness check on the release package before anything is pushed.

#### Scenario: Readiness failure at release time
- **WHEN** the release package fails the readiness check
- **THEN** the release stops with nothing pushed
