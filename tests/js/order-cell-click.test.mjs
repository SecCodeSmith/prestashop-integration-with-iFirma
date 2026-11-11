// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// Covers order-list-receipt-indicator, "Cell click opens the order": core opens the order page from
// mousedown/mouseup on td.clickable, so the module must leave every event outside its own actions
// alone and stop only the ones on the Issue action. Run: node --test tests/js/*.test.mjs
import test from "node:test";
import assert from "node:assert/strict";
import { pageWithOrdersGrid, orderRow, loadScript, click, fire, settle, jsonAnswer } from "./support/fake-browser.mjs";

const CONFIG = {
  ajaxUrl: "/admin-placeholder/index.php?controller=AdminIfirma&token=changeme",
  words: { confirm_issue: "Issue a document?", issuing: "Issuing...", request_failed: "Request failed" },
};

/** An Orders grid with order 42 whose td.clickable carries what core's own handler would receive. */
function ordersPage() {
  const { document, form } = pageWithOrdersGrid();
  const row = orderRow(document, form, 42);
  const reachedCore = [];
  ["mousedown", "mouseup", "click"].forEach((type) =>
    row.cell.addEventListener(type, (ev) => reachedCore.push(ev.type)),
  );
  const bulk = document.createElement("button");
  bulk.className = "js-ifirma-bulk-issue";
  form.appendChild(bulk);
  const bulkReachedCore = [];
  bulk.addEventListener("mousedown", () => bulkReachedCore.push("mousedown"));
  const runtime = loadScript({ document, config: CONFIG });
  return { ...row, bulk, reachedCore, bulkReachedCore, ...runtime };
}

test("mousedown on the iFirma cell outside the Issue action reaches core and is not stopped", () => {
  const page = ordersPage();

  const ev = fire("mousedown", page.text);

  assert.deepEqual(page.reachedCore, ["mousedown"]);
  assert.equal(ev.propagationStopped, false);
  assert.equal(ev.defaultPrevented, false);
});

test("mouseup on the iFirma cell outside the Issue action reaches core and is not prevented", () => {
  const page = ordersPage();

  const ev = fire("mouseup", page.text);

  assert.deepEqual(page.reachedCore, ["mouseup"]);
  assert.equal(ev.propagationStopped, false);
  assert.equal(ev.defaultPrevented, false);
});

test("click on the iFirma cell outside the Issue action reaches core and sends no request", () => {
  const page = ordersPage();

  const ev = click(page.text);

  assert.deepEqual(page.reachedCore, ["click"]);
  assert.equal(ev.propagationStopped, false);
  assert.equal(ev.defaultPrevented, false);
  assert.deepEqual(page.ajax.requests, []);
  assert.deepEqual(page.confirms, []);
});

test("mousedown on the Issue action is stopped before core can open the order", () => {
  const page = ordersPage();

  const ev = fire("mousedown", page.issue);

  assert.deepEqual(page.reachedCore, []);
  assert.equal(ev.propagationStopped, true);
});

test("mouseup on the Issue action is stopped and default-prevented", () => {
  const page = ordersPage();

  const ev = fire("mouseup", page.issue);

  assert.deepEqual(page.reachedCore, []);
  assert.equal(ev.propagationStopped, true);
  assert.equal(ev.defaultPrevented, true);
});

test("click on the Issue action is stopped, default-prevented and does not reach core", () => {
  const page = ordersPage();
  page.ajax.answer(jsonAnswer({ success: true, message: "ok", data: {} }));

  const ev = click(page.issue);

  assert.deepEqual(page.reachedCore, []);
  assert.equal(ev.propagationStopped, true);
  assert.equal(ev.defaultPrevented, true);
});

test("click on the Issue action posts IssueFromList for the order of that row after one confirm", async () => {
  const page = ordersPage();
  page.ajax.answer(jsonAnswer({ success: true, message: "ok", data: {} }));

  click(page.issue);
  await settle();

  assert.deepEqual(page.confirms, ["Issue a document?"]);
  assert.equal(page.ajax.requests.length, 1);
  assert.match(page.ajax.requests[0].url, /&action=IssueFromList$/);
  assert.equal(page.ajax.requests[0].type, "POST");
  assert.deepEqual(page.ajax.requests[0].data, { id_order: 42 });
});

test("click on the Issue action sends nothing when the employee declines the confirmation", async () => {
  const { document, form } = pageWithOrdersGrid();
  const row = orderRow(document, form, 42);
  const page = loadScript({ document, config: CONFIG, confirmAnswers: [false] });

  click(row.issue);
  await settle();

  assert.deepEqual(page.ajax.requests, []);
});

test("mousedown on the bulk Issue button is stopped before core can act on it", () => {
  const page = ordersPage();

  const ev = fire("mousedown", page.bulk);

  assert.deepEqual(page.bulkReachedCore, []);
  assert.equal(ev.propagationStopped, true);
});
