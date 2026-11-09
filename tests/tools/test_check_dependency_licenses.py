# SCM iFirma integration module for PrestaShop.
#
# @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
# @copyright 2026 SCM Jakub Berechowski
# @license   SecCodeSmith Commercial License v1.3
# SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
# See the LICENCE file distributed with this module for the full terms.
"""Scenarios of the dependency-license-audit spec: inventory, copyleft block, allow-list, stale doc."""
import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
import check_dependency_licenses as audit  # noqa: E402


def write(root, name, content):
    path = Path(root) / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content if isinstance(content, str) else json.dumps(content), encoding="utf-8")


def composer_lock(packages=(), dev=()):
    def entry(name, licence):
        return {"name": name, "version": "1.0.0", "license": licence}
    return {"packages": [entry(*p) for p in packages], "packages-dev": [entry(*p) for p in dev]}


def npm_lock(packages):
    return {"packages": {"": {}, **{f"node_modules/{name}": {"version": "1.0.0", "license": licence, "dev": dev}
                                    for name, licence, dev in packages}}}


class Workspace(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)
        self.addCleanup(self._tmp.cleanup)
        write(self.root, "composer.lock", composer_lock())
        write(self.root, "package-lock.json", npm_lock([]))
        write(self.root, "test-stack/ifirma-mock/requirements-dev.txt", "")
        self.python = {}

    def deps(self):
        return audit.inventory(self.root, python_metadata=lambda: dict(self.python))

    def findings(self):
        return audit.evaluate(self.deps())


class InventoryTest(Workspace):
    def test_every_manifest_entry_appears_with_licence_and_role(self):
        write(self.root, "composer.lock", composer_lock(packages=[("a/runtime", ["MIT"])], dev=[("a/dev", ["BSD-3-Clause"])]))
        write(self.root, "package-lock.json", npm_lock([("tool", "ISC", True), ("lib", "Apache-2.0", False)]))
        self.python = {"fastapi": "MIT"}
        write(self.root, "test-stack/ifirma-mock/requirements-dev.txt", "fastapi>=0.1\n")

        rows = {(d.ecosystem, d.name): (d.licence, d.role) for d in self.deps()}

        self.assertEqual(("MIT", "ships"), rows[("composer", "a/runtime")])
        self.assertEqual(("BSD-3-Clause", "dev"), rows[("composer", "a/dev")])
        self.assertEqual(("ISC", "dev"), rows[("npm", "tool")])
        self.assertEqual(("Apache-2.0", "ships"), rows[("npm", "lib")])
        self.assertEqual(("MIT", "test-stack"), rows[("python", "fastapi")])

    def test_a_dependency_without_licence_data_is_listed_as_unknown(self):
        write(self.root, "package-lock.json", npm_lock([("mystery", None, True)]))

        self.assertEqual("unknown", self.deps()[0].licence)


class PolicyTest(Workspace):
    def test_permissive_licences_pass(self):
        write(self.root, "composer.lock", composer_lock(packages=[("a/b", ["MIT"])]))

        self.assertEqual(([], []), self.findings())

    def test_a_gpl_library_that_ships_fails(self):
        write(self.root, "package-lock.json", npm_lock([("gplib", "GPL-3.0-only", False)]))

        errors, _ = self.findings()

        self.assertEqual(["copyleft licence GPL-3.0-only in a shipped dependency: npm gplib 1.0.0"], errors)

    def test_agpl_and_lgpl_count_as_copyleft(self):
        write(self.root, "package-lock.json", npm_lock([("a", "AGPL-3.0-or-later", False), ("l", "LGPL-2.1", False)]))

        errors, _ = self.findings()

        self.assertEqual(2, len(errors))

    def test_a_gpl_development_tool_only_warns(self):
        write(self.root, "package-lock.json", npm_lock([("gpltool", "GPL-2.0", True)]))

        errors, warnings = self.findings()

        self.assertEqual([], errors)
        self.assertEqual(["copyleft licence GPL-2.0 in a dev dependency (does not ship): npm gplib-placeholder".replace("gplib-placeholder", "gpltool 1.0.0")], warnings)

    def test_a_gpl_package_of_the_test_stack_only_warns(self):
        self.python = {"gplpy": "GPL-3.0-or-later"}
        write(self.root, "test-stack/ifirma-mock/requirements-dev.txt", "gplpy\n")

        errors, warnings = self.findings()

        self.assertEqual([], errors)
        self.assertEqual(1, len(warnings))

    def test_an_unknown_licence_fails_even_for_a_dev_dependency(self):
        write(self.root, "package-lock.json", npm_lock([("mystery", None, True)]))

        errors, _ = self.findings()

        self.assertEqual(["licence unknown: npm mystery 1.0.0 (record it by hand in docs/third-party-licenses.md)"], errors)

    def test_an_unlisted_licence_on_a_shipped_dependency_asks_for_an_owner_decision(self):
        write(self.root, "package-lock.json", npm_lock([("moz", "MPL-2.0", False)]))

        errors, _ = self.findings()

        self.assertEqual(["licence MPL-2.0 is not on the allow-list (owner decision needed): npm moz 1.0.0"], errors)

    def test_an_or_expression_with_a_permissive_alternative_passes(self):
        write(self.root, "package-lock.json", npm_lock([("dual", "(MIT OR GPL-3.0-only)", False)]))

        self.assertEqual(([], []), self.findings())

    def test_an_and_expression_with_copyleft_fails(self):
        write(self.root, "package-lock.json", npm_lock([("both", "MIT AND GPL-3.0-only", False)]))

        errors, _ = self.findings()

        self.assertEqual(1, len(errors))

    def test_legacy_composer_and_classifier_names_are_normalised(self):
        self.assertEqual("GPL-3.0-only", audit.normalise("GPL-3.0"))
        self.assertEqual("MIT", audit.normalise("MIT License"))
        self.assertEqual("BSD-3-Clause", audit.normalise("BSD License"))
        self.assertEqual("Apache-2.0", audit.normalise("Apache Software License"))


