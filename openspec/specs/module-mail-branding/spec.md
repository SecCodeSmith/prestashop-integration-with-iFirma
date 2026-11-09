# module-mail-branding Specification

## Purpose
Makes every e-mail the module sends look like the shop's own e-mails: the header shows the shop logo configured in PrestaShop and the shop name, both linking to the shop webpage, the footer prints that webpage, in both the HTML and the plain-text version of each template the module ships.

## Requirements

### Requirement: Shop logo in the e-mail header
Every HTML e-mail template shipped by the module (document issued, data request received, approved and rejected, one-time code) SHALL show the shop's e-mail logo in its header through the placeholder the shop mailer fills with an inline image, with the shop name as the image's alternative text, and the header SHALL still show the shop name as text when the shop has no logo.

#### Scenario: Shop with a logo
- **WHEN** the shop has a mail logo configured and the module sends the "document issued" e-mail
- **THEN** the HTML part references an inline image part that carries the shop logo and the image's alternative text is the shop name

#### Scenario: Every module template
- **WHEN** each of the module's e-mails (document issued, request received, approved, rejected, one-time code) is sent in Polish and in English
- **THEN** each HTML part references the inline logo image

#### Scenario: Shop without a logo
- **WHEN** the shop has neither a mail logo nor a header logo and the module sends an e-mail
- **THEN** the header shows the shop name as text and the e-mail is sent without error

### Requirement: Shop webpage link
The logo and the shop name in the header of every module HTML e-mail SHALL link to the configured shop webpage, or to the shop's own front URL when none is configured, and the footer of every HTML and text template SHALL print that address.

#### Scenario: Configured webpage
- **WHEN** the shop webpage is `https://scmedical.pl` and the module sends the approval e-mail
- **THEN** the header logo and shop name link to `https://scmedical.pl` and the footer of the HTML and text parts prints it

#### Scenario: No webpage configured
- **WHEN** the shop webpage setting is empty
- **THEN** the link and the footer use the shop's own front URL

### Requirement: Shop webpage setting
The configuration page SHALL offer a "Shop webpage" setting, empty by default, accepting only an absolute `http` or `https` URL of at most 255 characters without user information; any other value SHALL be refused with a message naming the field and nothing SHALL be saved. The setting SHALL be written with its default on install and upgrade and removed on uninstall.

#### Scenario: Valid URL
- **WHEN** the admin saves `https://scmedical.pl`
- **THEN** it is stored and used by the e-mails, the document comments and the PDF file names

#### Scenario: Invalid value
- **WHEN** the admin saves `scmedical.pl` without a scheme or `https://user:pw@scmedical.pl`
- **THEN** the page shows an error naming the webpage field and no setting is changed

### Requirement: Plain-text version unchanged in content
The plain-text version of each template SHALL carry the same information as the HTML version, SHALL name the shop in its first line, and SHALL contain no image reference.

#### Scenario: Text part
- **WHEN** the "document issued" e-mail is sent
- **THEN** its text part starts with the shop name and contains the document number and the order reference but no `cid:` or image markup
