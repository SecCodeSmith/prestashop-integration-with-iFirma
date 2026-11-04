# ksef-submission Specification

## Purpose
Submitting issued iFirma invoices to the National e-Invoicing System (KSeF) through the iFirma send endpoint, automatically for the configured invoice set and manually from the admin panel, and showing the resulting KSeF state to the merchant and the customer.

## Requirements

### Requirement: Submission mode
The module SHALL provide the setting `IFIRMA_KSEF_MODE` with the values `b2b` (default), `all` and `off`, and SHALL normalise any other stored value to `b2b` when it is read and when it is saved.

#### Scenario: Default mode
- **WHEN** the merchant has not changed the setting
- **THEN** the mode is `b2b`
- **AND** only invoices whose buyer has a NIP are submitted automatically

#### Scenario: All invoices mode
- **WHEN** the mode is `all`
- **THEN** every issued invoice is submitted automatically, including invoices to private buyers

#### Scenario: Off mode
- **WHEN** the mode is `off`
- **THEN** no invoice is submitted automatically
- **AND** the manual **Send to KSeF** action remains available

### Requirement: Automatic submission in the pipeline
The issuing pipeline SHALL run a `ksef` step between the `number` and `deliver` steps. The step SHALL record one outcome on the receipt: `skipped` with a reason, `ok`, or `failed`. The step SHALL NOT block delivery or change the issuance outcome.

#### Scenario: B2B invoice is submitted
- **WHEN** an invoice is issued, the mode is `b2b` and the buyer has a NIP
- **THEN** the module calls the iFirma KSeF send endpoint with `DataWysylki` null
- **AND** when iFirma answers `Kod` 0 the receipt records `ksef_status` `WYSLANA` and `ksef_sent_at`

#### Scenario: Private buyer is skipped in B2B mode
- **WHEN** an invoice is issued, the mode is `b2b` and the buyer has no NIP
- **THEN** no submission call is made
- **AND** the step is recorded as skipped with the reason "private buyer"

#### Scenario: Submission failure is non-blocking
- **WHEN** an invoice is issued and the KSeF submission fails
- **THEN** delivery still runs
- **AND** the document outcome stays `issued`
- **AND** the result message contains "KSeF: <error>"
- **AND** the receipt records `ksef_status` `BLAD` and stores `ksef_error`

#### Scenario: Already accepted invoice is skipped
- **WHEN** the pipeline reaches the `ksef` step for a document whose receipt already shows a KSeF number or an accepted status
- **THEN** no submission call is made
- **AND** the step is recorded as skipped with the reason "already in KSeF"

#### Scenario: Unsupported type is skipped
- **WHEN** the document type does not support the KSeF send endpoint
- **THEN** no submission call is made
- **AND** the step is recorded as skipped with the reason "unsupported type"

#### Scenario: Mode off at issuance
- **WHEN** an invoice is issued and the mode is `off`
- **THEN** the step is recorded as skipped with the reason "disabled"

### Requirement: Manual submission
The admin panel SHALL offer a **Send to KSeF** action for an issued document. The action SHALL submit the document regardless of the configured mode, and SHALL NOT be offered or accepted for a document whose KSeF state is accepted.

#### Scenario: Manual send overrides the mode
- **WHEN** the mode is `off` or `b2b` and the admin clicks **Send to KSeF** on a B2B-ineligible document
- **THEN** the module submits the document
- **AND** the receipt records the outcome of that attempt

#### Scenario: Accepted document is never re-sent
- **WHEN** the receipt shows an accepted KSeF state with a KSeF number
- **THEN** the **Send to KSeF** action is not offered
- **AND** a request to send it anyway is refused with a message

### Requirement: Status visibility
The admin order panel SHALL show the KSeF state as exactly one of four readable states: not sent (with the reason), sent at (awaiting iFirma), accepted (with the KSeF number), or error (with the message). The customer order page SHALL show the KSeF number when one is present and SHALL NOT show any KSeF error text.

#### Scenario: Not sent with reason
- **WHEN** the step was skipped with a reason
- **THEN** the admin row shows "not sent" and the reason

#### Scenario: Sent and awaiting iFirma
- **WHEN** the receipt has `ksef_status` `WYSLANA` and no KSeF number
- **THEN** the admin row shows "sent at" with `ksef_sent_at` and states that iFirma has not yet confirmed

#### Scenario: Accepted with number
- **WHEN** the receipt has a KSeF number and an accepted status
- **THEN** the admin row shows "accepted" with the KSeF number

#### Scenario: Error state
- **WHEN** the receipt has `ksef_status` `BLAD`
- **THEN** the admin row shows "error" with the stored message

#### Scenario: Customer sees the number only
- **WHEN** the customer opens the order page and the receipt has a KSeF number
- **THEN** the page shows the KSeF number
- **AND** a stored KSeF error is not shown

### Requirement: Status synchronisation
Status synchronisation SHALL keep the `WYSLANA` marker until iFirma reports a non-empty `KsefStatus`. It SHALL store `KsefNumer` whenever iFirma returns a non-empty value.

#### Scenario: Empty status keeps the marker
- **WHEN** the receipt has `ksef_status` `WYSLANA` and the list returns an empty `KsefStatus`
- **THEN** `ksef_status` remains `WYSLANA`

#### Scenario: Accepted status and number overwrite the marker
- **WHEN** the list returns `KsefStatus` `PRZYJETA` and a `KsefNumer`
- **THEN** `ksef_status` becomes `PRZYJETA`
- **AND** the KSeF number is stored on the receipt

#### Scenario: Number without status
- **WHEN** the list returns a `KsefNumer` with an empty `KsefStatus`
- **THEN** the KSeF number is stored
- **AND** `ksef_status` is not changed

### Requirement: Schema columns
The module SHALL have the columns `ksef_sent_at` (DATETIME NULL) and `ksef_error` (TEXT NULL) on the `ifirma_receipt` table. Each column SHALL be added at most once: the lazy schema check SHALL add a missing column after consulting `information_schema.COLUMNS`, and the 1.1.0 upgrade script SHALL add it only when it is still missing.

#### Scenario: Column added once
- **WHEN** the schema check runs and `ksef_sent_at` is missing
- **THEN** the column is added
- **AND** a second run of the check issues no `ALTER TABLE` for that column

#### Scenario: Upgrade after lazy addition
- **WHEN** the 1.1.0 upgrade script runs on a table where the lazy check has already added both columns
- **THEN** the script adds no columns and reports no error
