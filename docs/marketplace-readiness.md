# PrestaShop Addons readiness

State of the module against the PrestaShop Addons technical validation, checked 2026-11-09 for version 1.8.0.
The mechanical rules are enforced on every built zip by `tools/check_marketplace_readiness.py` (CI and the release
workflow). Only the official Validator and the marketplace review decide; this page lists what is met, what was
fixed, and what only the owner can do.

Sources: the PrestaShop developer documentation (technical validation checklist and key steps) and the partner help
center ("Guide technical validation", "Validation process for your addons").

## Requirements

| # | Requirement | State | Evidence |
|---|---|---|---|
| 1 | Zip with a single top-level folder named after the module | met | `scripts/build-zip.mjs`, checker rule "entry outside ifirma/" |
| 2 | `index.php` in every folder | met | checker rule "folder without index.php" |
| 3 | `config.xml` with name, display name, version, author, tab | met | checker rule "config.xml lacks" |
| 4 | Declared PrestaShop compatibility (`ps_versions_compliancy` 8.0.0 - 9.99.99) | met | checker; smoke matrix 8.0.5, 8.1.7, 8.2.2, 9.0.3, 9.1.4 (`smoke.yml`) |
| 5 | Compatible with the latest PrestaShop version | met | 9.1.4 in the smoke matrix; re-run when a newer one ships |
| 6 | `_PS_VERSION_` guard in every PHP file | fixed | `IfirmaDownloadThrottle.php` lacked it; `ShippedCodeMarketplaceRulesTest` |
| 7 | No `@` error suppression, no `eval`, `base64_decode`, debug output or `die` | fixed | `@iconv` and `die()` removed; test above and the checker |
| 8 | Self-contained: nothing downloaded or executed after installation | met | checker rule "remote content loaded" |
| 9 | Outbound calls only to the documented service, over TLS | met | iFirma API only (`https://www.ifirma.pl/iapi/`), certificate verification on; checker rules for URLs |
| 10 | Does not alter PrestaShop core tables | met | only the module's own `ifirma_*` tables are created, altered or dropped (`sql/`, `IfirmaSchema.php`) |
| 11 | No alert in debug mode | met | the smoke stack runs with `PS_DEV_MODE=1` |
| 12 | Documentation and support links in the module interface | fixed | links on the configuration page (`IfirmaHelpLinks`, `HelpLinksTest`) |
| 13 | Translations through the module's own `l()` | met | `TranslationCoverageTest`, Polish file complete |
| 14 | No development or junk files, no community files in the zip | met | `verify-package`, checker |

Direct `curl` use (`IfirmaHttpTransport`) is accepted: it only talks to the iFirma API over HTTPS with verification
on and explicit timeouts, and nothing is fetched as code. If the Validator flags it as a warning, the justification
is this paragraph.

## Open items (owner actions)

| Item | Why only the owner can do it |
|---|---|
| Run the official PrestaShop Validator on `dist/ifirma-<version>.zip` and fix what it reports | it is an upload to an external service; do it with the release zip, not a test build |
| Logo size | `logo.png` is 256x256. Confirm with the Validator whether it accepts that; resize only if it is flagged |
| English PDF user guide for the Addons product sheet (installation, configuration, usage) | marketing validation requires it; `docs/` is the source material |
| Product sheet: name, short description, screenshots (`docs/img` holds back-office captures) | written in the partner account |
| Build the submission zip with the PrestaShop SDK if the review asks for it | the repository build (`npm run build:zip`) already produces the layout, the SDK is the marketplace's own tool |
| Licence for the marketplace listing | the module is under the SecCodeSmith Commercial License (free for SMB and non-commercial use, paid for Enterprise); confirm that the Addons terms accept it or choose the licence to sell under. Not something code can settle |
| Optional: PSR-2 / PrestaShop coding-standard pass | recommended by the documentation, not a rejection reason; the code uses the PrestaShop 1.7 style (`array()`, no short syntax) |

## How to re-check

```bash
npm run build:zip
python tools/check_marketplace_readiness.py dist/ifirma-<version>.zip
```
