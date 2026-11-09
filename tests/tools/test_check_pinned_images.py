# SCM iFirma integration module for PrestaShop.
#
# @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
# @copyright 2026 SCM Jakub Berechowski
# @license   SecCodeSmith Commercial License v1.3
# SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
# See the LICENCE file distributed with this module for the full terms.
"""Scenario of the ci-vulnerability-scanning spec: a compose file or Dockerfile that references an
image without a pinned tag (or with `latest`) is reported with its file and line."""
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
import check_pinned_images as pinned  # noqa: E402


def findings_in(name, text):
    with tempfile.TemporaryDirectory() as tmp:
        (Path(tmp) / name).write_text(text, encoding="utf-8")
        return pinned.check_tree(Path(tmp))


class PinnedImagesTest(unittest.TestCase):
    def test_a_latest_tag_in_a_compose_file_is_named_with_file_and_line(self):
        found = findings_in("docker-compose.yml", "services:\n  shop:\n    image: prestashop/prestashop:latest\n")

        self.assertEqual(["docker-compose.yml:3: image prestashop/prestashop:latest is not pinned"], found)

    def test_an_image_without_a_tag_is_named(self):
        found = findings_in("compose.yaml", "services:\n  db:\n    image: mariadb\n")

        self.assertEqual(["compose.yaml:3: image mariadb is not pinned"], found)

    def test_a_dockerfile_from_line_is_checked_and_a_stage_alias_is_ignored(self):
        found = findings_in("Dockerfile", "FROM python AS build\nFROM build\n")

        self.assertEqual(["Dockerfile:1: image python is not pinned"], found)

    def test_pinned_tags_and_digests_pass(self):
        compose = "services:\n  a:\n    image: mariadb:11.4\n  b:\n    image: nginx@sha256:" + "a" * 64 + "\n"

        self.assertEqual([], findings_in("docker-compose.yml", compose))
        self.assertEqual([], findings_in("Dockerfile", "FROM python:3.12-slim\n"))

    def test_a_registry_with_a_port_is_not_mistaken_for_a_tag(self):
        found = findings_in("docker-compose.yml", "services:\n  a:\n    image: registry.test:5000/team/app\n")

        self.assertEqual(["docker-compose.yml:3: image registry.test:5000/team/app is not pinned"], found)

    def test_a_variable_with_a_latest_default_is_named(self):
        found = findings_in("docker-compose.yml", "services:\n  a:\n    image: shop:${TAG:-latest}\n")

        self.assertEqual(["docker-compose.yml:3: image shop:${TAG:-latest} is not pinned"], found)

    def test_main_returns_one_on_findings_and_zero_when_clean(self):
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "docker-compose.yml").write_text("services:\n  a:\n    image: x\n", encoding="utf-8")
            self.assertEqual(1, pinned.main([tmp]))
            (Path(tmp) / "docker-compose.yml").write_text("services:\n  a:\n    image: x:1\n", encoding="utf-8")
            self.assertEqual(0, pinned.main([tmp]))


if __name__ == "__main__":
    unittest.main()
