# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Queue-first issuance: a refused document goes to a queue, and an in-flight marker is written before the create request.
- Cron runner with a run lock, a queue job and the `IFIRMA_CRON_MAX_ITEMS` limit.
- KSeF submitter setting, a state read before every submission, and delivery only after KSeF acceptance.
- Rate guard with per-minute and per-day windows (`IFIRMA_RATE_MAX_PER_DAY`).
- Recognition of iFirma's rate limit answer (HTTP 429), with back-off.
- Stale-queue and KSeF alerts, a customer view and a download gate for documents still waiting.
- Admin panel queue and KSeF states, and sorting of the Orders list by document state.

### Changed

- Upgrade script for module version 1.8.0.

## [1.7.0] - 2026-11-08

Compare: [v1.5.0...v1.7.0](https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.5.0...v1.7.0)

### Added

- Guest data-change requests with one-time codes, auto-accept, mail branding, a stamp prefix, the iFirma template and a shop webpage.

### Changed

- Developer guidelines in `CLAUDE.md`: SOLID, clean code, TDD and BDD, and security rules.

### Fixed

- Guest code verification failed on MariaDB because of a stray `LIMIT` in a `getRow()` statement.
- The account order page lacked the `summary_only` flag that its template reads.
- Security: forged guest and customer requests are rejected, parallel guesses cannot exceed the code attempts, duplicate pipeline starts are blocked, guest tracking no longer shows document details, and the code mail subject no longer carries the order reference.

## [1.5.0] - 2026-11-07

Compare: [v1.4.1...v1.5.0](https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.4.1...v1.5.0)

### Added

- Documents list with bulk actions, an Orders-list issue indicator, a notification tab, permission levels and endpoint hardening.

### Changed

- The README is split into pages under `docs/`, with an architecture page and screenshots.

### Fixed

- Review follow-ups of the receipt operations: list preset and actions, bulk runner, throttle and settings.

## [1.4.1] - 2026-11-06

Compare: [v1.4.0...v1.4.1](https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.4.0...v1.4.1)

### Changed

- The Orders list reads the newest receipt with one `LEFT JOIN` instead of two correlated subqueries per order.

## [1.4.0] - 2026-11-06

Compare: [v1.3.2...v1.4.0](https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.3.2...v1.4.0)

### Added

- iFirma column on the Orders list, through the three order-grid hooks (upgrade 1.4.0 registers them).

### Changed

- Refactoring of the contractor payload and the invoice payload builders into smaller steps, without behaviour change.

### Fixed

- The customer-page panel has its own column, so it spans the hook row.

## [1.3.2] - 2026-11-06

Compare: [v1.3.1...v1.3.2](https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.3.1...v1.3.2)

### Changed

- Refactoring of the issuance pipeline, receipt creation, duplicate guard, admin actions and settings into smaller steps, without behaviour change.

## [1.3.1] - 2026-11-05

Compare: [v1.3.0...v1.3.1](https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.3.0...v1.3.1)

### Added

- Coverage reporting, a changed-line gate and a PHP 7.2 lint in CI.

### Changed

- Refactoring of receipt, contractor and admin code to intention-revealing result objects, without behaviour change.

### Fixed

- A KSeF failure without a message is recorded as a failed submission, not as sent.
- The release push, package guard and CI commit identity are hardened.

## [1.3.0] - 2026-11-04

### Added

- Contractor registration before issuance, with contractor linking and diagnostics.
- Package verification and build metadata.
- Payment method handling and Polish translations.
- Upgrade script for version 1.2.0, which drops PrestaShop 1.7 and adds the admin customer hook.

### Changed

- PrestaShop 8.x support; PrestaShop 1.7 is no longer supported.

[Unreleased]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.7.0...HEAD
[1.7.0]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.5.0...v1.7.0
[1.5.0]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.3.2...v1.4.0
[1.3.2]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/releases/tag/v1.3.0
