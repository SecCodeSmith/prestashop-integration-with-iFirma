# Spec Delta

## MODIFIED Requirements

### Requirement: Optional limit visibility
When the optional `abonent` key is configured, the module configuration page SHALL be able to display the used and granted daily API limit from `abonent/limit.json`; when it is not configured, the feature SHALL be hidden without error. Opening the page SHALL send no request to iFirma: the page SHALL show the last stored reading with its time, and SHALL read a fresh one only on the employee's request.

#### Scenario: Key configured
- **GIVEN** the limit was read at 10:02 as 1,200 used of 15,000
- **WHEN** the admin opens the configuration page with the `abonent` key set
- **THEN** used and granted daily limits are displayed with the time 10:02
- **AND** no request is sent to iFirma

#### Scenario: Key not configured
- **WHEN** the `abonent` key is empty
- **THEN** no limit figure is shown, no request is sent and no error occurs
- **AND** the page shows a hint to enter the `abonent` key instead of the check button

#### Scenario: Limit request fails
- **WHEN** the limit request fails
- **THEN** the page still renders with a short notice that the limit is unavailable

#### Scenario: No reading yet
- **GIVEN** the `abonent` key is set and the limit was never read
- **WHEN** the admin opens the configuration page
- **THEN** the page says that the limit was not checked yet and offers the check button

#### Scenario: Reading from another day
- **GIVEN** the last reading is from yesterday
- **WHEN** the admin opens the configuration page
- **THEN** the page shows the reading with its date and does not present it as today's

### Requirement: Local rate guard
The module SHALL count its own requests of the last 60 seconds and per calendar day (shop timezone) and SHALL refuse to start a request when either count has reached its configured cap: the minute cap `IFIRMA_RATE_MAX_PER_MIN` (default 80, allowed 1 to 100, below the iFirma limit of 100 per minute) and the day cap `IFIRMA_RATE_MAX_PER_DAY` (default 14000, allowed 1 to 15000, below the iFirma limit of 15000 per day). The minute cap SHALL hold for every period of 60 seconds, wherever it starts, so a burst around the turn of a calendar minute cannot send more than the cap. A refusal SHALL be a distinct transient "rate limited" error that names the window that refused and that callers do not persist as a document error state. Both counts SHALL be changed atomically, so concurrent requests cannot lose increments and exceed a cap. A minute counter written by an earlier module version SHALL be read as requests of its calendar minute, never as zero.

#### Scenario: Under the cap
- **WHEN** fewer requests than the minute cap were made in the last 60 seconds and fewer than the day cap today
- **THEN** the request is sent and both counts rise by one

#### Scenario: Cap reached
- **WHEN** the minute cap is reached within the last 60 seconds
- **THEN** the request is not sent
- **AND** a rate limited error naming the minute window is returned

#### Scenario: Day cap reached
- **WHEN** the day cap is reached today and the count of the last 60 seconds is below its cap
- **THEN** the request is not sent
- **AND** a rate limited error naming the day window is returned

#### Scenario: Burst across the turn of a minute
- **GIVEN** the minute cap is 80 and 80 requests were sent at 10:00:59
- **WHEN** 80 more requests are started at 10:01:00
- **THEN** none of them is sent
- **AND** each gets the rate limited error naming the minute window

#### Scenario: New minute
- **GIVEN** the minute cap is 80 and 80 requests were sent at 10:00:59
- **WHEN** the calendar minute has changed and a request is started at 10:01:59
- **THEN** it is not sent, because the 80 requests are not yet older than 60 seconds
- **WHEN** a request is started at 10:02:00
- **THEN** it is sent

#### Scenario: New day
- **WHEN** the calendar day changes
- **THEN** the day counter restarts from zero

#### Scenario: Transient error not persisted
- **WHEN** a pipeline step hits the rate limited error
- **THEN** the receipt record is not set to an error status

#### Scenario: Concurrent requests
- **WHEN** two requests pass the guard at the same moment
- **THEN** the count of the last 60 seconds and the day count each end two higher than before

