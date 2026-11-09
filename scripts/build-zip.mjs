// Build the PrestaShop-installable package: dist/ifirma-<version>[+build.<n>].zip
//
// The archive puts every runtime file under a top-level `ifirma/` folder (the
// module's technical name), which is exactly what PrestaShop expects from a zip
// uploaded through Modules > Upload a module. Development and test assets
// (test-stack, tools, scripts, CI config, node_modules, dist) are excluded by
// using an explicit allow-list rather than a deny-list, so nothing leaks in.
// Inside the allow-listed folders, dependency, VCS and test folders and OS junk
// or local secrets (.DS_Store, Thumbs.db, .env*) are skipped as well. A missing
// allow-listed path fails the build instead of producing an incomplete zip.
//
// Build identity comes from the environment and is written to ifirma/BUILD
// (generated in memory, never into the working tree). The module version in
// ifirma.php / config.xml is never touched, so ps_module.version (VARCHAR(8))
// and upgrade-script selection behave exactly as for a release.
//   IFIRMA_BUILD_CHANNEL  local (default) | test | release
//   IFIRMA_BUILD_NUMBER   required for test: monotonically increasing integer (CI run number)
//   IFIRMA_BUILD_COMMIT   commit hash (cut to 7 chars); falls back to `git rev-parse HEAD`
import { execSync } from "node:child_process";
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { createZip } from "./zip-writer.mjs";

const MODULE = "ifirma";
const CHANNELS = ["local", "test", "release"];
const { version } = JSON.parse(readFileSync("package.json", "utf8"));

// Runtime files shipped to merchants. Keep in sync with package.json "files".
const FILES = ["ifirma.php", "config.xml", "index.php", "logo.png", "LICENCE", "README.md"];
const DIRS = ["classes", "controllers", "mails", "sql", "translations", "upgrade", "views"];

// Never shipped, even when nested inside an allow-listed folder.
const NESTED_DEV_DIRS = ["node_modules", ".git", "tests"];
const JUNK_FILE = /^(\.DS_Store|Thumbs\.db|\.env(\..*)?)$/;

/** True for a path (relative, "/"-separated) that may ship. */
function shipped(path) {
  const segments = path.split("/");
  if (segments.some((s) => NESTED_DEV_DIRS.includes(s))) return false;
  return !JUNK_FILE.test(segments[segments.length - 1]);
}

/** Every shippable file below `dir`, as "/"-separated paths in a stable order. */
function listFiles(dir) {
  return readdirSync(dir)
    .sort()
    .flatMap((name) => {
      const path = `${dir}/${name}`;
      if (!shipped(path)) return [];
      return statSync(path).isDirectory() ? listFiles(path) : [path];
    });
}

function gitCommit() {
  try {
    return execSync("git rev-parse HEAD", { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
  } catch {
    return null;
  }
}

function buildInfo(env) {
  const channel = env.IFIRMA_BUILD_CHANNEL || "local";
  if (!CHANNELS.includes(channel)) {
    throw new Error(`build-zip: IFIRMA_BUILD_CHANNEL must be one of ${CHANNELS.join(", ")} (got "${channel}")`);
  }
  let build = null;
  if (channel === "test") {
    build = Number(env.IFIRMA_BUILD_NUMBER);
    if (!Number.isInteger(build) || build < 1) {
      throw new Error(`build-zip: test builds need a positive integer IFIRMA_BUILD_NUMBER (got "${env.IFIRMA_BUILD_NUMBER ?? ""}")`);
    }
  }
  const commit = (env.IFIRMA_BUILD_COMMIT || gitCommit() || "").slice(0, 7) || null;
  return { version, build, commit, builtAt: new Date().toISOString(), channel };
}

const info = buildInfo(process.env);
const suffix = info.channel === "test" ? `+build.${info.build}` : "";

const missing = [...FILES, ...DIRS].filter((path) => !existsSync(path));
if (missing.length) {
  throw new Error(`build-zip: allow-listed path(s) missing: ${missing.join(", ")}`);
}

mkdirSync("dist", { recursive: true });
const outPath = `dist/${MODULE}-${version}${suffix}.zip`;
const paths = [...FILES, ...DIRS.flatMap(listFiles)];
const entries = paths.map((path) => ({ name: `${MODULE}/${path}`, data: readFileSync(path) }));
entries.push({ name: `${MODULE}/BUILD`, data: Buffer.from(JSON.stringify(info, null, 2) + "\n") });

const zip = createZip(entries, new Date(info.builtAt));
writeFileSync(outPath, zip);
console.log(`Built ${outPath} (${(zip.length / 1024).toFixed(1)} kB, files rooted at ${MODULE}/, channel ${info.channel})`);
