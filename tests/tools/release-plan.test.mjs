// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// Scenarios of the release-branching spec. Run: node --test tests/tools/*.test.mjs
import test from "node:test";
import assert from "node:assert/strict";
import { planRelease, parseRemoteRefs, bumpVersion } from "../../scripts/release-plan.mjs";

const base = { currentVersion: "1.8.0", ref: "master", defaultBranch: "master", remoteRefs: [] };

test("bumpVersion raises patch, minor and major and resets the lower parts", () => {
  assert.equal(bumpVersion("1.8.3", "patch"), "1.8.4");
  assert.equal(bumpVersion("1.8.3", "minor"), "1.9.0");
  assert.equal(bumpVersion("1.8.3", "major"), "2.0.0");
});

test("bumpVersion refuses an unknown level or a malformed version", () => {
  assert.throws(() => bumpVersion("1.8.3", "huge"), /bump level/);
  assert.throws(() => bumpVersion("1.8", "patch"), /version/);
});

test("Minor release: its own release/X.Y branch, a tag on it, master untouched, PR back", () => {
  const plan = planRelease({ ...base, bump: "minor" });

  assert.deepEqual(
    { version: plan.version, tag: plan.tag, branch: plan.releaseBranch, base: plan.baseBranch, pr: plan.openPullRequest },
    { version: "1.9.0", tag: "v1.9.0", branch: "release/1.9", base: "master", pr: true }
  );
});

test("Major release: release/2.0 carries the commit and the tag", () => {
  const plan = planRelease({ ...base, currentVersion: "1.9.2", bump: "major" });

  assert.equal(plan.releaseBranch, "release/2.0");
  assert.equal(plan.tag, "v2.0.0");
});

test("Patch on master: no branch, no pull request", () => {
  const plan = planRelease({ ...base, bump: "patch" });

  assert.deepEqual({ branch: plan.releaseBranch, pr: plan.openPullRequest, version: plan.version }, { branch: null, pr: false, version: "1.8.1" });
});

test("Patch on a release branch: stays there and opens a PR into master", () => {
  const plan = planRelease({ ...base, ref: "release/1.9", currentVersion: "1.9.0", bump: "patch" });

  assert.deepEqual({ branch: plan.releaseBranch, pr: plan.openPullRequest, version: plan.version }, { branch: null, pr: true, version: "1.9.1" });
});

test("Minor release cut from a release branch still opens a PR into the default branch", () => {
  const plan = planRelease({ ...base, ref: "release/1.9", currentVersion: "1.9.0", bump: "minor" });

  assert.equal(plan.releaseBranch, "release/1.10");
  assert.equal(plan.openPullRequest, true);
});

test("Branch already exists: the plan refuses before anything is pushed", () => {
  const remoteRefs = ["refs/heads/release/1.9"];

  assert.throws(() => planRelease({ ...base, bump: "minor", remoteRefs }), /release\/1\.9 already exists.*merge or remove/s);
});

test("Tag already exists: the plan refuses", () => {
  const remoteRefs = ["refs/tags/v1.8.1"];

  assert.throws(() => planRelease({ ...base, bump: "patch", remoteRefs }), /v1\.8\.1 already exists/);
});

test("A tag or detached ref cannot be released from", () => {
  assert.throws(() => planRelease({ ...base, ref: "", bump: "patch" }), /branch/);
});

test("parseRemoteRefs keeps the ref names of git ls-remote output", () => {
  const output = "abc123\trefs/heads/master\ndef456\trefs/tags/v1.8.0\ndef456\trefs/tags/v1.8.0^{}\n";

  assert.deepEqual(parseRemoteRefs(output), ["refs/heads/master", "refs/tags/v1.8.0", "refs/tags/v1.8.0^{}"]);
});
