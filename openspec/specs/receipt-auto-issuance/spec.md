# receipt-auto-issuance Specification

## Purpose
Issue an iFirma document when an order reaches the merchant-chosen status, including hold conditions, the fixed pipeline order, failure recording and the rule that a create request is never blindly retried.

## Requirements

### Requirement: Trigger on configured status
When an order status changes to the configured trigger status (one status; value 0 means disabled) and the module has credentials configured, the module SHALL run the issuance pipeline for that order exactly once per status change.

#### Scenario: Trigger status reached
- **WHEN** an order changes to the configured trigger status
- **THEN** the issuance pipeline runs once for that order

#### Scenario: Trigger disabled
- **WHEN** the trigger status setting is 0
- **THEN** no pipeline runs on status changes

#### Scenario: Other status
- **WHEN** an order changes to a status other than the trigger status
- **THEN** no pipeline runs

#### Scenario: Credentials missing
- **WHEN** the order reaches the trigger status but credentials are not configured
- **THEN** no request is sent to iFirma

### Requirement: Pipeline order and recording
The pipeline SHALL execute hold check, duplicate guard, create, number lookup and delivery in that order and SHALL record the outcome of each step on the order receipt record (status, source, last error, delivery fields).

#### Scenario: Successful run
- **WHEN** all steps succeed
- **THEN** the steps run in the stated order
- **AND** the receipt record holds the document number, source and delivery outcome

#### Scenario: Step fails
- **WHEN** a step fails
- **THEN** later steps do not run
- **AND** the failing outcome is stored as last error on the receipt record

### Requirement: Hold on pending data-change request
When the order has a pending customer data-change request, the pipeline SHALL stop before the duplicate guard, record a hold reason naming the request and issue nothing; when that request is resolved and the order history already contains the trigger status, the pipeline SHALL run automatically.

#### Scenario: Pending request at trigger
- **WHEN** the trigger status is reached while request 7 is pending
- **THEN** no iFirma request is sent
- **AND** the recorded hold reason names request 7

#### Scenario: Request resolved after trigger
- **WHEN** the pending request is resolved and the order history contains the trigger status
- **THEN** the pipeline runs without further admin action

#### Scenario: Request resolved before trigger
- **WHEN** the request is resolved and the order history does not contain the trigger status
- **THEN** the pipeline does not run

### Requirement: No blind retry after unknown outcome
If the create call returns no interpretable response (timeout, transport error, or HTTP error without body), the receipt record SHALL be marked "unknown" with the error, and any later attempt, automatic or manual, SHALL pass through the duplicate guard before creating.

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

### Requirement: Validation-error retry
When iFirma rejects the create with `Kod` 201 and the message names `DodatkoweUwagi`, the module SHALL retry once without that object, keeping the reference in `Uwagi`; any other rejection SHALL be recorded as an error without retry.

#### Scenario: DodatkoweUwagi rejected
- **WHEN** iFirma answers `Kod` 201 naming `DodatkoweUwagi`
- **THEN** the module resends the request once without `DodatkoweUwagi`
- **AND** `Uwagi` still contains the order reference

#### Scenario: Other rejection
- **WHEN** iFirma answers `Kod` 200 or a `Kod` 201 not naming `DodatkoweUwagi`
- **THEN** the error is recorded and no retry is made

#### Scenario: Retry also fails
- **WHEN** the single retry is rejected
- **THEN** the error is recorded and no further retry is made

### Requirement: Failures never break the order status change
Any exception in the pipeline SHALL be logged and recorded on the receipt record, and the PrestaShop status update SHALL complete normally.

#### Scenario: Exception in pipeline
- **WHEN** the pipeline throws during a status change
- **THEN** the exception is logged and recorded on the receipt record
- **AND** the order status change is saved

#### Scenario: iFirma unreachable
- **WHEN** iFirma cannot be reached
- **THEN** the order status change is saved and the error is recorded