class DocumentationTest(Workspace):
    def doc(self):
        return self.root / "docs" / "third-party-licenses.md"

    def test_a_missing_or_stale_document_fails_the_check_until_written(self):
        write(self.root, "package-lock.json", npm_lock([("tool", "MIT", True)]))

        self.assertEqual(1, audit.main(["--root", str(self.root)], python_metadata=lambda: {}))
        self.assertEqual(0, audit.main(["--root", str(self.root), "--write"], python_metadata=lambda: {}))
        self.assertEqual(0, audit.main(["--root", str(self.root)], python_metadata=lambda: {}))

        self.assertIn("| npm | tool | 1.0.0 | MIT | dev |", self.doc().read_text(encoding="utf-8"))

    def test_changing_a_manifest_makes_the_document_stale(self):
        write(self.root, "package-lock.json", npm_lock([("tool", "MIT", True)]))
        audit.main(["--root", str(self.root), "--write"], python_metadata=lambda: {})
        write(self.root, "package-lock.json", npm_lock([("tool", "MIT", True), ("other", "MIT", True)]))

        self.assertEqual(1, audit.main(["--root", str(self.root)], python_metadata=lambda: {}))

    def test_the_text_around_the_generated_table_is_kept(self):
        write(self.root, "package-lock.json", npm_lock([("tool", "MIT", True)]))
        write(self.root, "docs/third-party-licenses.md", "# Notes\n\nhand written\n\n<!-- licenses:begin -->\nold\n<!-- licenses:end -->\n\nmore text\n")

        audit.main(["--root", str(self.root), "--write"], python_metadata=lambda: {})

        text = self.doc().read_text(encoding="utf-8")
        self.assertIn("hand written", text)
        self.assertIn("more text", text)
        self.assertNotIn("\nold\n", text)

    def test_a_platform_difference_in_the_python_packages_does_not_make_the_document_stale(self):
        write(self.root, "package-lock.json", npm_lock([("tool", "MIT", True)]))
        audit.main(["--root", str(self.root), "--write"], python_metadata=lambda: {"colorama": "BSD-3-Clause"})

        self.assertEqual(0, audit.main(["--root", str(self.root)], python_metadata=lambda: {"uvloop": "MIT"}))

    def test_a_hand_recorded_override_resolves_an_unknown_licence(self):
        write(self.root, "package-lock.json", npm_lock([("mystery", None, True)]))
        write(self.root, "docs/third-party-licenses.md", "<!-- licence-override: npm mystery MIT -->\n")

        self.assertEqual("MIT", self.deps()[0].licence)

    def test_a_licence_error_fails_even_when_the_document_is_current(self):
        write(self.root, "package-lock.json", npm_lock([("gplib", "GPL-3.0-only", False)]))
        audit.main(["--root", str(self.root), "--write"], python_metadata=lambda: {})

        self.assertEqual(1, audit.main(["--root", str(self.root)], python_metadata=lambda: {}))


if __name__ == "__main__":
    unittest.main()
