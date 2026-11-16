# Tasks

Every task follows the TDD loop from CLAUDE.md: write the failing test, show its red output, make it green, then refactor. Run `composer test` (scratchpad PHP toolchain or the test-stack container) after each task, and confirm the suite is green before ticking the box.

## Pull request slices

- PR A `fix/my-invoices-page`: this plan, group 1 and group 2 (the customer-facing bug fix ships first).
- PR B `feat/native-invoice-link` (stacked on A): groups 3 and 4.
- PR C `test/my-invoices-smoke` (stacked on B): group 5, plus any `fix-vuln:` commits from the red team.

## 1. One "can be handed out" rule (refactor, no behaviour change)

- [x] 1.1 Pin today's behaviour first: add `CustomerViewTest` cases showing that `forAccount()` and `forOrder()` give the same link decision for issued, KSeF-waiting, tokenless, missing and error rows. Verify they pass on the current code.
- [x] 1.2 Move the duplicated rule from `forAccount()` and `buildDocument()` into one public `IfirmaCustomerView` method (status, KSeF fields, token → URL or `null`), as design D3 describes. Verify 1.1 and the whole suite stay green and no golden file changes. This is its own `refactor:` commit.

## 2. "My invoices" page and account tile (the bug fix)

- [ ] 2.1 BDD first: copy the "Account block", "Account entry works without theme scripts", "My invoices page" and "My invoices page access" scenarios into a scenario-to-test checklist in the test file headers. Verify each scenario names at least one test.
- [ ] 2.2 Red, then green: `forAccount()` returns `page_url` (the module link to `documents`). Verify with a new `CustomerViewTest` case.
- [ ] 2.3 Red, then green: in `customer_account.tpl` the tile is a plain link to `page_url` with the count badge. No `data-toggle`, `data-bs-toggle` or `collapse`, and no list inside the tile. Verify with a template test in `ModuleCustomerHooksTest` that reads the file, like `testTheCustomerPanelTemplateFillsTheHookRow`.
- [ ] 2.4 Red, then green: `controllers/front/documents.php` (`IfirmaDocumentsModuleFrontController`). A logged-out visitor is redirected to login with `back` set to the page. A logged-in customer gets `ifirma_account` built only from their own id; an `id_customer` request parameter is ignored. No `postProcess()`, no iFirma client built. Verify with a new `DocumentsFrontTest` that uses an overriding twin, like `FrontControllerEndingsTest`, and checks that no DB write or HTTP call is recorded (`ScriptedTransport` empty, `IfirmaTestState` writes empty).
- [ ] 2.5 Red, then green: `views/templates/front/documents.tpl` extends `customer/page.tpl`. It shows one row per document (number, issue date, status label, external badge, download link only when `download_url` is set, no `target="_blank"`), the pending count and the empty message, and escapes every value with `|escape:'html':'UTF-8'`. Verify with a template test that checks the escaping on number and status and that no script toggle is present.
- [ ] 2.6 Breadcrumb ("Your account", "My invoices", with `addMyAccountToBreadcrumb()` guarded by `method_exists`) and page meta title. Verify with a `DocumentsFrontTest` case on `getBreadcrumbLinks()`.
- [ ] 2.7 CSS: drop the collapse-only list rules from `views/css/ifirma.css` and add the page list styles. Verify `grep -n collapse views/css/ifirma.css` prints nothing and the page renders styled in the BO/FO visual check (puppeteer recipe) on Classic and Hummingbird.
- [ ] 2.8 Translations: English sources "My invoices" and "You have no invoices yet."; Polish "Moje Faktury" and "Nie masz jeszcze faktur."; remove the stale "Moje dokumenty iFirma" entry. Verify `TranslationCoverageTest` is green.
- [ ] 2.9 Docs: `docs/usage.md` (customer side: "Moje Faktury" tile and page) and `docs/troubleshooting.md` ("the invoices tile does nothing": the cause and the fix version, plus the theme override path for `documents.tpl`). Verify both files mention the page and agree with the scenarios.

## 3. Built-in invoice link setting

- [ ] 3.1 Red, then green: add `IFIRMA_NATIVE_INVOICE_LINK` (bool, default on) to `IfirmaSettings`, `tests/Unit/ConfigurationKeysTest.php` and the settings golden fixture under `tests/fixtures/settings`. Treat the golden update as a deliberate, reviewed change. Verify `ConfigurationKeysTest` and the settings golden test pass.
- [ ] 3.2 Red, then green: the configuration form shows the switch, plus the `PS_INVOICE`-off warning only while the switch is on and `PS_INVOICE` is off. `PS_INVOICE` is read through the injected configuration seam. Verify with form tests for all four on/off combinations.
- [ ] 3.3 Docs: `docs/configuration.md` describes the setting, its default and the warning. Verify the text matches the "Built-in invoice link setting" scenarios.

