# Contributing to the iFirma module

Thank you for helping improve the iFirma PrestaShop module. This project is
source-available, not OSI open source: it is licensed under the
[SecCodeSmith Commercial License](LICENCE). Read that file before you contribute.

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).

## Licence of contributions

Contributions are accepted under the same terms as the repository
([LICENCE](LICENCE)): your contribution is licensed to the project owner and to
users on the terms of that licence (inbound = outbound). Do not submit code you
do not have the right to contribute.

Every commit must carry a Developer Certificate of Origin sign-off. Add it with
`git commit -s`, which appends a line such as:

```
Signed-off-by: Your Name <you@example.com>
```

## Running the tests

The unit suite needs no PrestaShop shop and no network.

```sh
composer install
composer test
```

* PHPUnit 9.6, configured by `phpunit.xml.dist`.
* Unit tests live in `tests/Unit`. PrestaShop classes are replaced by stubs in
  `tests/Stubs`; shared test setups live in `tests/Support`.
* Tests must be fast, isolated and deterministic: inject the clock, randomness,
  HTTP (`ScriptedTransport`) and database doubles. No real network and no sleeping.

The Docker smoke suite (`tests/smoke`, see `tests/smoke/README.md`) runs the
module end to end against a real PrestaShop container and the iFirma mock. Run it
when your change crosses the shop, API, mail or back-office boundary.

## Test-first development

No production code is written without a failing test that demands it.

1. **Red:** write one small test that describes the next behaviour and run it.
   Show that it fails for the right reason (a missing method or a wrong result,
   not a typo).
2. **Green:** write the simplest code that passes.
3. **Refactor:** with the suite green, remove duplication and improve names. A
   refactor commit changes no behaviour and adds no tests.

A bug fix starts with a test that reproduces the bug.

## OpenSpec flow

Behaviour is specified before code is written.

1. Propose a change under `openspec/changes/<change-name>/` with a proposal,
   design, tasks and specs. Specs use `#### Scenario:` blocks written as
   **Given / When / Then** in the language of the domain (order, receipt,
   invoice, contractor, data request).
2. Cover the unhappy path explicitly: wrong token, foreign customer, duplicate
   document, API error, missing PDF, rejected mail.
3. Each scenario maps to at least one test; each test can be traced to a scenario.
4. Implement the tasks, tick them off, and archive the change when it is done.

## Architecture and code style

Follow [CLAUDE.md](CLAUDE.md): SOLID responsibilities (`Ifirma*Service`
orchestrates, `Ifirma*Builder` shapes data, presenters render, `IfirmaSchema`
owns storage, `IfirmaHttpOutput` owns responses), clean code, and the security
rules. In short: small functions, intention-revealing names, typed exceptions
instead of swallowed errors, and no SQL, API calls or business logic in
`ifirma.php` or in controllers. Keep the code compatible with PHP 7.2 and with
PrestaShop 8.0 to 9.x.

## Coverage

```sh
composer coverage
```

writes `build/coverage.xml`. Every changed line must be covered: the diff-cover
gate requires **100%** of changed lines. Coverage is a floor, not the goal; the
tests must prove behaviour.

## Documentation

If your change alters behaviour, configuration, the upgrade path or the admin
UI, update the matching pages under [`docs/`](docs/) (`usage.md`,
`configuration.md`, `troubleshooting.md`, `upgrading.md`, `architecture.md`,
`development.md`) and the README where relevant.

## Commit style

* One concern per commit. Keep refactors, features and fixes apart.
* Use a short type prefix: `feat:`, `fix:`, `test:`, `docs:`, `refactor:`,
  `chore:`, `release:`.
* Security fixes are never mixed into a feature or release commit. Each accepted
  fix gets its own commit with the subject
  `fix-vuln: <what was exploitable and how it is closed>`. The body names the
  severity and the test that proves the negative case now fails.
* Dependency fixes use `fix-vuln: bump <package> <from> -> <to> (<advisory id>)`,
  one dependency per commit.

## Secrets and personal data

Never commit API keys, tokens, passwords, SMTP credentials, webhook secrets or
customer data, not even in tests, fixtures, docs, logs or screenshots. Use
placeholders such as `IFIRMA_API_KEY=changeme`. Before you push, check the diff
for keys, hashes and e-mail addresses that are not placeholders.

## Pull requests

Open a pull request against `master` and complete the checklist in
[`.github/PULL_REQUEST_TEMPLATE.md`](.github/PULL_REQUEST_TEMPLATE.md). Security
vulnerabilities must not be reported in issues or pull requests; follow
[SECURITY.md](SECURITY.md) instead.
