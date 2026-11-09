// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// Decides where a release lands, before anything is bumped or pushed:
//   - minor and major: a new `release/<major>.<minor>` branch, commit and tag on it, PR back;
//   - patch: stays on the dispatched branch; on a `release/*` branch it also opens a PR back.
// It refuses when the target branch or tag already exists on the remote.
//
//   node scripts/release-plan.mjs <patch|minor|major>   (writes GITHUB_OUTPUT lines)
// Environment: GITHUB_REF_NAME (the dispatched branch), DEFAULT_BRANCH (default: master).
import { execFileSync } from "node:child_process";
import { appendFileSync, readFileSync } from "node:fs";
import { pathToFileURL } from "node:url";

const LEVELS = ["patch", "minor", "major"];

export function bumpVersion(version, level) {
  if (!LEVELS.includes(level)) throw new Error(`unknown bump level "${level}"`);
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(version);
  if (!match) throw new Error(`version "${version}" is not major.minor.patch`);
  const [major, minor, patch] = match.slice(1).map(Number);
  if (level === "major") return `${major + 1}.0.0`;
  if (level === "minor") return `${major}.${minor + 1}.0`;
  return `${major}.${minor}.${patch + 1}`;
}

export function parseRemoteRefs(lsRemoteOutput) {
  return lsRemoteOutput
    .split("\n")
    .map((line) => line.split("\t")[1])
    .filter(Boolean);
}

/** @returns {{version: string, tag: string, releaseBranch: string|null, baseBranch: string, openPullRequest: boolean}} */
export function planRelease({ currentVersion, bump, ref, defaultBranch, remoteRefs }) {
  if (!ref) throw new Error("a release must be started from a branch, not from a tag");
  const version = bumpVersion(currentVersion, bump);
  const tag = `v${version}`;
  const [major, minor] = version.split(".");
  const ownBranch = bump === "patch" ? null : `release/${major}.${minor}`;

  if (remoteRefs.includes(`refs/tags/${tag}`)) {
    throw new Error(`tag ${tag} already exists: merge the open release pull request or remove the tag first`);
  }
  if (ownBranch && remoteRefs.includes(`refs/heads/${ownBranch}`)) {
    throw new Error(`branch ${ownBranch} already exists: merge or remove the existing release first`);
  }

  const landsOnDefault = !ownBranch && ref === defaultBranch;
  return { version, tag, releaseBranch: ownBranch, baseBranch: defaultBranch, openPullRequest: !landsOnDefault };
}

function main() {
  const lines = execFileSync("git", ["ls-remote", "--heads", "--tags", "origin"], { encoding: "utf8" });
  const plan = planRelease({
    currentVersion: JSON.parse(readFileSync("package.json", "utf8")).version,
    bump: process.argv[2],
    ref: process.env.GITHUB_REF_NAME,
    defaultBranch: process.env.DEFAULT_BRANCH || "master",
    remoteRefs: parseRemoteRefs(lines),
  });
  const output = [
    `version=${plan.version}`,
    `tag=${plan.tag}`,
    `release_branch=${plan.releaseBranch ?? ""}`,
    `base_branch=${plan.baseBranch}`,
    `open_pr=${plan.openPullRequest}`,
  ].join("\n");
  if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, output + "\n");
  console.log(output);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    main();
  } catch (error) {
    console.error(`release plan refused: ${error.message}`);
    process.exit(1);
  }
}
