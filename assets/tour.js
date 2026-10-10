/* Guided tour of the order screen. It drives the REAL interface (search, price slots, balance) with an animated
   finger; the order confirmation it opens is a demo: while the tour runs nothing can be submitted. */
(function () {
  "use strict";
  var $ = function (id) { return document.getElementById(id); };
  var SEEN = "pw-tour-seen";
  var active = false, layer, spot, finger, ripple, tip, steps = [], idx = 0, target = null, rafId = 0, timers = [], run = 0;
  var saved = { scroll: 0, product: "" };

  function wait(ms) { return new Promise(function (resolve) { timers.push(window.setTimeout(resolve, ms)); }); }
  function clearTimers() { timers.forEach(window.clearTimeout); timers = []; }

  var HAND = '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M20 6a4 4 0 0 1 8 0v17l3-1.2a4 4 0 0 1 5 2.2l.5 1.4 1.9-.6a4 4 0 0 1 4.8 2.6l1.3 4.1c1.8 5.7-.6 11.6-5.8 14.3H27a12 12 0 0 1-9.6-4.8L9.2 31.6a3.6 3.6 0 0 1 5.5-4.6L20 33z" fill="#fff" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/></svg>';

  function build() {
    layer = document.createElement("div"); layer.className = "tour-layer"; layer.setAttribute("role", "dialog"); layer.setAttribute("aria-modal", "true"); layer.setAttribute("aria-label", "Tutorial: kako se poručuje");
    spot = document.createElement("div"); spot.className = "tour-spot none";
    ripple = document.createElement("div"); ripple.className = "tour-ripple";
    finger = document.createElement("div"); finger.className = "tour-finger"; finger.innerHTML = HAND;
    tip = document.createElement("div"); tip.className = "tour-tip";
    tip.innerHTML = '<div class="tour-tip-top"><span class="tour-kicker" id="tourKicker"></span><button class="tour-skip" type="button" aria-label="Zatvori tutorial">✕</button></div>' +
      '<h3 id="tourTitle"></h3><p id="tourText"></p><p class="tour-note" id="tourNote" hidden></p><div class="tour-dots" id="tourDots"></div>' +
      '<div class="tour-actions"><button class="tour-btn" id="tourBack" type="button">Nazad</button><button class="tour-btn primary" id="tourNext" type="button">Dalje</button></div>';
    layer.append(spot, ripple, finger, tip);
    document.body.appendChild(layer);
    tip.querySelector(".tour-skip").addEventListener("click", finish);
    $("tourBack").addEventListener("click", function () { go(idx - 1); });
    $("tourNext").addEventListener("click", function () { if (idx >= steps.length - 1) finish(); else go(idx + 1); });
    document.addEventListener("keydown", onKey);
  }
  function onKey(e) { if (active && e.key === "Escape") finish(); }

  // ---------- helpers that touch the real page ----------
  function click(el) { if (el) el.click(); }
  function resetUi() {
    var trigger = $("productPickerTrigger");
    if (trigger && trigger.getAttribute("aria-expanded") === "true") click(trigger);
    [$("productSearch"), $("priceListSearch")].forEach(function (input) {
      if (input && input.value) { input.value = ""; input.dispatchEvent(new Event("input", { bubbles: true })); }
    });
    var confirmModal = $("confirmModal");
    if (confirmModal && confirmModal.classList.contains("open")) click($("cancelOrderBtn"));
    var tx = $("transactionsModal");
    if (tx && tx.classList.contains("open")) click($("closeTransactionsBtn"));
    document.querySelectorAll('.price-variant[aria-expanded="true"]').forEach(function (cell) {
      cell.setAttribute("aria-expanded", "false");
      var details = document.getElementById(cell.getAttribute("aria-controls"));
      if (details) details.hidden = true;
    });
  }
  function scrollTo(el, offset) {
    if (!el) return Promise.resolve();
    var r = el.getBoundingClientRect();
    var y = window.pageYOffset + r.top - (offset == null ? Math.round(window.innerHeight * 0.18) : offset);
    window.scrollTo({ top: Math.max(0, y), behavior: "smooth" });
    return wait(650);
  }
  function placeSpot(el) {
    if (!el) { spot.classList.add("none"); return; }
    var r = el.getBoundingClientRect();
    if (!r.width && !r.height) { spot.classList.add("none"); return; }
    var pad = 8;
    spot.classList.remove("none");
    spot.style.left = (r.left - pad) + "px"; spot.style.top = (r.top - pad) + "px";
    spot.style.width = (r.width + pad * 2) + "px"; spot.style.height = (r.height + pad * 2) + "px";
    var radius = parseFloat(getComputedStyle(el).borderTopLeftRadius) || 12;
    spot.style.borderRadius = Math.min(radius + 6, 28) + "px";
  }
  function loop() { if (!active) return; placeSpot(target); rafId = window.requestAnimationFrame(loop); }
  function setTarget(el) { target = el; if (!el) placeSpot(null); }

  async function tap(el, doClick) {
    if (!el) return;
    var r = el.getBoundingClientRect();
    var x = r.left + Math.min(r.width * 0.6, r.width - 14), y = r.top + r.height * 0.55;
    finger.classList.add("show");
    finger.style.left = x + "px"; finger.style.top = y + "px";
    await wait(900);
    finger.classList.add("tap"); ripple.style.left = x + "px"; ripple.style.top = y + "px"; ripple.classList.remove("go"); void ripple.offsetWidth; ripple.classList.add("go");
    await wait(220);
    if (doClick) click(el);
    await wait(260);
    finger.classList.remove("tap");
  }
  async function typeInto(input, text, myRun) {
    if (!input) return;
    input.value = "";
    for (var i = 0; i < text.length; i++) {
      if (myRun !== run) return;
      input.value += text.charAt(i);
      input.dispatchEvent(new Event("input", { bubbles: true }));
      await wait(150);
    }
  }
  function sampleWord() {
    try {
      var product = state.products[0];
      var label = product ? String(productLabel(product)) : "";
      var word = label.split(/\s+/).filter(function (w) { return w.replace(/[^A-Za-z0-9]/g, "").length >= 3; })[0] || "";
      word = word.replace(/[^A-Za-z0-9]/g, "").toLowerCase();
      return word.length >= 3 ? word : "god";
    } catch (e) { return "god"; }
  }
  // Keep the highlighted element above the step card (small phones): scroll a little if the card would cover it.
  async function fit(el) {
    if (!el || el.closest(".modal")) return;
    await wait(80);
    var tr = tip.getBoundingClientRect(), r = el.getBoundingClientRect();
    var overflow = r.bottom - (tr.top - 14);
    if (overflow > 0) {
      var shift = Math.min(overflow, Math.max(0, r.top - 12));
      if (shift > 2) { window.scrollBy({ top: shift, behavior: "smooth" }); await wait(550); }
    }
  }
  var firstSlot = function () { return document.querySelector("#pricesList .price-variant"); };

  // ---------- steps ----------
  function buildSteps() {
    steps = [
      { kind: "center", kicker: "Tutorial", title: "Kako se poručuje", text: "Pokazaćemo ti na pravom ekranu pretragu, cene, beleške i balans. Traje oko minut.", note: "Ništa se ne šalje i ne poručuje dok traje tutorial.", primary: "Počni" },
      { kicker: "Korak 1 · Pretraga", title: "Novi, pametniji pretraživač", text: "Razume skraćenice i greške u kucanju: „fc27“, „fc 27“ i „FC27“ nađu istu igru. Dodatno filtriraš po početnom slovu i tipu naloga.",
        run: async function (my) { resetUi(); await scrollTo($("productPickerTrigger")); click($("productPickerTrigger")); await wait(500); if (my !== run) return;
          var box = document.querySelector(".picker-search-wrap") || $("productSearch"); setTarget(box); await tap($("productSearch"), false); await typeInto($("productSearch"), sampleWord(), my); await fit(box); } },
      { kicker: "Korak 2 · Cenovnik", title: "Ista pretraga i u cenovniku", text: "Dok kucaš, cenovnik se odmah sužava na igre koje tražiš.",
        run: async function (my) { resetUi(); setTarget(null); await scrollTo($("priceListSearch")); if (my !== run) return; setTarget($("priceListSearch")); await tap($("priceListSearch"), false); await typeInto($("priceListSearch"), sampleWord(), my); } },
      { kicker: "Korak 3 · Prvi klik", title: "Preporučena minimalna cena", text: "Jedan klik na cenu (slot) pokazuje preporučenu minimalnu prodajnu cenu, ispod koje ne bi trebalo da prodaješ.",
        run: async function (my) { resetUi(); setTarget(null); var cell = firstSlot(); if (!cell) return; await scrollTo(cell); if (my !== run) return; setTarget(cell.parentElement); await tap(cell, true); await fit(cell.parentElement); } },
      { kicker: "Korak 4 · Drugi klik", title: "Potvrda porudžbine", text: "Drugi klik na isti slot otvara potvrdu porudžbine. Proveri proizvod, tip naloga i cenu pa potvrdi.", note: "Ovo je demonstracija: potvrda ovde ne šalje ništa.",
        run: async function (my) { resetUi(); setTarget(null); var cell = firstSlot(); if (!cell) return; await scrollTo(cell); if (my !== run) return; setTarget(cell.parentElement); click(cell); await wait(450);
          await tap(cell, true); await wait(450); if (my !== run) return; finger.classList.remove("show"); setTarget(document.querySelector("#confirmModal .modal")); } },
      { kicker: "Korak 5 · Beleške", title: "Tvoje beleške i plaćanje", text: "U svakoj porudžbini možeš da upišeš internu belešku (ime kupca, telefon, dogovor). Vidiš je samo ti. Označi i da li je porudžbina plaćena, pa filtriraj Plaćeno / Neplaćeno ili pretraži beleške lupom.",
        run: async function (my) { resetUi(); setTarget(null); var item = document.querySelector("#historyList .history-item") || $("historyList"); await scrollTo(item, Math.round(window.innerHeight * 0.12)); if (my !== run) return; setTarget(item);
          var actions = item.querySelector ? item.querySelector(".history-note-toggle") : null; if (actions) await tap(actions, false); await fit(item); } },
      { kicker: "Korak 6 · Balans", title: "Klik na BALANS", text: "Klik na balans otvara spisak svih transakcija do sada: uplate, porudžbine i ispravke, sa opisom i iznosom.",
        run: async function (my) { resetUi(); setTarget(null); await scrollTo($("balanceBtn"), 90); if (my !== run) return; setTarget($("balanceBtn")); await tap($("balanceBtn"), true); await wait(700); if (my !== run) return; finger.classList.remove("show"); setTarget(document.querySelector("#transactionsModal .modal")); } },
      { kind: "center", kicker: "Gotovo", title: "To je to!", text: "Tutorial možeš da pustiš ponovo kad god želiš, dugmetom „Tutorial“ iznad pretrage.", primary: "Zatvori" }
    ];
  }

  async function go(index) {
    if (index < 0 || index >= steps.length) return;
    run++; var my = run; clearTimers();
    idx = index;
    var step = steps[index];
    finger.classList.remove("show", "tap");
    tip.classList.toggle("center", step.kind === "center");
    $("tourKicker").textContent = step.kicker; $("tourTitle").textContent = step.title; $("tourText").textContent = step.text;
    $("tourNote").hidden = !step.note; $("tourNote").textContent = step.note || "";
    var dots = $("tourDots"); dots.textContent = "";
    steps.forEach(function (s, i) { var d = document.createElement("span"); d.className = "tour-dot" + (i === index ? " active" : ""); dots.appendChild(d); });
    $("tourBack").hidden = index === 0;
    tip.querySelector(".tour-actions").classList.toggle("single", index === 0);
    $("tourNext").textContent = step.primary || (index === steps.length - 1 ? "Zatvori" : "Dalje");
    if (step.kind === "center") { resetUi(); setTarget(null); await scrollTo(document.body.querySelector("#appBox") || document.body, 0); return; }
    if (step.run) { try { await step.run(my); } catch (e) {} }
  }

  function finish() {
    if (!active) return;
    active = false; run++; clearTimers(); window.cancelAnimationFrame(rafId);
    resetUi();
    try { if (saved.product && String(productSelect.value) !== String(saved.product)) chooseProduct(saved.product); } catch (e) {}
    try { localStorage.setItem(SEEN, "1"); } catch (e) {}
    document.querySelectorAll(".tour-banner.attention").forEach(function (b) { b.classList.remove("attention"); });
    layer.classList.remove("show");
    window.PWTourActive = false;
    window.setTimeout(function () { if (layer && layer.parentNode) layer.parentNode.removeChild(layer); layer = null; document.removeEventListener("keydown", onKey); window.scrollTo({ top: saved.scroll, behavior: "smooth" }); }, 280);
  }

  function start() {
    if (active || !document.body.classList.contains("is-authenticated")) return;
    if (!state.products.length) { return; }
    active = true; window.PWTourActive = true;
    saved.scroll = window.pageYOffset; try { saved.product = productSelect.value; } catch (e) {}
    build(); buildSteps();
    layer.getBoundingClientRect(); layer.classList.add("show");
    rafId = window.requestAnimationFrame(loop);
    go(0);
  }

  function init() {
    document.querySelectorAll("[data-tour-start]").forEach(function (button) { button.addEventListener("click", start); });
    var seen = false; try { seen = localStorage.getItem(SEEN) === "1"; } catch (e) {}
    if (!seen) document.querySelectorAll(".tour-banner").forEach(function (b) { b.classList.add("attention"); });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
  window.PWTour = { start: start, finish: finish };
})();
