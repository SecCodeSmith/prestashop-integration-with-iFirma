## Purpose

Make PrestaShop's built-in customer invoice PDF link (Order history and order details) hand out the iFirma document for orders the module invoices. A customer then finds the legal invoice where the shop normally offers it, and never receives a second, different PrestaShop invoice for the same order.

## ADDED Requirements

### Requirement: Orders the module handles
An order SHALL count as handled by the module when the module holds a document record for it in any state (queued, held, issuing, verifying, failed, issued, removed in iFirma), or when the order has a pending data-change request. Every other order SHALL count as not handled. The requirements below use this definition.

#### Scenario: Order with an issued document
- **WHEN** an order has an issued iFirma document
- **THEN** the order is handled by the module

#### Scenario: Order held before any document record exists
- **WHEN** an order has a pending data-change request and no document record
- **THEN** the order is handled by the module

#### Scenario: Order from before the module was installed
- **WHEN** an order has no document record and no pending data-change request
- **THEN** the order is not handled by the module

### Requirement: Built-in invoice link serves the iFirma document
When the setting is on and a logged-in customer opens PrestaShop's built-in invoice PDF link for their own handled order whose iFirma document can be handed out, the shop SHALL send the customer to the module's protected download of that document. It SHALL NOT generate PrestaShop's own invoice. Every check and limit of the protected download SHALL apply unchanged.

#### Scenario: Owner opens the invoice link of an invoiced order
- **WHEN** the setting is on and the logged-in owner opens the built-in invoice link of an order whose iFirma document is issued and downloadable
- **THEN** the response is the iFirma PDF from the protected download and PrestaShop's invoice PDF is not generated

#### Scenario: Download limits still apply
- **WHEN** the owner has used up their daily download cap and opens the built-in invoice link
- **THEN** the answer is the same "too many requests" answer the protected download gives, and no iFirma request is made

### Requirement: No PrestaShop invoice while the iFirma document is not available
When the setting is on and a handled order has no iFirma document that can be handed out, the shop SHALL answer the built-in invoice link with a short, generic "invoice not available yet" text. It SHALL NOT generate PrestaShop's own invoice, and it SHALL NOT send any iFirma request. A document can't be handed out while it is not issued yet, held, being verified, failed, awaiting KSeF acceptance, removed in iFirma, or has no download token.

#### Scenario: Document awaiting KSeF acceptance
- **WHEN** the owner opens the built-in invoice link of an order whose iFirma document awaits KSeF acceptance
- **THEN** the answer is the "not available yet" text, no PDF is returned and no iFirma request is made

#### Scenario: Order held for a data-change request
- **WHEN** the owner opens the built-in invoice link of an order that has a pending data-change request
- **THEN** the answer is the "not available yet" text and no PDF is returned

#### Scenario: Document removed in iFirma
- **WHEN** the order's iFirma document is in the missing state
- **THEN** the answer is the "not available yet" text and no PDF is returned

### Requirement: Guests get no invoice for handled orders
When the setting is on, a request to the built-in invoice link for a handled order that is authorised only by the order's secure key (a guest, not logged in) SHALL get the "not available yet" text. It SHALL NOT get PrestaShop's invoice or the iFirma PDF.

#### Scenario: Guest with the secure key
- **WHEN** a guest opens the built-in invoice link with the correct secure key for an order that has an issued iFirma document
- **THEN** the answer is the "not available yet" text and neither PDF is returned

### Requirement: Orders not handled by the module are unchanged
The shop SHALL leave PrestaShop's built-in invoice link unchanged for every order not handled by the module, and for every order while the setting is off.

#### Scenario: Order invoiced before the module was installed
- **WHEN** the owner opens the built-in invoice link of an order the module does not handle
- **THEN** PrestaShop's own invoice PDF is returned as before

#### Scenario: Setting off
- **WHEN** the setting is off and the owner opens the built-in invoice link of an order with an issued iFirma document
- **THEN** PrestaShop's own invoice PDF is returned as before

### Requirement: PrestaShop's own access checks stay first
The module SHALL act on the built-in invoice link only after PrestaShop accepts the request: invoices enabled, the order exists, and it belongs to the logged-in customer or the secure key matches. A request PrestaShop refuses SHALL get PrestaShop's refusal, and it SHALL NOT reveal whether the module handles the order.

#### Scenario: Another customer's order
- **WHEN** a logged-in customer opens the built-in invoice link with the id of an order that belongs to another customer
- **THEN** the answer is PrestaShop's "invoice not found" refusal, no PDF is returned and nothing tells whether that order has an iFirma document

#### Scenario: Wrong secure key
- **WHEN** a visitor opens the built-in invoice link with a wrong secure key
- **THEN** the answer is PrestaShop's refusal and no PDF is returned

### Requirement: Built-in invoice link setting
The module SHALL provide an on/off setting "Serve the iFirma document from the built-in invoice link". The setting SHALL be on by default for new installs, and the upgrade SHALL switch it on. When the setting is on and PrestaShop invoices are disabled, the configuration page SHALL warn that PrestaShop shows customers no built-in invoice link.

#### Scenario: Fresh install
- **WHEN** the module is installed
- **THEN** the setting is on

#### Scenario: Upgrade of an existing shop
- **WHEN** an existing shop upgrades to the version that brings this change
- **THEN** the setting is on and the built-in invoice hook is registered

#### Scenario: PrestaShop invoices disabled
- **WHEN** the setting is on and PrestaShop's "Enable invoices" option is off
- **THEN** the configuration page shows the warning next to the setting
