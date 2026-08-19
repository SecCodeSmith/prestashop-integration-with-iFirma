// Propagate package.json's version into the PrestaShop module's own version
// fields, so `npm version <patch|minor|major>` keeps all three in lock-step.
//
// npm runs this as the "version" lifecycle script: after package.json has been
// bumped but before the release commit is created. package.json adds the two
// files below to the commit via `git add` right after this script runs.
import { readFileSync, writeFileSync } from "node:fs";

const { version } = JSON.parse(readFileSync("package.json", "utf8"));

/** Replace exactly one substring; fail loudly if the anchor is missing. */
function patch(path, pattern, replacement) {
  const before = readFileSync(path, "utf8");
  if (!pattern.test(before)) {
    throw new Error(`sync-version: version anchor not found in ${path}`);
  }
  const after = before.replace(pattern, replacement);
  writeFileSync(path, after);
  console.log(`Synced version ${version} → ${path}`);
}

// ifirma.php:  $this->version = '1.0.0';
patch("ifirma.php", /(\$this->version\s*=\s*')[^']*(';)/, `$1${version}$2`);

// config.xml:  <version><![CDATA[1.0.0]]></version>
patch("config.xml", /(<version><!\[CDATA\[)[^\]]*(\]\]><\/version>)/, `$1${version}$2`);
