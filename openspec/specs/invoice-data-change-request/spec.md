# invoice-data-change-request Specification

## Purpose
Lets a customer ask for corrections to the billing data of an order before the accounting document is issued, and lets the merchant review, apply or reject that request, with the request pausing automatic issuance meanwhile.

## Requirements

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

### Requirement: Request content and validation
The form SHALL collect: invoice for a company (yes/no), company name, VAT/NIP number, first name, last name, address line 1, address line 2, postcode, city, country, and a free-text note. When "company" is yes, company name and NIP SHALL be required and the NIP SHALL be 10 digits after removing separators; postcode and city SHALL be required. First and last name SHALL pass the shop's name rule, the address lines and the city the shop's address and city rules, the postcode the format of the chosen country when it defines one, the country SHALL be an active country of the shop, the note SHALL be at most 1000 characters, and a value posted as an array SHALL count as empty.

#### Scenario: Valid company request
- **WHEN** the customer selects "company", enters a company name, the NIP "525-000-12-34", a postcode and a city
- **THEN** the input is accepted and the NIP is stored as the 10 digits 5250001234

#### Scenario: Company without NIP
- **WHEN** the customer selects "company" and leaves the NIP empty
- **THEN** the submission is rejected with a field-level message on the NIP and nothing is stored

#### Scenario: Malformed NIP
- **WHEN** the customer selects "company" and enters a NIP with 9 digits
- **THEN** the submission is rejected with a field-level message on the NIP and nothing is stored

#### Scenario: Missing postcode or city
- **WHEN** the customer leaves the postcode or the city empty
- **THEN** the submission is rejected with a field-level message on the empty field and nothing is stored

#### Scenario: Private person
- **WHEN** the customer selects "no company" and fills first name, last name, address, postcode and city
- **THEN** the input is accepted without requiring company name or NIP

#### Scenario: Name with markup
- **WHEN** the customer enters the first name `<script>x</script>`
- **THEN** the submission is rejected with a field-level message on the first name and nothing is stored

#### Scenario: Postcode format of the country
- **WHEN** the customer chooses Poland and enters the postcode `ABCDE`
- **THEN** the submission is rejected with a field-level message on the postcode

#### Scenario: Inactive country
- **WHEN** the posted country id is not an active country of the shop
- **THEN** the submission is rejected with a field-level message on the country

#### Scenario: Over-long note
- **WHEN** the note has 1001 characters
- **THEN** the submission is rejected with a field-level message on the note

### Requirement: CSRF protection
The submission and the cancellation actions SHALL require a valid per-session form token, and a request with a missing or wrong token SHALL be refused.

#### Scenario: Missing token on submission
- **WHEN** a submission arrives without the form token
- **THEN** it is refused and nothing is stored

#### Scenario: Wrong token on cancellation
- **WHEN** a cancellation arrives with a token that does not match the session
- **THEN** it is refused and the request stays pending

#### Scenario: Valid token
- **WHEN** a submission or cancellation arrives with the correct session token
- **THEN** the action is processed

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

### Requirement: Pending request holds issuance
While a request is pending for an order, automatic issuance for that order SHALL NOT create a document, and the admin manual Create action SHALL be blocked with a message pointing to the request.

#### Scenario: Trigger status reached during hold
- **WHEN** the order reaches the issuance trigger status while a request is pending
- **THEN** no document is created in iFirma and the hold is recorded on the order

#### Scenario: Manual create during hold
- **WHEN** an admin presses Create for an order with a pending request
- **THEN** no document is created and the message tells the admin to resolve the request first

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

### Requirement: Admin approves a request
From the admin order panel the admin SHALL see the pending request with a comparison against the current invoice address, SHALL be able to edit the requested fields, and approve it; the request SHALL become "approved", the resolving employee and time SHALL be recorded, and the customer SHALL receive an approval e-mail.

#### Scenario: Approve unchanged
- **WHEN** the admin approves a pending request without editing it
- **THEN** the request becomes "approved" with the employee and time recorded and the customer receives an approval e-mail

#### Scenario: Approve with edits
- **WHEN** the admin corrects the postcode and then approves
- **THEN** the corrected data is stored as the approved data and the original requested data is kept

#### Scenario: Invalid edit
- **WHEN** the admin edits the NIP of a company request to fewer than 10 digits and approves
- **THEN** the approval is refused with a field-level message and the request stays pending

