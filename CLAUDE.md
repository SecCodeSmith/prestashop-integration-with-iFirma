# CLAUDE.md

PrestaShop module `ifirma` (PHP 7.2+, PrestaShop 1.7 / 8.x). It issues receipts and
invoices through the iFirma API, handles customer data requests and ships admin
panels, mails and SQL upgrades. Every change must respect the rules below.

## SOLID

### S - Single Responsibility
- One class, one reason to change. A class either builds a payload, talks to HTTP,
  persists data, renders a view, or decides policy. Never two of these at once.
- Keep the existing split: `Ifirma*Service` orchestrates, `Ifirma*Builder` / `*Payload*`
  shapes data, `Ifirma*Presenter` / `*View` renders, `IfirmaSchema` / `sql/*` owns
  storage, `IfirmaHttpOutput` owns responses. Put new behaviour where its
  responsibility already lives; create a new class instead of growing a god class.
- `ifirma.php` is glue only: hook registration, wiring and delegation. No business
  logic, SQL or API calls in the module class or in controllers.
- A function does one thing at one level of abstraction. If you need "and" to
  describe it, split it.

### O - Open for extension, closed for modification
- Add behaviour by adding a class, a strategy or a hook, not by editing a working
  class and adding another `if` / `switch` branch on a type flag.
- Prefer polymorphism, maps of handlers, or small interfaces over type checks
  (`instanceof`, string type codes) scattered through callers.
- Public method signatures, configuration keys, hook names, DB columns and mail
  template names are contracts. Extend them additively; never rename or change
  meaning without an upgrade script and a documented migration.
- Settings go through `IfirmaSettings`; new options are added there and in
  `tests/Unit/ConfigurationKeysTest.php`, not read from `Configuration::get()` ad hoc.

### L - Liskov Substitution
- A subclass or implementation must be usable wherever its parent or interface is
  expected: same preconditions or weaker, same postconditions or stronger, same
  exceptions or a subset.
- Never override a method to throw "not supported", return `null` where the parent
  returns an object, or silently ignore arguments.
- Test doubles and fakes used in `tests/` must honour the same contract as the real
  class, otherwise the test proves nothing.

### I - Interface Segregation
- Keep interfaces small and role-based (a notifier sends, a matcher matches, a guard
  checks). No client should depend on methods it does not call.
- Pass only what a collaborator needs: an order id or a value object, not the whole
  `Context`, `Module` or `$_POST`.
- Do not force a class to implement a fat interface by stubbing out methods.

### D - Dependency Inversion
- High-level policy (services) depends on abstractions, not on concrete HTTP
  clients, `Db::getInstance()`, `Mail::Send()`, `Tools::getValue()` or `time()`.
  Inject those through constructor parameters with sensible defaults so production
  wiring stays simple and tests can substitute them.
- No `new ConcreteCollaborator()` inside business logic; construct at the edge
  (module class, controller, factory) and pass in.
- Side effects (clock, randomness, I/O, PrestaShop statics) sit behind an injectable
  seam so unit tests run without a shop.

## Clean code

- Code is self-explaining. Names say what and why; comments explain only the
  non-obvious *why* (a PrestaShop quirk, an iFirma API constraint, a security
  reason). Delete comments that restate the code and dead or commented-out code.
- Intention-revealing names: `receiptAlreadyIssuedFor($order)` not `check($o)`.
  No abbreviations except domain ones (`vat`, `nip`, `id_order`).
- Small functions, early returns, no deep nesting, no boolean flag parameters.
  Replace magic numbers and strings with named constants.
- No duplication. Three similar lines are a candidate helper; two similar classes
  are a candidate abstraction, but do not abstract before the second real use.
- Fail loudly and precisely: typed exceptions with actionable messages, never empty
  `catch`, never swallowed errors, never `@` suppression.
- Immutable value objects where data crosses a boundary; arrays only for
  short-lived local shapes. Validate at the boundary, trust inside.
