# Spec Delta

## Purpose

Gives every GitHub Release a short, human-readable summary of what the version contains, written from the commits since the previous release, without ever blocking or endangering the release.

## ADDED Requirements

### Requirement: Release carries an AI summary
A published release SHALL include a short summary of the version, generated from the commit subjects between the previous release tag and the release commit, shown above the auto-generated notes.

#### Scenario: Summary generated
- **WHEN** a release is published and the summary service answers
- **THEN** the release body starts with a "Summary" section of at most 120 words
- **AND** the auto-generated notes follow it unchanged

#### Scenario: First release without a previous tag
- **WHEN** no earlier release tag exists
- **THEN** the summary is built from all commits up to the release commit

### Requirement: Summary never blocks a release
A failure of the summary step (service unavailable, quota, empty or invalid answer, timeout) SHALL NOT fail or delay the release beyond a fixed timeout. The release SHALL then carry the plain list of commit subjects instead.

#### Scenario: Service unavailable
- **WHEN** the summary service returns an error or times out
- **THEN** the release is still published
- **AND** its body contains the commit subjects in place of the summary and the workflow log names the reason

#### Scenario: No commits since the last tag
- **WHEN** there are no commits between the previous tag and the release commit
- **THEN** no request is sent and the release body has no summary section

### Requirement: Summary input is limited and untrusted
Only commit subjects SHALL be sent to the service, never file contents, diffs, author e-mail addresses or secrets. Commit text SHALL be treated as untrusted: the answer is published as plain text only and never executed or interpreted as workflow commands.

#### Scenario: Subject contains instructions
- **WHEN** a commit subject tells the model to ignore its instructions or to emit workflow commands
- **THEN** the published summary is escaped plain text and no workflow command or extra release asset results

#### Scenario: Large history
- **WHEN** more subjects exist than the input limit allows
- **THEN** the newest subjects up to the limit are sent and the body notes that the list was truncated
