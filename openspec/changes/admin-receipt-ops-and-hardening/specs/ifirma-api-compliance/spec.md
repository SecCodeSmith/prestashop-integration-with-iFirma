# Spec Delta

## MODIFIED Requirements

### Requirement: Local rate guard
The module SHALL count its own requests per calendar minute and SHALL refuse to start a request above the configured cap (default 80, below the iFirma limit of 100 per minute) with a distinct transient "rate limited" error that callers do not persist as a document error state. The counter SHALL be incremented atomically, so concurrent requests cannot lose increments and exceed the cap.

#### Scenario: Under the cap
- **WHEN** fewer requests than the cap were made this minute
- **THEN** the request is sent and the counter increments

#### Scenario: Cap reached
- **WHEN** the cap is reached within the current minute
- **THEN** the request is not sent
- **AND** a rate limited error is returned

#### Scenario: New minute
- **WHEN** the calendar minute changes
- **THEN** the counter restarts from zero

#### Scenario: Transient error not persisted
- **WHEN** a pipeline step hits the rate limited error
- **THEN** the receipt record is not set to an error status

#### Scenario: Concurrent requests
- **WHEN** two requests pass the guard at the same moment
- **THEN** the counter ends two higher than before

## ADDED Requirements

### Requirement: Authentication failure starts a cool-down
When iFirma answers HTTP 401 or 403, or a `Kod` of 400, 401, 402, 403 or 499, the module SHALL record the time and message of the failure and SHALL send no further request to iFirma for 15 minutes. `Kod` 500 (insufficient permissions, endpoint-specific) SHALL NOT start a cool-down.

#### Scenario: Bare HTTP 403
- **WHEN** the PDF download for a delivery is answered with HTTP 403 and no `Kod` body
- **THEN** exactly one request is made, no wait happens and the cool-down starts

#### Scenario: Kod 500 does not pause
- **WHEN** the daily-limit call is answered with `Kod` 500
- **THEN** no cool-down starts and the next document request is sent

#### Scenario: Window over
- **WHEN** 15 minutes have passed since the failure
- **THEN** the next call is sent to iFirma

### Requirement: Calls inside the cool-down fail fast
A call attempted inside the cool-down window SHALL fail at once without an HTTP request, with a message naming the credential failure and its time; it SHALL never be retried with waits; a receipt record affected by it SHALL hold that message as its last error in state `error`, never `unknown`; and the cron run SHALL do nothing but report the cool-down.

#### Scenario: Second call blocked
- **WHEN** a create request is answered with `Kod` 401 and another document is triggered two minutes later
- **THEN** no HTTP request is made for the second document and its record holds the credential-failure message in state `error`

#### Scenario: Cron during the cool-down
- **WHEN** the cron endpoint is called inside the window
- **THEN** it makes no iFirma request, changes no record and its summary names the cool-down

### Requirement: Cool-down shown and cleared on the settings page
The configuration page SHALL show a banner naming the credential failure and its time while the cool-down is active, and saving the credentials section SHALL clear the cool-down.

#### Scenario: Banner shown
- **WHEN** the administrator opens the configuration page inside the window
- **THEN** a banner names the failure message and its time

#### Scenario: Save clears
- **WHEN** the administrator saves the configuration form inside the window
- **THEN** the cool-down ends and the next call is sent to iFirma

### Requirement: Base URL validation
The API base URL SHALL be saved only as an absolute URL with the `https` scheme and a host, without user information; `http` SHALL be accepted only when the shop runs in developer mode or the host is a loopback, private-range or single-label name; any other value SHALL be refused with a message naming the field and nothing SHALL be saved.

#### Scenario: Public https accepted
- **WHEN** `https://www.ifirma.pl/iapi/` is posted
- **THEN** it is saved

#### Scenario: Public http refused
- **WHEN** `http://www.ifirma.pl/iapi/` is posted on a shop that is not in developer mode
- **THEN** the page shows a message naming the base URL and no setting is changed

#### Scenario: Internal mock accepted
- **WHEN** `http://ifirma-mock:8001/iapi/` is posted
- **THEN** it is saved

#### Scenario: Other scheme refused
- **WHEN** `file:///etc/passwd` or `gopher://host/` is posted
- **THEN** the page shows a message naming the base URL and no setting is changed

### Requirement: Transport restrictions
The HTTP transport SHALL allow only the HTTP and HTTPS protocols, SHALL NOT follow redirects, and SHALL abort a response larger than 20 MB with a transport error.

#### Scenario: Redirect not followed
- **WHEN** iFirma answers a request with HTTP 302 to another host
- **THEN** the redirect is not followed and the call is treated as an HTTP error status

#### Scenario: Oversized body
- **WHEN** a PDF response exceeds 20 MB
- **THEN** the call ends with a transport error and no PDF is delivered
