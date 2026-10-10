/* Smooth Reseller <-> Admin login switch: the other page is fetched ahead of the tap, and the page we land on
   shows its login form at once instead of waiting (hidden) for the session check. */
(function () {
  "use strict";
  var KEY = "pw.switch", root = document.documentElement;
  try {
    var at = Number(sessionStorage.getItem(KEY) || 0);
    sessionStorage.removeItem(KEY);
    if (at && Date.now() - at < 8000) root.classList.add("from-switch");
  } catch (e) {}

  function prefetch(href) {
    if (document.querySelector('link[rel="prefetch"][href="' + href + '"]')) return;
    var l = document.createElement("link"); l.rel = "prefetch"; l.href = href; l.as = "document";
    document.head.appendChild(l);
  }
  document.addEventListener("DOMContentLoaded", function () {
    var link = document.querySelector(".login-switch a");
    if (!link) return;
    var href = link.getAttribute("href");
    ["pointerenter", "touchstart", "focus"].forEach(function (ev) { link.addEventListener(ev, function () { prefetch(href); }, { passive: true }); });
    link.addEventListener("click", function () { try { sessionStorage.setItem(KEY, String(Date.now())); } catch (e) {} });
    var native = window.PWApp && (window.PWApp.standalone || window.PWApp.native);
    if (!native) (window.requestIdleCallback || function (f) { return setTimeout(f, 1200); })(function () { prefetch(href); });
  });
})();