## 4. Built-in invoice link

- [x] 4.1 Check that `PdfInvoiceController::display()` runs `actionPDFInvoiceRender` with `order_invoice_list`, after the ownership and `secure_key` checks, on every PrestaShop tag the Smoke workflow covers: `grep` the core file inside each container. Record the result per tag in design.md Risks. If a tag lacks the hook, record that it falls back to PrestaShop's invoice; don't add a workaround.
- [ ] 4.2 Red, then green: `IfirmaNativeInvoiceOutcome` (immutable: `passThrough`, `redirectTo(url)`, `notAvailable`). Verify with a unit test of its three constructors and accessors.
- [ ] 4.3 Red, then green: `IfirmaNativeInvoiceLink::decide($idOrder, $customerLoggedIn)` with injected setting, document lookup, pending-request lookup and the D3 rule. Write one test per "Orders the module handles" and "Built-in invoice link …" scenario: setting off → pass; no record and no pending request → pass; pending request with no record → not available; KSeF wait, held, verifying, failed, missing, tokenless → not available; guest → not available; downloadable → redirect to the receipt link of the stored token. Verify with a new `NativeInvoiceLinkTest`.
- [ ] 4.4 Red, then green: `IfirmaHttpOutput::notAvailable($message)` sends a 404 status line, `text/plain; charset=utf-8`, `no-store` and `nosniff`, and echoes the message. Verify with the header sink in the existing `IfirmaHttpOutput` tests.
- [ ] 4.5 Red, then green: `hookActionPDFInvoiceRender()` in `ifirma.php` is wiring only. It reads `id_order` from the first `OrderInvoice` and casts it to `(int)`; a missing, empty or non-object list passes through. It calls the policy and acts on the outcome (redirect, not-available + exit, return). Use protected seams so a test twin records the redirect or exit instead of ending. Verify with `ModuleCustomerHooksTest` cases, including a negative test that a crafted non-collection `order_invoice_list` passes through untouched.
- [ ] 4.6 Register the hook in `install()` and add `upgrade/upgrade-1.11.0.php` (register `actionPDFInvoiceRender`, set `IFIRMA_NATIVE_INVOICE_LINK` to 1). Verify with `ModuleHooksTest` (the hook is in the install list) and a new upgrade test like the existing `upgrade-1.8.0` test. Don't edit any version string.
- [ ] 4.7 Docs: `docs/usage.md` (built-in invoice link, guest behaviour), `docs/upgrading.md` (1.11.0: hook registered, setting on, guests no longer get PrestaShop's invoice for handled orders, how to turn it off), `docs/troubleshooting.md` (another module on `actionPDFInvoiceRender`; no invoice link when `PS_INVOICE` is off). Verify the docs agree with the scenarios.

## 5. Smoke scenario and integration checks

- [ ] 5.1 Add smoke scenario T to `tests/smoke/run.php` and `tests/smoke/README.md`, on the test stack with Hummingbird and Classic. A logged-in customer opens My account and sees "Moje Faktury" (Polish customer). Following the tile, the page lists the issued document. Its link returns `application/pdf`. A KSeF-waiting document has no link. With `PS_INVOICE` on, `pdf-invoice&id_order=` of the iFirma order returns the iFirma PDF (the mock's bytes, not a TCPDF invoice). A foreign order id gets PrestaShop's refusal. A guest `secure_key` request gets the not-available text. With the setting off, PrestaShop's invoice comes back. The scenario restores every setting it changes. Verify a green smoke run on every Smoke workflow tag, with the output kept in `build/`.
- [ ] 5.2 Upgrade check: upgrade a shop from the current release to this branch and confirm that the hook is registered, the setting is on and the tile opens the page. Verify with the upgrade-test recipe and record the result in `tests/smoke/README.md` "Verified runs".
- [ ] 5.3 Full gate: `composer test` green, `composer coverage` followed by diff-cover at 100% of changed lines locally, `npm run verify:package` (no new files outside the module tree), then `graphify update .`. Verify by pasting the raw outputs into the PR description.
- [ ] 5.4 Red-team pass (new customer page, new hook answering a core customer endpoint, upgrade script), following CLAUDE.md "Red team after major updates", with emphasis on authorisation (foreign order, guest, forged `id_customer`), data exposure (state leak through the not-available answer) and open redirect. Report to `build/red-team/<date>-1.11.0.md`. Verify there are no open high or critical findings; each accepted finding gets its own `fix-vuln:` commit.

## Workflow follow-up

- Whichever of this change and `add-mark-sent-and-download-after-delivery` archives second restates "Account block" with both rules before archiving.
- Release through the Release workflow (minor bump), then archive the change with `/openspec-archive-change`.
