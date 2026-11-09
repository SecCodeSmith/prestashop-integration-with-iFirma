# Spec Delta

## MODIFIED Requirements

### Requirement: Issue events
A module record SHALL count as an issue while its current state is one of: issuance failed (`error`), unknown outcome, suspected duplicate, held by a pending data-change request, queued for issuance for more than 30 minutes, delivery failed, KSeF submission failed or rejected (`BLAD` or `ODRZUCONA`), awaiting KSeF acceptance for more than 48 hours after issuance, or document missing in iFirma. A record SHALL leave the list as soon as none of these applies.

#### Scenario: Failed issuance listed
- **WHEN** the pipeline records state `error` for order 42
- **THEN** the tab lists an issue for order 42 naming the failed issuance

#### Scenario: Delivered document not listed
- **WHEN** a record is issued, delivered and has no failed KSeF submission
- **THEN** the tab lists nothing for it

#### Scenario: Repaired record disappears
- **WHEN** the failed record of order 42 is later issued successfully
- **THEN** the issue for order 42 is no longer listed

#### Scenario: Stale queue listed
- **WHEN** order 42 has been queued for 31 minutes
- **THEN** the tab lists an issue for order 42 naming the queued issuance

#### Scenario: Rejected by KSeF listed
- **WHEN** status sync stores `ODRZUCONA` for the invoice of order 42
- **THEN** the tab lists an issue for order 42 naming the KSeF rejection

#### Scenario: Overdue KSeF wait listed
- **WHEN** the invoice of order 42 was issued 49 hours ago and still awaits KSeF acceptance
- **THEN** the tab lists an issue for order 42 naming the overdue KSeF confirmation

#### Scenario: Normal KSeF wait not listed
- **WHEN** the invoice of order 42 was issued 2 hours ago and awaits KSeF acceptance
- **THEN** the tab lists nothing for it
