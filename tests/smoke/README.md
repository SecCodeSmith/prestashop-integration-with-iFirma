# Module smoke run

`run.php` exercises the iFirma module end to end inside the PrestaShop container,
against the iFirma mock. It uses the real PrestaShop classes (orders, carts, order
history, hooks, mail) and the real module code; only the iFirma API is mocked.

## What it covers

Each scenario resets the mock (`POST /__mock/reset`) and creates a fresh order.

| | Scenario | Asserts |
|--|----------|---------|
| A | Duplicate guard, suspected then linked | a document of the buyer (its contractor identifier, the order's sale date and product lines, the order's gross) and a same-amount document of another buyer are seeded; pipeline outcome `suspected`, receipt `duplicate_suspected`, only the buyer's document recorded as a candidate (with its sale date), nothing created in iFirma; `linkCandidate` makes the receipt `external`, issued, with the seeded id |
| A2 | Duplicate guard, other buyer's document ignored | only a same-amount document of another buyer is seeded (the unfiltered window query returns it); outcome `issued`, no candidate recorded, a new document created |
| B | Duplicate guard, adopt by reference | external document under another contractor identifier but the buyer's name whose `Uwagi` holds the order reference; outcome `adopted`, no document created |
| C | Data request | company request submitted; moving the order to "Payment accepted" is held (no document); `approveRequest` issues immediately; payload has the override company, NIP and order reference; delivery `sent`; reference visible in the iFirma detail |
| D | Status sync and cron | `updateStatus` + `refreshStatus` give `paid` and `remote_paid_amount`; cron token check; deleting the document in the mock and `runCron()` set `remote_missing`; the real cron URL answers 403 (wrong token) and 200 + JSON (right token) |
| E | Customer visibility | the owner's order page contains the document number; another customer gets an empty string |

## Prerequisites

```bash
docker compose -f test-stack/docker-compose.yml up -d --build
```

Wait until PrestaShop has finished its first-boot install (a few minutes; follow it
with `docker compose -f test-stack/docker-compose.yml logs -f prestashop`). The module
folder is bind-mounted at `/var/www/html/modules/ifirma`, so this script is already in
the container. The demo data (products, the demo customer and address) must be installed,
which the default image does. The script installs the module itself if it is not installed.

## Run

```bash
docker compose -f test-stack/docker-compose.yml exec -u www-data prestashop php modules/ifirma/tests/smoke/run.php
```

Always run the scripts as `www-data` (`-u www-data`). `docker compose exec` defaults to root, and a root-run PHP script creates Smarty/Doctrine cache directories under `var/cache` that Apache can no longer write to, which breaks the whole shop with `SmartyException: unable to create directory ...`. If that already happened, repair it with `docker compose exec prestashop chown -R www-data:www-data /var/www/html/var`.

Exit code: `0` all assertions passed, `1` at least one failed, `2` the environment could
not be prepared (PrestaShop/mock/fixtures missing).

### Environment overrides

| Variable | Default | Meaning |
|----------|---------|---------|
| `PS_ROOT` | `/var/www/html` (4 levels above the script) | PrestaShop root containing `config/config.inc.php` |
| `IFIRMA_MOCK_URL` | `http://ifirma-mock:8001/iapi/` | base URL written to `IFIRMA_BASE_URL` |
| `IFIRMA_MOCK_HOST` | `http://ifirma-mock:8001` | host used for the `/__mock/*` test endpoints |
| `PS_LOCAL_URL` | `http://localhost` | shop URL used for the cron HTTP check |
| `SMOKE_ONLY` | all | letters of the scenarios to run, e.g. `SMOKE_ONLY=AB` |

Pass them with `exec -e NAME=value prestashop php ...`.

## Reading the output

```
[ OK ] pipeline outcome is suspected
[FAIL] receipt status held -- expected 'held', got NULL
[SKIP] cron URL over HTTP -- localhost answered HTTP 0 (set PS_LOCAL_URL)
[INFO] order #7 ABCDEFGHI total 23.9
```

`[FAIL]` lines print the expected and actual value. The last lines summarize the counts
and list every failed assertion. `[SKIP]` lines do not affect the exit code.

## Known limitations

- It creates real orders, carts, a data request and receipts in the dev database and
  changes module and shop configuration (`IFIRMA_*`, `PS_MAIL_METHOD=3`,
  `PS_ORDER_OUT_OF_STOCK=1`). Run `make destroy` in `test-stack/` to reset to a clean shop.
- Orders are created with `ps_wirepayment` / `ps_checkpayment`'s `validateOrder()`. If
  neither module is installed the script falls back to inserting a bare `Order` row with
  no order lines, so the iFirma document has no real positions and totals may be zero-ish.
- The iFirma API is the mock, not the real service; field shapes follow the mock.
- Mail is switched off (`PS_MAIL_METHOD=3`), so "delivery sent" proves the module's
  delivery path, not that an e-mail reached an inbox.
