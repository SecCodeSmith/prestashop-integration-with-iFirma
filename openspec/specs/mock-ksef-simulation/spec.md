# mock-ksef-simulation Specification

## Purpose
Letting the iFirma mock behave like an iFirma account that may submit invoices to KSeF on its own, and exposing the KSeF state and the number of KSeF send calls per document, so the module's no-double-submission check and its delivery after KSeF acceptance can be tested end to end.

## Requirements

### Requirement: KSeF fields on the document detail
The document detail answer (`GET /iapi/{type}/{id}.json`) SHALL carry `KsefStatus` and `KsefNumer` with the same values the invoice list reports for that document, as empty strings when the document has no KSeF state.

#### Scenario: Detail of a submitted invoice
- **WHEN** invoice 7 was submitted through the KSeF send endpoint
- **THEN** its detail answers `KsefStatus` `WYSLANA` and `KsefNumer` ""

#### Scenario: Detail of an accepted invoice
- **WHEN** invoice 7 was accepted through `POST /__mock/ksef/accept/7`
- **THEN** its detail answers `KsefStatus` `PRZYJETA` and the same `KsefNumer` as the invoice list

#### Scenario: Detail of a document without KSeF state
- **WHEN** a receipt (`paragon`) is read through the detail endpoint
- **THEN** `KsefStatus` and `KsefNumer` are empty strings

### Requirement: Account-level KSeF auto-send switch
The mock SHALL have a `ksef-auto` switch with the modes `off` (default), `send` and `accept`. `POST /__mock/ksef-auto` with `{"mode": "<mode>"}` SHALL set it and `GET /__mock/ksef-auto` SHALL report it. While the mode is `send`, every newly created document of a KSeF-capable type whose buyer has a NIP SHALL be stored with `KsefStatus` `WYSLANA`. While the mode is `accept`, it SHALL be stored with `PRZYJETA` and a generated KSeF number. `POST /__mock/reset` SHALL set the mode back to `off`.

#### Scenario: iFirma sends on its own
- **WHEN** the mode is `send` and the module creates a domestic invoice for a buyer with a NIP
- **THEN** the new invoice reports `KsefStatus` `WYSLANA` in its detail and in the list, without any KSeF send call

#### Scenario: iFirma sends and KSeF accepts at once
- **WHEN** the mode is `accept` and the module creates a domestic invoice for a buyer with a NIP
- **THEN** the new invoice reports `KsefStatus` `PRZYJETA` and a KSeF number

#### Scenario: Private buyer and receipts untouched
- **WHEN** the mode is `send` and the module creates a receipt or an invoice for a buyer without a NIP
- **THEN** the new document has no KSeF state

#### Scenario: Invalid mode refused
- **WHEN** the body names a mode other than `off`, `send` or `accept`
- **THEN** the answer is 422 and the mode does not change

#### Scenario: Test endpoints disabled
- **WHEN** `IFIRMA_MOCK_TEST_ENDPOINTS` is `false`
- **THEN** both `ksef-auto` routes answer 404

### Requirement: KSeF send calls counted per document
The mock SHALL count, per document, every authenticated KSeF send call that names it and is not refused by the rate limit, whether the call succeeds or is refused because the document is already in KSeF. The count SHALL be reported as `ksef_send_calls` in the documents of `/__mock/status.json` and on the status page.

#### Scenario: One submission
- **WHEN** the module submits invoice 7 once
- **THEN** invoice 7 reports `ksef_send_calls` 1

#### Scenario: Second submission refused and counted
- **WHEN** invoice 7 already has `KsefStatus` `WYSLANA` and a second send call names it
- **THEN** the call is answered with a non-zero `Kod` saying the invoice is already in KSeF
- **AND** invoice 7 reports `ksef_send_calls` 2

#### Scenario: Scheduled invoice refused
- **WHEN** invoice 7 has `KsefStatus` `ZAPLANOWANA` and a send call names it
- **THEN** the call is answered with a non-zero `Kod` saying the invoice is already in KSeF