- Keep PrestaShop compatibility: no PHP syntax beyond the declared minimum, no
  features that differ between 1.7 and 8.x without a guarded fallback.
- Every behaviour change ships with a unit test in `tests/Unit` (golden fixtures in
  `tests/fixtures` when output is structural) and the docs under `docs/` updated.
  Run the full suite and the diff-cover gate before declaring done.
- Leave the code cleaner than you found it, but keep unrelated refactors out of a
  focused change. One concern per commit.

## TDD and BDD

Development is test-first. No production code is written without a failing test
that demands it, and no change is done until the whole suite is green.

### The loop (TDD)
1. **Red:** write one small test in `tests/Unit` that describes the next behaviour
   and fails for the right reason (a missing method or wrong result, not a typo).
   Run it and show the failure output.
2. **Green:** write the simplest production code that passes. No speculative
   generality, no extra branches "while you are here".
3. **Refactor:** with the suite green, remove duplication, improve names and apply
   the SOLID and clean-code rules above. Tests stay green at every step; refactor
   commits change no behaviour and add no tests.
4. Repeat in small increments. A bug fix starts with a test that reproduces the bug.
- Run the suite with `composer test` (PHPUnit 9.6, `phpunit.xml.dist`). It needs no
  shop: PrestaShop classes come from `tests/Stubs`, shared setups from
  `tests/Support`. Extend a stub only with behaviour the real class has.
- Unit tests are fast, isolated and deterministic: inject clock, randomness, HTTP
  (`ScriptedTransport`) and DB doubles. No network, no sleeping, no real shop.
- One behaviour per test, named after the behaviour:
  `testReceiptIsNotIssuedTwiceForTheSameOrder`, not `testCreate2`. Arrange / Act /
  Assert, one logical assertion group, no conditionals or loops inside a test.
- Prefer assertions on observable behaviour (returned values, persisted rows, sent
  payloads, rendered output) over mock interaction counts. Mock only at the seams
  (iFirma API, mail, DB, clock).
- Golden tests (`*GoldenTest.php`, fixtures in `tests/fixtures/*/golden.json`) guard
  structural output such as payloads, settings and panels. Update a golden file only
  with a deliberate, reviewed behaviour change, never to make a red test pass.
- Coverage: `composer coverage` writes `build/coverage.xml`; every changed line must
  be covered (diff-cover gate) before the work is declared done. Coverage is a
  floor, not the goal; tests prove behaviour, not line counts.
- Never delete, skip or weaken a test to go green. If a test is wrong, fix it in a
  commit that explains why its expectation changed.

### Behaviour first (BDD)
- Start every feature or fix by writing its behaviour in Given / When / Then before
  touching code, in the language of the domain (order, receipt, invoice, contractor,
  data request, delivery), not of the implementation:
  *Given a paid order of a customer with a pending data request, when the order
  state changes, then no document is issued until the request is approved.*
- Those scenarios become the OpenSpec requirements and specs under `openspec/`
  (`#### Scenario:` blocks with Given / When / Then) and are the acceptance
  criteria. Each scenario maps to at least one test; each test can be traced to a
  scenario. Keep them in sync when either changes.
- Unit tests express scenarios at class level; the smoke run (`tests/smoke/run.php`,
  the lettered scenarios in `tests/smoke/README.md`) expresses them end to end
  inside the PrestaShop container against the iFirma mock and SMTP sink in
  `test-stack/`. A new user-visible behaviour gets a unit scenario always and a
  smoke scenario when it crosses the shop, API, mail or admin boundary.
- Cover the negative and edge scenarios explicitly: wrong token, foreign customer,
  duplicate document, API error, missing PDF, rejected mail. The unhappy path is a
  first-class scenario, not an afterthought.
- Scenario names and test names are the living documentation. When behaviour is
  unclear, read the scenarios first; when docs under `docs/` describe behaviour,
  they must agree with the scenarios.
