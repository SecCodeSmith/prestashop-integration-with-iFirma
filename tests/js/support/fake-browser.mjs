// SCM iFirma integration module for PrestaShop.
//
// @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
// @copyright 2026 SCM Jakub Berechowski
// @license   SecCodeSmith Commercial License v1.3
// SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
// See the LICENCE file distributed with this module for the full terms.
//
// A small DOM, jQuery and AJAX fake, just big enough to run views/js/admin/ifirma-bulk.js unchanged.
// What it honours from the real thing: Element.closest() walks the ancestors and matches the compound
// selectors the script uses; events run a capture phase from the root down and a bubble phase back up,
// stopPropagation() ends the walk at the current node; $.ajax answers asynchronously (microtask) like
// jQuery does and its done/fail callbacks fire once, in order, also when attached late. It does not
// model layout, CSS, a real Bootstrap modal, selector combinators or innerHTML parsing.
import vm from "node:vm";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const SCRIPT_PATH = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../../../views/js/admin/ifirma-bulk.js",
);

/* A compound selector: tag, #id, .class, [attr], [attr="value"], :checked, in any order, no combinators. */
const PART = /^(?:([a-zA-Z][a-zA-Z0-9]*)|#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:="([^"]*)")?\]|:(checked))/;

function parseCompound(selector) {
  const parts = { tag: null, id: null, classes: [], attrs: [], checked: false };
  let rest = selector.trim();
  while (rest !== "") {
    const m = PART.exec(rest);
    if (!m) {
      throw new Error("fake-browser does not support selector: " + selector);
    }
    if (m[1]) parts.tag = m[1].toLowerCase();
    if (m[2]) parts.id = m[2];
    if (m[3]) parts.classes.push(m[3]);
    if (m[4]) parts.attrs.push([m[4], m[5]]);
    if (m[6]) parts.checked = true;
    rest = rest.slice(m[0].length);
  }
  return parts;
}

class FakeEvent {
  constructor(type, target) {
    this.type = type;
    this.target = target;
    this.defaultPrevented = false;
    this.propagationStopped = false;
  }
  preventDefault() {
    this.defaultPrevented = true;
  }
  stopPropagation() {
    this.propagationStopped = true;
  }
}

class FakeNode {
  constructor() {
    this.parentNode = null;
    this.listeners = [];
  }
  addEventListener(type, fn, capture) {
    this.listeners.push({ type, fn, capture: capture === true });
  }
  /* Capture listeners run from the root down to the target, bubble listeners from the target up. */
  dispatch(event) {
    const chain = [];
    for (let n = this; n; n = n.parentNode) chain.unshift(n);
    const run = (node, capture) => {
      for (const l of node.listeners.filter((x) => x.type === event.type && x.capture === capture)) {
        l.fn.call(node, event);
      }
    };
    for (const node of chain) {
      run(node, true);
      if (event.propagationStopped) return event;
    }
    for (const node of [...chain].reverse()) {
      run(node, false);
      if (event.propagationStopped) return event;
    }
    return event;
  }
}

class FakeElement extends FakeNode {
  constructor(tagName) {
    super();
    this.tagName = tagName.toUpperCase();
    this.className = "";
    this.id = "";
    this.children = [];
    this.attributes = {};
    this.style = {};
    this.value = "";
    this.checked = false;
    this.disabled = false;
    this.text = "";
    this.html = "";
    const owner = this;
    this.classList = {
      add(c) {
        if (!owner.className.split(" ").includes(c)) owner.className = (owner.className + " " + c).trim();
      },
      remove(c) {
        owner.className = owner.className.split(" ").filter((x) => x && x !== c).join(" ");
      },
    };
  }
  get textContent() {
    return this.text + this.children.map((c) => c.textContent).join("");
  }
  set textContent(v) {
    this.children.forEach((c) => (c.parentNode = null));
    this.children = [];
    this.text = String(v);
  }
  get innerHTML() {
    return this.html;
  }
  set innerHTML(v) {
    this.children.forEach((c) => (c.parentNode = null));
    this.children = [];
    this.text = "";
    this.html = String(v);
  }
  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }
  insertBefore(child, ref) {
    child.parentNode = this;
    this.children.splice(this.children.indexOf(ref), 0, child);
    return child;
  }
  removeChild(child) {
    this.children = this.children.filter((c) => c !== child);
    child.parentNode = null;
    return child;
  }
  setAttribute(name, value) {
    this.attributes[name] = String(value);
    if (name === "id") this.id = String(value);
    if (name === "class") this.className = String(value);
  }
  getAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
  }
  removeAttribute(name) {
    delete this.attributes[name];
  }
  matches(selector) {
    const p = parseCompound(selector);
    const classes = this.className.split(" ");
    return (
      (!p.tag || this.tagName.toLowerCase() === p.tag) &&
      (!p.id || this.id === p.id) &&
      p.classes.every((c) => classes.includes(c)) &&
      p.attrs.every(([n, v]) => {
        const actual = this.getAttribute(n);
        return actual !== null && (v === undefined || actual === v);
      }) &&
      (!p.checked || this.checked)
    );
  }
  closest(selector) {
    for (let n = this; n instanceof FakeElement; n = n.parentNode) {
      if (n.matches(selector)) return n;
    }
    return null;
  }
  querySelectorAll(selector) {
    const found = [];
    const walk = (node) => {
      for (const c of node.children) {
        if (c.matches(selector)) found.push(c);
        walk(c);
      }
    };
    walk(this);
    return found;
  }
  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
  }
}

