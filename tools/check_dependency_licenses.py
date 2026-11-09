#!/usr/bin/env python3
# SCM iFirma integration module for PrestaShop.
#
# @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
# @copyright 2026 SCM Jakub Berechowski
# @license   SecCodeSmith Commercial License v1.3
# SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
# See the LICENCE file distributed with this module for the full terms.
"""
Dependency licence audit (spec dependency-license-audit).

    python tools/check_dependency_licenses.py            # check; exit 1 on an error or a stale document
    python tools/check_dependency_licenses.py --write    # regenerate the table in docs/third-party-licenses.md

Reads composer.lock, package-lock.json and the Python packages the mock needs (resolved from
test-stack/ifirma-mock/requirements*.txt through the installed metadata, transitive packages
included) and tags each with a role:
  ships       a runtime dependency; it would be part of what is distributed with the module
  dev         development tooling (composer require-dev, npm devDependencies), never shipped
  test-stack  Python packages of the iFirma mock, never shipped
Rules: a GPL/AGPL/LGPL licence on a `ships` dependency is an error, on any other role a warning; a
`ships` licence outside the permissive allow-list is an error until the owner decides; an unknown
licence is an error until it is recorded by hand with a line
    <!-- licence-override: <ecosystem> <name> <SPDX id> -->
in docs/third-party-licenses.md. The table between the `licenses:begin/end` markers of that file is
generated; the check fails when it differs from the manifests.
"""
from __future__ import annotations

import argparse
import re
import sys
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Dict, List, Optional, Tuple

DOC = Path("docs") / "third-party-licenses.md"
BEGIN, END = "<!-- licenses:begin -->", "<!-- licenses:end -->"
OVERRIDE = re.compile(r"<!--\s*licence-override:\s*(\S+)\s+(\S+)\s+(\S+)\s*-->")
ALLOWED = {
    "MIT", "MIT-0", "ISC", "0BSD", "BSD-2-Clause", "BSD-3-Clause", "Apache-2.0", "BlueOak-1.0.0",
    "Unlicense", "CC0-1.0", "Python-2.0", "PSF-2.0", "Zlib",
}
LEGACY_NAMES = {
    "MIT LICENSE": "MIT", "MIT": "MIT", "BSD LICENSE": "BSD-3-Clause", "BSD": "BSD-3-Clause",
    "BSD-3": "BSD-3-Clause", "APACHE SOFTWARE LICENSE": "Apache-2.0", "APACHE 2.0": "Apache-2.0",
    "APACHE LICENSE 2.0": "Apache-2.0", "APACHE LICENSE, VERSION 2.0": "Apache-2.0",
    "ISC LICENSE (ISCL)": "ISC", "PYTHON SOFTWARE FOUNDATION LICENSE": "PSF-2.0",
    "GPL-2.0": "GPL-2.0-only", "GPL-3.0": "GPL-3.0-only", "LGPL-2.1": "LGPL-2.1-only", "LGPL-3.0": "LGPL-3.0-only",
    "AGPL-3.0": "AGPL-3.0-only", "GNU GENERAL PUBLIC LICENSE V3 (GPLV3)": "GPL-3.0-only",
    "GNU GENERAL PUBLIC LICENSE V2 (GPLV2)": "GPL-2.0-only",
}
COPYLEFT = re.compile(r"^(A|L)?GPL", re.IGNORECASE)
UNKNOWN = "unknown"


@dataclass(frozen=True)
class Dependency:
    ecosystem: str
    name: str
    version: str
    licence: str
    role: str


def normalise(licence: str) -> str:
    cleaned = licence.strip()
    return LEGACY_NAMES.get(cleaned.upper(), cleaned)


def _licence_text(value) -> str:
    if isinstance(value, list):
        return " OR ".join(str(v) for v in value) if value else UNKNOWN
    if isinstance(value, dict):
        return str(value.get("type") or UNKNOWN)
    return str(value) if value else UNKNOWN


# --------------------------------------------------------------------------- inventory