- Definition of done for any change: scenarios written, failing tests shown, code
  green, refactored, smoke scenario run when applicable, diff coverage met, docs and
  specs updated, `graphify update .` run.

## Security

- **No secrets in the repository.** API keys, tokens, passwords, SMTP credentials,
  webhook secrets and customer data never appear in code, tests, fixtures, docs,
  logs, commit messages or `graphify-out/`. Use `Configuration` values entered in the
  back office, environment variables in `test-stack/`, and placeholders such as
  `IFIRMA_API_KEY=changeme` in examples. Before committing, grep the diff for keys,
  hashes and e-mail addresses that are not placeholders.
- Never log or echo secrets or full request bodies that contain them. Mask keys to
  their last four characters when they must be shown in the UI.
- **Validate and type all input** from `Tools::getValue()`, request bodies, webhooks
  and the iFirma API responses. Whitelist allowed values, cast ids to `int`, reject
  unexpected shapes before they reach a service.
- **Database:** use `pSQL()` / `bqSQL()` or prepared statements for every value in
  SQL, `(int)` casts for ids, and never interpolate raw input into a query, table
  or column name.
- **Output encoding:** escape everything rendered in Smarty, mails and JSON
  (`htmlspecialchars`, `|escape:'htmlall'`, `json_encode` with safe flags). Treat
  customer, order and API data as untrusted.
- **Authentication and authorisation:** admin actions require a valid employee
  token (`Tools::getAdminTokenLite` / controller token checks) and the module's
  access rights. Front controllers that expose data must verify ownership
  (customer id, signed token, expiry) and must never leak another customer's data.
  Deny by default.
- **CSRF and HTTP methods:** state-changing endpoints accept only POST with a valid
  token. Read-only endpoints must not mutate state.
- **Cryptography:** signed links and tokens use `hash_hmac` with a per-shop secret
  and `hash_equals` for comparison. Use `random_bytes` / `bin2hex` for tokens, never
  `rand`, `uniqid` or `md5` of predictable values. No home-made crypto.
- **Rate limiting and abuse:** public endpoints (data requests, webhooks) keep the
  existing throttle and duplicate guards; new ones must add equivalent protection.
- **Transport:** outbound calls to iFirma use HTTPS with certificate verification on,
  explicit timeouts and no credentials in URLs.
- **Files and paths:** never build file paths from user input; no `include` or
  `require` of dynamic names; uploads, if ever added, are validated by type and
  size and stored outside the web root.
- **Errors:** user-facing messages are generic; details go to the PrestaShop
  logger without secrets or personal data. No stack traces in responses.
- **Personal data (GDPR):** collect the minimum, keep it only as long as the data
  request flow requires, and make deletion paths complete (DB rows, mails, logs).
- **Dependencies:** pin versions in `composer.json` / `package.json`, review
  lockfile changes, and do not add a dependency for something a few lines of code
  can do safely.
- Security-sensitive changes (auth, tokens, SQL, crypto, endpoint exposure) get a
  dedicated test proving the negative case (wrong token, foreign customer, bad
  method) is rejected.

## Red team after major updates

A major update is any of: a release or version bump, a merged OpenSpec change,
a new or changed endpoint, hook, admin action, auth or token flow, SQL or upgrade
script, a dependency upgrade, or a PrestaShop / PHP target change. After such an
update, before it is released, run a red-team pass. Nothing ships with an open
finding of severity high or critical.

### The red team
- The manager spawns a team of **independent attacker agents in parallel**, one
  per dimension, each with a self-contained brief, read access to the code and the
  graph (`graphify query` first), and the instruction to think like an attacker,
  not a reviewer. Use the security agents available (`security-review`,
  `security-scanning:security-sast`, `*security-auditor`, `team-reviewer` on the
  security dimension) and cheap models for the sweep; escalate only to verify.