#### Scenario: Concurrent requests at the cap
- **GIVEN** the count of the last 60 seconds is one below the minute cap
- **WHEN** two requests reach the guard at the same moment
- **THEN** exactly one of them is sent

#### Scenario: Counter of an earlier version
- **GIVEN** the stored minute counter was written by module 1.9.1 as 80 requests of the calendar minute 10:00
- **WHEN** a request is started at 10:01:00 after the upgrade
- **THEN** it is not sent

#### Scenario: Counter dated in the future
- **GIVEN** the minute cap is 80 and the stored counter holds 80 requests dated after the current time, as after the server clock was set back
- **WHEN** a request is started
- **THEN** those requests are counted as requests of the current second and the request is not sent
- **AND** a request started 61 seconds later is sent

#### Scenario: Day cap setting
- **WHEN** the module is installed, or a shop running 1.6.0 is upgraded
- **THEN** `IFIRMA_RATE_MAX_PER_DAY` is 14000, and saving 0 or 15001 on the settings page is refused

## ADDED Requirements

### Requirement: Remaining limit checked on demand
With the `abonent` key set, the configuration page SHALL offer a button that reads the day limit from iFirma once and shows the requests used, granted and remaining for the account, with the time of the reading. The click SHALL send exactly one request, always a fresh one, SHALL count it like any request, and SHALL update the stored reading and the module's day counter as the cron reading does.

#### Scenario: Check succeeds
- **GIVEN** iFirma reports 1,200 used of 15,000
- **WHEN** an employee clicks "Check the remaining iFirma limit"
- **THEN** the page shows 1,200 used, 15,000 granted, 13,800 remaining and the current time
- **AND** exactly one request was sent to iFirma

#### Scenario: Fresh reading although a recent one exists
- **GIVEN** the cron read the limit two minutes ago
- **WHEN** the employee clicks the button
- **THEN** iFirma is asked again and the newer reading is shown

#### Scenario: Other clients used the account
- **GIVEN** the module's day counter reads 300 and iFirma reports 1,200 used
- **WHEN** the employee clicks the button
- **THEN** the module's day counter reads 1,200

#### Scenario: The click is counted
- **WHEN** the employee clicks the button and the request is sent
- **THEN** the module's minute and day counts rise by 1 and today's count of kind `limit` rises by 1

#### Scenario: Nothing left
- **GIVEN** iFirma reports 15,000 used of 15,000
- **WHEN** the employee clicks the button
- **THEN** the page shows 0 remaining and states that the module sends no request until tomorrow

### Requirement: A failed or refused limit check is reported
When the on-demand check cannot be done, the page SHALL say why and SHALL keep the stored reading unchanged. Refused by the module's own limit or the authentication cool-down: nothing is sent and no counter changes. iFirma error or unusable answer: the request was sent, so it is counted like any request, but the module's day counter is not corrected; the page shows a short generic notice, with the detail in the shop log only. The answer SHALL never show a key.

#### Scenario: Refused by the module's limit
- **GIVEN** the minute cap is reached
- **WHEN** the employee clicks the button
- **THEN** no request is sent, no counter changes, the page says the limit was not checked because of the minute limit, and the earlier reading stays

#### Scenario: iFirma answers with an error
- **WHEN** the limit request is sent and iFirma answers with an error
- **THEN** the page shows that the limit is unavailable and the earlier reading stays
- **AND** the request is counted in the minute and day counts, but the day counter is not raised to any figure

#### Scenario: Wrong abonent key
- **WHEN** iFirma refuses the `abonent` key
- **THEN** the page shows that the limit is unavailable, names no key value, and the error detail is in the shop log

#### Scenario: Unusable answer
- **WHEN** iFirma answers without usable numbers
- **THEN** the page shows that the limit is unavailable, the stored reading is not replaced and the day counter is not raised
- **AND** the click is counted as one request

