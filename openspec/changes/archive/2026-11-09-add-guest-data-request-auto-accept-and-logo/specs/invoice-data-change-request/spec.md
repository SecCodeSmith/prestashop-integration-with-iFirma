# Spec Delta

## MODIFIED Requirements

### Requirement: Eligibility
A data-change request SHALL be accepted only when the data-change feature is enabled, the requester is the order's customer (a logged-in customer who owns the order, or a guest-verified session for that order whose requester is the order's own customer record), the order has no issued or adopted document, and no other pending request exists for the order; otherwise the form SHALL NOT be offered and a direct submission SHALL be refused with a clear reason.

#### Scenario: Eligible customer
- **WHEN** the feature is enabled and the logged-in owner opens an order with no document and no pending request
- **THEN** the form is offered and a valid submission is accepted

#### Scenario: Eligible verified guest
- **WHEN** the feature is enabled and a visitor with a verified guest session for order 42 opens the guest form, and order 42 has no document and no pending request
- **THEN** the form is offered and a valid submission is accepted and stored with order 42's customer as the creator

#### Scenario: Feature disabled
- **WHEN** the data-change feature switch (IFIRMA_ALLOW_DATA_REQUEST) is off and a customer submits a request directly
- **THEN** the submission is refused with a reason stating that changes are not available

#### Scenario: Order of another customer
- **WHEN** a logged-in customer submits a request for an order owned by someone else
- **THEN** the submission is refused and nothing is stored

#### Scenario: Guest without a verified session
- **WHEN** a visitor posts the guest form for order 42 without a verified session for that order
- **THEN** the submission is refused, nothing is stored and the identification step is shown

#### Scenario: Pending request already exists
- **WHEN** the order already has a pending request and the customer submits another
- **THEN** the submission is refused with a reason pointing to the existing request

#### Scenario: Document already exists
- **WHEN** the order has an issued or adopted document and the customer submits a request
- **THEN** the submission is refused with a reason stating that the document has already been issued

### Requirement: Submission creates a pending request
A valid submission SHALL store a request containing the requested data, the note, the customer, the order and the timestamp, in status "pending" unless the auto-accept criteria approve it, SHALL show the customer a confirmation, and SHALL send an e-mail to the merchant notification address containing the order reference, the requested data, the differences versus the order's current invoice address and, for an automatic approval, a line saying the request was approved automatically.

#### Scenario: Successful submission
- **WHEN** an eligible customer submits valid data and the auto-accept mode is `none`
- **THEN** a pending request is stored with the data, note, customer, order and timestamp, and the order page shows a confirmation

#### Scenario: Guest confirmation
- **WHEN** a verified guest submits valid data
- **THEN** the request is stored and the guest page shows a confirmation with the order reference, without any link that requires a login

#### Scenario: Merchant notification
- **WHEN** a request is stored and the notification address is configured
- **THEN** that address receives an e-mail with the order reference, the requested data and the differences from the current invoice address

#### Scenario: Merchant notification of an automatic approval
- **WHEN** a request is approved automatically
- **THEN** the merchant e-mail additionally states that the request was approved automatically and names the mode (`safe` or `all`)

#### Scenario: Default notification address
- **WHEN** no notification address is configured
- **THEN** the e-mail is sent to the shop e-mail address

### Requirement: Customer cancels a request
The customer SHALL be able to cancel their own pending request from the order page or, for a verified guest, from the guest page, after which the request SHALL be "cancelled", the hold SHALL be released and the merchant SHALL NOT be required to act.

#### Scenario: Cancel own pending request
- **WHEN** the owner cancels a pending request with a valid token
- **THEN** the request status becomes "cancelled" and the order no longer shows the held state

#### Scenario: Guest cancels
- **WHEN** a visitor with a verified guest session for the order cancels its pending request with a valid token
- **THEN** the request status becomes "cancelled" and the hold is released

#### Scenario: Cancel a resolved request
- **WHEN** the customer attempts to cancel a request that is already approved, rejected or cancelled
- **THEN** the action is refused and the request status is unchanged

#### Scenario: Another customer's request
- **WHEN** a customer attempts to cancel a request that belongs to a different customer
- **THEN** the action is refused and the request stays pending