- Dimensions, each owned by one agent:
  1. **Injection:** SQL (`Db`, raw queries, `pSQL` / `(int)` gaps), Smarty / HTML /
     mail output, JSON, headers, file paths, dynamic includes.
  2. **Authentication and authorisation:** admin tokens and access rights, module
     permission levels, front controllers (ownership, signed links, expiry), cron
     token, CSRF on state changes, HTTP method enforcement.
  3. **Secrets and cryptography:** keys in code, fixtures, logs, `graphify-out`,
     mails, screenshots; weak randomness; timing-unsafe compares; masking in the UI.
  4. **Data exposure and privacy:** another customer's documents or data, PDF
     download throttle, error messages, logger content, GDPR deletion paths.
  5. **Abuse and availability:** rate limits, duplicate guards, retry loops, bulk
     runner, webhook and data-request endpoints hammered or replayed.
  6. **Dependencies and supply chain:** see below.
- Each agent reports findings in a fixed shape: title, severity (critical / high /
  medium / low / info), file:line, attack scenario with concrete input, evidence
  (code or a reproducing test or request), proposed fix. No finding without an
  attack scenario; "looks suspicious" is not a finding.
- The manager **verifies every high and critical finding independently** before it
  is accepted (reproduce with a unit test, a smoke step or a request against the
  test-stack). Unverified findings stay in the report as "unconfirmed".
- The report lands in `build/red-team/<date>-<version>.md` (local, not committed)
  and its accepted findings become OpenSpec scenarios or issues so they are tracked.

### Dependency vulnerability check
- Audit every manifest on every major update and whenever a lockfile changes:
  - PHP (dev tooling): `composer audit` from the scratchpad toolchain or inside the
    test-stack container (no composer on the host PATH).
  - Node (build scripts): `npm audit --audit-level=moderate`.
  - Python helpers in `tools/` and `test-stack/`: check the imports with the pypi
    vulnerability tools or `pip-audit`.
  - Docker images in `test-stack/*.yml`: pinned tags, no `latest`; note the upstream
    PrestaShop / PHP advisories that apply to the targeted versions.
- A vulnerable dependency is fixed by upgrading to the first patched version, with
  the lockfile diff reviewed. If no patched version exists, remove or replace the
  dependency, or document the accepted risk with the reason and an expiry date in
  the red-team report. Never silence an audit with an ignore list without that note.
- Shipped module code has no runtime Composer or npm dependencies; keep it that
  way. Anything added to `composer.json` or `package.json` is dev tooling only and
  must not end up in the built zip (`npm run verify:package` checks this).

### Fixing findings: separate, flagged commits
- Security fixes **never ride along in a feature, refactor or release commit**.
  Each accepted finding gets its own commit (or one commit per tightly related
  group) so it can be reviewed, cherry-picked and reverted on its own.
- The commit subject carries the `fix-vuln` flag as its type:
  `fix-vuln: <what was exploitable and how it is closed>`, for example
  `fix-vuln: reject PDF download for another customer's document`. The body names
  the red-team dimension, the severity, the report file and the test that proves
  the negative case now fails closed.
- A fix follows the TDD loop: first the reproducing test (the attack succeeds),
  then the fix (the attack is rejected), then the refactor. The test stays in
  `tests/Unit` or the smoke run as a permanent regression guard.
- Dependency fixes use the same flag: `fix-vuln: bump <package> <from> -> <to>
  (<advisory id>)`, one dependency per commit, lockfile included.
- After the fixes, the red team re-runs on the touched dimensions, and the release
  notes list the `fix-vuln` commits without exploit details beyond what the
  advisory already discloses.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- When graphify-out/graph.json exists, run `graphify query "<question>"` before your first grep, search or source-file read for a codebase question, and put this rule in every subagent prompt that explores code. Read raw files only for lines the graph or an earlier read already located. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
- A committed graphify-out/graph.json is rewritten whole on every build, so a large diff to it in a pull request is expected. Do not flag it as churn or suggest skipping the graph rebuild or its hooks.