- The script has not been run in CI; it needs the Docker stack.

## Notes from the first real run (PrestaShop 8.1.7, 2026-11-01)

- **Symfony kernel.** PrestaShop 1.7.7+/8/9 resolve hooks and services through the Symfony container, so a CLI
  script that only requires `config/config.inc.php` fails with `Kernel Container is not available`. `run.php`
  boots the kernel right after the bootstrap (PrestaShop 9 made `AppKernel` abstract and split it into
  `AdminKernel` / `FrontKernel`, so the block picks the first concrete one); copy it into any other CLI script.
- **`vendor/` is loaded by PrestaShop.** At boot PrestaShop includes `modules/*/vendor/autoload.php`. In the
  bind-mounted dev stack that means the module's dev-only Composer autoloader (PHPUnit) is loaded into the shop.
  Composer therefore installs into `tests/vendor/` (never loaded by PrestaShop) and has no `autoload-dev` classmap (the test stubs define classes named `Module`, `Tools`,
  `Mail`... and would shadow PrestaShop's own). Never ship `vendor/`; the release zip excludes it.
- **Login checks in CLI.** `Customer::isLogged()` needs a live cookie session in PS 8, so it is always false from
  the CLI. The module checks login in the front controllers and ownership in the services.
- **Cron URL check.** Inside the container `http://localhost` answers with PrestaShop's canonical-domain redirect
  (302), so that sub-check is skipped. Run it from the host instead with a wrong token (expect HTTP 403) and with
  the real token from the module configuration page (expect HTTP 200 and a JSON summary).

## Upgrade test 1.0.x -> 1.1.0

`upgrade-phase1.php` and `upgrade-phase2.php` reproduce the upgrade on the Docker stack:

1. Check out the 1.0.0 code next to the repo: `git worktree add ../ifirma-1.0.0 6565eab`.
2. Start the stack with the 1.0.0 tree mounted instead of the working tree (a compose override replacing the
   `prestashop` volumes with `ps_data:/var/www/html` and `<path to ifirma-1.0.0>:/var/www/html/modules/ifirma`),
   then run `docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/upgrade-phase1.php`.
   It reinstalls the module as 1.0.0 and issues one document against the mock.
3. Because the 1.0.0 uninstaller does not know the 1.1.0 table and settings, drop `ps_ifirma_data_request` and
   delete the `IFIRMA_*` settings that 1.0.0 does not define before continuing.
4. Recreate the container with the normal mount and run `docker compose exec -T -u www-data prestashop php
   modules/ifirma/tests/smoke/upgrade-phase2.php <id_order>`. It drives PrestaShop's own upgrade runner
   (`Module::needUpgrade` / `initUpgradeModule` / `runUpgradeModule`, after setting `installed` and
   `database_version` as the back office does) and asserts the schema, defaults, the old receipt and the guard.
5. `git worktree remove ../ifirma-1.0.0` when done.

## Verified runs

| Date | PrestaShop | PHP | Smarty | Result |
|---|---|---|---|---|
| 2026-11-01 | 8.1.7 | 8.1 | 4.3 | 47 passed, 1 skipped (in-container cron URL); upgrade 1.0.0 -> 1.1.0 verified |
| 2026-11-01 | 9.1.4 | 8.5 | 4.5 | 47 passed, 1 skipped; admin panel and configuration form rendered via `AdminKernel`; cron 403/200 from the host |

PrestaShop 9 / PHP 8.4+ also flagged implicitly nullable parameters (`Type $x = null`); the module now declares
them as `?Type $x = null` (PHP 7.1+ syntax, still inside the 7.2 floor).

## Contractor linking check

`contractor-link-check.php` seeds an "old" contractor in the mock, imports a CSV naming the demo customer,
verifies the mapping and issues a document that must carry the mapped identifier (and update the contractor
through PUT). Run it as `docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/contractor-link-check.php`.

The document request references the resolved contractor through the top-level `IdentyfikatorKontrahenta` field
(`NIPKontrahenta` carries the buyer NIP of a business customer). The check reads that field from the decoded
`request_payload` and also asserts that the `Kontrahent` block carries no `Identyfikator`, because the module
never sends `Kontrahent.Identyfikator`.

`guard-unknown-contractor-check.php` resets the mock state, confirms that `faktury.json?kontrahent=<unknown>` is answered with Kod 201 "Nie znaleziono kontrahenta o zadanym identyfikatorze" (the live-API behaviour that stopped every first issuance at the guard step before 2026-11-02), and then runs the duplicate guard and the whole pipeline for an order whose customer has no contractor yet: the guard must proceed and the document must be issued with the freshly created `PS-C` contractor. Run it as `docker compose exec -T -u www-data prestashop php modules/ifirma/tests/smoke/guard-unknown-contractor-check.php`. It wipes the mock's in-memory contractors and documents, so run it last.
