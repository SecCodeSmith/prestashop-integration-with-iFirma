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
- **THEN** no limit information is shown and no request or error occurs

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
When the on-demand check cannot be done, the page SHALL say why and SHALL keep the earlier reading and the counters unchanged. Refused by the module's own limit or the authentication cool-down: nothing is sent. iFirma error or unusable answer: a short generic notice, with the detail in the shop log only. The answer SHALL never show a key.

#### Scenario: Refused by the module's limit
- **GIVEN** the minute cap is reached
- **WHEN** the employee clicks the button
- **THEN** no request is sent, the page says the limit was not checked because of the minute limit, and the earlier reading stays

#### Scenario: iFirma answers with an error
- **WHEN** the limit request is sent and iFirma answers with an error
- **THEN** the page shows that the limit is unavailable and the earlier reading stays

#### Scenario: Wrong abonent key
- **WHEN** iFirma refuses the `abonent` key
- **THEN** the page shows that the limit is unavailable, names no key value, and the error detail is in the shop log

#### Scenario: Unusable answer
- **WHEN** iFirma answers without usable numbers
- **THEN** the page shows that the limit is unavailable and the counters are unchanged

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
- **GIVEN** the module counted 5 requests in the current minute
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
- **THEN** it is refused, nothing is sent and the count does not change

### Requirement: A refused request is reported and not retried in place
When the limit refuses a request, the caller SHALL learn that it was refused and which window refused it: the minute, the day or the back-off. The module SHALL NOT wait for the window and SHALL NOT send the request again within the same shop request. A refusal SHALL NOT be reported as a success, as an empty result or as an iFirma error.

#### Scenario: Employee downloads a PDF at the cap
- **GIVEN** the minute cap is reached
- **WHEN** an employee with the view level downloads the PDF of a document from the back office
- **THEN** the answer is HTTP 429 with a `Retry-After` header and a message that names the minute limit
- **AND** no request is sent to iFirma and no PDF bytes are returned

#### Scenario: Refusal during back-off
- **GIVEN** iFirma's limit answer started a back-off that ends in 40 seconds
- **WHEN** an employee downloads a PDF
- **THEN** the answer is HTTP 429 and `Retry-After` is at most 40 seconds

#### Scenario: No retry inside the request
- **WHEN** a PDF download is refused by the limit
- **THEN** the module makes no second attempt and no wait before answering

#### Scenario: Download works again after the window
- **GIVEN** a PDF download was refused in one minute
- **WHEN** the same download is requested in the next minute and the caps allow it
- **THEN** the PDF is returned
