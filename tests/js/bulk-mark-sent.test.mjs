// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// Covers receipt-documents-list, "Bulk mark as sent": the native bulk entry of the Documents list runs the kind
// mark_sent in batches after one confirmation. Run: node --test tests/js/*.test.mjs
import test from "node:test";
import assert from "node:assert/strict";
import { pageWithDocumentsList, loadScript, click, settle, jsonAnswer } from "./support/fake-browser.mjs";

const CONFIG = {
  ajaxUrl: "/admin-placeholder/index.php?controller=AdminIfirma&token=changeme",
  batchSizes: { mark_sent: 25 },
  words: {
    confirm_bulk_mark_sent: "Record %d documents as sent?",
    nothing_selected: "Select at least one record.",
    running: "%1$d of %2$d",
    summary_title: "Summary",
    request_failed: "Request failed",
    outcomes: { sent: "Sent", skipped: "Skipped", unknown: "Unknown" },
  },
};

test("the Mark as sent entry asks once and posts the kind mark_sent with the selected ids", async () => {
  const page = pageWithDocumentsList([4, 5], "submitBulkmarkSent");
  const runtime = loadScript({ document: page.document, config: CONFIG });
  runtime.ajax.answer(
    jsonAnswer({
      success: true,
      data: { results: [{ id: 4, outcome: "sent", message: "" }, { id: 5, outcome: "skipped", message: "Already sent" }] },
    })
  );

  click(page.button);
  await settle();

  assert.deepEqual(runtime.confirms, ["Record 2 documents as sent?"]);
  assert.equal(runtime.ajax.requests.length, 1);
  assert.equal(runtime.ajax.requests[0].data.kind, "mark_sent");
  assert.deepEqual(runtime.ajax.requests[0].data.ids, [4, 5]);
});
