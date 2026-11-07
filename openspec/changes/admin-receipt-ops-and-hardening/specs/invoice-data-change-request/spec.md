# Spec Delta

## MODIFIED Requirements

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

## ADDED Requirements

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
