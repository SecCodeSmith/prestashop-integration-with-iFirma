# Spec Delta

## Purpose

Proves on the Docker stack that the e-mails the module sends actually leave PrestaShop with the right recipients, subject, body, attachment and blind copy, and that a refusing mail server is recorded as a failed delivery without undoing the issued document.

## ADDED Requirements

### Requirement: Smoke run sends real mail through the sink
The module smoke run SHALL configure the shop to send mail over SMTP to the stack's mail sink (server and port overridable through the environment) instead of switching mail off, SHALL empty the sink's mailbox at the start of every scenario, and SHALL stop with the environment exit code when the sink is unreachable.

#### Scenario: Mail settings written for the run
- **WHEN** the smoke run starts
- **THEN** the shop's mail method is SMTP, the server is the sink's host name, the port is the sink's port, encryption is off and the SMTP user is empty

#### Scenario: Sink unreachable
- **WHEN** the mailbox endpoint of the sink does not answer
- **THEN** the run reports which host it tried and exits with the environment code before running any scenario

#### Scenario: Existing delivery check now proves a transaction
- **WHEN** the data-request scenario asserts "delivery recorded as sent"
- **THEN** that assertion passes only if the SMTP transaction with the sink succeeded

### Requirement: Receipt delivery e-mail is verified
After an issuance through the shop-mail channel the smoke run SHALL find exactly one message in the sink and SHALL assert its recipient is the order customer's e-mail, its sender is the shop e-mail, its subject contains the document number, its text and HTML parts contain the document number and the order reference, and it carries exactly one `application/pdf` attachment named after the document number whose content starts with `%PDF`.

#### Scenario: Delivery mail captured
- **WHEN** a document is issued for an order whose customer is `pub@prestashop.com` and the number is `1/2026`
- **THEN** the sink holds one message to `pub@prestashop.com` from the shop e-mail, with `1/2026` in the subject and in both bodies, the order reference in both bodies, and one attachment `ifirma-1-2026.pdf` of type `application/pdf` starting with `%PDF`

#### Scenario: No copy by default
- **WHEN** the copy mode is `none`
- **THEN** the captured message has no `bcc` recipient and the envelope holds the customer address only

### Requirement: Blind copy is verified on the wire
The smoke run SHALL assert the blind copy through the sink's envelope: with copy mode `shop` the shop e-mail is an envelope recipient absent from the `To` header, with a copy address equal to the customer address the envelope holds the customer only, and a manual resend with copy mode `shop` produces a second message with the same blind copy.

#### Scenario: Shop copy on the wire
- **WHEN** the copy mode is `shop`, the shop e-mail differs from the customer e-mail and a document is delivered
- **THEN** the captured message lists the shop e-mail under `bcc` and not under `to`

#### Scenario: Copy equal to the customer
- **WHEN** the copy mode is `shop` and the shop e-mail equals the customer e-mail (ignoring case)
- **THEN** the captured message has one envelope recipient and no `bcc`

#### Scenario: Resend carries the copy
- **WHEN** the admin resend action runs with copy mode `shop`
- **THEN** a second message with the same recipient, subject, attachment name and `bcc` is captured and the receipt's delivery timestamp is renewed

### Requirement: Refusing mail server is recorded as a failed delivery
With the sink's reject switch enabled the smoke run SHALL assert that issuance still succeeds, that the receipt records delivery `failed` with a non-empty error through the shop-mail channel, that no message was captured, and that a resend after the switch is cleared records `sent` and clears the error.

#### Scenario: Refusal during issuance
- **WHEN** the reject switch is enabled and a document is issued
- **THEN** the receipt is issued with its number, `delivery_status` is `failed`, `delivery_error` is not empty and the sink holds no message

#### Scenario: Recovery by resend
- **WHEN** the switch is cleared and the admin resend action runs
- **THEN** `delivery_status` is `sent`, `delivery_error` is empty and the sink holds one message

### Requirement: Data-request e-mails are verified
The smoke run SHALL assert the merchant notification sent at submission (to the configured notification address or the shop e-mail, subject with the order reference, body naming the requested company) and the customer mails sent on approval and on rejection (to the customer, subjects for the approved and rejected decisions, body naming the order reference).

#### Scenario: Merchant notified at submission
- **WHEN** a customer submits a data-change request with company `Smoke Sp. z o.o.`
- **THEN** the sink holds a message to the merchant address whose subject contains the order reference and whose body contains `Smoke Sp. z o.o.`

#### Scenario: Customer told about approval
- **WHEN** the admin approves the request
- **THEN** the sink holds a message to the customer with the approved subject and the order reference in the body, in addition to the delivery mail of the issued document

#### Scenario: Customer told about rejection
- **WHEN** the admin rejects a second request with a reason
- **THEN** the sink holds a message to the customer with the rejected subject, the order reference and the reason in the body

### Requirement: Scenario documented and runnable on its own
The smoke README SHALL list the e-mail scenario with what it asserts, the environment overrides for the sink host and port, and the fact that the shop keeps sending through the sink after a run; the scenario SHALL be selectable alone through the existing scenario filter.

#### Scenario: Run only the e-mail scenario
- **WHEN** the run is started with the scenario filter set to the e-mail scenario's letter
- **THEN** only that scenario runs and the summary counts only its assertions

#### Scenario: Reader finds the sink
- **WHEN** a developer reads the smoke README after a run
- **THEN** it says where captured mail can be inspected and how to point the shop back at another mail method