### Requirement: Audit trail
Every request SHALL keep who created it, who resolved it (an employee, or the system for an automatic approval with the mode that applied), when, the original data, the final approved data when edited, and the admin note, and these SHALL be visible in the admin order panel.

#### Scenario: Resolved request with edits
- **WHEN** an admin opens the order panel for an order with an approved and edited request
- **THEN** the panel shows the creator, the resolving employee, the creation and resolution times, the original data, the approved data and the admin note

#### Scenario: Automatically approved request
- **WHEN** an admin opens the order panel for an order whose request was approved automatically
- **THEN** the panel shows the request as approved, the resolver as "automatic (safe fields)" or "automatic (all)", the resolution time and the differences that were accepted

#### Scenario: Cancelled request
- **WHEN** a customer cancelled a request
- **THEN** the panel shows the request as cancelled with its creation and cancellation times

## ADDED Requirements

### Requirement: Auto-accept setting
The configuration page SHALL offer an auto-accept mode for data-change requests with the values `none` (every request waits for an employee), `safe` (requests that keep the buyer identity are approved automatically) and `all` (every valid request is approved automatically); the default SHALL be `none` on install and upgrade, an unknown posted value SHALL be stored as `none`, and the setting SHALL be removed on uninstall.

#### Scenario: Default after upgrade
- **WHEN** the module is upgraded from a version without the setting
- **THEN** the mode is `none` and requests behave as before

#### Scenario: Unknown value normalised
- **WHEN** the posted mode is not one of the three values
- **THEN** `none` is stored

#### Scenario: Help text
- **WHEN** the admin opens the configuration page
- **THEN** the mode's help text explains which fields `safe` lets through and that `all` skips the review entirely

### Requirement: Safe-field criteria
In mode `safe` a valid request SHALL be approved automatically only when, compared with the order's current invoice address: the company/private choice is unchanged (the address counts as a company when it has a company name or a VAT number), the normalised NIP is unchanged (both empty counts as unchanged) and the company name is unchanged; the first name, last name, address lines, postcode, city and country may differ. Any other difference SHALL leave the request pending.

#### Scenario: Postcode and street fix
- **WHEN** the mode is `safe` and a private person's request changes only the street and the postcode
- **THEN** the request is approved automatically

#### Scenario: Country change
- **WHEN** the mode is `safe` and the request changes only the country
- **THEN** the request is approved automatically

#### Scenario: NIP added
- **WHEN** the mode is `safe` and the current address has no VAT number while the request is a company request with a NIP
- **THEN** the request stays pending for the employee

#### Scenario: NIP changed
- **WHEN** the mode is `safe` and the request carries a NIP that differs from the current address's normalised NIP
- **THEN** the request stays pending

#### Scenario: Company name changed
- **WHEN** the mode is `safe` and the request keeps the NIP but changes the company name
- **THEN** the request stays pending

#### Scenario: Company to private
- **WHEN** the mode is `safe` and the current address is a company while the request is for a private person
- **THEN** the request stays pending

#### Scenario: Separator-only NIP difference
- **WHEN** the current VAT number is `525-000-12-34` and the request carries `5250001234`
- **THEN** the NIP counts as unchanged

#### Scenario: Mode all
- **WHEN** the mode is `all` and a valid request changes the NIP
- **THEN** the request is approved automatically

### Requirement: Automatic approval outcome
An automatically approved request SHALL be stored as "approved" with the requested data as the approved data, no resolving employee, the mode that applied, and the resolution time; the customer SHALL receive the approval e-mail; and the issuance SHALL resume exactly as after an admin approval (immediately when automatic issuance is enabled and the trigger status was already reached).

#### Scenario: Approved and resumed
- **WHEN** automatic issuance is enabled, the order history contains the trigger status and a request is approved automatically
- **THEN** the request is "approved" with no employee and the mode recorded, the customer receives the approval e-mail and the document is created with the approved data as the buyer

#### Scenario: Trigger not yet reached
- **WHEN** a request is approved automatically and the order has not reached the trigger status
- **THEN** no document is created until the trigger status is reached or an admin uses Create, and the order page shows no held state

#### Scenario: Issuance failure after automatic approval
- **WHEN** the resumed issuance fails
- **THEN** the request stays "approved", the failure is recorded on the receipt record as for any issuance, and the customer page shows no error text

