/* Phone section menu: two taps from anywhere to any panel section. */
(function () {
  "use strict";
  var SECTIONS = [
    { id: "secOrder", label: "Nova porudžbina", icon: '<path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>' },
    { id: "secCode", label: "Verifikacioni kod", icon: '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/>' },
    { id: "secPrices", label: "Cenovnik", icon: '<path d="m20.6 13.4-7.2 7.2a2 2 0 0 1-2.8 0L3.4 13.4a2 2 0 0 1-.6-1.4V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.4 7.4a1.7 1.7 0 0 1 0 2.4Z"/><circle cx="8" cy="8" r="1.2"/>' },
    { id: "secHistory", label: "Prethodne porudžbine", icon: '<rect x="5" y="4" width="14" height="17" rx="2.5"/><path d="M9 4.5h6M9 10h6M9 14h6"/>' }
  ];
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var NS = "http://www.w3.org/2000/svg";
  function icon(inner) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + inner + "</svg>"; }

  var fab = document.createElement("button");
  fab.className = "sn-fab"; fab.type = "button"; fab.setAttribute("aria-label", "Meni sekcija"); fab.setAttribute("aria-expanded", "false");
  fab.innerHTML = icon('<path d="M4 7h16M4 12h16M4 17h10"/>');
  var backdrop = document.createElement("div"); backdrop.className = "sn-backdrop";
  var drawer = document.createElement("nav"); drawer.className = "sn-drawer"; drawer.setAttribute("aria-label", "Sekcije panela");
  drawer.innerHTML = '<div class="sn-head"><strong>Idi na</strong><button class="sn-close" type="button" aria-label="Zatvori">✕</button></div><div class="sn-list"></div>' +
    '<div class="sn-top"><button class="sn-item" type="button" data-top>' + icon('<path d="M12 19V5M5 12l7-7 7 7"/>') + "Na vrh</button></div>";
  var list = drawer.querySelector(".sn-list"), items = {};
  SECTIONS.forEach(function (s) {
    var b = document.createElement("button"); b.className = "sn-item"; b.type = "button"; b.dataset.target = s.id;
    b.innerHTML = icon(s.icon) + "<span>" + s.label + "</span>";
    list.appendChild(b); items[s.id] = b;
  });
  document.body.appendChild(backdrop); document.body.appendChild(drawer); document.body.appendChild(fab);

  function setOpen(on) {
    drawer.classList.toggle("open", on); backdrop.classList.toggle("open", on);
    fab.setAttribute("aria-expanded", on ? "true" : "false");
    if (on) markActive();
  }
  function goTo(id) {
    var el = id ? document.getElementById(id) : null;
    setOpen(false);
    var top = el ? Math.max(0, window.pageYOffset + el.getBoundingClientRect().top - 10) : 0;
    window.setTimeout(function () { window.scrollTo({ top: top, behavior: reduce ? "auto" : "smooth" }); }, 120);
  }
  function markActive() {
    var line = window.innerHeight * 0.35, current = null;
    SECTIONS.forEach(function (s) {
      var el = document.getElementById(s.id);
      if (!el || !el.offsetParent) return;
      var r = el.getBoundingClientRect();
      if (r.top <= line && r.bottom > line * 0.5) current = s.id;
    });
    Object.keys(items).forEach(function (k) { items[k].classList.toggle("active", k === current); });
  }

  fab.addEventListener("click", function () { setOpen(!drawer.classList.contains("open")); });
  backdrop.addEventListener("click", function () { setOpen(false); });
  drawer.querySelector(".sn-close").addEventListener("click", function () { setOpen(false); });
  drawer.addEventListener("click", function (e) {
    var b = e.target.closest(".sn-item"); if (!b) return;
    goTo(b.hasAttribute("data-top") ? null : b.dataset.target);
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") setOpen(false); });
  // the drawer must never stay on top of the tutorial or a dialog
  new MutationObserver(function () { if (window.PWTourActive) setOpen(false); }).observe(document.body, { childList: true });
})();
