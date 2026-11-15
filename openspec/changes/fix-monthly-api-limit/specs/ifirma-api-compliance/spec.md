## MODIFIED Requirements

### Requirement: Optional limit visibility
When the optional `abonent` key is configured, the module configuration page SHALL be able to display the used and granted monthly API limit of the iFirma account from `abonent/limit.json`; when it is not configured, the feature SHALL be hidden without error. The page SHALL present the figures as the monthly API limit and SHALL NOT call them requests. Opening the page SHALL send no request to iFirma: the page SHALL show the last stored reading with its time, and SHALL read a fresh one only on the employee's request. A reading from an earlier day of the current calendar month is still this month's figure and SHALL be shown as such with its time; a reading from an earlier calendar month SHALL be shown as outdated.

#### Scenario: Key configured
- **GIVEN** the monthly API limit was read at 10:02 as 1,900 used of 2,000
- **WHEN** the admin opens the configuration page with the `abonent` key set
- **THEN** the used, granted and remaining monthly API limit (1,900, 2,000 and 100) are displayed with the time 10:02
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

#### Scenario: Reading from an earlier day of this month
- **GIVEN** today is 2026-11-15 and the last reading is from 2026-11-12
- **WHEN** the admin opens the configuration page
- **THEN** the page shows the reading as this month's monthly API limit with its date and time of reading
- **AND** the page does not call it outdated

#### Scenario: Reading from an earlier month
- **GIVEN** today is 2026-11-02 and the last reading is from 2026-10-31
- **WHEN** the admin opens the configuration page
- **THEN** the page shows the reading with its date and says it is outdated and no longer applies to this month

#### Scenario: Monthly limit used up
- **GIVEN** the last reading of this month says 2,000 used of 2,000
- **WHEN** the admin opens the configuration page
- **THEN** the page shows 0 remaining and warns that iFirma does not accept documents through the API until the limit is renewed

## REMOVED Requirements

### Requirement: Reading the current limit from iFirma
**Reason**: iFirma's `abonent/limit.json` reports the monthly API limit, not the daily request quota. Raising the day counter to it and lowering the day cap to the granted figure mixed two different limits.
**Migration**: Replaced by "Monthly API limit read from iFirma" and "A limit reading leaves the module's request counters alone". No data migration: an inflated day counter restarts at the next calendar day, and the stored reading keeps its format.

## ADDED Requirements

### Requirement: Monthly API limit read from iFirma
With the `abonent` key set, the module SHALL read the monthly API limit from iFirma (`abonent/limit.json`, used and granted) at the start of a cron run, keep the reading for ten minutes, and store it. A reading SHALL NOT change the module's request counters or any of its caps. Without the key, or when iFirma gives no usable answer, the module SHALL change nothing and SHALL NOT stop the other cron jobs.

#### Scenario: Reading is reported by the cron
- **GIVEN** iFirma reports 1,900 used of 2,000 granted
- **WHEN** the cron reads the limit
- **THEN** the cron answer's `limit` block says 1,900 used, 2,000 granted and 100 remaining

#### Scenario: A fresh reading is reused
- **GIVEN** the limit was read less than ten minutes ago, on this or the previous day
- **WHEN** the cron runs again
- **THEN** iFirma is not asked and the earlier reading is reported

#### Scenario: An older reading is read again
- **GIVEN** the limit was read more than ten minutes ago
- **WHEN** the cron runs
- **THEN** iFirma is asked and the new reading is stored

#### Scenario: iFirma does not answer
- **GIVEN** the limit cannot be read (no key, an error, or an unusable answer)
- **WHEN** the cron runs
- **THEN** the counters and the earlier reading are unchanged, the `limit` block holds nulls and the queue, KSeF, sync and delivery jobs still run

### Requirement: A limit reading leaves the module's request counters alone
A reading of the monthly API limit, by the cron job `limit` or by the employee's check, SHALL store the reading and SHALL change neither the module's day window counter, nor its minute window, nor the day cap or the minute cap. The day cap SHALL always be the configured `IFIRMA_RATE_MAX_PER_DAY` (default 14000). The request that reads the limit SHALL be counted like every other request, as kind `limit`.

#### Scenario: The day counter is not raised
- **GIVEN** the module's day counter reads 300 and iFirma reports 1,900 used of 2,000 granted
- **WHEN** the cron reads the limit, or the employee clicks "Check the remaining iFirma limit"
- **THEN** the stored reading is 1,900 of 2,000
- **AND** the module's day counter reads 301, the one request of the reading itself

#### Scenario: The day counter is not lowered or touched by a smaller reading
- **GIVEN** the module's day counter reads 1,500 and iFirma reports 1,200 used
- **WHEN** the cron reads the limit
- **THEN** the day counter reads 1,501

#### Scenario: A granted limit below the configured cap lowers nothing
- **GIVEN** the day cap is configured as 14,000 and iFirma reports 2,000 granted and 1,900 used
- **WHEN** a request is about to start and the module counted 2,500 requests today
- **THEN** the guard admits it

#### Scenario: A reading above the configured day cap does not refuse the next request
- **GIVEN** the day cap is configured as 14,000, the module counted 100 requests today and iFirma reports 20,000 used of 20,000 granted
- **WHEN** a request is about to start
- **THEN** the guard admits it and the request is sent

#### Scenario: A refused or failed check changes no counter
- **GIVEN** the minute cap is reached, or iFirma answers the check with an error
- **WHEN** the employee clicks the check
- **THEN** the stored reading is unchanged and the day counter holds only the request that was sent, if any

### Requirement: The module requests box shows only the module's own requests
The "Module requests" box of the configuration page SHALL show the module's own requests of the last 60 seconds and of today as `<module count> of <configured cap>`, and today's count per kind. It SHALL NOT show a row for other clients of the account: the module has no read endpoint for the account's daily request count and SHALL NOT infer one. Whatever a limit reading reported SHALL NOT appear in the counts.

#### Scenario: Day row
- **GIVEN** the module sent 546 requests today and the configured day cap is 14,000
- **WHEN** an employee opens the configuration page
- **THEN** the day row reads 546 of 14,000

#### Scenario: No other-clients row
- **GIVEN** the sum of today's kinds is lower than the day count
- **WHEN** an employee opens the configuration page
- **THEN** the box lists the kinds only and shows no row for other clients

#### Scenario: A reading does not change the box
- **GIVEN** the module sent 100 requests today and the limit was read as 1,900 of 2,000
- **WHEN** an employee opens the configuration page
- **THEN** the day row reads 101 of 14,000 (the reading's own request included) and the kind `limit` reads 1