### Requirement: Guest request form
A visitor with a verified guest session for an order SHALL get the same data-change form as a logged-in owner: the same fields pre-filled from the order's invoice address, the same validation and field-level messages, the same CSRF token, the same cool-down and daily cap (counted on the order's customer record), and the same confirmation; the form SHALL show only the order reference and the invoice address fields, never other order data.

#### Scenario: Pre-filled form
- **WHEN** the verified guest opens the form of order 42
- **THEN** the fields hold order 42's current invoice address and the page shows the reference and nothing else about the order

#### Scenario: Validation identical
- **WHEN** the verified guest selects "company" and leaves the NIP empty
- **THEN** the submission is rejected with the same field-level message as on the account form

#### Scenario: Cool-down shared with the account form
- **WHEN** the order's customer submitted a request two minutes ago from the account form and the verified guest submits another
- **THEN** the submission is refused with the wait message

### Requirement: Guest entry points
The module's block on the guest order-tracking page SHALL show the document state in the same wording as the account order page and, while a request is possible, a link to the guest request page pre-filled with the order reference; it SHALL offer no download link there. The account order page and the e-mails SHALL be unchanged.

#### Scenario: Guest tracking without a document
- **WHEN** a guest opens the tracking page of an order without a document and without a pending request
- **THEN** the block shows the not-issued state and links to the guest request page with the reference pre-filled

#### Scenario: Guest tracking with a pending request
- **WHEN** the order has a pending request
- **THEN** the block shows the held state and links to the guest page, where the request can be cancelled after verification

#### Scenario: Guest tracking with an issued document
- **WHEN** the order has an issued document
- **THEN** the block shows the issued state with the document number, no download link and the contact-the-shop message

### Requirement: Serialised submission
The eligibility check, the saving of the pending request and its automatic approval SHALL run under one database lock per order, released on every exit, so parallel submissions cannot all pass the pending, cool-down and daily-cap checks. A submission that cannot get the lock within 2 seconds SHALL be refused with a message asking the customer to wait a moment.

#### Scenario: Parallel submissions
- **WHEN** several submissions for the same order arrive at the same moment
- **THEN** they are checked and stored one after the other, the first is stored, the others are refused as pending, and the mails go out after the lock is released

#### Scenario: Submission lock not granted
- **WHEN** the order lock of the submission is not granted within 2 seconds
- **THEN** the submission is refused with the "another request is being processed" message and nothing is stored or mailed

### Requirement: Serialised resume of a held issuance
Resuming a held issuance after a resolved request SHALL run under a database lock per order and SHALL look the document up again under that lock, so parallel resumes cannot issue the document twice.

#### Scenario: Parallel resume
- **WHEN** a second resume gets the order lock after the first resume issued the document
- **THEN** it finds the issued document under the lock and creates nothing

#### Scenario: Resume lock not granted
- **WHEN** the resume lock is not granted within 5 seconds
- **THEN** nothing is issued and the event is logged

### Requirement: Form protection of the account form
The account data-change form SHALL carry the same per-visitor form token as the guest page and SHALL change state on POST only.

#### Scenario: Token of another visitor on the account form
- **WHEN** the account form is posted with a token derived from another visitor's cookie
- **THEN** it is refused with the token error and no request is stored

#### Scenario: Account form over GET
- **WHEN** a GET request carries a valid token and every field of the form
- **THEN** no request is stored or cancelled

### Requirement: Text encoding of the request
Every text field of a data-change request SHALL be valid UTF-8; a field that is not SHALL be refused with the field-level message, and a request whose data cannot be encoded SHALL never be stored.

#### Scenario: Bytes that are not UTF-8
- **WHEN** a field of the submitted form holds bytes that are not valid UTF-8
- **THEN** the submission is refused with the message "This value contains characters that are not allowed." on that field and nothing is stored

### Requirement: Guest tracking shows the document number only
For an issued document the block on the guest order-tracking page SHALL show the document number and nothing else about the document: no KSeF number, issue date, payment or delivery details and no download link.

#### Scenario: Issued document on the guest page
- **WHEN** a guest opens the tracking page of an order whose document has a KSeF number, is paid and was sent
- **THEN** the block shows the issued state and the document number, and none of those details
