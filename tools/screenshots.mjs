// Regenerate the documentation screenshots: docs/img/*.png
//
// Drives a headless Chrome (puppeteer-core) against the local PrestaShop 8.1 test
// stack and the iFirma mock API, and writes four PNG files at 1280x800, device
// scale 1:
//   mock-status.png         the mock's status page (/__mock/status), full page
//   module-configuration.png the module's configuration page in the back office
//   bo-orders-list.png      the Orders list with the iFirma column
//   bo-order-panel.png      the iFirma panel card on an order that has a document
// Before shooting, two documents are seeded on the mock (one paid receipt, one
// unpaid invoice with a KSeF acceptance) so the status page is not empty. A fresh
// seed is made on every run (after a reset unless MOCK_RESET=0); the mock is not
// reset afterwards.
// This is for the local test stack only (documented test credentials); never
// point it at a real shop.
//
// Prerequisite (does not touch package.json or the lock file):
//   npm i --no-save puppeteer-core
//
// Environment variables (defaults in brackets):
//   CHROME_PATH      [C:/Program Files/Google/Chrome/Application/chrome.exe]
//   PS_URL           [http://localhost:8099]
//   PS_ADMIN_DIR     [admin-dev]
//   PS_ADMIN_MAIL    [admin@example.com]
//   PS_ADMIN_PASSWD  [Passw0rd!]
//   MOCK_URL         [http://localhost:8001]
//   OUT_DIR          [docs/img]  (relative to the repository root)
//   MOCK_RESET       [1]  1 = reset the mock before seeding (document ids start at 1,
//                    which the shop's existing orders point to); 0 = keep its rows
//
// Run from the repository root:
//   node tools/screenshots.mjs
// Any step that cannot find what it needs aborts with a non-zero exit code.
import puppeteer from "puppeteer-core";
import { mkdirSync, statSync } from "node:fs";
import { dirname, isAbsolute, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const env = (name, fallback) => process.env[name] || fallback;

const CHROME_PATH = env("CHROME_PATH", "C:/Program Files/Google/Chrome/Application/chrome.exe");
const PS_URL = env("PS_URL", "http://localhost:8099").replace(/\/+$/, "");
const ADMIN_DIR = env("PS_ADMIN_DIR", "admin-dev");
const ADMIN_MAIL = env("PS_ADMIN_MAIL", "admin@example.com");
const ADMIN_PASSWD = env("PS_ADMIN_PASSWD", "Passw0rd!");
const MOCK_URL = env("MOCK_URL", "http://localhost:8001").replace(/\/+$/, "");
const OUT_RAW = env("OUT_DIR", "docs/img");
const OUT_DIR = isAbsolute(OUT_RAW) ? OUT_RAW : join(ROOT, OUT_RAW);
const MOCK_RESET = env("MOCK_RESET", "1") !== "0";
const MAX_KB = 300;
const MAX_STATUS_HEIGHT = 1800; // the documents table must fit; the page is clipped below this
const HIDE_CSS = ".sf-toolbar, [id^='sfwdt'] { display: none !important; }";
const ADMIN = `${PS_URL}/${ADMIN_DIR}`;

const written = [];

function fail(message) {
  throw new Error(message);
}

async function mockPost(path, body) {
  const res = await fetch(`${MOCK_URL}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!res.ok) fail(`Mock ${path} answered HTTP ${res.status}`);
  return res.json();
}

async function seedMock() {
  if (MOCK_RESET) await mockPost("/__mock/reset");
  // The unpaid invoice goes first: after a reset it gets FakturaId 1, the id the
  // shop's existing orders point to, so their panel finds its document.
  const unpaid = await mockPost("/__mock/documents", {
    doc_type: "fakturakraj",
    contractor: { Nazwa: "Anna Nowak Studio", NIP: "5260250995", Email: "anna@example.com" },
    gross: 246.9,
    paid: 0,
    uwagi: "Zamówienie 43",
  });
  const paid = await mockPost("/__mock/documents", {
    doc_type: "fakturaparagon",
    contractor: { Nazwa: "Jan Kowalski", NIP: "1234567890", Email: "jan@example.com" },
    gross: 123.45,
    paid: 123.45,
    uwagi: "Zamówienie 42",
  });
  for (const row of [paid, unpaid]) {
    if (row.FakturaId === undefined) fail(`Seeded document has no FakturaId: ${JSON.stringify(row)}`);
  }
  await mockPost(`/__mock/ksef/accept/${unpaid.FakturaId}`);
}

async function save(name, shoot) {
  const path = join(OUT_DIR, name);
  await shoot(path);
  const kb = statSync(path).size / 1024;
  if (kb < 1) fail(`${name} is empty (${kb.toFixed(1)} KB)`);
  if (kb > MAX_KB) fail(`${name} is ${kb.toFixed(0)} KB, over the ${MAX_KB} KB limit; reduce the viewport or clip`);
  written.push(`${name}  ${kb.toFixed(1)} KB`);
}

async function main() {
  mkdirSync(OUT_DIR, { recursive: true });
  await seedMock();

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: true,
    defaultViewport: { width: 1280, height: 800, deviceScaleFactor: 1 },
    args: ["--no-sandbox", "--hide-scrollbars"],
  });
  try {
    const page = await browser.newPage();
    const goto = async (url) => {
      await page.goto(url, { waitUntil: "networkidle2", timeout: 60000 });
      await page.addStyleTag({ content: HIDE_CSS });
    };

    // 1. Mock status page.
    await goto(`${MOCK_URL}/__mock/status`);
    const rows = await page.$$eval("table tbody tr", (r) => r.length).catch(() => 0);
    if (rows < 2) fail("Mock status page shows fewer than 2 table rows; seeding failed?");
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    await save("mock-status.png", (path) =>
      height > MAX_STATUS_HEIGHT
        ? page.screenshot({ path, clip: { x: 0, y: 0, width: 1280, height: MAX_STATUS_HEIGHT } })
        : page.screenshot({ path, fullPage: true }),
    );

    // 2. Log into the back office.
    await goto(`${ADMIN}/`);
    await page.waitForSelector("#email", { timeout: 15000 }).catch(() => fail("Login form (#email) not found"));
    await page.type("#email", ADMIN_MAIL);
    await page.type("#passwd", ADMIN_PASSWD);
    await Promise.all([
      page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }),
      page.click("button[type=submit]"),
    ]);
    const token = await page.evaluate(() => {
      for (const a of document.querySelectorAll("a[href*='_token=']")) {
        const m = a.href.match(/[?&]_token=([^&#]+)/);
        if (m) return m[1];
      }
      return null;
    });
    if (!token) fail("Login failed or no _token found in back-office links");

    // 3. Module configuration.
    await goto(`${ADMIN}/index.php/improve/modules/manage/action/configure/ifirma?_token=${token}`);
    const onConfig = await page.evaluate(() => !!document.querySelector("input[name*='IFIRMA' i], input[name*='ifirma' i]"));
    if (!onConfig) {
      await goto(`${ADMIN}/index.php/improve/modules/manage?_token=${token}`);
      const href = await page.evaluate(() => {
        const a = document.querySelector("a[href*='configure=ifirma'], a[href*='configure/ifirma']");
        return a ? a.href : null;
      });
      if (!href) fail("Module configuration page not found (no Configure link for ifirma)");
      await goto(href);
      const hasForm = await page.evaluate(() => !!document.querySelector("input[name*='IFIRMA' i], input[name*='ifirma' i]"));
      if (!hasForm) fail("Module configuration form (IFIRMA_* inputs) not found");
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await save("module-configuration.png", (path) => page.screenshot({ path }));

    // 4. Orders list.
    await goto(`${ADMIN}/index.php/sell/orders/?_token=${token}&order%5BorderBy%5D=id_order&order%5BsortOrder%5D=desc`);
    const iconSel = ".ifirma-grid-icon, td.column-ifirma_document i, td[class*='ifirma_document'] i";
    await page.waitForSelector(iconSel, { timeout: 15000 }).catch(() => fail("Orders list shows no iFirma icon"));
    const colVisible = async () =>
      page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return false;
        const r = el.getBoundingClientRect();
        return r.right <= window.innerWidth && r.left >= 0;
      }, iconSel);
    if (!(await colVisible())) {
      await page.setViewport({ width: 1440, height: 800, deviceScaleFactor: 1 });
      await new Promise((r) => setTimeout(r, 500));
      if (!(await colVisible())) fail("iFirma column is off-screen even at 1440 px");
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    const ordersUrl = page.url();
    await save("bo-orders-list.png", (path) => page.screenshot({ path }));
    await page.setViewport({ width: 1280, height: 800, deviceScaleFactor: 1 });

    // 5. Order page with the module panel.
    // The status sync looks documents up by issue date, so only orders issued today
    // match a freshly seeded mock. Take the first order whose panel, after a
    // refresh, shows a document number and no error alert.
    await goto(`${ADMIN}/index.php/sell/orders/?_token=${token}&order%5BorderBy%5D=id_order&order%5BsortOrder%5D=desc`);
    await page.waitForSelector(iconSel, { timeout: 15000 }).catch(() => fail("Orders list shows no iFirma icon"));
    const orderHrefs = await page.evaluate((sel) => {
      const out = [];
      for (const icon of document.querySelectorAll(sel)) {
        const row = icon.closest("tr");
        const link = row && [...row.querySelectorAll("a[href*='/sell/orders/']")].find((a) => /\/sell\/orders\/\d+\/view/.test(a.href));
        if (link && !out.includes(link.href)) out.push(link.href);
      }
      return out;
    }, iconSel);
    if (!orderHrefs.length) fail(`No order link found next to an iFirma icon on ${ordersUrl}`);
    let panel = null;
    for (const href of orderHrefs.slice(0, 15)) {
      await goto(href);
      await page.addStyleTag({ content: ".page-head, .header-toolbar, #header_infos { display: none !important; }" });
      let candidate = await page.waitForSelector("#ifirma-panel", { timeout: 15000 }).catch(() => null);
      if (!candidate) continue;
      // The panel shows the status stored at the last synchronisation; refresh it
      // against the (re)seeded mock so a document it now finds is shown as such.
      if (await page.$("#ifirma-refresh")) {
        await Promise.all([
          page.waitForNavigation({ waitUntil: "networkidle2", timeout: 30000 }),
          page.click("#ifirma-refresh"),
        ]).catch(() => null);
        await page.addStyleTag({ content: `${HIDE_CSS} .page-head, .header-toolbar, #header_infos { display: none !important; }` });
        candidate = await page.waitForSelector("#ifirma-panel", { timeout: 15000 }).catch(() => null);
        if (!candidate) continue;
      }
      const hasError = await candidate.evaluate((el) => [...el.querySelectorAll(".alert-danger")].some((a) => a.offsetParent !== null && a.innerText.trim() !== ""));
      const hasNumber = await candidate.evaluate((el) => /\d+\/\d{4}/.test(el.innerText));
      if (!hasError && hasNumber) {
        panel = candidate;
        break;
      }
    }
    if (!panel) fail("No order with a healthy iFirma panel (document number, no error alert) among the first 15 with an icon; reseed the mock to match the shop's documents");
    await panel.evaluate((el) => el.scrollIntoView({ block: "center" }));
    await new Promise((r) => setTimeout(r, 500));
    const box = await panel.boundingBox();
    if (!box || box.height < 50) fail("#ifirma-panel has no visible size");
    const pad = 12;
    const scrollY = await page.evaluate(() => window.scrollY);
    await save("bo-order-panel.png", (path) =>
      page.screenshot({
        path,
        captureBeyondViewport: true,
        clip: {
          x: Math.max(0, box.x - pad),
          y: Math.max(0, box.y + scrollY - pad),
          width: Math.min(1280, box.width + 2 * pad),
          height: box.height + 2 * pad,
        },
      }),
    );
  } finally {
    await browser.close();
  }
}

main()
  .then(() => {
    console.log(`Wrote to ${OUT_DIR}:`);
    for (const line of written) console.log(`  ${line}`);
  })
  .catch((err) => {
    console.error(`screenshots.mjs failed: ${err.message}`);
    process.exit(1);
  });
