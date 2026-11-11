// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// Covers receipt-documents-list, "Batch continuation": a resend of many records runs batch after batch
// after one confirmation, until every record was attempted or the guard refused, and a lost answer is
// reported as unknown. Run: node --test tests/js/*.test.mjs
import test from "node:test";
import assert from "node:assert/strict";
import { pageWithDocumentsList, loadScript, click, settle, jsonAnswer } from "./support/fake-browser.mjs";

const BATCH_SIZE = 25;
const CONFIG = {
  ajaxUrl: "/admin-placeholder/index.php?controller=AdminIfirma&token=changeme",
  batchSizes: { resend: BATCH_SIZE },
  words: {
    confirm_bulk_resend: "Resend %d records?",
    nothing_selected: "Select at least one record.",
    running: "%1$d of %2$d",
    summary_title: "Summary",
    request_failed: "Request failed",
    stopped_rate_limited: "Stopped: rate guard",
    outcomes: { sent: "Sent", not_attempted: "Not attempted", unknown: "Unknown" },
  },
};

const range = (from, to) => Array.from({ length: to - from + 1 }, (_, i) => from + i);

/** A documents list with the given records selected and the native resend button wired by the script. */
function listPage(ids, confirmAnswers = []) {
  const page = pageWithDocumentsList(ids);
  const runtime = loadScript({ document: page.document, config: CONFIG, confirmAnswers });
  return { ...page, ...runtime };
}

const sentBatch = (ids, extra = {}) =>
  jsonAnswer({ success: true, data: { results: ids.map((id) => ({ id, outcome: "sent", message: "" })), ...extra } });

const rendered = (document) =>
  document
    .getElementById("ifirma-bulk-modal")
    .querySelector("tbody")
    .children.map((tr) => tr.children.map((td) => td.textContent));

const renderedFor = (document, outcomeWord) =>
  rendered(document)
    .filter((row) => row[1] === outcomeWord)
    .map((row) => Number(row[0]));

const totalsText = (document) => document.getElementById("ifirma-bulk-modal").querySelector(".ifirma-bulk-totals").textContent;

async function runResend(page, answers) {
  answers.forEach((a) => page.ajax.answer(a));
  click(page.button);
  await settle();
}

test("60 selected records are sent in ceil(60/25) BulkRun requests after one confirmation", async () => {
  const ids = range(1, 60);
  const page = listPage(ids);

  await runResend(page, [sentBatch(range(1, 25)), sentBatch(range(26, 50)), sentBatch(range(51, 60))]);

  assert.deepEqual(page.confirms, ["Resend 60 records?"]);
  assert.equal(page.ajax.requests.length, 3);
  assert.ok(page.ajax.requests.every((r) => /&action=BulkRun$/.test(r.url) && r.type === "POST"));
});

test("every selected id is sent exactly once and in order across the batches", async () => {
  const page = listPage(range(1, 60));

  await runResend(page, [sentBatch(range(1, 25)), sentBatch(range(26, 50)), sentBatch(range(51, 60))]);

  assert.deepEqual(page.ajax.requests.map((r) => r.data.ids), [range(1, 25), range(26, 50), range(51, 60)]);
  assert.deepEqual(page.ajax.requests.map((r) => r.data.kind), ["resend", "resend", "resend"]);
});

test("only the last batch is marked final", async () => {
  const page = listPage(range(1, 60));

  await runResend(page, [sentBatch(range(1, 25)), sentBatch(range(26, 50)), sentBatch(range(51, 60))]);

  assert.deepEqual(page.ajax.requests.map((r) => r.data.final), [0, 0, 1]);
});

test("the second and later requests carry the running totals of the earlier batches", async () => {
  const page = listPage(range(1, 60));

  await runResend(page, [sentBatch(range(1, 25)), sentBatch(range(26, 50)), sentBatch(range(51, 60))]);

  assert.equal("totals[sent]" in page.ajax.requests[0].data, false);
  assert.equal(page.ajax.requests[1].data["totals[sent]"], 25);
  assert.equal(page.ajax.requests[2].data["totals[sent]"], 50);
});

