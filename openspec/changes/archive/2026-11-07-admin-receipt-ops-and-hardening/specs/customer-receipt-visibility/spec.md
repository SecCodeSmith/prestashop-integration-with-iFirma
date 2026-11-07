# Spec Delta

## ADDED Requirements

### Requirement: Download throttle
The customer PDF download SHALL fetch a given document from iFirma at most once per 30 seconds and at most ten documents per minute per customer; a request inside either window SHALL be answered with HTTP 429 and a "try again shortly" message and SHALL make no iFirma request.

#### Scenario: Repeated download of one document
- **WHEN** the owner downloads a document and requests it again 5 seconds later
- **THEN** the second response is HTTP 429 with the retry message and no iFirma request is made

#### Scenario: Window over
- **WHEN** the owner requests the document again after 30 seconds
- **THEN** the PDF is fetched and returned

#### Scenario: Loop over many documents
- **WHEN** a customer with 30 documents requests 15 different ones within a minute
- **THEN** the first ten are fetched and the others answer HTTP 429

### Requirement: Download token generation
The per-document download token SHALL come from the cryptographically secure random generator only; when that generator is unavailable the record SHALL be stored without a token, the customer download SHALL NOT be offered for it, and the failure SHALL be logged, instead of storing a weaker token.

#### Scenario: Generator available
- **WHEN** a document is created
- **THEN** its token is 40 hexadecimal characters from the secure generator

#### Scenario: Generator unavailable
- **WHEN** the secure generator throws while a document is created
- **THEN** the record is stored without a token, the customer pages show the document without a download link, and the log names the failure
