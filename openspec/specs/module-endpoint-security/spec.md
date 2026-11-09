# module-endpoint-security Specification

## Purpose
How the module's HTTP endpoints and settings page validate their input, disclose errors, set response headers and handle secrets, in the back office and the front office.

## Requirements

### Requirement: Paid amount validation
The amount posted to the payment registration action SHALL be accepted only as a scalar decimal number (comma or dot) with at most two decimals, greater than zero and not above the order's gross total; any other value SHALL be refused with a validation message before any iFirma request. An empty amount SHALL keep the current behaviour of using the order's paid amount.

#### Scenario: Comma decimal accepted
- **WHEN** the admin posts the amount `12,50` for an order whose gross total is 20.00
- **THEN** 12.50 is registered in iFirma

#### Scenario: Non-numeric refused
- **WHEN** the admin posts the amount `abc`
- **THEN** the action is refused with a validation message and no iFirma request is made

#### Scenario: Array refused
- **WHEN** the request carries `amount[]=1`
- **THEN** the action is refused with a validation message and no iFirma request is made

#### Scenario: Negative or excessive amount refused
- **WHEN** the admin posts `-5` or an amount above the order's gross total
- **THEN** the action is refused with a validation message and no iFirma request is made

#### Scenario: Empty amount
- **WHEN** the admin posts no amount
- **THEN** the order's paid amount is registered, as before

### Requirement: Generic bodies for unexpected errors
A failure that is not a module-originated message (an iFirma answer, a validation refusal or a permission refusal) SHALL be answered with a generic message, as `text/plain` with `X-Content-Type-Options: nosniff` on a non-AJAX endpoint or inside the JSON envelope on an AJAX endpoint, and the original message SHALL be written to the PrestaShop log.

#### Scenario: Database error during the admin PDF download
- **WHEN** a database exception is raised while the admin downloads a PDF
- **THEN** the response is HTTP 502, `text/plain`, with a generic message and the `nosniff` header, and the log holds the exception message

#### Scenario: Module message kept
- **WHEN** iFirma rejects a create request with an `Informacja` text
- **THEN** the JSON answer carries that text as before

#### Scenario: Type error inside an AJAX action
- **WHEN** a PHP `TypeError` is raised inside an AJAX action
- **THEN** the JSON answer has `success` false and a generic message, and the log holds the error text

### Requirement: PDF response headers and file names
Every PDF the module streams, to an employee or to a customer, SHALL carry `X-Content-Type-Options: nosniff` and a `Content-Disposition: attachment` whose file name is `{shop}-{name}.pdf`, where `{shop}` is the host of the configured shop webpage (the shop's own domain when none is configured), lower-cased and without a leading `www.`, and `{name}` is the document number, or the iFirma identifier when no number is stored; every character outside `0-9 A-Z a-z . _ -` in either part SHALL be replaced by `_`. The e-mail attachment of the shop-mail delivery SHALL use the same name.

#### Scenario: Identifier with a slash
- **WHEN** the shop domain is `scmedical.pl` and a document has no number and the identifier `12/ab`
- **THEN** the file name is `scmedical.pl-12_ab.pdf` on both download paths

#### Scenario: Configured webpage
- **WHEN** the shop webpage is `https://www.Hialumed.pl/` and the document number is `FV 7/2026`
- **THEN** the file name is `hialumed.pl-FV_7_2026.pdf` for the downloads and the e-mail attachment

#### Scenario: Headers present
- **WHEN** a customer downloads a document
- **THEN** the response carries `nosniff` and an attachment disposition

### Requirement: API keys are not echoed
The configuration page SHALL render the API key fields as password inputs without the stored value; a key field posted empty SHALL keep the stored key, a posted value SHALL replace it, and an explicit clear control SHALL remove it.

#### Scenario: Page source
- **WHEN** an administrator views the source of the configuration page
- **THEN** no stored API key appears in it

#### Scenario: Empty field keeps the key
- **WHEN** the form is saved with the key fields empty
- **THEN** the stored keys are unchanged and the page says the keys are set

#### Scenario: New key replaces
- **WHEN** a new hexadecimal key is posted
- **THEN** it replaces the stored key

#### Scenario: Clear control
- **WHEN** the clear control of a key is checked and the form is saved
- **THEN** that key is removed from the configuration

### Requirement: Array input for a scalar parameter is refused
Any scalar parameter of the module (a setting, a token, an identifier, an id, a note) that arrives as an array SHALL be treated as invalid: a settings field answers with a validation message naming the field, a token never matches, an id reads as 0; no type error SHALL reach the browser.

#### Scenario: Array login on save
- **WHEN** the configuration form posts the login as an array
- **THEN** the page shows a validation message for the login and no setting is changed

#### Scenario: Array token on the customer download
- **WHEN** the customer download is called with `token[]=x`
- **THEN** the response is "not found" and no PDF is fetched

### Requirement: Optional keys validated on save
The optional *abonent* and *rachunek* keys SHALL be validated like the *faktura* key (hexadecimal, even length) when a value is posted; an empty value SHALL remain allowed.

#### Scenario: Malformed abonent key
- **WHEN** the abonent key `xyz` is posted
- **THEN** the page shows a validation message for that key and no setting is changed

#### Scenario: Empty abonent key
- **WHEN** the abonent key is left empty
- **THEN** the form saves

### Requirement: Delivery e-mail escapes customer values
The customer first name, last name and the order reference SHALL be HTML-escaped in the HTML version of the delivery e-mail.

#### Scenario: Markup in a name
- **WHEN** the customer's first name is `<b>Jan</b>`
- **THEN** the HTML e-mail shows `<b>Jan</b>` as text and renders no bold
