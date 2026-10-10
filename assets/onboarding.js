/* Download card + step-by-step install tutorial. Runs only in a phone/desktop browser, never inside the app. */
(function () {
  "use strict";
  var P = window.PWApp;
  if (!P || P.standalone || P.native) return;

  var IOS_READY = true;      // flip to false to show the iOS button as "Uskoro dostupno"
  var ANDROID_READY = true;
  var FLAG = "pw.tutorial.";
  var $ = function (id) { return document.getElementById(id); };
  var card = $("getApp");
  if (!card) return;

  // ---------- small SVG kit ----------
  var FONT = 'font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif"';
  function phone(inner, bg) {
    return '<svg viewBox="0 0 240 300" role="img" aria-hidden="true">' +
      '<rect x="48" y="6" width="144" height="288" rx="26" fill="#0f172a"/>' +
      '<rect x="54" y="12" width="132" height="276" rx="21" fill="' + (bg || "#f8fafc") + '"/>' +
      '<rect x="105" y="17" width="30" height="6" rx="3" fill="#0f172a"/>' + inner + '</svg>';
  }
  function t(x, y, text, size, fill, weight, anchor) {
    return '<text x="' + x + '" y="' + y + '" ' + FONT + ' font-size="' + (size || 8) + '" fill="' + (fill || "#0f172a") +
      '" font-weight="' + (weight || 600) + '" text-anchor="' + (anchor || "start") + '">' + text + '</text>';
  }
  function bar(x, y, w, color) { return '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="5" rx="2.5" fill="' + (color || "#cbd5e1") + '"/>'; }
  function pulse(x, y, w, h, r) {
    return '<rect class="ob-pulse" x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="' + (r || 8) + '" fill="none" stroke="#3b82f6" stroke-width="3"/>' +
      '<rect class="ob-pulse d2" x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="' + (r || 8) + '" fill="none" stroke="#3b82f6" stroke-width="2"/>';
  }
  function button(x, y, w, h, label, hot) {
    return (hot ? pulse(x, y, w, h, 9) : "") +
      '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="9" fill="' + (hot ? "#2563eb" : "#e2e8f0") + '"/>' +
      t(x + w / 2, y + h / 2 + 3, label, 8, hot ? "#fff" : "#475569", 700, "middle");
  }
  function dialog(x, y, w, h) { return '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="14" fill="#fff" stroke="#e2e8f0"/>'; }
  var shareIcon = function (x, y, color, s) {
    return '<g transform="translate(' + x + ' ' + y + ') scale(' + (s || 1) + ')" fill="none" stroke="' + color + '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M0 -9v13M-5 -4l5 -5 5 5M-8 1v9h16v-9"/></g>';
  };
  function codeBoxes(y) {
    var out = "";
    for (var i = 0; i < 3; i++) out += '<rect x="' + (70 + i * 34) + '" y="' + y + '" width="30" height="24" rx="7" fill="#fff" stroke="#cbd5e1"/>' + t(85 + i * 34, y + 16, "• • •", 8, "#64748b", 700, "middle");
    return out;
  }
  var clipCount = 0;
  function appIcon(x, y, size) {
    var id = "obclip" + (++clipCount);
    return '<clipPath id="' + id + '"><rect x="' + x + '" y="' + y + '" width="' + size + '" height="' + size + '" rx="' + Math.round(size * 0.22) + '"/></clipPath>' +
      '<image href="/assets/apple-touch-icon.png" x="' + x + '" y="' + y + '" width="' + size + '" height="' + size + '" clip-path="url(#' + id + ')"/>';
  }

  var art = {
    chromeWarning: function () {
      return phone(
        t(70, 50, "playworld.rs", 8, "#64748b", 600) + bar(66, 60, 108, "#e2e8f0") + bar(66, 72, 80, "#e2e8f0") +
        dialog(64, 98, 112, 128) +
        '<circle cx="84" cy="122" r="9" fill="#fee2e2"/>' + t(84, 126, "!", 11, "#dc2626", 800, "middle") +
        t(98, 120, "Fajl može da", 8.5, "#0f172a", 700) + t(98, 131, "našteti uređaju", 8.5, "#0f172a", 700) +
        bar(74, 146, 92) + bar(74, 157, 70) +
        t(120, 176, "Otkaži", 8, "#64748b", 700, "middle") +
        button(74, 188, 92, 26, "Preuzmi svejedno", true), "#eef2f7");
    },
    unknownSources: function () {
      return phone(
        t(70, 48, "Instaliraj nepoznate", 8.5, "#0f172a", 700) + t(70, 59, "aplikacije", 8.5, "#0f172a", 700) +
        '<rect x="64" y="78" width="112" height="46" rx="12" fill="#fff" stroke="#e2e8f0"/>' +
        '<circle cx="82" cy="101" r="9" fill="#fde68a"/>' + t(98, 99, "Chrome", 8.5, "#0f172a", 700) + t(98, 110, "Nije dozvoljeno", 7, "#64748b", 600) +
        '<rect x="64" y="136" width="112" height="52" rx="12" fill="#fff" stroke="#e2e8f0"/>' +
        t(74, 156, "Dozvoli iz ovog", 8, "#0f172a", 700) + t(74, 167, "izvora", 8, "#0f172a", 700) +
        pulse(142, 152, 28, 16, 8) + '<rect x="142" y="152" width="28" height="16" rx="8" fill="#2563eb"/><circle cx="162" cy="160" r="6" fill="#fff"/>' +
        bar(74, 178, 70, "#e2e8f0"));
    },
    playProtect: function () {
      return phone(
        bar(66, 48, 108, "#e2e8f0") + bar(66, 60, 70, "#e2e8f0") +
        dialog(62, 92, 116, 150) +
        '<path d="M120 106l16 6v10c0 10-7 16-16 20-9-4-16-10-16-20v-10z" fill="#22c55e"/><path d="M112 124l6 6 11-12" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>' +
        t(120, 156, "Aplikacija blokirana", 8.5, "#0f172a", 700, "middle") + t(120, 167, "App blocked by Play Protect", 6.5, "#64748b", 600, "middle") +
        bar(72, 178, 96) + bar(72, 189, 74) +
        pulse(70, 198, 46, 16, 6) + t(93, 210, "Learn more", 8, "#2563eb", 800, "middle") +
        button(124, 198, 44, 24, "OK", false));
    },
    installAnyway: function () {
      return phone(
        bar(66, 48, 108, "#e2e8f0") +
        dialog(62, 78, 116, 180) +
        '<path d="M120 92l14 5v9c0 9-6 14-14 17-8-3-14-8-14-17v-9z" fill="#f59e0b"/>' + t(120, 116, "!", 12, "#fff", 800, "middle") +
        t(120, 142, "Nepoznat izdavač", 8.5, "#0f172a", 700, "middle") +
        bar(72, 152, 96) + bar(72, 163, 80) + bar(72, 174, 60) +
        t(120, 204, "Got it", 8, "#64748b", 700, "middle") +
        button(72, 214, 96, 28, "Install anyway", true));
    },
    openApp: function () {
      return phone(
        '<g class="ob-bob">' + appIcon(96, 62, 48) + '</g>' +
        t(120, 130, "PlayWorld Reseller", 9.5, "#0f172a", 800, "middle") +
        t(120, 146, "Aktivacija uređaja", 7.5, "#64748b", 600, "middle") +
        codeBoxes(168) +
        button(70, 206, 100, 26, "Aktiviraj uređaj", true));
    },
    safariShare: function () {
      var x = 120, y = 258;
      return phone(
        '<rect x="62" y="34" width="116" height="20" rx="10" fill="#e2e8f0"/>' + t(120, 47, "reseller.psigre.rs", 7.5, "#334155", 600, "middle") +
        bar(66, 70, 108, "#e2e8f0") + bar(66, 82, 84, "#e2e8f0") + '<rect x="66" y="98" width="108" height="64" rx="10" fill="#e2e8f0"/>' +
        '<rect x="54" y="240" width="132" height="48" rx="0" fill="#fff"/><rect x="54" y="240" width="132" height="1" fill="#e2e8f0"/>' +
        '<path d="M72 258l-6 0M66 258l5-5M66 258l5 5" stroke="#94a3b8" stroke-width="1.6" fill="none" stroke-linecap="round"/>' +
        pulse(x - 15, y - 14, 30, 28, 14) + shareIcon(x, y, "#2563eb", 1.15) +
        '<rect x="160" y="252" width="12" height="12" rx="3" fill="none" stroke="#94a3b8" stroke-width="1.6"/>');
    },
    shareSheet: function () {
      var rows = [["Kopiraj", 0], ["Dodaj u omiljene", 0], ["Dodaj na početni ekran", 1], ["Štampaj", 0]];
      var out = '<rect x="54" y="12" width="132" height="276" rx="21" fill="#0f172a" opacity=".35"/>' +
        '<rect x="54" y="82" width="132" height="206" rx="21" fill="#f1f5f9"/>' + t(120, 106, "PlayWorld Reseller", 8.5, "#0f172a", 700, "middle");
      rows.forEach(function (row, i) {
        var y = 122 + i * 38;
        out += (row[1] ? pulse(62, y, 116, 32, 10) : "") + '<rect x="62" y="' + y + '" width="116" height="32" rx="10" fill="' + (row[1] ? "#dbeafe" : "#fff") + '"/>' +
          t(72, y + 20, row[0], row[1] ? 6.8 : 8, row[1] ? "#1d4ed8" : "#0f172a", row[1] ? 800 : 600) +
          '<rect x="154" y="' + (y + 10) + '" width="12" height="12" rx="3" fill="none" stroke="' + (row[1] ? "#1d4ed8" : "#94a3b8") + '" stroke-width="1.6"/>' +
          (row[1] ? '<path d="M160 ' + (y + 12) + 'v8M156 ' + (y + 16) + 'h8" stroke="#1d4ed8" stroke-width="1.6" stroke-linecap="round"/>' : "");
      });
      return phone(out);
    },
    addDialog: function () {
      return phone(
        '<rect x="54" y="12" width="132" height="276" rx="21" fill="#f1f5f9"/>' +
        t(66, 40, "Otkaži", 8.5, "#2563eb", 600) +
        pulse(150, 27, 30, 20, 8) + t(165, 41, "Dodaj", 8.5, "#2563eb", 800, "middle") +
        t(120, 66, "Dodaj na početni ekran", 8.5, "#0f172a", 700, "middle") +
        '<rect x="62" y="80" width="116" height="74" rx="14" fill="#fff"/>' + appIcon(70, 90, 34) +
        t(112, 104, "PlayWorld", 9, "#0f172a", 700) + t(112, 117, "reseller.psigre.rs", 6.5, "#64748b", 600) + bar(70, 134, 100, "#e2e8f0"));
    },
    homeScreen: function () {
      var icons = "";
      for (var r = 0; r < 4; r++) for (var c = 0; c < 3; c++) {
        var x = 66 + c * 40, y = 44 + r * 54;
        if (r === 1 && c === 1) continue;
        icons += '<rect x="' + x + '" y="' + y + '" width="30" height="30" rx="8" fill="#fff" opacity=".35"/>';
      }
      return phone('<rect x="54" y="12" width="132" height="276" rx="21" fill="url(#wp)"/><defs><linearGradient id="wp" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#06b6d4"/></linearGradient></defs>' + icons +
        pulse(102, 94, 36, 36, 10) + appIcon(104, 96, 32) + t(120, 142, "PlayWorld", 7, "#fff", 700, "middle"));
    }
  };

  var STEPS = {
    android: [
      { title: "Preuzmite aplikaciju", text: "Dodirnite „Preuzmi za Android“. Ako Chrome upozori da fajl može da našteti uređaju, dodirnite „Preuzmi svejedno“ (Download anyway / OK).", art: art.chromeWarning },
      { title: "Dozvolite instalaciju", text: "Otvorite preuzeti fajl PlayWorld-Reseller.apk. Ako Android traži dozvolu, uključite „Dozvoli iz ovog izvora“ i vratite se nazad.", art: art.unknownSources },
      { title: "Play Protect upozorenje", text: "Ako Play Protect prikaže „Aplikacija blokirana“, dodirnite „Learn more“ (Saznajte više).", art: art.playProtect },
      { title: "Ipak instaliraj", text: "Zatim dodirnite „Install anyway“ (Ipak instaliraj). Upozorenje se javlja samo zato što aplikacija nije iz Play Store-a.", art: art.installAnyway },
      { title: "Otvorite i aktivirajte", text: "Otvorite PlayWorld Reseller i unesite jednokratni kod koji ste dobili od nas. Kod se unosi samo jednom.", art: art.openApp }
    ],
    ios: [
      { title: "Dodirnite Podeli", text: "Otvorite sajt u Safari-ju i dodirnite dugme Podeli (kvadrat sa strelicom) u donjoj traci.", art: art.safariShare },
      { title: "Dodaj na početni ekran", text: "Listajte meni naniže i izaberite „Dodaj na početni ekran“ (Add to Home Screen).", art: art.shareSheet },
      { title: "Potvrdite", text: "Dodirnite „Dodaj“ (Add) u gornjem desnom uglu.", art: art.addDialog },
      { title: "Pronađite ikonicu", text: "PlayWorld ikonica je sada na početnom ekranu. Otvarajte je odatle — radi preko celog ekrana, kao prava aplikacija.", art: art.homeScreen },
      { title: "Unesite kod", text: "Pri prvom otvaranju unesite jednokratni kod koji ste dobili od nas. Posle toga ostajete prijavljeni.", art: art.openApp }
    ]
  };

  // ---------- tutorial sheet ----------
  var overlay, slides, dots, copyTitle, copyText, backBtn, nextBtn, current = 0, steps = [], platform = "";
  function build() {
    overlay = document.createElement("div");
    overlay.className = "ob-backdrop";
    overlay.setAttribute("role", "dialog"); overlay.setAttribute("aria-modal", "true"); overlay.setAttribute("aria-label", "Uputstvo za instalaciju");
    overlay.innerHTML =
      '<div class="ob-sheet"><div class="ob-grab"></div>' +
      '<div class="ob-top"><span class="ob-kicker" id="obKicker"></span><button class="ob-close" type="button" aria-label="Zatvori">✕</button></div>' +
      '<div class="ob-stage" id="obStage"></div>' +
      '<div class="ob-copy"><h3 id="obTitle"></h3><p id="obText"></p></div>' +
      '<div class="ob-dots" id="obDots"></div>' +
      '<div class="ob-actions"><button class="ob-btn" id="obBack" type="button">Nazad</button><button class="ob-btn primary" id="obNext" type="button">Dalje</button></div></div>';
    document.body.appendChild(overlay);
    copyTitle = overlay.querySelector("#obTitle"); copyText = overlay.querySelector("#obText");
    backBtn = overlay.querySelector("#obBack"); nextBtn = overlay.querySelector("#obNext");
    overlay.querySelector(".ob-close").addEventListener("click", close);
    overlay.addEventListener("click", function (e) { if (e.target === overlay) close(); });
    backBtn.addEventListener("click", function () { go(current - 1); });
    nextBtn.addEventListener("click", function () { if (current >= steps.length - 1) close(); else go(current + 1); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && overlay.classList.contains("open")) close(); });
    // swipe between steps
    var x0 = null;
    overlay.addEventListener("touchstart", function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    overlay.addEventListener("touchend", function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 60) go(current + (dx < 0 ? 1 : -1));
    }, { passive: true });
  }

  function go(index) {
    if (index < 0 || index >= steps.length) return;
    current = index;
    Array.prototype.forEach.call(slides, function (slide, i) {
      slide.classList.toggle("active", i === index);
      slide.classList.toggle("past", i < index);
    });
    Array.prototype.forEach.call(dots, function (dot, i) { dot.classList.toggle("active", i === index); });
    copyTitle.textContent = steps[index].title;
    copyText.textContent = steps[index].text;
    backBtn.hidden = index === 0;
    overlay.querySelector(".ob-actions").classList.toggle("single", index === 0);
    nextBtn.textContent = index === steps.length - 1 ? "Gotovo" : "Dalje";
  }

  // Safari ignores user-scalable=no, so pinch zoom is blocked with gesture events and double-tap zoom with touch-action.
  var viewportMeta = document.querySelector('meta[name="viewport"]');
  var viewportBackup = viewportMeta ? viewportMeta.getAttribute("content") : "";
  function blockGesture(e) { e.preventDefault(); }
  function zoomLock(on) {
    ["gesturestart", "gesturechange", "gestureend"].forEach(function (name) {
      if (on) document.addEventListener(name, blockGesture, { passive: false });
      else document.removeEventListener(name, blockGesture, { passive: false });
    });
    if (viewportMeta) viewportMeta.setAttribute("content", on ? viewportBackup.replace(/,?\s*(maximum-scale|user-scalable)=[^,]*/g, "") + ",maximum-scale=1,user-scalable=no" : viewportBackup);
  }

  function open(which) {
    if (!overlay) build();
    zoomLock(true);
    platform = which; steps = STEPS[which];
    overlay.querySelector("#obKicker").textContent = (which === "ios" ? "iPhone · korak po korak" : "Android · korak po korak");
    var stage = overlay.querySelector("#obStage"), dotWrap = overlay.querySelector("#obDots");
    stage.innerHTML = ""; dotWrap.innerHTML = "";
    steps.forEach(function (step) {
      var slide = document.createElement("div"); slide.className = "ob-slide"; slide.innerHTML = step.art(); stage.appendChild(slide);
      var dot = document.createElement("span"); dot.className = "ob-dot"; dotWrap.appendChild(dot);
    });
    slides = stage.children; dots = dotWrap.children;
    go(0);
    overlay.getBoundingClientRect();
    overlay.classList.add("open");
    clearPending(which);
  }
  function close() { if (overlay) overlay.classList.remove("open"); zoomLock(false); }

  // ---------- "show the tutorial next time the site opens, but only after the icon was tapped" ----------
  function setPending(which) { try { localStorage.setItem(FLAG + which, "1"); } catch (e) {} }
  function clearPending(which) { try { localStorage.removeItem(FLAG + which); } catch (e) {} }
  function isPending(which) { try { return localStorage.getItem(FLAG + which) === "1"; } catch (e) { return false; } }
  function mine() { return P.isIOS ? "ios" : (P.isAndroid ? "android" : ""); }
  function maybeShow() {
    var which = mine();
    if (which && isPending(which) && document.visibilityState !== "hidden") open(which);
  }

  // ---------- download card wiring ----------
  var androidBtn = $("getAndroidBtn"), iosBtn = $("getIosBtn"), help = $("getAppHelp"), note = $("getAppNote");
  function say(message) { if (note) note.textContent = message || ""; }

  if (!ANDROID_READY) { androidBtn.classList.add("soon"); androidBtn.removeAttribute("href"); }
  androidBtn.addEventListener("click", function (e) {
    if (!ANDROID_READY) { e.preventDefault(); return; }
    if (P.isIOS) { e.preventDefault(); say("APK je za Android telefone. Na iPhone-u izaberite „iPhone“."); return; }
    say("");
    if (P.isAndroid) setPending("android");   // the tutorial appears the next time the site is opened
  });

  if (!IOS_READY) {
    iosBtn.classList.add("soon");
    var badge = document.createElement("em"); badge.className = "soon-badge"; badge.textContent = "Uskoro dostupno"; iosBtn.appendChild(badge);
  }
  iosBtn.addEventListener("click", function () {
    if (!IOS_READY) { say("iOS aplikacija je uskoro dostupna."); return; }
    if (!P.isIOS) { say("Otvorite ovaj sajt na iPhone-u u Safari-ju da dodate aplikaciju."); return; }
    say("");
    open("ios");    // nothing to download on iPhone, so the guide opens right away
  });

  if (help && P.isMobile) {
    help.hidden = false;
    help.addEventListener("click", function () { open(mine()); });
  }

  var menuBtn = $("appMenuBtn");
  if (menuBtn) {
    var setOpen = function (on) { card.classList.toggle("open", on); menuBtn.setAttribute("aria-expanded", on ? "true" : "false"); };
    menuBtn.addEventListener("click", function (e) { e.stopPropagation(); setOpen(!card.classList.contains("open")); });
    document.addEventListener("click", function (e) { if (card.classList.contains("open") && !card.contains(e.target)) setOpen(false); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") setOpen(false); });
    window.addEventListener("scroll", function () { if (card.classList.contains("open")) setOpen(false); }, { passive: true });
  }

  window.addEventListener("pageshow", maybeShow);
  document.addEventListener("visibilitychange", function () { if (document.visibilityState === "visible") maybeShow(); });
  window.PWOnboarding = { open: open };
  maybeShow();
})();
