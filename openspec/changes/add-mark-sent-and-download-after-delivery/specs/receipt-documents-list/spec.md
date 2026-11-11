# Spec Delta

## ADDED Requirements

### Requirement: Bulk mark as sent
The Documents list SHALL offer the bulk action "Mark as sent" for the selected documents. It SHALL mark each issued document that is not yet recorded as sent, SHALL skip every other selected document with a reason, and SHALL send no request to iFirma, so the request limit and the authentication pause SHALL NOT stop it. Its run summary and audit entry SHALL follow the rules of the other bulk actions.

#### Scenario: Mixed selection
- **GIVEN** a selection of two documents whose delivery failed, one document already recorded as sent and one order without an issued document
- **WHEN** an employee with the manage level runs "Mark as sent"
- **THEN** the two failed documents are recorded as sent through the channel "manual"
- **AND** the summary reports 2 marked and 2 skipped, each skipped one with its reason

#### Scenario: Request limit reached
- **GIVEN** the module's request limit is reached
- **WHEN** the bulk "Mark as sent" runs for three documents
- **THEN** all three are marked, no request is sent to iFirma and the run is not stopped

#### Scenario: Audit entry
- **WHEN** a bulk "Mark as sent" finishes
- **THEN** the log has one entry with the employee's id and the counts of marked and skipped documents
