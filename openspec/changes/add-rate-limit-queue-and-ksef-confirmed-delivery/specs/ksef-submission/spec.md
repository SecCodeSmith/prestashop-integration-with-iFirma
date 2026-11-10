# Spec Delta

## MODIFIED Requirements

### Requirement: Automatic submission in the pipeline
The issuing pipeline SHALL run a `ksef` step between the `number` and `deliver` steps. The step SHALL record one outcome on the receipt: `skipped` with a reason, `ok`, or `failed`. The step SHALL NOT block delivery or change the issuance outcome. Before any submission call, the step SHALL read the document's current KSeF state from iFirma and SHALL submit only when that state is empty; with submitter `ifirma` it SHALL make no submission call.

#### Scenario: B2B invoice is submitted
- **WHEN** an invoice is issued, the mode is `b2b`, the submitter is `module`, the buyer has a NIP and iFirma reports no KSeF state for the document
- **THEN** the module calls the iFirma KSeF send endpoint with `DataWysylki` null
- **AND** when iFirma answers `Kod` 0 the receipt records `ksef_status` `WYSLANA` and `ksef_sent_at`

#### Scenario: Private buyer is skipped in B2B mode
- **WHEN** an invoice is issued, the mode is `b2b` and the buyer has no NIP
- **THEN** no submission call is made
- **AND** the step is recorded as skipped with the reason "private buyer (B2B mode)"

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
- **AND** the step is recorded as skipped with the reason "type not supported"

#### Scenario: Mode off at issuance
- **WHEN** an invoice is issued and the mode is `off`
- **THEN** the step is recorded as skipped with the reason "KSeF disabled"

#### Scenario: Already sent by iFirma
- **WHEN** an invoice for a buyer with a NIP is issued, the submitter is `module`, and iFirma already reports `KsefStatus` `WYSLANA` for it
- **THEN** no submission call is made
- **AND** the receipt stores the reported KSeF state and number
- **AND** the step is recorded as skipped with the reason "already sent by iFirma"

#### Scenario: Submitter iFirma
- **WHEN** an invoice for a buyer with a NIP is issued and the submitter is `ifirma`
- **THEN** no submission call is made
- **AND** the step is recorded as skipped with the reason "sent by iFirma"

#### Scenario: KSeF state cannot be read
- **WHEN** the read of the document's KSeF state fails or is refused by the rate limit
- **THEN** no submission call is made and `ksef_status` stays empty
- **AND** the step is recorded as skipped with the reason "KSeF state unavailable, retried by the cron"

### Requirement: Manual submission
The admin panel SHALL offer a **Send to KSeF** action for an issued document. The action SHALL submit the document regardless of the configured mode and submitter. It SHALL NOT be offered or accepted for a document whose KSeF state is accepted. Before submitting, the action SHALL read the document's current KSeF state from iFirma and SHALL refuse when iFirma reports that the document was sent, scheduled or accepted.

#### Scenario: Manual send overrides the mode
- **WHEN** the mode is `off` or `b2b` and the admin clicks **Send to KSeF** on a B2B-ineligible document
- **THEN** the module submits the document
- **AND** the receipt records the outcome of that attempt

#### Scenario: Accepted document is never re-sent
- **WHEN** the receipt shows an accepted KSeF state with a KSeF number
- **THEN** the **Send to KSeF** action is not offered
- **AND** a request to send it anyway is refused with a message

#### Scenario: Document already sent by iFirma
- **WHEN** the admin clicks **Send to KSeF** and iFirma reports `KsefStatus` `ZAPLANOWANA` for the document
- **THEN** no submission call is made
- **AND** the admin sees that iFirma has already scheduled the submission, and the receipt stores the reported state

#### Scenario: Resubmission after a rejection
- **WHEN** iFirma reports `KsefStatus` `ODRZUCONA` for the document and the admin clicks **Send to KSeF**
- **THEN** the module submits the document and records the outcome

