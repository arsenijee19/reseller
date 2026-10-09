/* PlayWorld app helper: detects the installed (Home Screen) app and keeps it signed in with a
   one-time-code device credential, the same model as the Android app. Shared by index.html and admin.html. */
(function () {
  "use strict";
  var ua = navigator.userAgent || "";
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  var isAndroid = /Android/i.test(ua);
  var native = !!window.PlayWorldNative;
  var standalone = !native && (navigator.standalone === true || (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches));
  var KEY = "pw.device";
  var root = document.documentElement;
  if (standalone) root.classList.add("app-standalone");
  if (native) root.classList.add("app-native");
  if (isIOS) root.classList.add("is-ios");
  if (isAndroid) root.classList.add("is-android");

  function read() { try { return JSON.parse(localStorage.getItem(KEY) || "null"); } catch (e) { return null; } }
  function write(value) { try { localStorage.setItem(KEY, JSON.stringify(value)); } catch (e) {} }
  function clear() { try { localStorage.removeItem(KEY); } catch (e) {} }

  function randomBytes(length) {
    var bytes = new Uint8Array(length);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return bytes;
  }
  function base64url(bytes) {
    var text = "";
    for (var i = 0; i < bytes.length; i++) text += String.fromCharCode(bytes[i]);
    return btoa(text).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }
  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") return window.crypto.randomUUID();
    var b = randomBytes(16);
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ("0" + x.toString(16)).slice(-2); }).join("");
    return h.slice(0, 8) + "-" + h.slice(8, 12) + "-" + h.slice(12, 16) + "-" + h.slice(16, 20) + "-" + h.slice(20);
  }

  function post(action, body) {
    return fetch("/api/device_auth.php?action=" + action, {
      method: "POST", credentials: "same-origin", cache: "no-store",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) { return { status: res.status, json: json || {} }; });
    });
  }

  /** Exchange the one-time code for a long-lived device credential kept on this phone. */
  function activate(code) {
    var id = uuid();
    var secret = base64url(randomBytes(32));
    return post("activate", {
      code: String(code || ""), device_id: id, device_token: secret,
      platform: isIOS ? "ios" : "android",
      device_name: (isIOS ? "iPhone / iPad" : "Android") + " (aplikacija)"
    }).then(function (r) {
      if (r.status === 200 && r.json.ok) {
        write({ id: id, secret: secret, role: r.json.role || "reseller" });
        return { ok: true, role: r.json.role || "reseller" };
      }
      return { ok: false, error: r.json.error || "Aktivacija nije uspela. Proverite kod i pokušajte ponovo." };
    }).catch(function () { return { ok: false, error: "Nema veze sa serverom. Proverite internet i pokušajte ponovo." }; });
  }

  /** Open a fresh server session from the stored credential. {ok}, {none}, {revoked} or {network}. */
  function renew() {
    var device = read();
    if (!device) return Promise.resolve({ ok: false, none: true });
    return post("session", { device_id: device.id, device_token: device.secret }).then(function (r) {
      if (r.status === 200 && r.json.ok) {
        if (r.json.role && r.json.role !== device.role) { device.role = r.json.role; write(device); }
        return { ok: true, role: device.role };
      }
      if (r.status === 401) { clear(); return { ok: false, revoked: true }; }
      return { ok: false, network: true };
    }).catch(function () { return { ok: false, network: true }; });
  }

  function logout() {
    var device = read();
    clear();
    if (!device) return Promise.resolve();
    return post("logout", { device_id: device.id, device_token: device.secret }).catch(function () {});
  }

  var lastEnded = 0;
  /** The server ended the session (deactivated, token changed, device revoked): re-check, then reload. */
  function sessionEnded() {
    var now = Date.now();
    if (now - lastEnded < 5000) return;
    lastEnded = now;
    renew().then(function (r) { if (!r.network) window.location.reload(); });
  }

  // Coming back to the app after a while re-validates the device right away.
  var hiddenAt = 0;
  document.addEventListener("visibilitychange", function () {
    if (!standalone) return;
    if (document.visibilityState === "hidden") { hiddenAt = Date.now(); return; }
    if (hiddenAt && Date.now() - hiddenAt > 20000 && read()) {
      renew().then(function (r) { if (r.revoked) window.location.reload(); });
    }
  });

  // iOS keeps the page shifted after the keyboard closes inside a fixed layer; a tiny scroll nudge resets it.
  if (isIOS) {
    document.addEventListener("focusout", function (event) {
      var t = event.target;
      if (!t || !/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
      window.setTimeout(function () {
        if (/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || "")) return;
        var y = window.pageYOffset;
        window.scrollTo(window.pageXOffset, y + 1);
        window.scrollTo(window.pageXOffset, y);
      }, 80);
    });
  }

  window.PWApp = {
    standalone: standalone, native: native, isIOS: isIOS, isAndroid: isAndroid,
    isMobile: isIOS || isAndroid, hasDevice: function () { return !!read(); },
    role: function () { var d = read(); return d ? d.role : ""; },
    activate: activate, renew: renew, logout: logout, sessionEnded: sessionEnded
  };
})();
