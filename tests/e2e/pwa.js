// Browser-level checks of the installed Home Screen app, the download card and the tutorial.
// Run through run.sh (needs the PHP + MariaDB fixture). Uses the globally installed playwright.
const { chromium, devices } = require(process.env.PLAYWRIGHT_DIR || "/opt/node22/lib/node_modules/playwright");
const { execFileSync } = require("child_process");
const crypto = require("crypto");

const BASE = process.env.BASE, SOCK = process.env.DB_SOCK;
let failed = 0;
const sql = (q) => execFileSync("mariadb", [`--socket=${SOCK}`, "-uroot", "pw", "-N", "-e", q]).toString().trim();
const check = (label, ok, detail = "") => { console.log((ok ? "PASS  " : "FAIL  ") + label + (ok ? "" : "   -> " + detail)); if (!ok) failed++; };
const hash = (code) => crypto.createHash("sha256").update(code).digest("hex");
const standaloneInit = () => Object.defineProperty(navigator, "standalone", { get: () => true });

(async () => {
  const phphash = execFileSync("php", ["-r", 'echo password_hash("Pwa-Reseller-4821-xq", PASSWORD_DEFAULT);']).toString();
  // schema is created by the first request of test_sessions.py, which already ran; seed fresh rows
  sql("DELETE FROM reseller_app_devices; DELETE FROM reseller_device_activation_codes; DELETE FROM admin_app_devices; DELETE FROM admin_device_activation_codes; DELETE FROM login_attempts;");
  sql("UPDATE resellers SET status='active', token_hash='" + phphash.replace(/'/g, "''") + "' WHERE id=1");
  sql("UPDATE admin_users SET status='active' WHERE id=1");
  const browser = await chromium.launch();
  const iphone = devices["iPhone 15"];

  // ---------------------------------------------------------- 1. installed app: code -> stays signed in -> revoke
  console.log("\n== A. Installed iPhone app: one-time code");
  const codeA = "ABCDEFGHJKLM";
  sql(`INSERT INTO reseller_device_activation_codes (reseller_id, code_hash, device_label, created_by_admin_id, expires_at) VALUES (1,'${hash(codeA)}','iPhone test',1,NOW() + INTERVAL 1 DAY)`);
  let ctx = await browser.newContext({ ...iphone });
  await ctx.addInitScript(standaloneInit);
  let page = await ctx.newPage();
  const errors = []; page.on("pageerror", (e) => errors.push(e.message)); page.on("console", (m) => { if (m.type() === "error") console.log("console error:", m.text()); }); page.on("requestfailed", (r) => console.log("request failed:", r.url()));
  await page.goto(BASE + "/");
  await page.waitForSelector("#appActivationBox:not([hidden])", { timeout: 8000 });
  check("code screen is shown instead of the token login", await page.isVisible("#appActivationBox") && !(await page.isVisible("#resellerLoginForm")));
  check("download card is hidden inside the app", !(await page.isVisible("#getApp")));
  await page.fill("#appActivationCode", "abcd efgh-jklm");
  check("code input formats itself as XXXX-XXXX-XXXX", (await page.inputValue("#appActivationCode")) === "ABCD-EFGH-JKLM");
  await page.fill("#appActivationCode", "WRONGCODE123");
  await page.click("#appActivationBtn");
  await page.waitForFunction(() => document.getElementById("appActivationMsg").textContent.length > 3);
  check("wrong code shows an error", (await page.textContent("#appActivationMsg")).length > 3, await page.textContent("#appActivationMsg"));
  await page.fill("#appActivationCode", codeA);
  await page.click("#appActivationBtn");
  await page.waitForSelector("#appBox", { state: "visible", timeout: 10000 });
  check("correct code opens the reseller panel", await page.isVisible("#appBox"));
  check("device credential is stored on the phone", await page.evaluate(() => !!localStorage.getItem("pw.device")));
  check("device is registered on the server as ios", sql("SELECT platform FROM reseller_app_devices LIMIT 1") === "ios");
  check("the code is consumed", sql("SELECT COUNT(*) FROM reseller_device_activation_codes WHERE consumed_at IS NOT NULL") === "1");
  await page.reload();
  await page.waitForSelector("#appBox", { state: "visible", timeout: 10000 });
  check("reopening the app stays signed in (no code asked again)", await page.isVisible("#appBox") && !(await page.isVisible("#appActivationBox")));
  sql("UPDATE reseller_app_devices SET revoked_at = NOW()");
  await page.reload();
  await page.waitForSelector("#appActivationBox:not([hidden])", { timeout: 8000 });
  check("after the admin revokes the device the code screen returns", await page.isVisible("#appActivationBox"));
  check("revoked credential is removed from the phone", await page.evaluate(() => !localStorage.getItem("pw.device")));
  check("the user is told that access was revoked", /opozvan/i.test(await page.textContent("#appActivationMsg")), await page.textContent("#appActivationMsg"));
  await page.evaluate(() => window.scrollTo(0, 0)); await page.waitForTimeout(900);
  await page.screenshot({ path: process.env.SHOTS + "/pwa-activation.png" });
  await ctx.close();

  // ---------------------------------------------------------- 2. deactivation while the app is open
  console.log("\n== B. Deactivated while the app is open");
  const codeB = "NPQRSTUVWXYZ";
  sql(`INSERT INTO reseller_device_activation_codes (reseller_id, code_hash, device_label, created_by_admin_id, expires_at) VALUES (1,'${hash(codeB)}','iPhone B',1,NOW() + INTERVAL 1 DAY)`);
  ctx = await browser.newContext({ ...iphone }); await ctx.addInitScript(standaloneInit); page = await ctx.newPage();
  await page.goto(BASE + "/"); await page.waitForSelector("#appActivationBox:not([hidden])");
  await page.fill("#appActivationCode", codeB); await page.click("#appActivationBtn");
  await page.waitForSelector("#appBox", { state: "visible", timeout: 10000 });
  sql("UPDATE resellers SET status='inactive' WHERE id=1");
  await page.evaluate(() => { document.dispatchEvent(new Event("visibilitychange")); });
  await page.evaluate(() => fetch("/api/me.php", { credentials: "same-origin" }));
  await page.reload();
  await page.waitForSelector("#appActivationBox:not([hidden])", { timeout: 8000 });
  check("deactivated reseller is back at the code screen after reopening", await page.isVisible("#appActivationBox"));
  sql("UPDATE resellers SET status='active' WHERE id=1");
  await ctx.close();

  // ---------------------------------------------------------- 3. admin code in the installed app
  console.log("\n== C. Admin code in the installed app");
  const codeC = "H2J3K4L5M6N7";
  sql(`INSERT INTO admin_device_activation_codes (admin_id, code_hash, device_label, expires_at) VALUES (1,'${hash(codeC)}','Admin iPhone',NOW() + INTERVAL 15 MINUTE)`);
  ctx = await browser.newContext({ ...iphone }); await ctx.addInitScript(standaloneInit); page = await ctx.newPage();
  await page.goto(BASE + "/"); await page.waitForSelector("#appActivationBox:not([hidden])");
  await page.fill("#appActivationCode", codeC); await page.click("#appActivationBtn");
  await page.waitForURL("**/admin.html", { timeout: 10000 });
  await page.waitForSelector("#adminPanel:not(.hidden)", { timeout: 10000 });
  check("admin code opens the admin panel", await page.isVisible("#adminPanel"));
  check("admin stays signed in after reopening", await (async () => { await page.reload(); await page.waitForSelector("#adminPanel:not(.hidden)", { timeout: 10000 }); return await page.isVisible("#adminPanel"); })());
  sql("UPDATE admin_app_devices SET revoked_at = NOW()");
  await page.goto(BASE + "/admin.html");
  await page.waitForURL(BASE + "/", { timeout: 10000 });
  await page.waitForSelector("#appActivationBox:not([hidden])", { timeout: 8000 });
  check("revoked admin device is sent back to the code screen", await page.isVisible("#appActivationBox"));
  await ctx.close();

  // ---------------------------------------------------------- 3b. admin drawer + code issuing on an iPhone (the "frozen screen" bug)
  console.log("\n== C2. Admin: drawer and code issuing on an iPhone");
  const adminPw = "AdminPass-12345";
  sql("DELETE FROM admin_two_factor");
  sql("UPDATE admin_users SET password_hash='" + execFileSync("php", ["-r", 'echo password_hash("' + adminPw + '", PASSWORD_DEFAULT);']).toString().replace(/'/g, "''") + "' WHERE id=1");
  const codeD = "QRSTUVWXYZ23";
  sql(`INSERT INTO admin_device_activation_codes (admin_id, code_hash, device_label, expires_at) VALUES (1,'${hash(codeD)}','Admin drawer',NOW() + INTERVAL 15 MINUTE)`);
  ctx = await browser.newContext({ ...iphone }); await ctx.addInitScript(standaloneInit); page = await ctx.newPage();
  await page.goto(BASE + "/"); await page.waitForSelector("#appActivationBox:not([hidden])");
  await page.fill("#appActivationCode", codeD); await page.click("#appActivationBtn");
  await page.waitForSelector("#adminPanel:not(.hidden)", { timeout: 10000 });
  await page.waitForSelector(".reseller-actions .icon-action", { timeout: 10000 });
  const vh = page.viewportSize().height, vw = page.viewportSize().width;
  const drawerHit = () => page.evaluate(([x, y]) => { const el = document.elementFromPoint(x, y); return !!(el && el.closest(".reseller-drawer")); }, [vw / 2, vh * 0.6]);
  const drawerScroll = async () => {
    await page.evaluate(() => { document.querySelector(".drawer-content").scrollTop = 0; });
    await page.mouse.move(vw / 2, vh * 0.6); await page.mouse.wheel(0, 500); await page.waitForTimeout(400);
    return page.evaluate(() => document.querySelector(".drawer-content").scrollTop);
  };
  await page.click(".reseller-actions .icon-action");
  await page.waitForSelector("#resellerDrawerBackdrop.open"); await page.waitForTimeout(500);
  check("opening the drawer does not pop the keyboard up on iOS", await page.evaluate(() => !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)), await page.evaluate(() => document.activeElement.tagName));
  check("drawer content is scrollable and scrolls", (await page.evaluate(() => { const c = document.querySelector(".drawer-content"); return c.scrollHeight > c.clientHeight; })) && (await drawerScroll()) > 0);
  await page.evaluate(() => document.getElementById("appActivationDeviceLabel").scrollIntoView({ block: "center" }));
  await page.fill("#appActivationDeviceLabel", "Test telefon");
  await page.click("#createAppActivationCode");
  await page.waitForSelector("#adminReauthModal.open", { timeout: 5000 });
  await page.waitForTimeout(500);
  const box = await page.locator("#adminReauthModal .admin-modal").boundingBox();
  check("confirmation dialog with a password field is centered, not a bottom sheet", box.y > 40 && box.y + box.height < vh - 8, JSON.stringify(box));
  check("confirmation dialog is above the drawer and receives touches", await page.evaluate(() => { const r = document.querySelector("#adminReauthModal .admin-modal").getBoundingClientRect(); const el = document.elementFromPoint(r.x + r.width / 2, r.y + 20); return !!el.closest("#adminReauthModal"); }));
  await page.fill("#adminReauthPassword", adminPw);
  await page.click("#adminReauthConfirmBtn");
  await page.waitForSelector("#appActivationCodeOutput:not([hidden])", { timeout: 10000 });
  await page.waitForTimeout(500);
  check("a new device code is shown", /^[A-Z0-9]{12}$/.test((await page.textContent("#appActivationCodeValue")).trim()), await page.textContent("#appActivationCodeValue"));
  check("no confirmation overlay is left blocking the screen", (await page.locator(".admin-modal-backdrop.open").count()) === 0);
  check("touches still reach the drawer after issuing the code", await drawerHit());
  check("drawer still scrolls after issuing the code", (await drawerScroll()) > 0);
  await page.screenshot({ path: process.env.SHOTS + "/admin-drawer-ios.png" });
  await ctx.close();

  // ---------------------------------------------------------- 4. website: download card + tutorial
  console.log("\n== D. Website on an Android phone");
  ctx = await browser.newContext({ ...devices["Pixel 7"] }); page = await ctx.newPage();
  await page.goto(BASE + "/"); await page.waitForSelector("#getApp", { state: "visible" });
  await page.waitForTimeout(900); await page.screenshot({ path: process.env.SHOTS + "/site-android-login.png", fullPage: true });
  check("download card is visible on the login screen", await page.isVisible("#getAndroidBtn") && await page.isVisible("#getIosBtn"));
  check("APK button points to the published file", (await page.getAttribute("#getAndroidBtn", "href")) === "/downloads/PlayWorld-Reseller.apk");
  check("tutorial does NOT show before the icon is tapped", !(await page.isVisible(".ob-backdrop.open").catch(() => false)));
  await page.addInitScript(() => {});
  await page.evaluate(() => { document.getElementById("getAndroidBtn").addEventListener("click", (e) => e.preventDefault(), true); });
  await page.click("#getAndroidBtn");
  check("tapping the Android icon does not open the tutorial yet", !(await page.locator(".ob-backdrop.open").count()));
  await page.reload(); await page.waitForSelector(".ob-backdrop.open", { timeout: 8000 });
  check("next time the site opens the Android tutorial appears", await page.locator(".ob-backdrop.open").count() === 1);
  check("zoom is locked while the tutorial is open", /maximum-scale=1/.test(await page.getAttribute('meta[name="viewport"]', "content")) && (await page.evaluate(() => getComputedStyle(document.querySelector(".ob-slide")).touchAction)) === "manipulation");
  check("tutorial has 5 steps", await page.locator(".ob-slide").count() === 5);
  const titles = [];
  for (let i = 0; i < 5; i++) { titles.push(await page.textContent("#obTitle")); await page.screenshot({ path: `${process.env.SHOTS}/tutorial-android-${i + 1}.png` }); if (i < 4) { await page.click("#obNext"); await page.waitForTimeout(550); } }
  check("Play Protect steps mention Learn more and Install anyway", (await page.locator(".ob-slide").allTextContents()).join(" ").includes("Learn more") && (await page.locator(".ob-slide").allTextContents()).join(" ").includes("Install anyway"));
  await page.click("#obNext"); await page.waitForTimeout(500);
  check("zoom is restored after the tutorial is closed", !/maximum-scale/.test(await page.getAttribute('meta[name="viewport"]', "content")));
  await page.reload(); await page.waitForTimeout(800);
  check("tutorial is shown only once per tap", !(await page.locator(".ob-backdrop.open").count()));
  await ctx.close();

  console.log("\n== E. Website on an iPhone");
  ctx = await browser.newContext({ ...iphone }); page = await ctx.newPage();
  await page.goto(BASE + "/"); await page.waitForSelector("#getApp", { state: "visible" });
  await page.click("#getIosBtn"); await page.waitForSelector(".ob-backdrop.open", { timeout: 5000 });
  check("iPhone button opens the Add to Home Screen guide", /iPhone/.test(await page.textContent("#obKicker")));
  for (let i = 0; i < 5; i++) { await page.screenshot({ path: `${process.env.SHOTS}/tutorial-ios-${i + 1}.png` }); if (i < 4) { await page.click("#obNext"); await page.waitForTimeout(550); } }
  await ctx.close();

  console.log("\n== F. Website on a desktop");
  ctx = await browser.newContext({ viewport: { width: 1200, height: 800 } }); page = await ctx.newPage();
  await page.goto(BASE + "/"); await page.waitForSelector("#loginBox", { state: "visible" });
  check("desktop login screen has no install card", !(await page.locator("#getApp").isVisible()));
  check("no JavaScript errors", errors.length === 0, errors.join("|"));
  await browser.close();
  console.log(failed ? `\n${failed} FAILED` : "\nALL PWA TESTS PASSED");
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
