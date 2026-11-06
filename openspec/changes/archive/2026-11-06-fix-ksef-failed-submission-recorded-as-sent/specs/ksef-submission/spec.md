# Spec Delta

## ADDED Requirements

### Requirement: A submission is recorded as sent only when iFirma confirms it
The module SHALL record a KSeF submission as sent (`ksef_status` `WYSLANA`, `ksef_sent_at` stamped) only when iFirma answers the send request with `Kod` 0. Every other outcome, including an error raised during the call, SHALL be recorded as a failure (`ksef_status` `BLAD`) with a non-empty `ksef_error`, for automatic and manual submission alike. A failure without a message of its own SHALL store "iFirma did not confirm the KSeF submission".

#### Scenario: Error without a message
- **WHEN** the send request to iFirma fails with an error whose message is empty
- **THEN** the receipt records `ksef_status` `BLAD` and `ksef_error` "iFirma did not confirm the KSeF submission"
- **AND** `ksef_sent_at` is not stamped
- **AND** the submission is reported as failed with that message

#### Scenario: Error with a whitespace-only message
- **WHEN** the send request fails with an error whose message is only whitespace
- **THEN** the receipt records `ksef_status` `BLAD` and `ksef_error` "iFirma did not confirm the KSeF submission"

#### Scenario: Error with a message
- **WHEN** the send request fails with an error whose message is "connection reset"
- **THEN** the receipt records `ksef_status` `BLAD` and `ksef_error` "connection reset"

#### Scenario: Pipeline step with an unexplained failure
- **WHEN** an invoice is issued and its automatic submission fails with an error that has no message
- **THEN** the `ksef` step is recorded as failed and the result message contains "KSeF: iFirma did not confirm the KSeF submission"
- **AND** delivery still runs and the document outcome stays `issued`