class FakeDocument extends FakeNode {
  constructor() {
    super();
    this.readyState = "complete";
    this.body = new FakeElement("body");
    this.body.parentNode = this;
    this.children = [this.body];
  }
  createElement(tag) {
    return new FakeElement(tag);
  }
  getElementById(id) {
    return this.body.id === id ? this.body : this.body.querySelectorAll("#" + id)[0] || null;
  }
  querySelectorAll(selector) {
    return this.body.querySelectorAll(selector);
  }
  querySelector(selector) {
    return this.body.querySelector(selector);
  }
}

/* jQuery's Deferred for the calls the script makes: resolve/reject settle once; done/fail callbacks fire
   in registration order, also when added after settling; done/fail return the promise. */
function makeDeferred() {
  let state = "pending";
  let value = [];
  const doneFns = [];
  const failFns = [];
  const flush = (fns) => fns.splice(0).forEach((fn) => fn.apply(null, value));
  const promise = {
    done(fn) {
      doneFns.push(fn);
      if (state === "resolved") flush(doneFns);
      return promise;
    },
    fail(fn) {
      failFns.push(fn);
      if (state === "rejected") flush(failFns);
      return promise;
    },
  };
  const settle = (next, fns) => (...args) => {
    if (state !== "pending") return;
    state = next;
    value = args;
    flush(fns);
  };
  return {
    resolve: settle("resolved", doneFns),
    reject: settle("rejected", failFns),
    promise: () => promise,
    ...promise,
  };
}

/** Collected requests plus a queue of scripted answers: {text} succeeds, {fail:{status,responseText}} fails. */
export class ScriptedAjax {
  constructor() {
    this.requests = [];
    this.answers = [];
  }
  answer(a) {
    this.answers.push(a);
  }
  handle(options) {
    // Recorded through JSON so the test side compares host-realm plain values, not vm-realm objects.
    this.requests.push(JSON.parse(JSON.stringify({ url: options.url, type: options.type, data: options.data })));
    const next = this.answers.shift();
    if (!next) {
      throw new Error("ScriptedAjax: no scripted answer left for request " + options.url);
    }
    const d = makeDeferred();
    queueMicrotask(() => (next.fail ? d.reject(next.fail, "error", "") : d.resolve(next.text, "success", {})));
    return d.promise();
  }
}

export function pageWithOrdersGrid() {
  const document = new FakeDocument();
  const form = new FakeElement("form");
  form.setAttribute("id", "order_filter_form");
  const host = new FakeElement("div");
  document.body.appendChild(host);
  host.appendChild(form);
  return { document, form };
}

export function pageWithDocumentsList(ids, nativeKey = "submitBulkresend") {
  const document = new FakeDocument();
  const boxes = ids.map((id) => {
    const box = new FakeElement("input");
    box.setAttribute("type", "checkbox");
    box.setAttribute("name", "ifirma_receiptBox[]");
    box.value = String(id);
    box.checked = true;
    document.body.appendChild(box);
    return box;
  });
  const button = new FakeElement("button");
  button.setAttribute("onclick", "if (confirm('?')) { " + nativeKey + "() }");
  document.body.appendChild(button);
  return { document, boxes, button };
}

/** An Orders-grid row: a td.clickable (core opens the order from it) holding the iFirma cell. */
export function orderRow(document, form, idOrder) {
  const tr = new FakeElement("tr");
  const cell = new FakeElement("td");
  cell.className = "clickable column-ifirma_document";
  const text = new FakeElement("span");
  text.textContent = "none";
  const issue = new FakeElement("a");
  issue.className = "js-ifirma-issue";
  issue.setAttribute("data-id-order", String(idOrder));
  issue.textContent = "Issue";
  cell.appendChild(text);
  cell.appendChild(issue);
  tr.appendChild(cell);
  form.appendChild(tr);
  return { tr, cell, text, issue };
}

/**
 * Loads the real script into a fresh vm context.
 * confirmAnswers: array of booleans handed out one per window.confirm call (default: always true).
 */
export function loadScript({ document, config, confirmAnswers = [] }) {
  const ajax = new ScriptedAjax();
  const confirms = [];
  const alerts = [];
  const reloads = [];
  const $ = (node) => ({ on() { return this; }, modal() { return this; } });
  $.fn = {};
  $.Deferred = makeDeferred;
  $.ajax = (options) => ajax.handle(options);
  const window = {
    ifirmaAdmin: config,
    jQuery: $,
    confirm(message) {
      confirms.push(message);
      return confirmAnswers.length ? confirmAnswers.shift() : true;
    },
    alert(message) {
      alerts.push(message);
    },
    location: { reload: () => reloads.push(true) },
  };
  const context = vm.createContext({ window, document });
  vm.runInContext(fs.readFileSync(SCRIPT_PATH, "utf8"), context, { filename: SCRIPT_PATH });
  return { ajax, confirms, alerts, reloads };
}

/** Lets every pending microtask (the scripted answers and the callbacks they trigger) run. */
export function settle() {
  return new Promise((resolve) => setImmediate(resolve));
}

export function click(target) {
  return target.dispatch(new FakeEvent("click", target));
}

export function fire(type, target) {
  return target.dispatch(new FakeEvent(type, target));
}

export const jsonAnswer = (payload) => ({ text: JSON.stringify(payload) });