test("a finished run renders one row per record and the totals", async () => {
  const page = listPage(range(1, 60));

  await runResend(page, [sentBatch(range(1, 25)), sentBatch(range(26, 50)), sentBatch(range(51, 60))]);

  assert.equal(rendered(page.document).length, 60);
  assert.equal(totalsText(page.document), "Sent: 60");
});

test("ids returned in remaining are sent again in a later request", async () => {
  const page = listPage(range(1, 30));

  await runResend(page, [sentBatch(range(1, 20), { remaining: range(21, 25) }), sentBatch(range(21, 30))]);

  assert.deepEqual(page.ajax.requests.map((r) => r.data.ids), [range(1, 25), range(21, 30)]);
  assert.deepEqual(page.ajax.requests.map((r) => r.data.final), [0, 1]);
  assert.deepEqual(renderedFor(page.document, "Sent"), range(1, 30));
});

test("a guard refusal stops the run and reports every record not yet attempted as not attempted", async () => {
  const page = listPage(range(1, 60));

  await runResend(page, [sentBatch(range(1, 10), { remaining: range(11, 25), stopped: "rate_limited" })]);

  assert.equal(page.ajax.requests.length, 1);
  assert.deepEqual(renderedFor(page.document, "Sent"), range(1, 10));
  assert.deepEqual(renderedFor(page.document, "Not attempted"), range(11, 60));
  assert.equal(page.document.getElementById("ifirma-bulk-modal").querySelector(".ifirma-bulk-progress").textContent, "Stopped: rate guard");
  assert.equal(rendered(page.document)[10][2], "Stopped: rate guard");
});

test("a module error answer stops the run and reports the batch and the rest as not attempted", async () => {
  const page = listPage(range(1, 60));
  const refusal = { fail: { status: 403, responseText: JSON.stringify({ success: false, message: "Forbidden" }) } };

  await runResend(page, [refusal]);

  assert.equal(page.ajax.requests.length, 1);
  assert.deepEqual(renderedFor(page.document, "Not attempted"), range(1, 60));
  assert.equal(rendered(page.document)[0][2], "Forbidden");
});

test("a lost answer reports the batch in flight as unknown and sends nothing further", async () => {
  const page = listPage(range(1, 60));
  const transportFailure = { fail: { status: 0, responseText: "" } };

  await runResend(page, [transportFailure]);

  assert.equal(page.ajax.requests.length, 1);
  assert.deepEqual(renderedFor(page.document, "Unknown"), range(1, 25));
  assert.deepEqual(renderedFor(page.document, "Not attempted"), range(26, 60));
});

test("an answer that is not a JSON envelope counts as a lost answer in the middle of a run", async () => {
  const page = listPage(range(1, 60));

  await runResend(page, [sentBatch(range(1, 25)), { text: "<html>gateway timeout</html>" }]);

  assert.equal(page.ajax.requests.length, 2);
  assert.deepEqual(renderedFor(page.document, "Sent"), range(1, 25));
  assert.deepEqual(renderedFor(page.document, "Unknown"), range(26, 50));
  assert.deepEqual(renderedFor(page.document, "Not attempted"), range(51, 60));
});

test("declining the confirmation sends no request", async () => {
  const page = listPage(range(1, 60), [false]);

  click(page.button);
  await settle();

  assert.equal(page.confirms.length, 1);
  assert.deepEqual(page.ajax.requests, []);
});

test("no selected record sends no request and asks for a selection", async () => {
  const page = listPage(range(1, 3));
  page.boxes.forEach((box) => (box.checked = false));

  click(page.button);
  await settle();

  assert.deepEqual(page.alerts, ["Select at least one record."]);
  assert.deepEqual(page.confirms, []);
  assert.deepEqual(page.ajax.requests, []);
});