#### Scenario: State read fails
- **WHEN** the admin clicks **Send to KSeF** and the read of the document's KSeF state fails
- **THEN** no submission call is made and the admin sees the error

### Requirement: Status visibility
The admin order panel SHALL show the KSeF state as exactly one of five readable states: not sent (with the reason), waiting for iFirma (submitter `ifirma`, no state reported yet), sent and awaiting acceptance (sent by the module with `ksef_sent_at`, or reported by iFirma with its status), accepted (with the KSeF number), or error (`BLAD` or `ODRZUCONA`, with the message). The customer order page SHALL show the KSeF number when one is present and SHALL NOT show any KSeF error text.

#### Scenario: Not sent with reason
- **WHEN** the step was skipped with a reason
- **THEN** the admin row shows "not sent" and the reason

#### Scenario: Waiting for iFirma
- **WHEN** the submitter is `ifirma`, the document goes to KSeF under the mode, and no KSeF state is stored
- **THEN** the admin row shows that the module waits for iFirma to send the document

#### Scenario: Sent and awaiting iFirma
- **WHEN** the receipt has `ksef_status` `WYSLANA` and no KSeF number
- **THEN** the admin row shows "sent at" with `ksef_sent_at` and states that iFirma has not yet confirmed

#### Scenario: Sent by iFirma
- **WHEN** the receipt has `ksef_status` `ZAPLANOWANA` reported by iFirma and no `ksef_sent_at`
- **THEN** the admin row shows that iFirma sent or scheduled the document, with the status as reported

#### Scenario: Accepted with number
- **WHEN** the receipt has a KSeF number and an accepted status
- **THEN** the admin row shows "accepted" with the KSeF number

#### Scenario: Error state
- **WHEN** the receipt has `ksef_status` `BLAD`
- **THEN** the admin row shows "error" with the stored message

#### Scenario: Rejected by KSeF
- **WHEN** the receipt has `ksef_status` `ODRZUCONA`
- **THEN** the admin row shows "error" and states that KSeF rejected the document

#### Scenario: Customer sees the number only
- **WHEN** the customer opens the order page and the receipt has a KSeF number
- **THEN** the page shows the KSeF number
- **AND** a stored KSeF error is not shown

## ADDED Requirements

### Requirement: Submitter setting
The module SHALL provide the setting `IFIRMA_KSEF_SUBMITTER` with the values `module` (default) and `ifirma`, and SHALL normalise any other stored value to `module` when it is read and when it is saved. The mode keeps deciding which documents go to KSeF; the submitter decides who submits them.

#### Scenario: Default submitter
- **WHEN** the module is installed, or a shop running 1.6.0 is upgraded
- **THEN** the submitter is `module`

#### Scenario: Unknown value normalised
- **WHEN** the stored value is `both`
- **THEN** the module reads it as `module`

#### Scenario: Mode off ignores the submitter
- **WHEN** the mode is `off` and the submitter is `ifirma`
- **THEN** no document is treated as going to KSeF

### Requirement: Deferred submission retried by the cron
With submitter `module`, the cron SHALL submit, oldest first and at most `IFIRMA_CRON_MAX_ITEMS` per run, every issued document that goes to KSeF under the mode, has an empty `ksef_status` and is awaiting KSeF acceptance. Each submission SHALL first read the document's KSeF state, as the pipeline step does.

#### Scenario: Submission deferred by the rate limit
- **WHEN** the KSeF state read of an invoice was refused during issuance and the cron runs with budget
- **THEN** the cron reads the state and, if it is empty, submits the invoice once

#### Scenario: iFirma sent it in the meantime
- **WHEN** the cron finds that iFirma now reports `KsefStatus` `WYSLANA` for a deferred invoice
- **THEN** no submission call is made and the reported state is stored

#### Scenario: Submitter iFirma
- **WHEN** the submitter is `ifirma`
- **THEN** the cron makes no KSeF submission call