def inventory(root: Path, python_metadata: Optional[Callable[[], Dict[str, str]]] = None) -> List[Dependency]:
    root = Path(root)
    deps: List[Dependency] = []
    composer = root / "composer.lock"
    if composer.exists():
        data = json.loads(composer.read_text(encoding="utf-8"))
        for key, role in (("packages", "ships"), ("packages-dev", "dev")):
            for p in data.get(key, []):
                deps.append(Dependency("composer", p["name"], p.get("version", "-"), _licence_text(p.get("license")), role))
    npm = root / "package-lock.json"
    if npm.exists():
        for path, p in json.loads(npm.read_text(encoding="utf-8")).get("packages", {}).items():
            if not path or p.get("link"):
                continue
            name = path.rsplit("node_modules/", 1)[-1]
            deps.append(Dependency("npm", name, p.get("version", "-"), _licence_text(p.get("license")), "dev" if p.get("dev") else "ships"))
    metadata = python_metadata() if python_metadata else installed_python_licences(root)
    for name, licence in metadata.items():
        deps.append(Dependency("python", name, "-", licence or UNKNOWN, "test-stack"))
    overrides = _overrides(root)
    resolved = [
        Dependency(d.ecosystem, d.name, d.version, overrides.get((d.ecosystem, d.name), d.licence), d.role)
        for d in deps
    ]
    return sorted(resolved, key=lambda d: (d.ecosystem, d.name.lower()))


def _overrides(root: Path) -> Dict[Tuple[str, str], str]:
    doc = Path(root) / DOC
    if not doc.exists():
        return {}
    return {(m.group(1), m.group(2)): m.group(3) for m in OVERRIDE.finditer(doc.read_text(encoding="utf-8"))}


def installed_python_licences(root: Path) -> Dict[str, str]:
    """Licences of the packages the mock requires, transitive ones included, read from the
    installed metadata of the running interpreter (install requirements-dev.txt first)."""
    from importlib import metadata

    requirements = sorted((Path(root) / "test-stack" / "ifirma-mock").glob("requirements*.txt"))
    queue: List[Tuple[str, Tuple[str, ...]]] = []
    for file in requirements:
        for line in file.read_text(encoding="utf-8").splitlines():
            match = re.match(r"^\s*([A-Za-z0-9._-]+)(?:\[([^\]]+)\])?", line)
            if match and not line.strip().startswith(("-", "#")):
                queue.append((match.group(1), tuple((match.group(2) or "").split(","))))
    seen: Dict[str, str] = {}
    while queue:
        name, extras = queue.pop()
        key = re.sub(r"[-_.]+", "-", name).lower()
        if key in seen:
            continue
        try:
            meta = metadata.metadata(name)
        except metadata.PackageNotFoundError:
            continue  # a marker-excluded dependency of this platform
        seen[key] = _metadata_licence(meta)
        for requirement in metadata.requires(name) or []:
            dependency = re.match(r"^\s*([A-Za-z0-9._-]+)(?:\[([^\]]+)\])?", requirement)
            wanted = re.search(r"extra\s*==\s*['\"]([^'\"]+)['\"]", requirement)
            if wanted and wanted.group(1) not in extras:
                continue
            queue.append((dependency.group(1), tuple((dependency.group(2) or "").split(","))))
    return seen


def _metadata_licence(meta) -> str:
    expression = meta.get("License-Expression")
    if expression:
        return expression
    classifiers = [c.split("::")[-1].strip() for c in (meta.get_all("Classifier") or []) if c.startswith("License ::")]
    classifiers = [c for c in classifiers if c not in ("OSI Approved",)]
    if classifiers:
        return " OR ".join(sorted(set(classifiers)))
    text = (meta.get("License") or "").strip()
    return text if text and len(text) < 80 and "\n" not in text else UNKNOWN


# --------------------------------------------------------------------------- policy

def _term_kind(term: str) -> str:
    spdx = re.sub(r"\+$", "", normalise(term))
    if COPYLEFT.match(spdx):
        return "copyleft"
    return "permissive" if spdx in ALLOWED else "other"


