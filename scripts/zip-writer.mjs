// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// A minimal ZIP writer (stored or deflated entries, UTF-8 names, no ZIP64, no encryption), enough
// for the module package. It replaces the `archiver` dependency, whose every release carries an
// unfixable minimatch advisory, and it is deterministic: the same entries and time give the same
// bytes.
import { lstatSync, readdirSync } from "node:fs";
import { deflateRawSync } from "node:zlib";

const TABLE = Uint32Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});

export function crc32(buffer) {
  let crc = 0xffffffff;
  for (const byte of buffer) crc = TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function dosDateTime(date) {
  const time = (date.getUTCHours() << 11) | (date.getUTCMinutes() << 5) | (date.getUTCSeconds() >> 1);
  const day = ((date.getUTCFullYear() - 1980) << 9) | ((date.getUTCMonth() + 1) << 5) | date.getUTCDate();
  return { time, day };
}

function assertSafeName(name) {
  if (name.startsWith("/") || name.includes("\\") || name.split("/").includes("..")) {
    throw new Error(`zip-writer: unsafe entry name "${name}"`);
  }
}

/**
 * @param {{name: string, data: Buffer}[]} entries names use "/" and are relative
 * @param {Date} modified one modification time for every entry
 * @returns {Buffer}
 */
export function createZip(entries, modified) {
  const { time, day } = dosDateTime(modified);
  const parts = [];
  const central = [];
  let offset = 0;

  for (const { name, data } of entries) {
    assertSafeName(name);
    const nameBytes = Buffer.from(name, "utf8");
    const deflated = deflateRawSync(data, { level: 9 });
    const useDeflate = deflated.length < data.length;
    const body = useDeflate ? deflated : data;
    const method = useDeflate ? 8 : 0;
    const crc = crc32(data);
    const flags = 0x0800; // UTF-8 names

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(flags, 6);
    local.writeUInt16LE(method, 8);
    local.writeUInt16LE(time, 10);
    local.writeUInt16LE(day, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(body.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(nameBytes.length, 26);
    parts.push(local, nameBytes, body);

    const entry = Buffer.alloc(46);
    entry.writeUInt32LE(0x02014b50, 0);
    entry.writeUInt16LE(20, 4);
    entry.writeUInt16LE(20, 6);
    entry.writeUInt16LE(flags, 8);
    entry.writeUInt16LE(method, 10);
    entry.writeUInt16LE(time, 12);
    entry.writeUInt16LE(day, 14);
    entry.writeUInt32LE(crc, 16);
    entry.writeUInt32LE(body.length, 20);
    entry.writeUInt32LE(data.length, 24);
    entry.writeUInt16LE(nameBytes.length, 28);
    entry.writeUInt32LE(offset, 42);
    central.push(entry, nameBytes);

    offset += local.length + nameBytes.length + body.length;
  }

  const centralSize = central.reduce((sum, b) => sum + b.length, 0);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(centralSize, 12);
  end.writeUInt32LE(offset, 16);
  return Buffer.concat([...parts, ...central, end]);
}

/**
 * Every file below `dir` that `shipped(path)` accepts, as "/"-separated paths in a stable order.
 * A symbolic link is refused: following one could pull files from outside the module (a secret,
 * the checkout's .git/config) into the published package.
 */
export function listFiles(dir, shipped) {
  return readdirSync(dir)
    .sort()
    .flatMap((name) => {
      const path = `${dir}/${name}`;
      if (!shipped(path)) return [];
      const stat = lstatSync(path);
      if (stat.isSymbolicLink()) throw new Error(`zip-writer: symbolic link in the package tree: ${path}`);
      return stat.isDirectory() ? listFiles(path, shipped) : [path];
    });
}
