# Spec Delta

## ADDED Requirements

### Requirement: Configuration page enforces the Configure permission itself
The module SHALL check the employee's *Configure* permission for the module on every request to its configuration page and SHALL NOT rely on the shop core for it. Without that permission the page SHALL show only a generic refusal: no setting, no key hint, no cron address or token, no form. No action of the page SHALL run.

#### Scenario: Employee with Module Manager view only
- **GIVEN** an employee whose profile may view the Module Manager and may not configure the iFirma module
- **WHEN** the employee opens the module's configuration page on a shop whose core lets them reach it
- **THEN** the page shows a generic "no permission" message and nothing else of the page

#### Scenario: Cron token stays hidden
- **GIVEN** the same employee
- **WHEN** the configuration page is requested
- **THEN** the response contains neither the cron token nor the cron address

#### Scenario: Settings save refused
- **GIVEN** the same employee
- **WHEN** a request posts the settings form with new API credentials
- **THEN** no setting changes and the generic refusal is shown

#### Scenario: Cron token rotation refused
- **GIVEN** the same employee
- **WHEN** a request posts the "regenerate cron token" action
- **THEN** the cron token is unchanged

#### Scenario: Contractor actions refused
- **GIVEN** the same employee
- **WHEN** a request posts the contractor import or the contractor map reset
- **THEN** nothing is imported and the map is unchanged

#### Scenario: Employee who may configure
- **WHEN** an employee whose profile may configure the module opens the page
- **THEN** the page opens and saves as before

### Requirement: Configuration page actions need POST and a valid token
Every action of the configuration page that changes state (saving settings, regenerating the cron token, importing contractors, resetting the contractor map, checking the remaining limit) SHALL run only for a POST request that carries a valid back-office token. Any other request SHALL show the page without running the action.

#### Scenario: GET with an action name
- **GIVEN** an employee who may configure the module
- **WHEN** a GET request names the "regenerate cron token" action in its query string
- **THEN** the page is shown and the cron token is unchanged

#### Scenario: GET settings save
- **WHEN** a GET request carries the settings form's fields and its submit name
- **THEN** no setting changes

#### Scenario: Missing token
- **WHEN** a POST request for the contractor map reset carries no back-office token
- **THEN** the map is unchanged and a generic error is shown

#### Scenario: Wrong token
- **WHEN** a POST request for the settings save carries a token that is not the employee's
- **THEN** no setting changes and a generic error is shown

#### Scenario: Valid request
- **WHEN** an employee who may configure the module posts the settings form from the page
- **THEN** the settings are saved as before