def _expression_kind(expression: str) -> str:
    text = expression.strip().strip("()")
    if re.search(r"\sOR\s", text, re.IGNORECASE):
        kinds = [_expression_kind(part) for part in re.split(r"\sOR\s", text, flags=re.IGNORECASE)]
        if "permissive" in kinds:
            return "permissive"
        return "other" if "other" in kinds else "copyleft"
    if re.search(r"\sAND\s", text, re.IGNORECASE):
        kinds = [_expression_kind(part) for part in re.split(r"\sAND\s", text, flags=re.IGNORECASE)]
        if "copyleft" in kinds:
            return "copyleft"
        return "other" if "other" in kinds else "permissive"
    return _term_kind(text)


def evaluate(deps: List[Dependency]) -> Tuple[List[str], List[str]]:
    errors: List[str] = []
    warnings: List[str] = []
    for d in deps:
        who = f"{d.ecosystem} {d.name} {d.version}" if d.version != "-" else f"{d.ecosystem} {d.name}"
        if d.licence == UNKNOWN:
            errors.append(f"licence unknown: {who} (record it by hand in docs/third-party-licenses.md)")
            continue
        kind = _expression_kind(d.licence)
        ships = d.role == "ships"
        if kind == "copyleft":
            if ships:
                errors.append(f"copyleft licence {d.licence} in a shipped dependency: {who}")
            else:
                warnings.append(f"copyleft licence {d.licence} in a {d.role} dependency (does not ship): {who}")
        elif kind == "other":
            if ships:
                errors.append(f"licence {d.licence} is not on the allow-list (owner decision needed): {who}")
            else:
                warnings.append(f"licence {d.licence} is not on the allow-list ({d.role} dependency, does not ship): {who}")
    return errors, warnings


# --------------------------------------------------------------------------- document

def render_table(deps: List[Dependency]) -> str:
    lines = ["| ecosystem | name | version | licence | role |", "|---|---|---|---|---|"]
    lines += [f"| {d.ecosystem} | {d.name} | {d.version} | {d.licence} | {d.role} |" for d in deps]
    return "\n".join(lines)


DEFAULT_DOC = "# Third-party licences\n\nGenerated inventory of every dependency (`python tools/check_dependency_licenses.py --write`).\n\n"


def render_document(existing: Optional[str], deps: List[Dependency]) -> str:
    block = f"{BEGIN}\n{render_table(deps)}\n{END}"
    if existing is None:
        return DEFAULT_DOC + block + "\n"
    if BEGIN in existing and END in existing:
        head, rest = existing.split(BEGIN, 1)
        _, tail = rest.split(END, 1)
        return head + block + tail
    return existing.rstrip("\n") + "\n\n" + block + "\n"


def _without_python_rows(text: str) -> str:
    """Python packages depend on the platform (marker-excluded wheels), so only the policy check
    sees them; a platform difference must not make the document look stale."""
    return "\n".join(line for line in text.split("\n") if not line.startswith("| python |"))


def main(argv: List[str], python_metadata: Optional[Callable[[], Dict[str, str]]] = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--root", default=".")
    parser.add_argument("--write", action="store_true", help="regenerate the table in docs/third-party-licenses.md")
    args = parser.parse_args(argv)
    root = Path(args.root)
    deps = inventory(root, python_metadata)
    errors, warnings = evaluate(deps)
    doc = root / DOC
    existing = doc.read_text(encoding="utf-8") if doc.exists() else None
    wanted = render_document(existing, deps)

    if args.write:
        doc.parent.mkdir(parents=True, exist_ok=True)
        doc.write_text(wanted, encoding="utf-8", newline="\n")
    elif _without_python_rows((existing or "").replace("\r\n", "\n")) != _without_python_rows(wanted):
        errors.append(f"{DOC.as_posix()} is out of date: run python tools/check_dependency_licenses.py --write")

    for warning in warnings:
        print(f"warning: {warning}")
    for error in errors:
        print(f"error: {error}")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
