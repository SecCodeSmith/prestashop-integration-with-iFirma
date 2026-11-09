# Spec Delta

## MODIFIED Requirements

### Requirement: Trigger on configured status
When an order status changes to the configured trigger status (one status; value 0 means disabled) and the module has credentials configured, the module SHALL first record the order as queued and SHALL then run the issuance pipeline for that order exactly once per status change, under the order's lock; when the lock is busy the order SHALL stay queued for the cron endpoint.

#### Scenario: Trigger status reached
- **WHEN** an order changes to the configured trigger status
- **THEN** the order is recorded as queued and the issuance pipeline runs once for that order

#### Scenario: Trigger disabled
- **WHEN** the trigger status setting is 0
- **THEN** no pipeline runs on status changes

#### Scenario: Other status
- **WHEN** an order changes to a status other than the trigger status
- **THEN** no pipeline runs

#### Scenario: Credentials missing
- **WHEN** the order reaches the trigger status but credentials are not configured
- **THEN** no request is sent to iFirma

#### Scenario: Another run holds the order
- **WHEN** an order changes to the trigger status while another run holds its lock
- **THEN** no pipeline runs in this status change and the order stays queued

### Requirement: No blind retry after unknown outcome
If the create call returns no interpretable response (timeout, transport error, or HTTP error without body), the receipt record SHALL be marked "unknown" with the error, and any later attempt, automatic or manual, SHALL pass through the duplicate guard before creating. The record SHALL be marked "unknown" before the create request is sent, so a request that ends while the create is in flight also leaves "unknown".

#### Scenario: Timeout on create
- **WHEN** the create request times out
- **THEN** the receipt status is "unknown" and the error is recorded
- **AND** no second create request is sent in the same run

#### Scenario: Later attempt finds the document
- **WHEN** a later attempt runs and the guard finds the document iFirma created
- **THEN** the document is adopted and no new one is created

#### Scenario: Later attempt finds nothing
- **WHEN** a later attempt runs and the guard finds no candidates
- **THEN** a new document is created

#### Scenario: Request ends while the create is in flight
- **WHEN** the shop request ends (timeout or fatal error) after the create request was sent and before its answer was stored
- **THEN** the record is "unknown" with the message that iFirma has not answered the create request yet
- **AND** the cron does not issue it as a queued order

#### Scenario: Answer stored over the marker
- **WHEN** iFirma answers the create request with a document
- **THEN** the record is issued and the in-flight message is gone
