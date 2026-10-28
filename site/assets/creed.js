/* CREED front page. Small vanilla JS, no build step.

   1. copy buttons        4. the policy reader (Policy::stateOf, in the browser)
   2. scroll reveals      5. Form CR-1: the inspection stamp
   3. thumb index         6. Table 2-1, re-read for today      7. OS tabs

   Everything here is a nicety. With the script off, every clause, stamp,
   table row and command is still printed, and the certificate shows PHP 8.2
   on 2026-10-28. Creed Bratton would call that an alibi.
*/
(function () {
  "use strict";

  var root = document.documentElement;
  root.classList.add("js");
  var motion = root.classList.contains("motion");
  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

  /* ------------------------------------------------------- 1. copy buttons */
  var toast = $("[data-toast]");
  var toastTimer;
  function say(text) {
    if (!toast) return;
    toast.textContent = text;
    toast.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement("textarea");
      ta.value = text;
      ta.setAttribute("readonly", "");
      ta.style.position = "fixed";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand("copy") ? resolve() : reject(); } catch (e) { reject(e); }
      document.body.removeChild(ta);
    });
  }
  $$("[data-copy]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      copyText(btn.getAttribute("data-copy")).then(function () {
        btn.classList.add("is-copied");
        btn.textContent = "Copied";
        say("Copied. Entered into the record.");
        setTimeout(function () { btn.classList.remove("is-copied"); btn.textContent = "Copy"; }, 1800);
      }, function () { say("Copy didn't take. Select the command and copy it by hand."); });
    });
  });

  /* ----------------------------------------------------- 2. scroll reveals */
  var reveals = $$("[data-reveal]");
  if (motion && "IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add("is-in"); io.unobserve(e.target); }
      });
    }, { rootMargin: "0px 0px -6% 0px", threshold: 0.06 });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add("is-in"); });
  }

  /* ------------------------------------------------------- 3. thumb index */
  var thumbs = $$("[data-thumb]");
  if (thumbs.length && "IntersectionObserver" in window) {
    var byId = {};
    thumbs.forEach(function (t) { byId[t.getAttribute("data-thumb")] = t; });
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        thumbs.forEach(function (t) { t.classList.remove("is-here"); t.removeAttribute("aria-current"); });
        var t = byId[e.target.id];
        if (t) { t.classList.add("is-here"); t.setAttribute("aria-current", "true"); }
      });
    }, { rootMargin: "-45% 0px -50% 0px" });
    Object.keys(byId).forEach(function (id) { var s = document.getElementById(id); if (s) spy.observe(s); });
  }

  /* ------------------------------------ 4. the policy reader, in the browser */
  /* A copy of the dates in policy/policy.json (2026-10-28), for this page
     only. The policy file is the source; real tools read it through
     Waynestate\Creed\Policy, never through a copy like this one. We know.
     [active_until, security_until], or "current" for a line the policy
     marks "current": true. The rules mirror src/Policy.php. */
  var POLICY = {
    eol_grace_days: 90,
    components: {
      php: { "8.1": [null, "2025-12-31"], "8.2": ["2024-12-31", "2026-12-31"], "8.3": ["2025-12-31", "2027-12-31"], "8.4": ["2026-12-31", "2028-12-31"], "8.5": ["2027-12-31", "2029-12-31"] },
      laravel: { "10": ["2024-08-06", "2025-02-04"], "11": ["2025-09-03", "2026-03-12"], "12": ["2026-08-13", "2027-02-24"], "13": ["2027-09-17", "2028-03-17"] },
      node: { "20": ["2024-10-22", "2026-04-30"], "22": ["2025-10-21", "2027-04-30"], "24": ["2026-10-20", "2028-04-30"], "26": ["2027-10-20", "2029-04-30"] },
      mysql: { "5.6": ["2018-02-28", "2021-02-28"], "5.7": ["2020-10-31", "2023-10-31"], "8.0": ["2025-04-30", "2026-04-30"], "8.4": ["2029-04-30", "2032-04-30"], "9.7": ["2031-04-30", "2034-04-30"] },
      vue: { "2": ["2023-12-31", "2023-12-31"], "3": "current" },
      inertia: { "2": "current" }
    }
  };
  var NAMES = { php: "PHP", laravel: "Laravel", node: "Node", mysql: "MySQL", vue: "Vue", inertia: "Inertia" };
  var ENUM = { active: "State::Active", security: "State::Security", eol: "State::Eol", eol_grace_expired: "State::EolGraceExpired" };

  function addDays(iso, n) {
    var d = new Date(iso + "T00:00:00Z");
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }
  function daysBetween(a, b) { return Math.round((Date.parse(b + "T00:00:00Z") - Date.parse(a + "T00:00:00Z")) / 864e5); }
  function cmpVersion(a, b) {
    var x = a.split("."), y = b.split(".");
    for (var i = 0; i < Math.max(x.length, y.length); i++) {
      var d = (+x[i] || 0) - (+y[i] || 0);
      if (d) return d;
    }
    return 0;
  }
  function versionsOf(c) { return Object.keys(POLICY.components[c] || {}).sort(cmpVersion); }
  function graceEnd(c, v) { return addDays(POLICY.components[c][v][1], POLICY.eol_grace_days); }

  // The policy line a version belongs to, or null. Mirrors Policy::resolveVersion.
  function resolveVersion(component, version) {
    var best = null;
    Object.keys(POLICY.components[component] || {}).forEach(function (l) {
      if (version !== l && version.indexOf(l + ".") !== 0) return;
      if (best === null || l.length > best.length) best = l;
    });
    return best;
  }
  // Whether a version sorts above every listed line. Mirrors Policy::isAheadOfPolicy.
  function isAheadOfPolicy(component, version) {
    var versions = POLICY.components[component];
    if (!versions) throw new Error("Unknown component: " + component);
    if (resolveVersion(component, version) !== null) return false;
    return Object.keys(versions).every(function (l) {
      var prefix = version.split(".").slice(0, l.split(".").length).join(".");
      return cmpVersion(prefix, l) > 0;
    });
  }

  // Same order and same refusals as Policy::stateOf. Current versions are
  // never refused: a current line, or a version newer than every line, is Active.
  function stateOf(component, version, day) {
    var versions = POLICY.components[component];
    if (!versions) throw new Error("Unknown component: " + component);
    var line = resolveVersion(component, version);
    if (line === null) {
      if (isAheadOfPolicy(component, version)) return "active";
      throw new Error("Unknown version of " + component + ": " + version);
    }
    if (versions[line] === "current") return "active";
    var activeUntil = versions[line][0], securityUntil = versions[line][1];
    if (!securityUntil) throw new Error("Version " + version + " of " + component + " has no security_until, and this reader does not guess.");
    if (activeUntil !== null && day <= activeUntil) return "active";
    if (day <= securityUntil) return "security";
    if (day <= addDays(securityUntil, POLICY.eol_grace_days)) return "eol";
    return "eol_grace_expired";
  }
  function oldestSupported(component, day) {
    var ok = versionsOf(component).filter(function (v) {
      var s = stateOf(component, v, day);
      return s === "active" || s === "security";
    });
    return ok.length ? ok[0] : null;
  }
  function todayIso() {
    var d = new Date();
    return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2);
  }
  function plural(n, word) { return n + " " + word + (n === 1 ? "" : "s"); }

  /* ------------------------------------------- 5. Form CR-1: the stamp */
  var form = $("[data-inspect-form]");
  if (form) {
    var verSel = $("[data-inspect-version]");
    var dateIn = $("[data-inspect-date]");
    var chips = $$("[data-day]");
    var cert = $("[data-certificate]");
    var stamp = $("[data-inkstamp]");
    var live = $("[data-inspect-live]");
    var out = {
      what: $("[data-cert-what]"), day: $("[data-cert-day]"), call: $("[data-cert-call]"),
      returns: $("[data-cert-returns]"), finding: $("[data-cert-finding]"), clause: $("[data-cert-clause]"),
      serial: $("[data-cert-serial]"), top: $("[data-ink-top]"), word: $("[data-ink-word]"), sub: $("[data-ink-sub]")
    };
    var serial = 1;
    var DEFAULTS = { php: "8.2", laravel: "12", node: "24", mysql: "8.0", vue: "3", inertia: "2" };
    // Versions people will ask about that the policy does not list. One older
    // than every line, which the reader refuses, and some newer than every
    // line, which it calls Active: current versions are never refused.
    var UNLISTED = { php: [["8.0", "not in the policy"]], vue: [["4", "newer than the policy"]], inertia: [["3", "newer than the policy"]] };

    function component() { return $("input[name=component]:checked", form).value; }

    function fillVersions(c) {
      var listed = versionsOf(c);
      var html = "";
      listed.forEach(function (v) { html += "<option value=\"" + v + "\">" + v + "</option>"; });
      (UNLISTED[c] || []).forEach(function (u) { html += "<option value=\"" + u[0] + "\">" + u[0] + " (" + u[1] + ")</option>"; });
      verSel.innerHTML = html;
      verSel.value = DEFAULTS[c];
    }

    function clauseFor(c, v, state) {
      if (state === "refused" || resolveVersion(c, v) === null) return "c-1-4";
      if (c === "inertia") return "c-1-9";
      if (c === "vue") return "c-1-9";
      if (state === "eol") return "c-5-1";
      if (state === "eol_grace_expired") return "c-1-5";
      return { php: state === "security" ? "c-2-2" : "c-2-1", laravel: "c-2-5", node: "c-2-6", mysql: "c-2-7" }[c];
    }

    function findingFor(c, v, day, state, err) {
      var name = NAMES[c];
      if (state === "refused") {
        return name + " " + v + " is not in the policy, and it is not newer than every line the policy lists. The reader refuses to guess, and so does the inspector.";
      }
      var line = resolveVersion(c, v);
      if (line === null) {
        return name + " " + v + " is newer than every line the policy lists, so it is Active. Current versions are never refused. Passes.";
      }
      var e = POLICY.components[c][line];
      if (e === "current") {
        return "A current line: Active on every date. " + name + " publishes no end date, and the policy does not invent one. Passes.";
      }
      var later = versionsOf(c).filter(function (x) { return cmpVersion(x, v) > 0 && stateOf(c, x, day) === "active"; });
      var floor = oldestSupported(c, day);
      if (state === "active") {
        return "Active support until " + e[0] + ", then security fixes until " + e[1] + ". Passes.";
      }
      if (state === "security") {
        var left = daysBetween(day, e[1]);
        if (left === 0) return "The last day of security fixes. Still Security today, with arms wide open; EOL tomorrow.";
        return "Security fixes only, until " + e[1] + ": " + plural(left, "day") + " left." + (later.length ? " Plan the move to " + name + " " + later[0] + " now." : " Plan the move now.");
      }
      if (state === "eol") {
        var g = graceEnd(c, v);
        return "End of life since " + addDays(e[1], 1) + ". In the 90-day grace until " + g + " (" + plural(daysBetween(day, g), "day") + " left); a named, dated override can carry an app that far.";
      }
      return "Past grace since " + addDays(graceEnd(c, v), 1) + ". No override applies." + (floor ? " The oldest " + name + " that passes on this day is " + floor + "." : "");
    }

    var WORDS = { active: "Supported", security: "Security only", eol: "EOL", eol_grace_expired: "EOL", refused: "Refused" };

    function ghost() {
      if (!stamp) return;
      var g = stamp.cloneNode(true);
      g.classList.remove("is-thunk");
      g.classList.add("inkstamp--ghost");
      g.removeAttribute("data-inkstamp");
      $$("[data-ink-top],[data-ink-word],[data-ink-sub]", g).forEach(function (n) { n.removeAttribute("data-ink-top"); n.removeAttribute("data-ink-word"); n.removeAttribute("data-ink-sub"); });
      var dx = Math.round(Math.random() * 60 - 50), dy = Math.round(Math.random() * 40 - 30);
      g.style.translate = dx + "px " + dy + "px";
      cert.insertBefore(g, stamp);
      var ghosts = $$(".inkstamp--ghost", cert);
      if (ghosts.length > 3) ghosts[0].remove();
    }

    function inspect(thunk) {
      var c = component(), v = verSel.value, day = dateIn.value;
      chips.forEach(function (ch) {
        var d = ch.getAttribute("data-day") === "today" ? todayIso() : ch.getAttribute("data-day");
        ch.setAttribute("aria-pressed", d === day ? "true" : "false");
      });
      if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
        live.textContent = "Enter a day as year, month, day. The inspector does not guess.";
        return;
      }
      var state, returns;
      try {
        state = stateOf(c, v, day);
        returns = ENUM[state];
      } catch (err) {
        state = "refused";
        returns = "InvalidArgumentException: " + err.message;
      }
      var clauseId = clauseFor(c, v, state);
      var clauseEl = document.getElementById(clauseId);
      var title = clauseEl ? $(".clause__title", clauseEl).textContent.trim().replace(/\.$/, "") : "";
      var sec = title.split(" ")[0];
      var finding = findingFor(c, v, day, state);

      if (thunk) ghost();
      serial += thunk ? 1 : 0;
      out.serial.textContent = "No. " + ("000" + serial).slice(-4);
      out.what.textContent = NAMES[c] + " " + v;
      out.day.textContent = day;
      out.call.textContent = "$policy->stateOf('" + c + "', '" + v + "', new DateTimeImmutable('" + day + "'))";
      out.returns.textContent = returns;
      out.finding.textContent = finding;
      out.clause.textContent = title;
      out.clause.setAttribute("href", "#" + clauseId);

      stamp.className = "inkstamp inkstamp--" + state;
      out.top.textContent = "CREED " + sec + " · " + day;
      out.word.textContent = WORDS[state];
      out.sub.textContent = state === "refused" ? "Will not guess"
        : state === "eol" ? NAMES[c] + " " + v + " · in grace"
        : state === "eol_grace_expired" ? NAMES[c] + " " + v + " · grace expired"
        : NAMES[c] + " " + v;

      $$(".clause.is-cited").forEach(function (el) { el.classList.remove("is-cited"); });
      if (clauseEl) clauseEl.classList.add("is-cited");
      $$("tr.is-inspected").forEach(function (el) { el.classList.remove("is-inspected"); });
      var row = $("tr[data-c=\"" + c + "\"][data-v=\"" + v + "\"]");
      if (row) row.classList.add("is-inspected");

      if (thunk) {
        live.textContent = "Stamped " + WORDS[state].toUpperCase() + (state === "eol_grace_expired" ? ", grace expired" : "") + ". " + NAMES[c] + " " + v + " on " + day + ". " + finding + " Applicable clause: " + title + ".";
        if (motion) {
          stamp.classList.remove("is-thunk"); cert.classList.remove("is-jolt");
          void stamp.offsetWidth;
          stamp.classList.add("is-thunk"); cert.classList.add("is-jolt");
        }
      }
    }

    form.addEventListener("submit", function (e) { e.preventDefault(); inspect(true); });
    $$("input[name=component]", form).forEach(function (r) {
      r.addEventListener("change", function () { fillVersions(component()); inspect(true); });
    });
    verSel.addEventListener("change", function () { inspect(true); });
    dateIn.addEventListener("change", function () { inspect(true); });
    chips.forEach(function (ch) {
      ch.addEventListener("click", function () {
        var d = ch.getAttribute("data-day");
        dateIn.value = d === "today" ? todayIso() : d;
        inspect(true);
      });
    });

    fillVersions("php");
    dateIn.value = todayIso();
    inspect(false);

    // The first time the form comes into view, the inspector brings the
    // stamp down on the certificate that is already filled in.
    if (motion && "IntersectionObserver" in window) {
      var once = new IntersectionObserver(function (entries) {
        if (!entries[0].isIntersecting) return;
        once.disconnect();
        setTimeout(function () {
          stamp.classList.add("is-thunk"); cert.classList.add("is-jolt");
        }, 250);
      }, { threshold: 0.55 });
      once.observe(cert);
    }
  }

  /* ------------------------------------------ 6. Table 2-1, read for today */
  var adopt = $("[data-adopt]");
  if (adopt) {
    var today = todayIso();
    var LABEL = { active: "Active", security: "Security", eol: "EOL, in grace", eol_grace_expired: "Grace expired" };
    $$("tr[data-c]", adopt).forEach(function (tr) {
      var s = stateOf(tr.getAttribute("data-c"), tr.getAttribute("data-v"), today);
      var cell = tr.querySelector(".st");
      cell.className = "st st--" + s;
      cell.textContent = LABEL[s];
    });
    $$("[data-floor]", adopt).forEach(function (b) { b.textContent = oldestSupported(b.getAttribute("data-floor"), today) || "none"; });
    $$("[data-adopt-day]").forEach(function (n) { n.textContent = today; });
  }

  /* ------------------------------------------------------------ 7. OS tabs */
  var os = $("[data-os]");
  if (os) {
    var osTabs = $$("[role=tab]", os);
    var pick = function (tab, focus) {
      osTabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute("aria-controls")).hidden = !on;
      });
      if (focus) tab.focus();
    };
    osTabs.forEach(function (t, i) {
      t.addEventListener("click", function () { pick(t); });
      t.addEventListener("keydown", function (e) {
        var n = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
        if (e.key === "Home") n = -i;
        if (e.key === "End") n = osTabs.length - 1 - i;
        if (n === undefined) return;
        e.preventDefault();
        pick(osTabs[(i + n + osTabs.length) % osTabs.length], true);
      });
    });
    var plat = ((navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || "").toLowerCase();
    if (plat.indexOf("mac") >= 0) pick(osTabs[1]);
    else if (plat.indexOf("linux") >= 0 || plat.indexOf("x11") >= 0) pick(osTabs[2]);
  }
})();