### Requirement: Manual issuance uses the same pipeline
The admin Create action SHALL run the identical pipeline (hold, guard, create, number, deliver) and report the step that stopped it.

#### Scenario: Manual create succeeds
- **WHEN** the admin clicks Create on an order with no document
- **THEN** all pipeline steps run in order and the admin sees the issued number

#### Scenario: Manual create held
- **WHEN** the admin clicks Create while a data-change request is pending
- **THEN** nothing is issued
- **AND** the admin is told the hold step stopped the run

#### Scenario: Manual create blocked by guard
- **WHEN** the guard marks a suspected duplicate
- **THEN** the admin is told the guard step stopped the run

### Requirement: Document type selection
The created document type SHALL follow the merchant setting, `fakturaparagon` (default) or `fakturakraj`, and unknown values SHALL fall back to `fakturaparagon`.

#### Scenario: Default type
- **WHEN** no document type is configured
- **THEN** the document is created as `fakturaparagon`

#### Scenario: Invoice type configured
- **WHEN** the setting is `fakturakraj`
- **THEN** the document is created as `fakturakraj`

#### Scenario: Unknown value
- **WHEN** the setting holds an unrecognised value
- **THEN** the document is created as `fakturaparagon`

### Requirement: Payment registration unchanged
The admin SHALL be able to register the paid amount on an issued document through the existing "Update status in iFirma" action, including for adopted external documents.

#### Scenario: Module-created document
- **WHEN** the admin runs Update status in iFirma on an issued document
- **THEN** the paid amount is registered in iFirma and the status becomes paid

#### Scenario: Adopted external document
- **WHEN** the admin runs the action on an adopted external document
- **THEN** the paid amount is registered the same way

#### Scenario: No issued document
- **WHEN** the order has no issued document
- **THEN** the action is refused with an error message

### Requirement: Payment method follows the order
The document's `SposobZaplaty` SHALL be derived from the order's payment method when the payment-method mode is "order" (default): the merchant map by payment module name first, then the built-in module map, then keywords in the order's payment label, then the configured fallback code; in mode "fixed" the configured code SHALL always be used.

#### Scenario: Built-in module mapping
- **WHEN** an order was paid with the `ps_cashondelivery` module and mode is "order"
- **THEN** the document is created with `SposobZaplaty` = `POB`

#### Scenario: Merchant map overrides the built-in map
- **WHEN** the merchant map contains `ps_checkpayment=PRZ` and the order was paid with `ps_checkpayment`
- **THEN** the document is created with `SposobZaplaty` = `PRZ` instead of the built-in `CZK`

#### Scenario: Label keyword fallback
- **WHEN** the payment module is unknown to both maps and the order payment label contains "PayPal"
- **THEN** the document is created with `SposobZaplaty` = `PAL`

#### Scenario: Nothing matches
- **WHEN** neither the module nor the label can be mapped
- **THEN** the configured fallback payment method is used

#### Scenario: Fixed mode
- **WHEN** the payment-method mode is "fixed" and the configured code is `GTK`
- **THEN** every document is created with `SposobZaplaty` = `GTK` regardless of the order

### Requirement: Payment method settings validation
The configuration page SHALL offer every iFirma payment code (GTK, POB, PRZ, KAR, PZA, CZK, KOM, BAR, DOT, PAL, ALG, P24, TPA, ELE) for the fallback code, a mode selector, and a merchant map of `module=CODE` lines; a line with an unknown code or malformed module name SHALL be rejected with a message naming the line and nothing SHALL be saved.

#### Scenario: Valid map saved
- **WHEN** the merchant saves `ps_checkpayment=PRZ` and `mygateway*=ELE`
- **THEN** both lines are stored and applied to later documents

#### Scenario: Invalid code rejected
- **WHEN** the merchant saves a map line `ps_checkpayment=XYZ`
- **THEN** the form shows an error naming line 1 and no setting is changed
