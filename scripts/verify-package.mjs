// Verify built packages: node scripts/verify-package.mjs [--channel <channel>] [zip...]
// (default: dist/*.zip)
//
// Fails (exit 1) when a zip breaks the packaging contract:
//   - an entry outside the top-level `ifirma/` folder,
//   - a development/test path inside the package (a repo-level dev folder at the
//     top, or a dependency/VCS/test folder or OS junk/secret file at any depth),
//   - a missing module file (ifirma.php, config.xml, index.php, LICENCE),
//   - a missing or inconsistent `ifirma/BUILD` manifest (version must match
//     package.json; test builds need a build number that matches the file name;
//     with --channel, the channel must be that one).
// Uses a minimal reader of the zip central directory so no extra dependency is needed.
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { inflateRawSync } from "node:zlib";

const MODULE = "ifirma";
const CHANNELS = ["local", "test", "release"];
const DEV_PATHS = ["tests/", "test-stack/", "tools/", "scripts/", ".github/", "node_modules/", "openspec/", "graphify-out/"];
const NESTED_DEV_DIRS = ["node_modules", ".git", "tests"];
const JUNK_FILE = /^(\.DS_Store|Thumbs\.db|\.env(\..*)?)$/;
const REQUIRED = ["ifirma.php", "config.xml", "index.php", "LICENCE"];
const { version } = JSON.parse(readFileSync("package.json", "utf8"));

/** @returns {Map<string, () => Buffer>} entry name -> lazy content reader */
function readZip(path) {
  const buf = readFileSync(path);
  const eocd = buf.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
  if (eocd < 0) throw new Error("not a zip file (no end-of-central-directory record)");
  const count = buf.readUInt16LE(eocd + 10);
  let p = buf.readUInt32LE(eocd + 16);
  const entries = new Map();
  for (let i = 0; i < count; i++) {
    if (buf.readUInt32LE(p) !== 0x02014b50) throw new Error("corrupt central directory");
    const method = buf.readUInt16LE(p + 10);
    const size = buf.readUInt32LE(p + 20);
    const nameLen = buf.readUInt16LE(p + 28);
    const extraLen = buf.readUInt16LE(p + 30);
    const commentLen = buf.readUInt16LE(p + 32);
    const local = buf.readUInt32LE(p + 42);
    const name = buf.toString("utf8", p + 46, p + 46 + nameLen);
    entries.set(name, () => {
      const start = local + 30 + buf.readUInt16LE(local + 26) + buf.readUInt16LE(local + 28);
      const data = buf.subarray(start, start + size);
      if (method === 0) return data;
      if (method === 8) return inflateRawSync(data);
      throw new Error(`${name}: unsupported compression method ${method}`);
    });
    p += 46 + nameLen + extraLen + commentLen;
  }
  return entries;
}

function verify(path, expectedChannel) {
  const problems = [];
  const entries = readZip(path);
  for (const name of entries.keys()) {
    if (!name.startsWith(`${MODULE}/`)) problems.push(`entry outside ${MODULE}/: ${name}`);
    const inner = name.slice(MODULE.length + 1);
    const segments = inner.split("/").filter(Boolean);
    const dev =
      DEV_PATHS.some((d) => inner === d.slice(0, -1) || inner.startsWith(d)) ||
      segments.some((s) => NESTED_DEV_DIRS.includes(s));
    if (dev) problems.push(`development path in package: ${name}`);
    else if (JUNK_FILE.test(segments[segments.length - 1] || "")) problems.push(`junk or secret file in package: ${name}`);
  }
  for (const file of REQUIRED) {
    if (!entries.has(`${MODULE}/${file}`)) problems.push(`missing ${MODULE}/${file}`);
  }

  const read = entries.get(`${MODULE}/BUILD`);
  if (!read) {
    problems.push(`missing ${MODULE}/BUILD`);
    return problems;
  }
  let info;
  try {
    info = JSON.parse(read().toString("utf8"));
  } catch (e) {
    problems.push(`BUILD is not valid JSON: ${e.message}`);
    return problems;
  }
  if (info.version !== version) problems.push(`BUILD version ${info.version} != package.json ${version}`);
  if (!CHANNELS.includes(info.channel)) problems.push(`BUILD channel "${info.channel}" not one of ${CHANNELS.join(", ")}`);
  if (expectedChannel && info.channel !== expectedChannel) problems.push(`BUILD channel "${info.channel}", expected "${expectedChannel}"`);
  if (Number.isNaN(Date.parse(info.builtAt))) problems.push(`BUILD builtAt is not a timestamp: ${info.builtAt}`);
  const file = path.replace(/\\/g, "/").split("/").pop();
  if (info.channel === "test") {
    if (!Number.isInteger(info.build) || info.build < 1) problems.push(`test BUILD needs a positive build number (got ${info.build})`);
    if (!/^[0-9a-f]{7}$/.test(info.commit || "")) problems.push(`test BUILD needs a 7-char commit (got ${info.commit})`);
    if (file !== `${MODULE}-${version}+build.${info.build}.zip`) problems.push(`file name ${file} does not match BUILD (build ${info.build})`);
  } else {
    if (info.build !== null) problems.push(`${info.channel} BUILD must not carry a build number (got ${info.build})`);
    if (file !== `${MODULE}-${version}.zip`) problems.push(`file name ${file} should be ${MODULE}-${version}.zip`);
  }
  return problems;
}

const args = process.argv.slice(2);
let expectedChannel = null;
const flag = args.indexOf("--channel");
if (flag >= 0) {
  expectedChannel = args[flag + 1];
  args.splice(flag, 2);
  if (!CHANNELS.includes(expectedChannel)) {
    console.error(`verify-package: --channel must be one of ${CHANNELS.join(", ")} (got "${expectedChannel ?? ""}")`);
    process.exit(1);
  }
}
const dist = existsSync("dist") ? readdirSync("dist") : [];
const zips = args.length ? args : dist.filter((f) => f.endsWith(".zip")).map((f) => `dist/${f}`);
if (!zips.length) {
  console.error("verify-package: no zip found (build first: npm run build:zip)");
  process.exit(1);
}
let failed = 0;
for (const zip of zips) {
  let problems;
  try {
    problems = verify(zip, expectedChannel);
  } catch (e) {
    problems = [e.message];
  }
  if (problems.length) {
    failed++;
    console.error(`FAIL ${zip}`);
    for (const p of problems) console.error(`  - ${p}`);
  } else {
    console.log(`ok   ${zip}`);
  }
}
process.exit(failed ? 1 : 0);
