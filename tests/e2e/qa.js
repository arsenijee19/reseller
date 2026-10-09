// "Ordinary user on a phone" walk-through: reseller and admin on several phone sizes against the real backend.
// Reports layout problems (horizontal overflow, controls outside the screen, hidden inputs) and broken flows.
const { chromium, devices } = require(process.env.PLAYWRIGHT_DIR || "/opt/node22/lib/node_modules/playwright");
const { execFileSync } = require("child_process");
const BASE = process.env.BASE, SOCK = process.env.DB_SOCK, SHOTS = process.env.SHOTS || "/tmp/pw-e2e-shots";
const TOKEN = "Qa-Reseller-Token-7391", ADMIN_PW = "AdminPass-12345";
let failed = 0; const notes = [];
const sql = (q) => execFileSync("mariadb", [`--socket=${SOCK}`, "-uroot", "pw", "-N", "-e", q]).toString().trim();
const phpHash = (v) => execFileSync("php", ["-r", 'echo password_hash($argv[1], PASSWORD_DEFAULT);', v]).toString().replace(/'/g, "''");
const check = (label, ok, detail = "") => { console.log((ok ? "PASS  " : "FAIL  ") + label + (ok ? "" : "   -> " + detail)); if (!ok) failed++; };

const PHONES = [
  ["iPhone-SE", { ...devices["iPhone SE"] }],
  ["iPhone-15", { ...devices["iPhone 15"] }],
  ["Pixel-7", { ...devices["Pixel 7"] }],
];

async function overflow(page) {
  return page.evaluate(() => {
    const w = window.innerWidth;
    const bad = [];
    document.querySelectorAll("body *").forEach((el) => {
      const r = el.getBoundingClientRect();
      const s = getComputedStyle(el);
      if (r.width && s.visibility !== "hidden" && s.display !== "none" && s.position !== "fixed" && (r.right > w + 1 || r.left < -1) && !el.closest(".modal-backdrop:not(.open),.admin-modal-backdrop:not(.open),.drawer-backdrop:not(.open),.ob-backdrop,[hidden]")) {
        // ignore content inside an intentionally scrollable container
        let p = el.parentElement, scrollable = false;
        while (p && p !== document.body) { const o = getComputedStyle(p).overflowX; if (o === "auto" || o === "scroll" || o === "hidden") { scrollable = true; break; } p = p.parentElement; }
        if (!scrollable) bad.push((el.id ? "#" + el.id : el.tagName.toLowerCase() + "." + String(el.className).split(" ")[0]) + " right=" + Math.round(r.right));
      }
    });
    return { scrollW: document.documentElement.scrollWidth, innerW: w, bad: bad.slice(0, 6) };
  });
}
async function inView(page, sel) {
  return page.evaluate((s) => { const el = document.querySelector(s); if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), vh: innerHeight, vw: innerWidth, ok: r.top >= 0 && r.bottom <= innerHeight && r.left >= 0 && r.right <= innerWidth }; }, sel);
}
async function layoutOk(page, label) {
  const o = await overflow(page);
  check(`${label}: no horizontal overflow`, o.scrollW <= o.innerW + 1 && o.bad.length === 0, JSON.stringify(o));
}
async function closeReleaseNotes(page, tag) {
  // The "what's new" dialog opens a moment after login; a real user has to be able to close it on any phone.
  const opened = await page.waitForSelector(".release-backdrop.open", { timeout: 3500 }).then(() => true).catch(() => false);
  if (!opened) return;
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${SHOTS}/qa-${tag}-release-notes.png` });
  const btns = await page.evaluate(() => [...document.querySelectorAll(".release-backdrop.open button")].map((b) => { const r = b.getBoundingClientRect(); return { text: b.textContent.trim().slice(0, 30), ok: r.top >= 0 && r.bottom <= innerHeight && r.width > 0 }; }));
  check(`${tag}: "what's new" dialog has a close button on screen`, btns.some((b) => b.ok), JSON.stringify(btns));
  const closer = page.locator(".release-backdrop.open button").filter({ hasText: /./ }).last();
  await closer.click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(600);
  check(`${tag}: "what's new" dialog closes`, (await page.locator(".release-backdrop.open").count()) === 0);
}

(async () => {
  // ---------------------------------------------------------- fixture data
  sql("DELETE FROM admin_two_factor; DELETE FROM reseller_two_factor; DELETE FROM login_attempts; DELETE FROM orders; DELETE FROM wallet_transactions; DELETE FROM product_prices; DELETE FROM reseller_app_devices; DELETE FROM reseller_device_activation_codes;");
  sql(`UPDATE admin_users SET password_hash='${phpHash(ADMIN_PW)}' WHERE id=1`);
  sql(`UPDATE resellers SET status='active', balance_rsd=25000, discount_percent=5, token_hash='${phpHash(TOKEN)}', display_name='QA Reseller', email='qa.reseller@example.test', phone='+381641234567', profile_completed_at=NOW(), admin_notes='' WHERE id=1`);
  const products = [
    ["fc27-ps5", "EA SPORTS FC 27", "PS5 Primary", 2400], ["fc27-ps4", "EA SPORTS FC 27", "PS4 Primary", 2200],
    ["gta5-ps5", "Grand Theft Auto V Enhanced", "PS5 Primary", 1800], ["gow-ps5", "God of War Ragnarök", "PS5 Primary", 3100],
    ["fifa-ps4", "FIFA 23", "PS4 Secondary", 900], ["crash-ps4", "Crash Bandicoot N. Sane Trilogy", "PS4 / PS5 Primary", 1500],
    ["ps-plus-12", "PlayStation Plus Essential 12 meseci", "PS4 / PS5 Subscription", 7900], ["longname", "The Elder Scrolls V: Skyrim Anniversary Edition Collector's Pack Ultimate", "PS5 Primary", 3500],
  ];
  products.forEach(([id, n, t, p]) => sql(`INSERT INTO product_prices (product_id, product_name, account_type, price) VALUES ('${id}','${n.replace(/'/g, "''")}','${t}',${p})`));
  for (let i = 0; i < 6; i++) sql(`INSERT INTO orders (request_id, reseller_id, reseller_email, product_id, buyer_email, price_rsd, created_at) VALUES ('r${i}', 1, 'qa.reseller@example.test', '${products[i][0]}', 'buyer${i}@example.test', ${products[i][3]}, NOW() - INTERVAL ${i + 1} DAY)`);
  for (let i = 0; i < 5; i++) sql(`INSERT INTO wallet_transactions (reseller_id, amount_rsd, type, description, created_at) VALUES (1, ${i % 2 ? -2000 : 5000}, '${i % 2 ? "ORDER" : "TOPUP"}', 'QA test ${i}', NOW() - INTERVAL ${i} DAY)`);

  const browser = await chromium.launch();
  for (const [name, dev] of PHONES) {
    console.log(`\n================ ${name} (${dev.viewport.width}x${dev.viewport.height}) ================`);
    sql("DELETE FROM login_attempts");
    let ctx = await browser.newContext({ ...dev });
    await ctx.addInitScript(() => { try { localStorage.setItem("pw-release-seen", "x"); } catch (e) {} });
    let page = await ctx.newPage();
    const errors = []; page.on("pageerror", (e) => errors.push(e.message));

    // ---------------- reseller
    console.log("-- reseller");
    await page.goto(BASE + "/"); await page.waitForSelector("#resellerLoginForm", { state: "visible" }); await page.waitForTimeout(700);
    await layoutOk(page, "login page");
    await page.screenshot({ path: `${SHOTS}/qa-${name}-1-login.png`, fullPage: true });
    await page.fill("#token", "wrong-token-123"); await page.click("#loginBtn");
    await page.waitForFunction(() => document.getElementById("loginMsg") && !document.getElementById("loginMsg").hidden && document.getElementById("loginMsg").textContent.length > 2, null, { timeout: 8000 });
    check("wrong token shows an error under the field", await page.locator("#loginMsg").isVisible());
    await page.fill("#token", TOKEN); await page.click("#loginBtn");
    await page.waitForSelector("#appBox", { state: "visible", timeout: 12000 }); await page.waitForTimeout(900);
    await closeReleaseNotes(page, name);
    check("reseller panel opens after login", await page.isVisible("#appBox"));
    await layoutOk(page, "reseller panel");
    await page.screenshot({ path: `${SHOTS}/qa-${name}-2-panel.png`, fullPage: true });
    await page.waitForTimeout(1600);
    const bal = await page.textContent("#balanceText");
    check("balance on screen matches the database", bal.replace(/\D/g, "") === sql("SELECT balance_rsd FROM resellers WHERE id=1"), bal + " vs " + sql("SELECT balance_rsd FROM resellers WHERE id=1"));

    // product picker
    await page.click("#productPickerTrigger"); await page.waitForTimeout(500);
    check("product picker opens", await page.locator("#productPickerMenu").isVisible());
    await page.fill("#productSearch", "fc 27"); await page.waitForTimeout(500);
    const optionCount = await page.locator("#productPickerMenu .product-option").count();
    check("search finds FC 27 products", optionCount >= 1, String(optionCount));
    await page.screenshot({ path: `${SHOTS}/qa-${name}-3-picker.png` });
    await page.locator("#productPickerMenu .product-option").first().click(); await page.waitForTimeout(500);
    check("order button enabled after choosing a product", !(await page.locator("#orderBtn").isDisabled()));
    await page.locator("#orderBtn").scrollIntoViewIfNeeded(); await page.click("#orderBtn");
    await page.waitForSelector("#confirmModal.open"); await page.waitForTimeout(500);
    const confirmBtn = await inView(page, "#confirmOrderBtn");
    check("order confirmation: the confirm button is on screen without scrolling", confirmBtn && confirmBtn.ok, JSON.stringify(confirmBtn));
    await page.screenshot({ path: `${SHOTS}/qa-${name}-4-confirm.png` });
    await page.click("#confirmOrderBtn");
    await page.waitForSelector("#successModal.open", { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(800);
    const ordered = sql("SELECT COUNT(*) FROM orders WHERE reseller_id=1");
    check("order is created", Number(ordered) >= 7, "orders=" + ordered);
    await page.screenshot({ path: `${SHOTS}/qa-${name}-5-success.png` });
    if (await page.locator("#successModal.open").count()) { await page.click("#closeSuccessBtn"); await page.waitForTimeout(500); }
    check("balance went down after the order", !/^25[. ]?000/.test((await page.textContent("#balanceText")).trim()), await page.textContent("#balanceText"));

    // "Uplatio sam"
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click("#paidBtn"); await page.waitForTimeout(900);
    const paidMsg = await page.locator("#paidMsg").textContent();
    check("'Uplatio sam' gives feedback", paidMsg.length > 5, paidMsg);

    // balance / transactions
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click("#balanceBtn"); await page.waitForSelector("#transactionsModal.open"); await page.waitForTimeout(700);
    const closeTx = await inView(page, "#closeTransactionsBtn");
    check("transactions: close button on screen", closeTx && closeTx.ok, JSON.stringify(closeTx));
    const amountCell = await page.evaluate(() => { const c = document.querySelector("#transactionsTableBody tr td.transaction-amount"); if (!c) return null; const r = c.getBoundingClientRect(); return { right: Math.round(r.right), vw: innerWidth, ok: r.right <= innerWidth && r.left >= 0 }; });
    check("transactions: the amount is visible without sideways scrolling", amountCell && amountCell.ok, JSON.stringify(amountCell));
    await page.screenshot({ path: `${SHOTS}/qa-${name}-6-transactions.png` });
    await page.click("#closeTransactionsBtn"); await page.waitForTimeout(500);

    // settings (My Profile)
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click("#accountSettingsBtn"); await page.waitForSelector("#settingsModal.open"); await page.waitForTimeout(700);
    const scrollable = await page.evaluate(() => { const m = document.querySelector("#settingsModal .modal"); return { sh: m.scrollHeight, ch: m.clientHeight, ov: getComputedStyle(m).overflowY }; });
    check("profile dialog scrolls when taller than the screen", scrollable.sh <= scrollable.ch + 1 || scrollable.ov === "auto", JSON.stringify(scrollable));
    await page.locator("#settingsModal .modal").evaluate((m) => { m.scrollTop = m.scrollHeight; }); await page.waitForTimeout(300);
    const saveBtn = await inView(page, "#saveSettingsBtn");
    check("profile dialog: save button reachable by scrolling inside the dialog", saveBtn && saveBtn.ok, JSON.stringify(saveBtn));
    await page.screenshot({ path: `${SHOTS}/qa-${name}-7-settings.png` });
    await page.click("#closeSettingsBtn"); await page.waitForTimeout(500);

    // history
    await page.locator("#historyList").scrollIntoViewIfNeeded(); await page.waitForTimeout(300);
    check("scroll-to-top button floats over the page (position: fixed)", await page.evaluate(() => getComputedStyle(document.getElementById("scrollTopBtn")).position === "fixed"));
    await layoutOk(page, "order history");
    await page.screenshot({ path: `${SHOTS}/qa-${name}-8-history.png` });
    await page.click("#themeBtn"); await page.waitForTimeout(400); await layoutOk(page, "dark theme panel");
    await page.screenshot({ path: `${SHOTS}/qa-${name}-9-dark.png` });
    await page.click("#themeBtn");
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click("#logoutBtn"); await page.waitForSelector("#resellerLoginForm", { state: "visible", timeout: 8000 });
    check("logout returns to the login screen", await page.isVisible("#resellerLoginForm"));
    check("reseller flow: no JavaScript errors", errors.length === 0, errors.join(" | "));
    errors.length = 0;

    // ---------------- admin
    console.log("-- admin");
    sql("DELETE FROM login_attempts");
    await page.goto(BASE + "/admin.html"); await page.waitForSelector("#loginPanel", { state: "visible" }); await page.waitForTimeout(600);
    await layoutOk(page, "admin login");
    await page.fill("#adminPassword", ADMIN_PW); await page.click("#adminLoginBtn");
    await page.waitForSelector("#adminPanel:not(.hidden)", { timeout: 12000 }); await page.waitForTimeout(900);
    await layoutOk(page, "admin panel");
    await page.screenshot({ path: `${SHOTS}/qa-${name}-10-admin.png`, fullPage: false });
    await page.waitForSelector(".reseller-actions .icon-action"); await page.click(".reseller-actions .icon-action");
    await page.waitForSelector("#resellerDrawerBackdrop.open"); await page.waitForTimeout(600);
    await page.screenshot({ path: `${SHOTS}/qa-${name}-11-drawer.png` });
    await page.evaluate(() => document.getElementById("appActivationDeviceLabel").scrollIntoView({ block: "center" }));
    await page.fill("#appActivationDeviceLabel", "QA telefon");
    await page.click("#createAppActivationCode");
    await page.waitForSelector("#adminReauthModal.open", { timeout: 6000 }); await page.waitForTimeout(600);
    const pwField = await inView(page, "#adminReauthPassword");
    check("admin password field is visible on screen when the confirmation opens (no scrolling needed)", pwField && pwField.ok, JSON.stringify(pwField));
    const cBtn = await inView(page, "#adminReauthConfirmBtn");
    check("confirmation button is on screen", cBtn && cBtn.ok, JSON.stringify(cBtn));
    check("confirmation is above everything and receives touches", await page.evaluate(() => { const r = document.querySelector("#adminReauthModal .admin-modal").getBoundingClientRect(); const el = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return !!(el && el.closest("#adminReauthModal")); }));
    await page.screenshot({ path: `${SHOTS}/qa-${name}-12-reauth.png` });
    await page.fill("#adminReauthPassword", ADMIN_PW); await page.click("#adminReauthConfirmBtn");
    await page.waitForSelector("#appActivationCodeOutput:not([hidden])", { timeout: 10000 }); await page.waitForTimeout(600);
    const codeBox = await inView(page, "#appActivationCodeOutput");
    check("the new code is on screen right after it is issued (no scrolling to find it)", codeBox && codeBox.top >= 0 && codeBox.top < codeBox.vh, JSON.stringify(codeBox));
    await page.screenshot({ path: `${SHOTS}/qa-${name}-13-code.png` });
    check("admin flow: no JavaScript errors", errors.length === 0, errors.join(" | "));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} FAILED` : "\nQA WALK-THROUGH PASSED");
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
