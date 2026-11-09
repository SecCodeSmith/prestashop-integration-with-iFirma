# Security Policy

## Supported versions

Security fixes are released for the latest minor release line and for the
previous minor release line. Older lines are not maintained. See
[CHANGELOG.md](CHANGELOG.md) for the released versions and what each one changed.

## Reporting a vulnerability

**Do not report security vulnerabilities in public issues, discussions or pull
requests.** Report them privately, using one of these channels:

1. GitHub private vulnerability reporting: open the repository's
   **Security** tab and choose **Report a vulnerability**
   (<https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/security/advisories/new>).
2. E-mail: <admin@seccodesmith.pl>.

## What to include

* The module version and the PrestaShop and PHP versions you tested with
* The affected file, endpoint, hook or admin action
* The attack scenario with concrete input, and what an attacker gains
* Steps or a request that reproduces the issue, ideally as a failing test
* Your suggested fix, if you have one

Do not include real API keys, tokens, passwords or customer data in a report. Use
placeholders and synthetic orders. If a secret or personal data has already been
exposed, say so in the report so that it can be rotated or removed.

## Response and fix targets

* First response within **5 business days** of receiving the report.
* Fix targets by severity, counted from the confirmed report:
  * Critical: fix released within **7 days**.
  * High: fix released within **30 days**.
  * Medium and low: scheduled into the next release of a supported line.
* We agree a disclosure date with the reporter. The default is coordinated
  disclosure: the advisory is published when the fixed version is released, and
  the reporter is credited unless they ask not to be.

## Scope

In scope:

* The module code in this repository (`ifirma.php`, `classes/`, `controllers/`,
  `mails/`, `views/`, `upgrade/`, `sql/`, `translations/`)
* The release pipeline and build tooling of this repository (`build/`, the
  GitHub workflows, the packaging scripts and their dependencies)

Out of scope:

* The iFirma service and its API, which are operated by iFirma. Report those
  to iFirma.
* PrestaShop core and third-party PrestaShop modules. Report those to their
  maintainers.
* Vulnerabilities that need a compromised back office administrator account or
  a compromised server, unless the module makes such an attack worse than the
  platform already allows.
* Findings from automated scanners without a demonstrated attack scenario.
