# Spec Delta

## MODIFIED Requirements

### Requirement: Access control on panel actions
Every panel action (check, count, search, link, unlink, register) SHALL require an authenticated back-office employee, a valid module admin token and the module's view level; the panel itself SHALL be rendered only for an employee with the view level. Link, unlink and register SHALL also require edit permission on customers. Every value from iFirma or the employee SHALL be escaped when displayed.

#### Scenario: Missing token
- **WHEN** a request to link a contractor arrives without a valid admin token
- **THEN** it is refused and no link is stored

#### Scenario: Employee without edit permission
- **WHEN** an employee with view-only access to customers tries to unlink a customer
- **THEN** the action is refused with a permission message and the link is unchanged

#### Scenario: Employee without the module's view level
- **WHEN** an employee whose profile has no view level of the module tab opens a customer page and then posts a check request with a valid token
- **THEN** the page shows no iFirma panel and the request is refused with a permission message

#### Scenario: Markup in a contractor name
- **WHEN** iFirma returns a contractor named `<script>alert(1)</script>`
- **THEN** the panel shows the name as text and no script runs
