// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// The release package is written by scripts/zip-writer.mjs instead of a dependency (every
// archiver release carries an unfixable minimatch advisory). The tests read the result back with
// Python's zipfile, an independent reader. Run: node --test tests/tools/*.test.mjs
import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { crc32, createZip } from "../../scripts/zip-writer.mjs";

function readBack(buffer) {
  const dir = mkdtempSync(join(tmpdir(), "zipw-"));
  const file = join(dir, "t.zip");
  writeFileSync(file, buffer);
  const script = [
    "import json,sys,zipfile",
    "z=zipfile.ZipFile(sys.argv[1])",
    "print(json.dumps({'bad':z.testzip(),'entries':[[i.filename,i.compress_type,z.read(i).decode('utf-8','replace'),list(i.date_time)] for i in z.infolist()]}))",
  ].join("\n");
  // `python3` is a Microsoft Store stub on Windows (exit 9009), so try each name until one runs.
  const python = ["python3", "python"]
    .map((name) => spawnSync(name, ["-I", "-c", script, file], { encoding: "utf8" }))
    .find((run) => run.status === 0) ?? { status: 1, stderr: "no working python interpreter found" };
  assert.equal(python.status, 0, python.stderr);
  return JSON.parse(python.stdout);
}

const at = new Date(Date.UTC(2026, 10, 9, 12, 30, 40));

test("crc32 matches the known check value", () => {
  assert.equal(crc32(Buffer.from("123456789")), 0xcbf43926);
});

test("A zip of text and binary entries reads back intact in an independent reader", () => {
  const binary = Buffer.from([0, 1, 2, 3, 255, 254]);
  const zip = createZip([
    { name: "ifirma/a.txt", data: Buffer.from("hello ".repeat(100)) },
    { name: "ifirma/Zażółć.txt", data: Buffer.from("zażółć gęślą jaźń") },
    { name: "ifirma/b.bin", data: binary },
    { name: "ifirma/empty", data: Buffer.alloc(0) },
  ], at);

  const result = readBack(zip);

  assert.equal(result.bad, null);
  assert.deepEqual(result.entries.map((e) => e[0]), ["ifirma/a.txt", "ifirma/Zażółć.txt", "ifirma/b.bin", "ifirma/empty"]);
  assert.equal(result.entries[0][2], "hello ".repeat(100));
  assert.equal(result.entries[1][2], "zażółć gęślą jaźń");
});

test("The same input gives byte-identical output", () => {
  const entries = [{ name: "ifirma/a", data: Buffer.from("x") }];

  assert.ok(createZip(entries, at).equals(createZip(entries, at)));
});

test("Entries carry the given modification time", () => {
  const result = readBack(createZip([{ name: "ifirma/a", data: Buffer.from("x") }], at));

  assert.deepEqual(result.entries[0][3], [2026, 11, 9, 12, 30, 40]);
});

test("A name with a parent-folder segment or a leading slash is refused", () => {
  assert.throws(() => createZip([{ name: "../x", data: Buffer.alloc(0) }], at), /unsafe/);
  assert.throws(() => createZip([{ name: "/x", data: Buffer.alloc(0) }], at), /unsafe/);
  assert.throws(() => createZip([{ name: "a\\b", data: Buffer.alloc(0) }], at), /unsafe/);
});
