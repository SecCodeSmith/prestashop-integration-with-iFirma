#!/usr/bin/env python3
# SCM iFirma integration module for PrestaShop.
#
# @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
# @copyright 2026 SCM Jakub Berechowski
# @license   SecCodeSmith Commercial License v1.3
# SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
# See the LICENCE file distributed with this module for the full terms.
"""
Fails when a compose file or Dockerfile references a container image without a pinned tag: no tag,
`latest`, or a variable that defaults to `latest`. A digest (`@sha256:...`) counts as pinned.

    python tools/check_pinned_images.py test-stack

Exit status: 0 clean, 1 findings.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path
from typing import List

COMPOSE_NAMES = re.compile(r"^(docker-)?compose.*\.ya?ml$|^docker-compose.*\.ya?ml$")
IMAGE_LINE = re.compile(r"^\s*image:\s*(\S+)")
FROM_LINE = re.compile(r"^\s*FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+(\S+))?", re.IGNORECASE)
SKIPPED_DIRS = {".git", "node_modules", ".venv", "__pycache__"}


def is_pinned(reference: str) -> bool:
    if "@sha256:" in reference:
        return True
    last_segment = reference.rsplit("/", 1)[-1]
    if ":" not in last_segment:
        return False
    tag = last_segment.split(":", 1)[1]
    return tag != "latest" and not tag.endswith("-latest}") and "latest" not in tag


def check_tree(root: Path) -> List[str]:
    findings: List[str] = []
    for path in sorted(root.rglob("*")):
        if not path.is_file() or any(part in SKIPPED_DIRS for part in path.parts):
            continue
        is_compose = bool(COMPOSE_NAMES.match(path.name))
        is_dockerfile = path.name == "Dockerfile" or path.name.startswith("Dockerfile.")
        if not (is_compose or is_dockerfile):
            continue
        stages: set = set()
        for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            match = (IMAGE_LINE if is_compose else FROM_LINE).match(line)
            if not match:
                continue
            reference = match.group(1).strip("'\"")
            if is_dockerfile:
                if reference in stages or reference == "scratch":
                    continue
                if match.group(2):
                    stages.add(match.group(2))
            if not is_pinned(reference):
                findings.append(f"{path.relative_to(root).as_posix()}:{number}: image {reference} is not pinned")
    return findings


def main(argv: List[str]) -> int:
    root = Path(argv[0]) if argv else Path(".")
    findings = check_tree(root)
    for finding in findings:
        print(finding)
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
