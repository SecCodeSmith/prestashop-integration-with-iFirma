# Spec Delta

## MODIFIED Requirements

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
