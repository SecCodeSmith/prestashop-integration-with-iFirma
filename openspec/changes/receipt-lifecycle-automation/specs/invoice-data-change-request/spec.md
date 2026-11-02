# Spec Delta

## Purpose

Lets a customer ask for corrections to the billing data of an order before the accounting document is issued, and lets the merchant review, apply or reject that request, with the request pausing automatic issuance meanwhile.

## ADDED Requirements

### Requirement: Eligibility
A data-change request SHALL be accepted only when the data-change feature is enabled, the customer is logged in and owns the order, the order has no issued or adopted document, and no other pending request exists for the order; otherwise the form SHALL NOT be offered and a direct submission SHALL be refused with a clear reason.

#### Scenario: Eligible customer
- **WHEN** the feature is enabled and the logged-in owner opens an order with no document and no pending request
- **THEN** the form is offered and a valid submission is accepted

#### Scenario: Feature disabled
- **WHEN** the data-change feature switch (IFIRMA_ALLOW_DATA_REQUEST) is off and a customer submits a request directly
- **THEN** the submission is refused with a reason stating that changes are not available

#### Scenario: Order of another customer
- **WHEN** a logged-in customer submits a request for an order owned by someone else
- **THEN** the submission is refused and nothing is stored

#### Scenario: Pending request already exists
- **WHEN** the order already has a pending request and the customer submits another
- **THEN** the submission is refused with a reason pointing to the existing request

#### Scenario: Document already exists
- **WHEN** the order has an issued or adopted document and the customer submits a request
- **THEN** the submission is refused with a reason stating that the document has already been issued

### Requirement: Request content and validation
The form SHALL collect: invoice for a company (yes/no), company name, VAT/NIP number, first name, last name, address line 1, address line 2, postcode, city, country, and a free-text note. When "company" is yes, company name and NIP SHALL be required and the NIP SHALL be 10 digits after removing separators; postcode and city SHALL be required.

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
A valid submission SHALL store a pending request containing the requested data, the note, the customer, the order and the timestamp, SHALL show the customer a confirmation on the order page, and SHALL send an e-mail to the merchant notification address containing the order reference, the requested data and the differences versus the order's current invoice address.

#### Scenario: Successful submission
- **WHEN** an eligible customer submits valid data
- **THEN** a pending request is stored with the data, note, customer, order and timestamp, and the order page shows a confirmation

#### Scenario: Merchant notification
- **WHEN** a request is stored and the notification address is configured
- **THEN** that address receives an e-mail with the order reference, the requested data and the differences from the current invoice address

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
The customer SHALL be able to cancel their own pending request from the order page, after which the request SHALL be "cancelled", the hold SHALL be released and the merchant SHALL NOT be required to act.

#### Scenario: Cancel own pending request
- **WHEN** the owner cancels a pending request with a valid token
- **THEN** the request status becomes "cancelled" and the order no longer shows the held state

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
Every request SHALL keep who created it, who resolved it, when, the original data, the final approved data when edited, and the admin note, and these SHALL be visible in the admin order panel.

#### Scenario: Resolved request with edits
- **WHEN** an admin opens the order panel for an order with an approved and edited request
- **THEN** the panel shows the creator, the resolving employee, the creation and resolution times, the original data, the approved data and the admin note

#### Scenario: Cancelled request
- **WHEN** a customer cancelled a request
- **THEN** the panel shows the request as cancelled with its creation and cancellation times
