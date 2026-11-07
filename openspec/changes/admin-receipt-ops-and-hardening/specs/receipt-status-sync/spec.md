# Spec Delta

## MODIFIED Requirements

### Requirement: Cron endpoint authentication
A module front endpoint SHALL run a bulk sync only when called with the correct secret token, which is generated at install or upgrade and shown on the configuration page, given either as the `token` query parameter or as the `X-Ifirma-Token` request header; the comparison SHALL be constant-time, an empty configured token SHALL never match, and a wrong or missing token SHALL return HTTP 403 and do nothing.

#### Scenario: Correct token
- **WHEN** the endpoint is called with the configured token
- **THEN** the bulk sync runs and returns HTTP 200

#### Scenario: Wrong or missing token
- **WHEN** the token is wrong or absent
- **THEN** the response is HTTP 403 and no iFirma request is made

#### Scenario: Token in the header
- **WHEN** the endpoint is called without a query token and with the configured token in `X-Ifirma-Token`
- **THEN** the bulk sync runs and returns HTTP 200

#### Scenario: Empty configured token
- **WHEN** the configured token is empty and the endpoint is called with an empty token
- **THEN** the response is HTTP 403

## ADDED Requirements

### Requirement: Cron token generation
The cron token SHALL come from the cryptographically secure random generator only; when that generator is unavailable, install, upgrade and regeneration SHALL fail with a message instead of storing a weaker token.

#### Scenario: Regeneration
- **WHEN** the administrator regenerates the cron token
- **THEN** the new token is 32 hexadecimal characters from the secure generator

#### Scenario: Generator unavailable
- **WHEN** the secure generator throws during regeneration
- **THEN** the page shows an error, the previous token stays in place and no weaker token is stored
