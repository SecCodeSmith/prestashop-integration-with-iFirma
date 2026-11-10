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

### Requirement: Registering a customer's contractor needs the issue level
Creating a contractor in iFirma from a customer's page SHALL need the same permission as creating it from an order: the module's issue level, in addition to the right to edit customers. The view level SHALL NOT be enough.

#### Scenario: View level only
- **GIVEN** an employee with the module's view level and the right to edit customers, without the issue level
- **WHEN** the employee triggers "Register" on a customer's page
- **THEN** the action is refused with the permission message and no request is sent to iFirma

#### Scenario: Issue level
- **WHEN** an employee with the issue level and the right to edit customers triggers "Register"
- **THEN** the contractor is registered as before

#### Scenario: Read-only customer actions unchanged
- **WHEN** an employee with the view level checks or searches a customer's contractor
- **THEN** the action works as before

### Requirement: State-changing back-office actions need POST
Every back-office action of the module that changes state in the shop or in iFirma SHALL run only for a POST request. A GET request that names such an action SHALL be refused and SHALL change nothing. Read-only actions (PDF download, issue feed, customer check, search and remote count) SHALL keep working with GET.

#### Scenario: Which actions change state
- **WHEN** the module's back-office actions are listed
- **THEN** these need POST: create receipt, issue anyway, link candidate, register contractor, issue from the list, update status, send again, send to KSeF, refresh, approve request, reject request, link customer, unlink customer, register customer, mark the issue feed as seen, and every bulk run

#### Scenario: GET names a state-changing action
- **GIVEN** an employee with the manage level and a valid token
- **WHEN** a GET request names the "send again" action for an order
- **THEN** the answer is a refusal, no e-mail is sent and the record is unchanged

#### Scenario: GET cannot issue a document
- **WHEN** a GET request names the "create receipt" action with a valid token
- **THEN** no document is issued and no request is sent to iFirma

#### Scenario: POST works as before
- **WHEN** the module's own back-office pages post the same actions
- **THEN** they run as before

#### Scenario: Read-only action over GET
- **WHEN** an employee with the view level opens the PDF download link
- **THEN** the PDF is returned as before

#### Scenario: Bulk run over GET
- **WHEN** a GET request names a bulk action of the Documents list
- **THEN** nothing is retried, sent or refreshed

### Requirement: Employee PDF downloads have their own request budget
All back-office PDF downloads together SHALL use at most 30 iFirma requests per calendar minute. A download beyond that SHALL be answered with HTTP 429, a `Retry-After` header and a message that says the download limit of the back office is reached, and SHALL send no request. The budget SHALL NOT refuse issuing documents, status sync or the cron.

#### Scenario: Budget used up
- **GIVEN** 30 back-office PDF downloads were sent in the current minute
- **WHEN** an employee with the view level downloads another PDF
- **THEN** the answer is HTTP 429 with a `Retry-After` header and no request is sent

#### Scenario: Issuance not affected
- **GIVEN** the back-office download budget is used up
- **WHEN** an order reaches the trigger status
- **THEN** its document is issued

#### Scenario: Access check first
- **GIVEN** the budget is used up
- **WHEN** an employee without the view level requests a PDF
- **THEN** the answer is the access-denied answer, not 429
