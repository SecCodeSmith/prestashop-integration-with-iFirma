# Spec Delta

## ADDED Requirements

### Requirement: Marking as sent needs the manage level and POST
"Mark as sent", in the order panel and as a bulk action of the Documents list, SHALL require the manage level and SHALL run only for a POST request that carries a valid module token. An employee without the manage level SHALL NOT see the action. A refused request SHALL change no document.

#### Scenario: Employee without the manage level
- **GIVEN** a document whose delivery failed
- **WHEN** an employee with the view and issue levels but not the manage level posts "Mark as sent" with a valid module token
- **THEN** the response is HTTP 403 with `success` false and a permission message
- **AND** the document's delivery state is unchanged

#### Scenario: GET names the action
- **GIVEN** an employee with the manage level and a valid token
- **WHEN** a GET request names the "Mark as sent" action for an order
- **THEN** the answer is a refusal and the document's delivery state is unchanged

#### Scenario: Missing token
- **WHEN** the action arrives without a valid module token
- **THEN** it is refused by the token check before any permission check, and no document changes

#### Scenario: Panel without manage
- **WHEN** an employee without the manage level opens an order whose document is not sent
- **THEN** the panel shows no "Mark as sent" button

#### Scenario: Bulk without manage
- **WHEN** an employee without the manage level opens the Documents list or posts the bulk "Mark as sent"
- **THEN** the bulk action is not offered and the posted run is refused with a permission message

#### Scenario: Manage-level employee
- **WHEN** an employee with the manage level posts "Mark as sent" from the order panel
- **THEN** the document is recorded as sent as specified in `receipt-delivery`
