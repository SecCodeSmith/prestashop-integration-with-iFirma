// Build the PrestaShop-installable package: dist/ifirma-<version>.zip
//
// The archive puts every runtime file under a top-level `ifirma/` folder (the
// module's technical name), which is exactly what PrestaShop expects from a zip
// uploaded through Modules > Upload a module. Development and test assets
// (test-stack, tools, scripts, CI config, node_modules, dist) are excluded by
// using an explicit allow-list rather than a deny-list, so nothing leaks in.
import archiver from "archiver";
import { createWriteStream, existsSync, mkdirSync, readFileSync } from "node:fs";

const MODULE = "ifirma";
const { version } = JSON.parse(readFileSync("package.json", "utf8"));

// Runtime files shipped to merchants. Keep in sync with package.json "files".
const FILES = ["ifirma.php", "config.xml", "index.php", "logo.png", "LICENCE", "README.md"];
const DIRS = ["classes", "controllers", "mails", "sql", "translations", "upgrade", "views"];

mkdirSync("dist", { recursive: true });
const outPath = `dist/${MODULE}-${version}.zip`;
const output = createWriteStream(outPath);
const archive = archiver("zip", { zlib: { level: 9 } });

output.on("close", () => {
  const kb = (archive.pointer() / 1024).toFixed(1);
  console.log(`Built ${outPath} (${kb} kB, files rooted at ${MODULE}/)`);
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
await archive.finalize();
