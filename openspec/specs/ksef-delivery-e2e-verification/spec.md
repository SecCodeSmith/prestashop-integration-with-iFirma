# ksef-delivery-e2e-verification Specification

## Purpose
End-to-end proof, on the Docker test stack against the iFirma mock and its SMTP sink, that the module never submits an invoice to KSeF twice and hands a KSeF invoice to the customer only after KSeF accepted it.

## Requirements

### Requirement: Module submission and delivery after acceptance
The smoke run SHALL have a scenario S. In it, with KSeF mode `b2b`, submitter `module` and the mock's `ksef-auto` switch `off`, an invoice for a buyer with a NIP is issued. The scenario SHALL check that the invoice is submitted once and e-mailed only after the mock accepts it.

#### Scenario: Submitted once and held
- **WHEN** the invoice is issued
- **THEN** the mock reports `ksef_send_calls` 1 for it
- **AND** the record shows delivery `awaiting_ksef` and the SMTP sink holds no e-mail for the order

#### Scenario: Delivered after acceptance
- **WHEN** the mock accepts the invoice and the cron runs
- **THEN** the record shows the KSeF number and delivery `sent`
- **AND** the SMTP sink holds exactly one e-mail for the order

### Requirement: iFirma's own submission is detected
Scenario S SHALL issue an invoice for a buyer with a NIP while the mock's `ksef-auto` switch is `send`, with submitter `module`. The module SHALL NOT submit that invoice and SHALL still wait for acceptance.

#### Scenario: No second submission
- **WHEN** the invoice is issued
- **THEN** the mock reports `ksef_send_calls` 0 for it
- **AND** the record shows the KSeF state reported by the mock and delivery `awaiting_ksef`

#### Scenario: Delivered after iFirma's submission is accepted
- **WHEN** the mock accepts the invoice and the cron runs
- **THEN** exactly one e-mail for the order is in the SMTP sink

### Requirement: Submitter iFirma
Scenario S SHALL issue an invoice for a buyer with a NIP with submitter `ifirma` while the mock's `ksef-auto` switch is `off`, as when iFirma sends at a scheduled hour. The module SHALL make no KSeF send call and SHALL deliver only after acceptance.

#### Scenario: Waiting for iFirma
- **WHEN** the invoice is issued and the cron runs before iFirma sent it
- **THEN** the mock reports `ksef_send_calls` 0, the record shows delivery `awaiting_ksef` and the SMTP sink holds no e-mail for the order

#### Scenario: Accepted later
- **WHEN** the mock accepts the invoice and the cron runs
- **THEN** exactly one e-mail for the order is in the SMTP sink

### Requirement: Rejection and documents outside KSeF
Scenario S SHALL check that a rejected invoice is never delivered automatically, that it is reported as needing attention, and that documents that do not go to KSeF are delivered at issuance.

#### Scenario: Rejected invoice
- **WHEN** the mock rejects an awaiting invoice and the cron runs
- **THEN** the SMTP sink holds no e-mail for the order and the record is listed as needing attention

#### Scenario: Receipt and private buyer delivered at once
- **WHEN** a receipt and an invoice for a buyer without a NIP are issued in mode `b2b`
- **THEN** both are e-mailed at issuance and the mock reports `ksef_send_calls` 0 for both

#### Scenario: Customer download held
- **WHEN** the owner of an awaiting invoice requests its PDF download with a valid token
- **THEN** the answer is "not found"
- **AND** after acceptance the same request returns the PDF
