# Spec Delta

## ADDED Requirements

### Requirement: Document print template
When the configured document template name is not empty, every `fakturakraj` and `fakturaparagon` create request SHALL carry it as `NazwaSzablonu`; when it is empty the field SHALL be omitted so iFirma uses the account's default template. The stored request payload SHALL show the field as sent.

#### Scenario: Template configured
- **WHEN** the template name is `logo` and a document is created
- **THEN** the create request contains `"NazwaSzablonu": "logo"` and the stored request payload shows it

#### Scenario: Template empty
- **WHEN** the template name setting is empty and a document is created
- **THEN** the create request has no `NazwaSzablonu` key

#### Scenario: Retries keep the template
- **WHEN** the create is retried without `DodatkoweUwagi` or without `IdentyfikatorKontrahenta`
- **THEN** the retried request still carries the same `NazwaSzablonu`

### Requirement: Document template setting
The configuration page SHALL offer a "Document template in iFirma" text setting, empty by default, trimmed on save, at most 100 characters and without control characters; a longer value or one with control characters SHALL be refused with a message naming the field and nothing SHALL be saved. The setting SHALL be written with its default on install and upgrade and removed on uninstall. Its help text SHALL say that the template, including the logo it prints, is defined in the iFirma account.

#### Scenario: Default after install
- **WHEN** the module is installed or upgraded
- **THEN** the template setting exists and is empty

#### Scenario: Valid name saved
- **WHEN** the admin saves ` logo ` with surrounding spaces
- **THEN** `logo` is stored

#### Scenario: Over-long name refused
- **WHEN** the admin saves a 101-character template name
- **THEN** the page shows an error naming the template field and no setting is changed

#### Scenario: Uninstall
- **WHEN** the module is uninstalled
- **THEN** the template setting no longer exists