#### Scenario: Figures that are not whole numbers
- **WHEN** iFirma answers with a used or granted figure that is a decimal number, carries a sign or an exponent, is text, is above ten million, or with a granted figure of zero
- **THEN** it is treated as an unusable answer: the page shows that the limit is unavailable and no reading is stored

### Requirement: Limit check is a protected action
The on-demand check SHALL run only for an employee who may configure the module, only on a POST request with a valid back-office token. Without the `abonent` key the button SHALL NOT be offered and a forged request SHALL send nothing.

#### Scenario: GET request
- **WHEN** the check is requested with GET
- **THEN** no request is sent to iFirma and nothing changes

#### Scenario: Wrong or missing token
- **WHEN** the check is posted with a wrong or missing back-office token
- **THEN** it is rejected and no request is sent to iFirma

#### Scenario: Employee without the right to configure the module
- **WHEN** such an employee posts the check
- **THEN** it is rejected and no request is sent to iFirma

#### Scenario: No abonent key
- **WHEN** the `abonent` key is empty
- **THEN** the page shows a hint to enter the key instead of the button, and a posted check sends nothing

#### Scenario: Repeated clicks
- **WHEN** the employee clicks the button five times in a minute
- **THEN** five requests are sent and counted, and the module's minute cap still applies to the sixth

### Requirement: Every request kind counts toward the limits
Every request the module sends to iFirma SHALL count toward its minute limit and its day limit, whatever it is for. Reading a document list, reading a document detail and downloading a PDF SHALL count exactly like creating a document. No kind of request SHALL pass the limit check uncounted.

#### Scenario: Status list request counts
- **GIVEN** the module counted 5 requests in the last 60 seconds
- **WHEN** a status sync sends one list request
- **THEN** the minute count is 6 and the day count rose by 1

#### Scenario: Document detail request counts
- **WHEN** the module reads the detail of one document
- **THEN** the minute count and the day count each rise by 1

#### Scenario: PDF download counts
- **WHEN** the module downloads the PDF of one document
- **THEN** the minute count and the day count each rise by 1

#### Scenario: PDF download for a delivery counts
- **WHEN** a shop-mail delivery downloads the PDF twice because the first answer was "not ready"
- **THEN** the minute count and the day count each rise by 2

#### Scenario: Status check refused at the cap
- **GIVEN** the minute count has reached the minute cap
- **WHEN** a status list request is about to start
- **THEN** it is refused, nothing is sent and the count does not change

#### Scenario: PDF download refused at the cap
- **GIVEN** the day count has reached the day cap
- **WHEN** a PDF download is about to start
- **THEN** it is refused, nothing is sent and the day count does not change

### Requirement: A refused request is reported and not retried in place
When the limit refuses a request, the caller SHALL learn that it was refused and which window refused it: the minute, the day or the back-off. The module SHALL NOT wait for the window and SHALL NOT send the request again within the same shop request. A refusal SHALL NOT be reported as a success, as an empty result or as an iFirma error. The exact seconds to wait concern the back office only; a customer is always told 60 (see `customer-receipt-visibility`).

#### Scenario: Employee downloads a PDF at the cap
- **GIVEN** the minute cap is reached
- **WHEN** an employee with the view level downloads the PDF of a document from the back office
- **THEN** the answer is HTTP 429 with a `Retry-After` header of the seconds until the module can send again, which is when enough of the counted requests are older than 60 seconds (1 to 61), and a message that names the minute limit
- **AND** no request is sent to iFirma and no PDF bytes are returned

#### Scenario: Refusal during back-off
- **GIVEN** iFirma's limit answer started a back-off that ends in 40 seconds
- **WHEN** an employee downloads a PDF in the back office
- **THEN** the answer is HTTP 429 and `Retry-After` is at most 40 seconds

#### Scenario: No retry inside the request
- **WHEN** a PDF download is refused by the limit
- **THEN** the module makes no second attempt and no wait before answering

#### Scenario: Download works again after the window
- **GIVEN** a PDF download was refused in one minute
- **WHEN** the same download is requested in the next minute and the caps allow it
- **THEN** the PDF is returned