### Requirement: Approved data overrides the document payload
When the document for the order is later created, the buyer (`Kontrahent`) SHALL be built from the approved data instead of the order's invoice address: company name and NIP with `OsobaFizyczna` false when a company was requested, otherwise a private person. The override used SHALL be recorded with the document's request payload and the PrestaShop address SHALL NOT be modified.

#### Scenario: Company override
- **WHEN** the approved data is a company with a NIP and the document is created
- **THEN** `Kontrahent` carries the company name and NIP with `OsobaFizyczna` false

#### Scenario: Private person override
- **WHEN** the approved data is a private person and the document is created
- **THEN** `Kontrahent` carries the person's name and address with `OsobaFizyczna` true and no NIP

#### Scenario: Audit and address untouched
- **WHEN** a document is created using an approved override
- **THEN** the stored request payload shows the override that was used and the order's PrestaShop invoice address is unchanged

#### Scenario: No approved request
- **WHEN** the order has no approved request
- **THEN** `Kontrahent` is built from the order's invoice address

### Requirement: Admin rejects a request
The admin SHALL be able to reject a pending request with a required reason; the request SHALL become "rejected", the hold SHALL be released, and the customer SHALL receive an e-mail containing the reason.

#### Scenario: Reject with reason
- **WHEN** the admin rejects a pending request and enters a reason
- **THEN** the request becomes "rejected", the hold is released and the customer receives an e-mail containing the reason

#### Scenario: Reject without reason
- **WHEN** the admin rejects a pending request and leaves the reason empty
- **THEN** the rejection is refused and the request stays pending

### Requirement: Resolution resumes issuance
When a request is approved or rejected, and automatic issuance is enabled and the order's history already contains the trigger status, the issuance pipeline SHALL run immediately; otherwise the next trigger or a manual Create SHALL issue the document.

#### Scenario: Trigger status already reached
- **WHEN** automatic issuance is enabled, the order history contains the trigger status and the admin resolves the request
- **THEN** the issuance pipeline runs immediately for the order

#### Scenario: Trigger status not yet reached
- **WHEN** the order history does not contain the trigger status and the admin resolves the request
- **THEN** no document is created until the trigger status is reached or an admin uses Create

#### Scenario: Automatic issuance disabled
- **WHEN** automatic issuance is disabled and the admin resolves the request
- **THEN** no document is created until an admin uses Create

### Requirement: Requests after issuance
When a document is already issued or adopted for the order, the order page SHALL NOT offer the data-change form and SHALL tell the customer to contact the shop because issued documents cannot be changed through the shop.

#### Scenario: Issued document
- **WHEN** the customer opens an order that has an issued document
- **THEN** no form is offered and the page tells the customer to contact the shop for changes

#### Scenario: Adopted document
- **WHEN** the order's document was adopted from iFirma
- **THEN** the page shows the same contact-the-shop message and no form

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

### Requirement: Submission cool-down
After a customer submits or cancels a request for an order, a new submission for the same order within 10 minutes SHALL be refused with a message naming the wait, and a customer SHALL be able to submit at most ten requests per 24 hours across all orders; a refused submission SHALL store nothing and send no e-mail.

#### Scenario: Resubmit after cancel
- **WHEN** the customer cancels a request and submits a new one for the same order two minutes later
- **THEN** the submission is refused with the wait message, nothing is stored and no merchant e-mail is sent

#### Scenario: Daily cap
- **WHEN** the customer has submitted ten requests in the last 24 hours and submits an eleventh for another order
- **THEN** the submission is refused with a message and no e-mail is sent

#### Scenario: Window over
- **WHEN** the customer submits again for the same order after 10 minutes
- **THEN** the submission is processed as usual

### Requirement: Admin text limits
The admin note of an approval and the reason of a rejection SHALL be at most 1000 characters and SHALL be stored as text; a longer value SHALL be refused with a message and the request SHALL stay pending.

#### Scenario: Over-long rejection reason
- **WHEN** the admin rejects with a 1001-character reason
- **THEN** the rejection is refused with a message and the request stays pending

#### Scenario: Markup in the admin note
- **WHEN** the admin approves with the note `<b>ok</b>`
- **THEN** the note is stored and shown as text everywhere it appears

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
