# Spec Delta

## MODIFIED Requirements

### Requirement: Panel on the customer page
The back-office customer view SHALL show an iFirma panel for the viewed customer on every supported PrestaShop version, including installations upgraded from an earlier module version. The panel SHALL render without calling the iFirma API, and any API-backed part SHALL load after the page. The panel SHALL take the full width of the area PrestaShop gives to the hook and align with the page's own cards, and its contractor and count tables SHALL keep each value beside its label.

#### Scenario: Panel shown on a fresh install
- **WHEN** an employee opens the customer page of customer 7 on a shop where the module was just installed
- **THEN** the page contains the iFirma panel for customer 7

#### Scenario: Panel shown after upgrade
- **WHEN** the module is upgraded from 1.1.0 and an employee opens a customer page
- **THEN** the iFirma panel is shown without reinstalling the module

#### Scenario: Page does not wait for iFirma
- **WHEN** iFirma does not answer within the request timeout
- **THEN** the customer page and the panel's local information are still displayed, and only the API-backed parts show that they could not be loaded

#### Scenario: Panel spans the hook row
- **WHEN** an employee opens the customer page on PrestaShop 8.1 or 9.x
- **THEN** the iFirma panel is as wide as the cards above it, with the same left and right edges

#### Scenario: Contractor fields stay on one line
- **WHEN** the panel shows a linked contractor with identifier `KOWALSKI-01`, name "Jan Kowalski" and the match type
- **THEN** each of the identifier, name, link type and "In iFirma" values is shown beside its label, not under it
