// Build the PrestaShop-installable package: dist/ifirma-<version>[+build.<n>].zip
//
// The archive puts every runtime file under a top-level `ifirma/` folder (the
// module's technical name), which is exactly what PrestaShop expects from a zip
// uploaded through Modules > Upload a module. Development and test assets
// (test-stack, tools, scripts, CI config, node_modules, dist) are excluded by
// using an explicit allow-list rather than a deny-list, so nothing leaks in.
//
// Build identity comes from the environment and is written to ifirma/BUILD
// (generated in memory, never into the working tree). The module version in
// ifirma.php / config.xml is never touched, so ps_module.version (VARCHAR(8))
// and upgrade-script selection behave exactly as for a release.
//   IFIRMA_BUILD_CHANNEL  local (default) | test | release
//   IFIRMA_BUILD_NUMBER   required for test: monotonically increasing integer (CI run number)
//   IFIRMA_BUILD_COMMIT   commit hash (cut to 7 chars); falls back to `git rev-parse HEAD`
import archiver from "archiver";
import { execSync } from "node:child_process";
import { createWriteStream, existsSync, mkdirSync, readFileSync } from "node:fs";

const MODULE = "ifirma";
const CHANNELS = ["local", "test", "release"];
const { version } = JSON.parse(readFileSync("package.json", "utf8"));

// Runtime files shipped to merchants. Keep in sync with package.json "files".
const FILES = ["ifirma.php", "config.xml", "index.php", "logo.png", "LICENCE", "README.md"];
const DIRS = ["classes", "controllers", "mails", "sql", "translations", "upgrade", "views"];

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

mkdirSync("dist", { recursive: true });
const outPath = `dist/${MODULE}-${version}${suffix}.zip`;
const output = createWriteStream(outPath);
const archive = archiver("zip", { zlib: { level: 9 } });

output.on("close", () => {
  const kb = (archive.pointer() / 1024).toFixed(1);
  console.log(`Built ${outPath} (${kb} kB, files rooted at ${MODULE}/, channel ${info.channel})`);
});
archive.on("warning", (err) => {
  if (err.code !== "ENOENT") throw err;
});
archive.on("error", (err) => {
  throw err;
});

archive.pipe(output);
for (const file of FILES) {
  if (existsSync(file)) archive.file(file, { name: `${MODULE}/${file}` });
}
for (const dir of DIRS) {
  if (existsSync(dir)) archive.directory(dir, `${MODULE}/${dir}`);
}
archive.append(JSON.stringify(info, null, 2) + "\n", { name: `${MODULE}/BUILD` });
await archive.finalize();
