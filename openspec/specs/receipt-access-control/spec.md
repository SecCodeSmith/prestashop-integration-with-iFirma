# receipt-access-control Specification

## Purpose
Who may see, issue and manage the module's documents in the back office: three permission levels granted through PrestaShop's profile permissions on the module's tab and enforced server-side on every back-office entry point of the module.

## Requirements

### Requirement: Three permission levels on the module tab
The module SHALL expose three permission levels through the profile permissions of its back-office tab, listed under *Orders* on the *Team > Permissions* page: **view** (the tab's View permission), **issue** (the tab's Add permission) and **manage** (the tab's Edit permission). The tab's Delete permission SHALL have no effect on the module.

#### Scenario: Tab listed on the Permissions page
- **WHEN** an administrator opens *Team > Permissions* and selects any profile
- **THEN** the module tab appears under *Orders* with its View, Add, Edit and Delete toggles

#### Scenario: Delete has no effect
- **WHEN** a profile holds only the Delete permission of the module tab
- **THEN** its employees can neither see, issue nor manage documents

### Requirement: Module permission documented as a prerequisite
Because PrestaShop runs a module's back-office hooks only for profiles holding the module's own View permission (Team > Permissions > Modules), the documentation SHALL state that this permission must be granted together with the tab's View level, and the module SHALL NOT work around that core rule.

#### Scenario: Profile without the module permission
- **WHEN** a profile holds the tab's View and Add levels but not the module's View permission
- **THEN** PrestaShop renders none of the module's panels for its employees, while the AJAX actions still follow the tab levels

#### Scenario: Documented
- **WHEN** an administrator reads the Permissions section of the configuration guide
- **THEN** it names both permissions to grant

### Requirement: View gates every module screen
Every screen of the module in the back office SHALL be shown only to an employee with the view level: the iFirma panel on the order page, the "iFirma" column of the Orders list, the documents list page, the "iFirma" notification tab, the contractor panel on the customer page and the admin PDF download.

#### Scenario: Order page without view
- **WHEN** an employee of a profile without the view level opens an order
- **THEN** the page has no iFirma panel

#### Scenario: Orders list without view
- **WHEN** an employee without the view level opens the Orders list
- **THEN** the list has no "iFirma" column and no iFirma bulk action

#### Scenario: Documents list without view
- **WHEN** an employee without the view level opens the documents list URL directly
- **THEN** PrestaShop answers with its access-denied page and no record is listed

#### Scenario: PDF download without view
- **WHEN** an employee without the view level calls the admin PDF download with a valid module token
- **THEN** the download is refused with a permission message and no iFirma request is made

### Requirement: Issue level gates document creation
The actions that create or adopt a document SHALL require the issue level: Create, Issue anyway, Link a duplicate candidate, Register contractor, Retry issuance (single and bulk) and Issue from the Orders list (single and bulk). An employee with the view level only SHALL NOT see these actions.

#### Scenario: View-only employee calls Create
- **WHEN** an employee with the view level only sends the Create action for an order with a valid module token
- **THEN** the action is refused with a permission message, no receipt record changes and no iFirma request is made

#### Scenario: View-only order panel
- **WHEN** an employee with the view level only opens an order without a document
- **THEN** the panel shows the document state but no Create, Issue anyway, Link or Register contractor button

#### Scenario: Issue-level employee
- **WHEN** an employee with the issue level sends the Create action
- **THEN** the issuance pipeline runs as for any manual creation

### Requirement: Manage level gates operations on issued documents
The actions on an existing document or request SHALL require the manage level: Send again, Update status (register payment), Send to KSeF, Refresh from iFirma, Approve and Reject a data-change request, and the bulk Resend and Refresh. An employee without the manage level SHALL NOT see these actions.

#### Scenario: Issue-level employee resends
- **WHEN** an employee with the view and issue levels but not the manage level sends the Resend action
- **THEN** the action is refused with a permission message and no e-mail is sent

#### Scenario: Manage-level employee approves a request
- **WHEN** an employee with the manage level approves a pending data-change request
- **THEN** the request is approved as before

#### Scenario: Panel without manage
- **WHEN** an employee without the manage level opens an order with an issued document
- **THEN** the panel shows the document, its states and the Download PDF link, but no Send again, Update status, Send to KSeF, Refresh, Approve or Reject button

### Requirement: Server-side enforcement
Every permission check SHALL be made by the server on each request, independently of which buttons the page rendered; a refused AJAX call SHALL answer HTTP 403 with the module's JSON envelope (`success` false and a permission message) and SHALL change nothing.

#### Scenario: Crafted request
- **WHEN** an employee with the view level only posts the Send to KSeF action with a valid module token from a script
- **THEN** the response is HTTP 403 with `success` false and a permission message, and no KSeF submission is made

#### Scenario: Missing token still refused first
- **WHEN** any action arrives without a valid module token
- **THEN** it is refused by the token check before any permission check or action runs

### Requirement: Defaults on install and upgrade
On install the SuperAdmin profile SHALL hold all three levels and every other profile SHALL hold none. An upgrade SHALL keep every permission row that exists, so a profile keeps the levels it had, and SHALL move the module tab into the Orders menu without recreating it.

#### Scenario: Fresh install
- **WHEN** the module is installed on a shop with the profiles SuperAdmin, Logistician, Translator and Salesman
- **THEN** SuperAdmin holds view, issue and manage and the three other profiles hold none

#### Scenario: Upgrade keeps granted levels
- **WHEN** a profile was granted the tab's View and Edit permissions before the upgrade
- **THEN** after the upgrade the profile still holds the view and manage levels and the tab is listed under Orders

### Requirement: Settings page permission unchanged
The module's configuration page SHALL stay governed by the module's *Configure* permission only; the three document levels SHALL NOT be required to open or save it.

#### Scenario: Configure without document levels
- **WHEN** an employee whose profile may configure the module but holds no level of the module tab opens the configuration page
- **THEN** the page opens and saves as before
