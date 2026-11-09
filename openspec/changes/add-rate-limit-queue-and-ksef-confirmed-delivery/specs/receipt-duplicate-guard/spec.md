# Spec Delta

## MODIFIED Requirements

### Requirement: Inconclusive guard
If the list query fails because of network error or authentication error, automatic issuance SHALL abort and record the error, and manual issuance SHALL display the error and offer Issue anyway. If the list query is refused by the rate limit, automatic and manual issuance SHALL abort and record the order as queued instead of recording an error, and manual issuance SHALL also display the rate-limit message without offering Issue anyway.

#### Scenario: Automatic issuance
- **WHEN** the list query fails because of a network or authentication error during status-triggered issuance
- **THEN** no document is created
- **AND** the error is recorded on the receipt record

#### Scenario: Manual issuance
- **WHEN** the list query fails because of a network or authentication error during an admin Create action
- **THEN** the error is displayed to the admin
- **AND** an Issue anyway action is offered

#### Scenario: Automatic issuance refused by the rate limit
- **WHEN** the list query is refused by the rate limit during status-triggered issuance
- **THEN** no document is created
- **AND** the order's record is queued with the refusal message and is not in state `error`

#### Scenario: Manual issuance refused by the rate limit
- **WHEN** the list query is refused by the rate limit during an admin Create action
- **THEN** the rate-limit message is displayed to the admin
- **AND** the order's record is queued, so the cron endpoint issues it later
- **AND** Issue anyway is not offered, because bypassing the guard would not get past the limit
