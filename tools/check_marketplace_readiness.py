#!/usr/bin/env python3
# SCM iFirma integration module for PrestaShop.
#
# @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
# @copyright 2026 SCM Jakub Berechowski
# @license   SecCodeSmith Commercial License v1.3
# SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
# See the LICENCE file distributed with this module for the full terms.
"""
Marketplace readiness check: applies the PrestaShop Addons technical-validation rules that can be
checked mechanically to a BUILT module zip (so it checks what ships, not the working tree).

    python tools/check_marketplace_readiness.py dist/ifirma-1.8.0.zip

Rules (spec marketplace-readiness): one top-level `ifirma/` folder; an index.php in every folder;
config.xml with name, displayName, version, author and tab; a logo; a declared PrestaShop
compatibility range; no development or junk paths and no repository community files (code of conduct, contributing,
security, support, changelog, code owners); the _PS_VERSION_ guard in every PHP file except
index.php and translations; no eval, base64_decode, debug output, die or `@` error suppression;
no executable content loaded from a remote URL; outbound URLs only to listed hosts over TLS; a
documentation and a support link in the module interface.

Exit status: 0 clean, 1 findings, 2 usage error. The official PrestaShop Validator and the manual
review still decide; see docs/marketplace-readiness.md.
"""
from __future__ import annotations

import re
import sys
import zipfile
from typing import IO, List, Tuple, Union

MODULE = "ifirma"
CONFIG_TAGS = ("name", "displayName", "version", "author", "tab")
DEV_PATHS = ("tests/", "test-stack/", "tools/", "scripts/", ".github/", "node_modules/", "openspec/", "graphify-out/")
NESTED_DEV_DIRS = {"node_modules", ".git", "tests"}
COMMUNITY_FILES = ("CODE_OF_CONDUCT.md", "CONTRIBUTING.md", "SECURITY.md", "SUPPORT.md", "CHANGELOG.md", "CODEOWNERS")
JUNK_FILE = re.compile(r"^(\.DS_Store|Thumbs\.db|\.env(\..*)?)$")
FORBIDDEN_CALLS = ("eval", "var_dump", "print_r", "base64_decode", "die")
FORBIDDEN_CALL = re.compile(r"(?<![\w>:$\\])(" + "|".join(FORBIDDEN_CALLS) + r")\s*(?:\(|;)")
REMOTE_INCLUDE = re.compile(
    r"(?<![\w>:$\\])(?:include|require|include_once|require_once|file_get_contents|fopen|copy|readfile|"
    r"simplexml_load_file)\b\s*\(?\s*['\"]https?://"
)
URL = re.compile(r"https?://[^\s'\"<>)]+")
# Hosts the module may talk to or link to. github.com is limited to the project's own account.
ALLOWED_HOSTS = ("www.ifirma.pl", "ifirma.pl", "seccodesmith.pl", "www.seccodesmith.pl")
ALLOWED_GITHUB_PREFIX = "github.com/SecCodeSmith/"
# Documented test-stack hints in the configuration help text; plain HTTP is accepted only for these.
TEST_HOSTS = ("ifirma-mock", "localhost", "127.0.0.1")

Finding = str


def check_zip(source: Union[str, IO[bytes]]) -> List[Finding]:
    findings: List[Finding] = []
    with zipfile.ZipFile(source) as archive:
        names = [n for n in archive.namelist()]
        files = {n: archive.read(n) for n in names if not n.endswith("/")}

    prefix = MODULE + "/"
    for name in files:
        if not name.startswith(prefix):
            findings.append(f"entry outside {prefix}: {name}")
    findings += _structure_findings(files, prefix)
    findings += _config_findings(files, prefix)
    findings += _path_findings(files, prefix)
    for name, raw in files.items():
        if name.startswith(prefix) and name.endswith(".php"):
            findings += _php_findings(name, raw.decode("utf-8", "replace"), prefix)
    findings += _documentation_findings(files)
    return sorted(set(findings))


def _structure_findings(files, prefix) -> List[Finding]:
    directories = {prefix.rstrip("/")}
    for name in files:
        if name.startswith(prefix):
            parts = name.split("/")[:-1]
            for i in range(1, len(parts) + 1):
                directories.add("/".join(parts[:i]))
    return [f"folder without index.php: {d}" for d in sorted(directories) if f"{d}/index.php" not in files]


def _config_findings(files, prefix) -> List[Finding]:
    findings: List[Finding] = []
    if prefix + "logo.png" not in files:
        findings.append(f"missing {prefix}logo.png")
    config = files.get(prefix + "config.xml")
    if config is None:
        findings.append(f"missing {prefix}config.xml")
    else:
        text = config.decode("utf-8", "replace")
        findings += [f"config.xml lacks <{tag}>" for tag in CONFIG_TAGS if f"<{tag}>" not in text]
    module_class = files.get(prefix + MODULE + ".php")
    if module_class is None:
        findings.append(f"missing {prefix}{MODULE}.php")
    elif b"ps_versions_compliancy" not in module_class:
        findings.append(f"{MODULE}.php does not declare ps_versions_compliancy")
    return findings


