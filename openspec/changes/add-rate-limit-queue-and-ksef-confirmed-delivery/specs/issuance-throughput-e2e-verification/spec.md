# Spec Delta

## Purpose

End-to-end proof, on the Docker test stack against the iFirma mock and its SMTP sink, that the module issues every document of a burst of at least 50 paid orders exactly once while honouring iFirma's per-minute and per-day limits, whether the module or iFirma refuses the requests.

## ADDED Requirements

### Requirement: Fifty documents in a row within the module's own caps
The smoke run SHALL have a scenario R in which at least 50 orders reach the trigger status one right after another. The mock runs with the iFirma limits (100 per minute, 15000 per day) and the module with its default minute cap. The cron endpoint then runs until no order is queued, or until a deadline of 8 minutes.

#### Scenario: Every order gets exactly one document
- **WHEN** 50 new orders reach the trigger status in a row and the cron has drained the queue
- **THEN** each of the 50 orders has exactly one issued module record
- **AND** the mock holds exactly 50 new documents, one per order stamp
- **AND** no record of the 50 is in state `error`, `unknown` or `duplicate_suspected`

#### Scenario: Every order is recorded at once
- **WHEN** the 50 orders were moved to the trigger status and before the cron runs
- **THEN** each of the 50 orders has a module record that is issued or `queued`, and none of them has no record

#### Scenario: The local cap engaged
- **WHEN** the 50 orders were moved to the trigger status
- **THEN** at least one of them was queued before the cron ran

#### Scenario: The module never tripped iFirma's minute limit
- **WHEN** the scenario finishes
- **THEN** the mock reports no refused call and a busiest minute of at most the module's minute cap

#### Scenario: Every customer received one e-mail
- **WHEN** the scenario finishes
- **THEN** the SMTP sink holds exactly one delivery e-mail for each of the 50 orders

### Requirement: Server-side minute limit during a burst
Scenario R SHALL also lower the mock's per-minute limit below the module's cap, as another client of the same account would. In that setup, iFirma's refusal SHALL lead to queued orders and a back-off, never to errors or duplicates.

#### Scenario: Refusal from iFirma queues the rest
- **WHEN** the mock's per-minute limit is 30, the module's cap is 80, and 20 orders reach the trigger status in a row
- **THEN** the mock refuses at most one call before the module backs off
- **AND** every order without a document is queued, none is in state `error` or `unknown`

#### Scenario: Drained after the window
- **WHEN** the next minute is simulated on the mock, the module's back-off has ended and the cron runs
- **THEN** all 20 orders have exactly one document each and nothing is queued

### Requirement: Daily limits during a burst
Scenario R SHALL drive the run into iFirma's per-day limit and into the module's own per-day cap. In both cases the orders SHALL be queued without errors and SHALL be issued after the next day is simulated.

#### Scenario: iFirma's daily limit
- **WHEN** the mock's per-day limit is set to 15 calls above today's count and 10 orders reach the trigger status in a row
- **THEN** the mock refuses at most one call, the orders without a document are queued, and the module makes no further call while its back-off lasts
- **AND** after the next day is simulated on the mock and the module's back-off is cleared, the cron issues every queued order once

#### Scenario: The module's daily cap
- **WHEN** the module's per-day cap is set to 10 above its current day count and 10 orders reach the trigger status in a row
- **THEN** the mock refuses no call and the orders the cap refused are queued
- **AND** after the module's day counter is cleared, the cron issues every queued order once

### Requirement: Scenario isolation and runtime
Scenario R SHALL restore the mock limits, the module's caps, counters and back-off, and the cron item cap to their values from before the run, even when an assertion fails. It SHALL run on its own with `SMOKE_ONLY=R`, and the smoke README SHALL state its expected duration.

#### Scenario: Later scenarios unaffected
- **WHEN** the full smoke run continues after scenario R
- **THEN** the scenarios after R see the default limits and an empty queue

#### Scenario: Run alone
- **WHEN** the smoke run is started with `SMOKE_ONLY=R`
- **THEN** only scenario R runs and the run reports its checks
