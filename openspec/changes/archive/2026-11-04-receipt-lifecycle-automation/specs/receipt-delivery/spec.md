# Spec Delta

## Purpose

Delivering an issued iFirma document to the customer by e-mail through the merchant-configured channel, recording the delivery state, and letting the admin resend.

## ADDED Requirements

### Requirement: Delivery follows issuance
After a document is created by the module and the delivery channel is not "none", the module SHALL attempt delivery to the order customer's e-mail as the last step of the issuing pipeline and SHALL record the outcome (sent, failed or skipped), channel, timestamp and error on the receipt record.

#### Scenario: Successful delivery after issuance
- **WHEN** the module creates a document and the channel is shop mail
- **THEN** the customer is e-mailed immediately after creation
- **AND** the receipt record shows delivery "sent" with the channel and a timestamp

#### Scenario: Delivery is the last step
- **WHEN** the pipeline finishes creating a document
- **THEN** delivery runs after the document number has been stored

### Requirement: Shop-mail channel
With the shop-mail channel (default), the module SHALL fetch the document PDF from iFirma and send it as an attachment through the shop's mail system, in the order's language, from the shop's sender, using the "receipt issued" template with the document number and order reference.

#### Scenario: Mail content
- **WHEN** delivery runs through shop mail for an order placed in Polish
- **THEN** the e-mail is in Polish, sent from the shop's sender address to the order customer
- **AND** the body contains the document number and the order reference
- **AND** the document PDF is attached

#### Scenario: PDF cannot be fetched
- **WHEN** the PDF download from iFirma fails
- **THEN** no e-mail is sent and delivery is recorded as failed with the error

### Requirement: iFirma-mail channel
When the merchant selects iFirma mail and the document type is one that iFirma documents for its send endpoint (`fakturakraj` and the other listed types, not `fakturaparagon`), the module SHALL call `POST {type}/send/{id}.json` with the customer e-mail as `SkrzynkaEmailOdbiorcy` and the configured `Tekst` (max 1000 characters); only `Kod` 0 SHALL count as success.

#### Scenario: iFirma send succeeds
- **WHEN** the channel is iFirma mail, the type is `fakturakraj` and iFirma answers `Kod` 0
- **THEN** delivery is recorded as sent through the iFirma channel

#### Scenario: iFirma send returns an error
- **WHEN** iFirma answers with a non-zero `Kod` or no parseable response
- **THEN** delivery is recorded as failed with the mapped error message

#### Scenario: Long message text
- **WHEN** the configured `Tekst` exceeds 1000 characters
- **THEN** the module sends only the first 1000 characters

### Requirement: Fallback for unsupported types
When iFirma mail is selected but the document type is not supported by the send endpoint, the module SHALL deliver through shop mail and SHALL record the channel as a fallback so the admin can see it.

#### Scenario: Receipt-type document with iFirma mail selected
- **WHEN** the channel is iFirma mail and the document type is `fakturaparagon`
- **THEN** the document is delivered through shop mail
- **AND** the receipt record shows the channel as shop mail fallback

### Requirement: Delivery failure does not undo issuance
A failed delivery SHALL leave the document issued, SHALL record the failure and error on the receipt record, SHALL show it in the admin panel, and SHALL NOT show the error text to the customer.

#### Scenario: Mail system failure
- **WHEN** the shop mail system fails to send
- **THEN** the document stays issued and downloadable
- **AND** the admin panel shows delivery "failed" with the error text

#### Scenario: Customer view after failure
- **WHEN** the customer opens the order page after a failed delivery
- **THEN** the page shows the document as issued without any error text

### Requirement: Manual resend
The admin SHALL be able to trigger delivery again at any time for an issued document, including adopted external documents, through the currently configured channel, and each attempt SHALL update the recorded delivery state.

#### Scenario: Resend after failure
- **WHEN** the admin clicks resend on a document whose delivery failed and the attempt succeeds
- **THEN** the recorded state becomes "sent" with a new timestamp and the old error is cleared

#### Scenario: Resend of an adopted document
- **WHEN** the admin resends a document adopted from iFirma
- **THEN** it is delivered through the configured channel and the state is updated

#### Scenario: Resend with channel none
- **WHEN** the channel is "none" and the admin triggers resend
- **THEN** nothing is sent and the admin is told that delivery is disabled

### Requirement: Delivery disabled
When the delivery channel is "none", the pipeline SHALL record delivery as skipped and SHALL send nothing.

#### Scenario: Channel none at issuance
- **WHEN** the module creates a document and the channel is "none"
- **THEN** no e-mail and no iFirma send call are made
- **AND** the receipt record shows delivery "skipped"

### Requirement: Adopted external documents are not auto-delivered
A document adopted from iFirma that was created by another source SHALL be recorded as delivery skipped with reason "external", unless the admin resends it manually.

#### Scenario: Adoption of an existing document
- **WHEN** the module adopts an existing iFirma document for an order
- **THEN** no e-mail is sent to the customer
- **AND** delivery is recorded as skipped with reason "external"

### Requirement: Configuration warning about customer e-mails
The configuration page SHALL warn that with the default channel documents start being e-mailed to customers on the next trigger, and SHALL let the merchant select "none".

#### Scenario: Warning displayed
- **WHEN** the admin opens the configuration page
- **THEN** a warning states that documents will be e-mailed to customers on the next trigger with the default channel
- **AND** the channel selector offers shop mail, iFirma mail and none

#### Scenario: Merchant disables delivery
- **WHEN** the admin selects "none" and saves
- **THEN** subsequent issuance records delivery as skipped