def _path_findings(files, prefix) -> List[Finding]:
    findings: List[Finding] = []
    for name in files:
        if not name.startswith(prefix):
            continue
        inner = name[len(prefix):]
        segments = [s for s in inner.split("/") if s]
        dev = any(inner.startswith(d) for d in DEV_PATHS) or any(s in NESTED_DEV_DIRS for s in segments[:-1])
        if dev or JUNK_FILE.match(segments[-1] if segments else ""):
            findings.append(f"development or junk path: {name}")
        if inner in COMMUNITY_FILES:
            findings.append(f"community file in package: {name}")
    return findings


def _documentation_findings(files) -> List[Finding]:
    for raw in files.values():
        if b"DOCUMENTATION_URL" in raw and b"SUPPORT_URL" in raw:
            return []
    return ["no documentation and support links in the module interface"]


def _php_findings(name: str, source: str, prefix: str) -> List[Finding]:
    findings: List[Finding] = []
    is_index = name.endswith("/index.php")
    is_translation = name.startswith(prefix + "translations/")
    code, with_strings, strings = scan_php(source)

    if not is_index and not is_translation and not re.search(r"defined\(\s*'_PS_VERSION_'\s*\)", with_strings):
        findings.append(f"missing _PS_VERSION_ guard: {name}")

    for number, line in enumerate(code.split("\n"), 1):
        if "@" in line:
            findings.append(f"error suppression (@): {name}:{number}")
        for match in FORBIDDEN_CALL.finditer(line):
            findings.append(f"forbidden {match.group(1)}: {name}:{number}")
    for number, line in enumerate(with_strings.split("\n"), 1):
        if REMOTE_INCLUDE.search(line):
            findings.append(f"remote content loaded: {name}:{number}")

    for line_number, text in strings:
        for match in URL.finditer(text):
            findings += _url_findings(match.group(0).rstrip(".,;:"), f"{name}:{line_number}")
    return findings


def _url_findings(url: str, where: str) -> List[Finding]:
    scheme, rest = url.split("://", 1)
    host = re.split(r"[/:?#]", rest, maxsplit=1)[0]
    if host in TEST_HOSTS:
        return []
    if scheme == "http":
        return [f"url without TLS: {url} ({where})"]
    allowed = host in ALLOWED_HOSTS or rest.startswith(ALLOWED_GITHUB_PREFIX)
    return [] if allowed else [f"url on an unlisted host: {url} ({where})"]


def scan_php(source: str) -> Tuple[str, str, List[Tuple[int, str]]]:
    """Split PHP source into the text with comments and strings blanked, the text with only comments
    blanked, and the string literals as (line, content). Newlines are kept so line numbers hold."""
    blanked: List[str] = []
    kept: List[str] = []
    strings: List[Tuple[int, str]] = []
    i, n, line = 0, len(source), 1

    def emit(code_text: str, kept_text: str) -> None:
        blanked.append(code_text)
        kept.append(kept_text)

    def blank(text: str) -> str:
        return re.sub(r"[^\n]", " ", text)

    while i < n:
        ch = source[i]
        two = source[i:i + 2]
        if two == "/*" or ch == "#" and source[i:i + 2] != "#[" or two == "//":
            end = source.find("*/", i + 2) + 2 if two == "/*" else _line_end(source, i)
            if end < 2 and two == "/*":
                end = n
            chunk = source[i:end]
            emit(blank(chunk), blank(chunk))
            line += chunk.count("\n")
            i = end
        elif source.startswith("<<<", i) and re.match(r"<<<\s*['\"]?(\w+)['\"]?\r?\n", source[i:]):
            m = re.match(r"<<<\s*['\"]?(\w+)['\"]?\r?\n", source[i:])
            end_match = re.search(r"\n[ \t]*" + m.group(1) + r"\b", source[i + m.end():])
            end = n if end_match is None else i + m.end() + end_match.end()
            chunk = source[i:end]
            strings.append((line, chunk))
            emit(blank(chunk), chunk)
            line += chunk.count("\n")
            i = end
        elif ch in ("'", '"'):
            j = i + 1
            while j < n and source[j] != ch:
                j += 2 if source[j] == "\\" else 1
            chunk = source[i:j + 1]
            strings.append((line, chunk[1:-1]))
            emit(blank(chunk), chunk)
            line += chunk.count("\n")
            i = j + 1
        else:
            emit(ch, ch)
            if ch == "\n":
                line += 1
            i += 1
    return "".join(blanked), "".join(kept), strings


def _line_end(source: str, start: int) -> int:
    end = source.find("\n", start)
    return len(source) if end < 0 else end


def main(argv: List[str]) -> int:
    if not argv:
        print("usage: check_marketplace_readiness.py <module.zip>...", file=sys.stderr)
        return 2
    failed = False
    for path in argv:
        try:
            findings = check_zip(path)
        except (OSError, zipfile.BadZipFile) as error:
            findings = [f"cannot read the zip: {error}"]
        if findings:
            failed = True
            print(f"FAIL {path}")
            for finding in findings:
                print(f"  - {finding}")
        else:
            print(f"ok   {path}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
