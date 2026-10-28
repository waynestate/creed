/* CREED front page. Small vanilla JS, no build step.

   1. copy buttons       4. the reader (Policy::stateOf, in the browser)
   2. scroll reveals     5. OS tabs
   3. the deck: drop the needle and the tracklist plays

   Everything here is a nicety: with the script off, every word on the page is
   still printed, the tracklist is still readable, and the reader table shows
   the states as of 2026-10-28. Creed Bratton would call that a cover story.
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
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
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
        say("Copied. Pressed to your clipboard at 33⅓.");
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
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add("is-in"); });
  }

  /* --------------------------------------------------------- 3. the deck */
  var deck = $("[data-deck]");
  var tracks = $$("[data-track]");
  if (deck && tracks.length) {
    var arm = $(".tonearm__arm", deck);
    var disc = $(".disc--deck", deck);
    var sideText = $("[data-side-text]", deck);
    var playBtn = $("[data-play]", deck);
    var playLabel = $("[data-play-label]", deck);
    var npTitle = $("[data-np-title]", deck);
    var npWhy = $("[data-np-why]", deck);
    var npBar = $("[data-np-bar]", deck);
    var npTime = $("[data-np-time]", deck);
    var npLive = $("[data-np-live]", deck);

    var STEP = 2600;            // ms per track: 78 rpm, roughly
    var REST = 0;               // arm angle off the record
    var OUTER = 19, INNER = 36; // arm angles at the first and last groove
    var sideOf = function (i) { return tracks[i].querySelector(".track__no").textContent.charAt(0); };
    var sides = { A: [], B: [] };
    tracks.forEach(function (t, i) { sides[sideOf(i)].push(i); });

    var state = { playing: false, index: -1, side: "A", start: 0, raf: 0, timer: 0 };

    function secondsOf(i) {
      var p = (tracks[i].getAttribute("data-time") || "0:00").split(":");
      return (+p[0]) * 60 + (+p[1]);
    }
    function fmt(s) { s = Math.floor(s); return Math.floor(s / 60) + ":" + ("0" + (s % 60)).slice(-2); }
    function angleFor(i) {
      var list = sides[sideOf(i)];
      var j = list.indexOf(i);
      return OUTER + (list.length > 1 ? j / (list.length - 1) : 0) * (INNER - OUTER);
    }
    function setArm(deg) { if (arm) arm.style.setProperty("--arm", deg + "deg"); }
    function nameOf(i) { return tracks[i].querySelector(".track__no").textContent + " · " + tracks[i].querySelector(".track__name").textContent; }
    function whyOf(i) {
      var notes = document.getElementById(tracks[i].getAttribute("aria-controls"));
      var why = notes && notes.querySelector(".why");
      return why ? why.textContent : "";
    }

    function expand(i) {
      tracks.forEach(function (t, k) {
        var open = k === i;
        t.setAttribute("aria-expanded", open ? "true" : "false");
        t.classList.toggle("is-current", open && state.playing);
        var notes = document.getElementById(t.getAttribute("aria-controls"));
        if (notes) notes.hidden = !open;
      });
    }

    // Flip the record between sides: edge-on, swap the label, back round.
    function flipTo(side) {
      state.side = side;
      var text = side === "A" ? "SIDE A · THE POLICY · CREED · WSU-001 · 33⅓ ·" : "SIDE B · YOUR APP · CREED · WSU-001 · 33⅓ ·";
      if (!motion || !disc.animate) { sideText.textContent = text; return Promise.resolve(); }
      return disc.animate([{ transform: "rotateY(0deg)" }, { transform: "rotateY(90deg)" }], { duration: 320, easing: "ease-in" }).finished
        .then(function () {
          sideText.textContent = text;
          return disc.animate([{ transform: "rotateY(-90deg)" }, { transform: "rotateY(0deg)" }], { duration: 380, easing: "ease-out" }).finished;
        });
    }

    function tick() {
      if (!state.playing) return;
      var p = Math.min(1, (performance.now() - state.start) / STEP);
      npBar.style.width = (p * 100) + "%";
      npTime.textContent = fmt(p * secondsOf(state.index)) + " / " + tracks[state.index].getAttribute("data-time");
      state.raf = requestAnimationFrame(tick);
    }

    function playTrack(i) {
      clearTimeout(state.timer);
      var go = function () {
        if (!state.playing) return;
        state.index = i;
        setArm(angleFor(i));
        expand(i);
        npTitle.textContent = nameOf(i);
        npWhy.textContent = whyOf(i);
        state.start = performance.now();
        cancelAnimationFrame(state.raf);
        tick();
        state.timer = setTimeout(function () {
          var next = i + 1;
          if (next >= tracks.length) { stop("That's the record. Flip it, or pick a track."); return; }
          // A short silence before the hidden track, as is traditional.
          var gap = tracks[next].classList.contains("track--hidden") ? 1400 : 0;
          if (gap) {
            npTitle.textContent = "…";
            npWhy.textContent = "Keep listening.";
            npBar.style.width = "0%";
          }
          state.timer = setTimeout(function () { playTrack(next); }, gap);
        }, STEP);
      };
      var side = sideOf(i);
      if (side !== state.side) {
        // Lift, flip, and drop again on the outer groove of the new side.
        setArm(REST);
        state.timer = setTimeout(function () {
          flipTo(side).then(function () { setArm(OUTER); state.timer = setTimeout(go, motion ? 700 : 0); });
        }, motion ? 700 : 0);
      } else {
        go();
      }
    }

    function start(i, announce) {
      state.playing = true;
      deck.classList.add("is-playing");
      playBtn.setAttribute("aria-pressed", "true");
      playLabel.textContent = "Lift the needle";
      var first = i || 0;
      if (announce) npLive.textContent = "Playing " + nameOf(first) + ".";
      // The arm swings over before the first groove.
      if (sideOf(first) === state.side) {
        setArm(OUTER - 1);
        state.timer = setTimeout(function () { playTrack(first); }, motion ? 650 : 0);
      } else {
        playTrack(first);
      }
    }

    function stop(message) {
      state.playing = false;
      clearTimeout(state.timer);
      cancelAnimationFrame(state.raf);
      deck.classList.remove("is-playing");
      playBtn.setAttribute("aria-pressed", "false");
      playLabel.textContent = "Drop the needle";
      setArm(REST);
      tracks.forEach(function (t) { t.classList.remove("is-current"); });
      if (message) { npTitle.textContent = message; npWhy.textContent = "The needle is up."; }
      npLive.textContent = message || "Needle up.";
    }

    playBtn.addEventListener("click", function () {
      if (state.playing) { stop("Needle up. Your place is kept."); return; }
      var from = state.index >= 0 && state.index < tracks.length - 1 ? state.index : 0;
      start(from, true);
    });

    tracks.forEach(function (t, i) {
      t.addEventListener("click", function () {
        if (state.playing && state.index === i) {
          // A second click on the playing track just folds its notes.
          var open = t.getAttribute("aria-expanded") === "true";
          t.setAttribute("aria-expanded", open ? "false" : "true");
          document.getElementById(t.getAttribute("aria-controls")).hidden = open;
          return;
        }
        clearTimeout(state.timer);
        if (!state.playing) { start(i, true); return; }
        npLive.textContent = "Playing " + nameOf(i) + ".";
        playTrack(i);
      });
    });

    // The cover's "Drop the needle": go to the deck, then play from A1.
    $$("[data-drop-needle]").forEach(function (a) {
      a.addEventListener("click", function (e) {
        e.preventDefault();
        var target = $("#tracklist");
        target.scrollIntoView({ behavior: motion ? "smooth" : "auto", block: "start" });
        playBtn.focus({ preventScroll: true });
        if (!state.playing) setTimeout(function () { start(0, true); }, motion ? 800 : 0);
      });
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && state.playing) stop("Needle up.");
    });
  }

  /* ---------------------------------------- 4. the reader (Policy::stateOf) */
  /* A copy of the dates in policy/policy.json (2026-10-28), for this demo
     only. The policy file is the source; real tools read it through
     Waynestate\Creed\Policy, never through a copy like this one. We know.
     The rules below mirror src/Policy.php line for line. */
  var POLICY = {
    eol_grace_days: 90,
    components: {
      php: { "8.1": [null, "2025-12-31"], "8.2": ["2024-12-31", "2026-12-31"], "8.3": ["2025-12-31", "2027-12-31"], "8.4": ["2026-12-31", "2028-12-31"], "8.5": ["2027-12-31", "2029-12-31"] },
      laravel: { "10": ["2024-08-06", "2025-02-04"], "11": ["2025-09-03", "2026-03-12"], "12": ["2026-08-13", "2027-02-24"], "13": ["2027-09-17", "2028-03-17"] },
      node: { "20": ["2024-10-22", "2026-04-30"], "22": ["2025-10-21", "2027-04-30"], "24": ["2026-10-20", "2028-04-30"], "26": ["2027-10-20", "2029-04-30"] },
      mysql: { "5.6": ["2018-02-28", "2021-02-28"], "5.7": ["2020-10-31", "2023-10-31"], "8.0": ["2025-04-30", "2026-04-30"], "8.4": ["2029-04-30", "2032-04-30"], "9.7": ["2031-04-30", "2034-04-30"] }
    }
  };

  function addDays(iso, n) {
    var d = new Date(iso + "T00:00:00Z");
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }
  // Same order as Policy::stateOf: inclusive ends, string compare on ISO days.
  function stateOf(component, version, day) {
    var entry = POLICY.components[component][version];
    var activeUntil = entry[0], securityUntil = entry[1];
    if (activeUntil !== null && day <= activeUntil) return "active";
    if (day <= securityUntil) return "security";
    if (day <= addDays(securityUntil, POLICY.eol_grace_days)) return "eol";
    return "eol_grace_expired";
  }
  function cmpVersion(a, b) {
    var x = a.split("."), y = b.split(".");
    for (var i = 0; i < Math.max(x.length, y.length); i++) {
      var d = (+x[i] || 0) - (+y[i] || 0);
      if (d) return d;
    }
    return 0;
  }
  function oldestSupported(component, day) {
    var ok = Object.keys(POLICY.components[component]).filter(function (v) {
      var s = stateOf(component, v, day);
      return s === "active" || s === "security";
    }).sort(cmpVersion);
    return ok.length ? ok[0] : null;
  }

  var reader = $("[data-reader]");
  if (reader) {
    var rows = $("[data-reader-rows]", reader);
    var floor = $("[data-reader-floor]", reader);
    var dateIn = $("[data-reader-date]", reader);
    var tabs = $$("[role=tab]", reader);
    var panel = $("[role=tabpanel]", reader);
    var chips = $$("[data-date]", reader);
    var current = "php";
    var previous = {};

    function todayIso() {
      var d = new Date();
      return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2);
    }

    function render() {
      var day = dateIn.value;
      if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) return; // the reader refuses to guess
      var versions = Object.keys(POLICY.components[current]).sort(cmpVersion);
      var html = "";
      versions.forEach(function (v) {
        var s = stateOf(current, v, day);
        var key = current + v;
        var changed = previous[key] && previous[key] !== s;
        previous[key] = s;
        html += "<tr><th scope=\"row\">" + current + " " + v + "</th><td><span class=\"state state--" + s + (changed ? " is-changed" : "") + "\">" + s + "</span></td><td>" + POLICY.components[current][v][1] + "</td></tr>";
      });
      rows.innerHTML = html;
      var o = oldestSupported(current, day);
      floor.innerHTML = "Oldest supported " + current + " on " + day + ": <strong>" + (o || "none") + "</strong>";
      chips.forEach(function (c) { c.setAttribute("aria-pressed", c.getAttribute("data-date") === day ? "true" : "false"); });
    }

    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
      });
      current = tab.getAttribute("data-component");
      panel.setAttribute("aria-labelledby", tab.id);
      if (focus) tab.focus();
      render();
    }

    tabs.forEach(function (t, i) {
      t.addEventListener("click", function () { select(t); });
      t.addEventListener("keydown", function (e) {
        var n = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
        if (e.key === "Home") n = -i;
        if (e.key === "End") n = tabs.length - 1 - i;
        if (n === undefined) return;
        e.preventDefault();
        select(tabs[(i + n + tabs.length) % tabs.length], true);
      });
    });
    chips.forEach(function (c) {
      c.setAttribute("aria-pressed", "false");
      c.addEventListener("click", function () { dateIn.value = c.getAttribute("data-date"); render(); });
    });
    dateIn.addEventListener("input", render);
    dateIn.value = todayIso();
    render();
  }

  /* ------------------------------------------------------------ 5. OS tabs */
  var os = $("[data-os]");
  if (os) {
    var osTabs = $$("[role=tab]", os);
    function pick(tab, focus) {
      osTabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute("aria-controls")).hidden = !on;
      });
      if (focus) tab.focus();
    }
    osTabs.forEach(function (t, i) {
      t.addEventListener("click", function () { pick(t); });
      t.addEventListener("keydown", function (e) {
        var n = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
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
