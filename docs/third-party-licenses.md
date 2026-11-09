# Third-party licences

Audit of every library, tool and bundled asset of this repository (checked 2026-11-09). **No GNU GPL, AGPL or LGPL
component is part of the module, and none is used by the development tooling or the test stack.** The check is
automated: `python tools/check_dependency_licenses.py` runs in CI, in the weekly security run and in the release
workflow. It fails on a copyleft licence in anything that ships, on a shipped licence outside the permissive
allow-list (MIT, BSD, Apache-2.0, ISC, BlueOak-1.0.0 and similar) and on an unknown licence.

## What ships with the module

The installable zip contains only the module's own PHP, SQL, CSS, JavaScript, mail templates, translations, `logo.png`,
`LICENCE` and `README.md`, all under the SecCodeSmith Commercial License. It has **no runtime dependencies**: no
Composer `vendor/`, no `node_modules/`, no bundled fonts, icon sets or scripts. The admin scripts use the `jQuery`
that PrestaShop itself loads (MIT, not bundled); PrestaShop core is OSL 3.0 / AFL 3.0, not GPL. A runtime
dependency added later is tagged `ships` below and must pass the copyleft and allow-list rules.

## Roles

| role | meaning | rule |
|---|---|---|
| `ships` | would be distributed with the module | copyleft or non-allow-listed licence fails the check |
| `dev` | Composer `require-dev` and npm `devDependencies`: PHPUnit and its dependencies | never in the zip (`vendor-dir` is `tests/vendor`, excluded) |
| `test-stack` | Python packages of the iFirma mock in `test-stack/` (a separate repository, MIT) | never in the zip |

## Container images of the test stack

They run as separate services on a developer's machine or in CI and are never distributed with the module, so a
copyleft layer in them is aggregation, not linkage.

| image | licence of the main component | note |
|---|---|---|
| `mariadb:11.4` | GPL-2.0 (MariaDB server) | separate process, reached over the network |
| `prestashop/prestashop:<tag>` | OSL 3.0 / AFL 3.0 (PrestaShop), PHP licence, GPL parts of the Debian base | tags pinned, see `test-stack/docker-compose.yml` |
| `python:3.12-slim` | PSF-2.0, GPL parts of the Debian base | base of the mock image |

## Accepted exceptions

`certifi` (MPL-2.0, weak file-level copyleft) is a transitive dependency of `httpx` in the test stack. It is not
shipped and not modified. Accepted on 2026-11-09, review by 2027-05-09.

## Generated inventory

Generated from `composer.lock`, `package-lock.json` and the packages installed for the mock
(`python tools/check_dependency_licenses.py --write`). The Python rows depend on the platform they were generated
on and are not compared by the staleness check; the policy check still reads them.

<!-- licenses:begin -->
| ecosystem | name | version | licence | role |
|---|---|---|---|---|
| composer | doctrine/instantiator | 2.0.0 | MIT | dev |
| composer | myclabs/deep-copy | 1.14.0 | MIT | dev |
| composer | nikic/php-parser | v5.9.0 | BSD-3-Clause | dev |
| composer | phar-io/manifest | 2.0.4 | BSD-3-Clause | dev |
| composer | phar-io/version | 3.2.1 | BSD-3-Clause | dev |
| composer | phpunit/php-code-coverage | 9.2.32 | BSD-3-Clause | dev |
| composer | phpunit/php-file-iterator | 3.0.6 | BSD-3-Clause | dev |
| composer | phpunit/php-invoker | 3.1.1 | BSD-3-Clause | dev |
| composer | phpunit/php-text-template | 2.0.4 | BSD-3-Clause | dev |
| composer | phpunit/php-timer | 5.0.3 | BSD-3-Clause | dev |
| composer | phpunit/phpunit | 9.6.38 | BSD-3-Clause | dev |
| composer | sebastian/cli-parser | 1.0.2 | BSD-3-Clause | dev |
| composer | sebastian/code-unit | 1.0.8 | BSD-3-Clause | dev |
| composer | sebastian/code-unit-reverse-lookup | 2.0.3 | BSD-3-Clause | dev |
| composer | sebastian/comparator | 4.0.10 | BSD-3-Clause | dev |
| composer | sebastian/complexity | 2.0.3 | BSD-3-Clause | dev |
| composer | sebastian/diff | 4.0.6 | BSD-3-Clause | dev |
| composer | sebastian/environment | 5.1.5 | BSD-3-Clause | dev |
| composer | sebastian/exporter | 4.0.9 | BSD-3-Clause | dev |
| composer | sebastian/global-state | 5.0.8 | BSD-3-Clause | dev |
| composer | sebastian/lines-of-code | 1.0.4 | BSD-3-Clause | dev |
| composer | sebastian/object-enumerator | 4.0.4 | BSD-3-Clause | dev |
| composer | sebastian/object-reflector | 2.0.4 | BSD-3-Clause | dev |
| composer | sebastian/recursion-context | 4.0.7 | BSD-3-Clause | dev |
| composer | sebastian/resource-operations | 3.0.4 | BSD-3-Clause | dev |
| composer | sebastian/type | 3.2.1 | BSD-3-Clause | dev |
| composer | sebastian/version | 3.0.2 | BSD-3-Clause | dev |
| composer | theseer/tokenizer | 1.3.1 | BSD-3-Clause | dev |
| python | aiosmtpd | - | Apache Software License | test-stack |
| python | annotated-doc | - | MIT | test-stack |
| python | annotated-types | - | MIT | test-stack |
| python | anyio | - | MIT | test-stack |
| python | atpublic | - | Apache Software License | test-stack |
| python | attrs | - | MIT | test-stack |
| python | certifi | - | Mozilla Public License 2.0 (MPL 2.0) | test-stack |
| python | click | - | BSD-3-Clause | test-stack |
| python | colorama | - | BSD License | test-stack |
| python | fastapi | - | MIT | test-stack |
| python | h11 | - | MIT License | test-stack |
| python | httpcore | - | BSD-3-Clause | test-stack |
| python | httptools | - | MIT | test-stack |
| python | httpx | - | BSD License | test-stack |
| python | idna | - | BSD-3-Clause | test-stack |
| python | iniconfig | - | MIT | test-stack |
| python | opentelemetry-api | - | Apache-2.0 | test-stack |
| python | packaging | - | Apache-2.0 OR BSD-2-Clause | test-stack |
| python | pluggy | - | MIT License | test-stack |
| python | pydantic | - | MIT | test-stack |
| python | pydantic-core | - | MIT | test-stack |
| python | pygments | - | BSD-2-Clause | test-stack |
| python | pytest | - | MIT | test-stack |
| python | python-dotenv | - | BSD-3-Clause | test-stack |
| python | pyyaml | - | MIT License | test-stack |
| python | starlette | - | BSD-3-Clause | test-stack |
| python | typing-extensions | - | PSF-2.0 | test-stack |
| python | typing-inspection | - | MIT | test-stack |
| python | uvicorn | - | BSD-3-Clause | test-stack |
| python | watchfiles | - | MIT License | test-stack |
| python | websockets | - | BSD-3-Clause | test-stack |
<!-- licenses:end -->
