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
The scheduled run SHALL execute every scenario the smoke runner defines except those skipped on purpose with the skip list (the burst scenario R), including scenarios whose name has more than one character. A name in the selection or in the skip list that the runner does not define SHALL stop the run before the shop is touched. A scenario that has no entry in the committed check-floor file SHALL fail the run.

#### Scenario: Two-character scenario runs
- **WHEN** the scheduled run executes
- **THEN** scenario A2 runs together with every other scenario except R

#### Scenario: Burst left out on purpose
- **WHEN** the scheduled run executes
- **THEN** scenario R does not run and the job summary says so

#### Scenario: New scenario runs without being listed in the workflow
- **GIVEN** a scenario T was added to the smoke runner and to the check-floor file
- **WHEN** the scheduled run executes
- **THEN** T runs, because the run selects every scenario that is not skipped on purpose

#### Scenario: Unknown name in the selection or the skip list
- **GIVEN** the skip list names a scenario the runner does not define
- **WHEN** the run starts
- **THEN** it stops with exit status 2 before any shop setting is changed and names the unknown scenario

#### Scenario: New scenario without a floor entry
- **GIVEN** a scenario T was added to the smoke runner and has no entry in the check-floor file
- **WHEN** the scheduled run executes
- **THEN** the run fails with a failed check that names scenario T

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

#### Scenario: Module defect found (review rule)
- **WHEN** a change makes a red run green because a check found a module defect
- **THEN** the change fixes the module, together with a unit test that reproduces the defect, and contains no removed, skipped or loosened check
- **AND** the check floor and the review verify that no check was dropped

#### Scenario: Unsupported version (review rule)
- **WHEN** the module cannot support a version in the matrix
- **THEN** the declared supported range and the documentation are changed in a reviewed change, and the matrix follows that range

#### Scenario: Check count never drops silently
- **GIVEN** the committed check-floor file holds a minimum of executed checks for each scenario
- **WHEN** a run executes fewer checks in a scenario than its floor
- **THEN** the run fails with a failed check that names the scenario, the executed count and the floor

#### Scenario: Floor file missing or malformed
- **WHEN** the check-floor file is missing, or is not a JSON object of non-negative whole numbers
- **THEN** the run fails, so a run whose check count is not verified is never green

#### Scenario: Lowering the floor is a reviewed change
- **WHEN** a scenario's floor is lowered
- **THEN** that is a visible change of the committed check-floor file, which the review has to accept
- **AND** a run with a failed check never writes the file
