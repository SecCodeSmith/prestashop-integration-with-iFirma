# Spec Delta

## Purpose

Keeps the scheduled end-to-end smoke run over every supported PrestaShop version trustworthy: each job tests the version it names, and a failed run leaves enough evidence to find the failing check.

## ADDED Requirements

### Requirement: Each matrix job tests the version it names
The smoke workflow SHALL start, for each entry of its version matrix, a shop of exactly that PrestaShop version, and SHALL fail the job before any scenario runs when the running shop reports another version. The test stack SHALL take the image tag from the workflow and SHALL default to a pinned tag, never `latest`.

#### Scenario: Matrix tag used
- **WHEN** the job for `8.1.7-apache` starts its stack
- **THEN** the shop container runs the image tagged `8.1.7-apache` and the shop reports version 8.1.7

#### Scenario: Stack ignores the tag
- **GIVEN** a test-stack commit whose compose file names a fixed image
- **WHEN** the job for `8.0.5-apache` runs
- **THEN** the job fails in the version check with the expected and the found version, and no scenario runs

#### Scenario: No tag given
- **WHEN** the stack is started without an image tag
- **THEN** it uses the pinned default tag and the pinned-image check passes

### Requirement: Scenarios start on a finished install
The workflow SHALL start the scenarios only after the shop's first-boot install has finished and the shop answers its front page with a success status. A shop that does not get there within the wait limit SHALL fail the job with the shop's log, and no scenario SHALL run.

#### Scenario: Install finished
- **WHEN** the shop's install has completed and the front page answers 200
- **THEN** the scenarios start

#### Scenario: Install failed
- **WHEN** the first-boot install reports a failure
- **THEN** the job fails in the wait step and prints the shop log

#### Scenario: Timeout
- **WHEN** the shop is not ready after the wait limit
- **THEN** the job fails and names the limit

### Requirement: The scheduled run covers every scenario but the burst
The scheduled run SHALL execute every smoke scenario listed in the smoke README except the burst scenario R, including scenarios whose name has more than one character. A scenario named in the selection that does not exist, or an existing scenario that was neither selected nor excluded on purpose, SHALL fail the job.

#### Scenario: Two-character scenario runs
- **WHEN** the scheduled run executes
- **THEN** scenario A2 runs together with A to Q and S

#### Scenario: Burst left out on purpose
- **WHEN** the scheduled run executes
- **THEN** scenario R does not run and the job summary says so

#### Scenario: New scenario not selected
- **GIVEN** a scenario T was added to the smoke run and not to the workflow's selection
- **WHEN** the scheduled run executes
- **THEN** the job fails and names scenario T

### Requirement: A failed run keeps its evidence
The workflow SHALL keep the full output of the smoke run for every job, passed or failed, as a downloadable artifact, and SHALL list the failed checks and the totals in the job summary. The kept output SHALL contain no secret: keys and tokens SHALL be masked.

#### Scenario: Failed check listed
- **WHEN** one check of scenario F fails in the job for `9.0.3-apache`
- **THEN** that job's summary shows the failed check with its detail and the totals of passed, failed and skipped checks
- **AND** the full output is attached to the run as an artifact named after the version

#### Scenario: Passed run
- **WHEN** every check passes
- **THEN** the summary shows the totals and the artifact is still attached

#### Scenario: No secret in the output
- **WHEN** the smoke output is kept
- **THEN** it contains no API key, cron token or download token in full

### Requirement: A red run is fixed at its cause
A failing smoke check SHALL be resolved by correcting the defect it found in the module, the test stack or the workflow. A check, scenario or matrix entry SHALL NOT be removed, skipped, loosened or made to pass unconditionally to obtain a green run. A check whose expectation was wrong SHALL be corrected only together with the specification scenario that states the right behaviour.

#### Scenario: Module defect found
- **WHEN** a check fails because the module misbehaves on one PrestaShop version
- **THEN** the module is fixed with a unit test that reproduces the defect, and the check stays as it was

#### Scenario: Unsupported version
- **WHEN** the module cannot support a version in the matrix
- **THEN** the declared supported range and the documentation are changed in a reviewed change, and the matrix follows that range

#### Scenario: Check count never drops silently
- **GIVEN** the last green run executed a known number of checks per version
- **WHEN** a later run executes fewer checks
- **THEN** the job fails unless the smoke README was changed in the same commit to say which checks were removed and why
