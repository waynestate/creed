/* CREED /php: the PHP lifecycle, live.

   Reads ../php-feed.php (the server merge, at most ten minutes old). When that
   fails, reads endoflife.date and php-src's tags straight from the browser
   (both send CORS headers; php.net does not) and says so. When both fail, the
   page says that too. Every number on the page comes from a source; nothing is
   typed in. Polls every ten minutes while the tab is open, so a release
   announced for tomorrow shows up without a reload.

   Everything from a source goes into the page as text (textContent), never as
   markup. Creed Bratton trusts nobody's paperwork, and neither does this file. */
(function () {
  "use strict";

  var root = document.querySelector("[data-pl]");
  if (!root) return;

  var POLL_MS = 10 * 60 * 1000;
  var MAX_AGE_MIN = 30;
  var RECENT_EOL_DAYS = 1100;
  var NEW_DAYS = 30;
  var DAY = 86400000;
  var FEED = new URL("../php-feed.php", window.location.href).toString();
  var EOL_API = "https://endoflife.date/api/v1/products/php";
  var TAGS_API = "https://api.github.com/repos/php/php-src/git/matching-refs/tags/php-";

  var q = function (sel) { return root.querySelector(sel); };
  var els = {
    mode: q("[data-pl-mode]"),
    checked: q("[data-pl-checked]"),
    alert: q("[data-pl-alert]"),
    newest: q("[data-pl-newest]"),
    patches: q("[data-pl-patches]"),
    next: q("[data-pl-next]"),
    rows: q("[data-pl-rows]"),
    axis: q("[data-pl-axis]"),
    chart: q("[data-pl-chart]"),
    detail: q("[data-pl-detail]"),
    fit: q("[data-pl-fit]"),
    sources: q("[data-pl-sources]"),
    tl: q("[data-pl-timelines]"),
    tlOlder: q("[data-pl-timelines-older]"),
    tlOlderWrap: q("[data-pl-timelines-older-wrap]"),
    tlOlderCount: q("[data-pl-timelines-older-count]"),
    sourcesAll: q("[data-pl-sources-all]"),
    notes: q("[data-pl-notes]")
  };

  var state = { doc: null, mode: "reading", seen: null, first: true, pinned: null, nextKey: null };

  /* ------------------------------------------------------------ helpers */

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === "class") el.className = v;
        else if (k === "text") el.textContent = v;
        else el.setAttribute(k, v === true ? "" : String(v));
      });
    }
    (kids || []).forEach(function (kid) {
      if (kid === null || kid === undefined || kid === false) return;
      el.appendChild(typeof kid === "string" ? document.createTextNode(kid) : kid);
    });
    return el;
  }

  function clear(el) { while (el && el.firstChild) el.removeChild(el.firstChild); }

  function dayMs(day) { return Date.parse(day + "T00:00:00Z"); }

  function todayStr() { return new Date().toISOString().slice(0, 10); }

  var WD = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  function fmtDay(day) {
    if (!day) return "date not given";
    var d = new Date(dayMs(day));
    return isNaN(d) ? day : WD[d.getUTCDay()] + " " + day;
  }

  function fmtTime(iso) { return iso ? iso.slice(11, 16) + " UTC" : ""; }

  function ago(iso) {
    if (!iso) return "never";
    var min = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60000));
    if (min < 1) return "just now";
    if (min < 60) return min + " min ago";
    var hr = Math.round(min / 60);
    if (hr < 48) return hr + " h ago";
    return Math.round(hr / 24) + " days ago";
  }

  function cmpVersion(a, b) {
    var pa = String(a).split(".").map(Number), pb = String(b).split(".").map(Number);
    for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
      var d = (pa[i] || 0) - (pb[i] || 0);
      if (d) return d < 0 ? -1 : 1;
    }
    return 0;
  }

  function stateLabel(s) {
    return { active: "Active", security: "Security only", eol: "End of life", planned: "Planned", unknown: "Unknown" }[s] || s;
  }

  function statusLabel(s) {
    return {
      announced: "announced (php.net news)",
      due: "due now, not out yet (php.net news)",
      tagged: "tagged in php-src, not announced",
      candidate: "release candidate tagged",
      development: "in development",
      scheduled: "on the wiki schedule",
      "no-timeline": "no timeline released yet"
    }[s] || s;
  }

  function branchList(arr) {
    if (!arr || !arr.length) return "";
    if (arr.length === 1) return arr[0];
    var sorted = arr.slice().sort(cmpVersion);
    return sorted[0] + " to " + sorted[sorted.length - 1];
  }

  /* ------------------------------------------------------------ loading */

  function fetchJson(url, ms) {
    var ctl = "AbortController" in window ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, ms) : null;
    return fetch(url, { cache: "no-store", signal: ctl ? ctl.signal : undefined, headers: { Accept: "application/json" } })
      .then(function (r) {
        if (timer) clearTimeout(timer);
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      });
  }

  function load() {
    return fetchJson(FEED, 20000)
      .then(function (doc) {
        if (!doc || doc.schema !== 1 || !Array.isArray(doc.branches)) throw new Error("not the feed");
        var age = (Date.now() - Date.parse(doc.generated_at)) / 60000;
        return { doc: doc, mode: doc.stale || age > MAX_AGE_MIN ? "stale" : "live" };
      })
      .catch(function () {
        return fallback().then(function (doc) { return { doc: doc, mode: doc.branches.length ? "fallback" : "down" }; });
      })
      .then(function (res) {
        render(res.doc, res.mode);
      });
  }

  /* The browser fallback: endoflife.date for the branches, php-src's tags for
     what comes next. The same rules as the server, fewer sources. */
  function fallback() {
    var now = new Date().toISOString().replace(/\.\d+Z$/, "Z");
    var today = todayStr();
    var sources = [];
    return fetchJson(EOL_API, 12000)
      .then(function (j) { sources.push(src("endoflife", "cross-check", "endoflife.date", "https://endoflife.date/php", true, now)); return j; },
            function (e) { sources.push(src("endoflife", "cross-check", "endoflife.date", "https://endoflife.date/php", false, now, e.message)); return null; })
      .then(function (eol) {
        var rel = eol && eol.result && Array.isArray(eol.result.releases) ? eol.result.releases : [];
        var branches = [];
        rel.forEach(function (r) {
          if (!/^\d+\.\d+$/.test(r.name || "")) return;
          var d = { released: r.releaseDate || null, active_until: r.eoasFrom || null, security_until: r.eolFrom || null };
          var st = d.released && d.released > today ? "planned" : d.security_until && today > d.security_until ? "eol" : d.active_until && today > d.active_until ? "security" : "active";
          if (st === "eol" && d.security_until && (dayMs(today) - dayMs(d.security_until)) / DAY > RECENT_EOL_DAYS) return;
          var latest = r.latest && /^\d+\.\d+\.\d+$/.test(r.latest.name || "") ? { version: r.latest.name, date: r.latest.date || null, security: false, source: "endoflife.date" } : null;
          branches.push({
            branch: r.name, state: st, released: d.released, active_until: d.active_until, security_until: d.security_until,
            days_left: st !== "eol" && d.security_until ? Math.round((dayMs(d.security_until) - dayMs(today)) / DAY) : null,
            is_new: !!(d.released && d.released <= today && (dayMs(today) - dayMs(d.released)) / DAY <= NEW_DAYS),
            latest: latest, dates_from: "endoflife.date", disagreements: [], next: null
          });
        });
        branches.sort(function (a, b) { return cmpVersion(a.branch, b.branch); });
        var majors = {};
        branches.forEach(function (b) { if (b.state !== "eol") majors[b.branch.split(".")[0]] = true; });
        var keys = Object.keys(majors).map(Number);
        if (keys.length) majors[Math.max.apply(null, keys) + 1] = true;
        var calls = Object.keys(majors).map(function (m) {
          return fetchJson(TAGS_API + m + ".", 12000)
            .then(function (refs) { sources.push(src("tags-" + m, "php-src", "php-src tags (PHP " + m + ")", "https://github.com/php/php-src/tags", true, now)); return refs; },
                  function (e) { sources.push(src("tags-" + m, "php-src", "php-src tags (PHP " + m + ")", "https://github.com/php/php-src/tags", false, now, e.message)); return []; });
        });
        return Promise.all(calls).then(function (lists) {
          var finals = {}, pres = {};
          lists.forEach(function (refs) {
            (Array.isArray(refs) ? refs : []).forEach(function (r) {
              var m = /(?:^|\/)php-(\d+\.\d+\.\d+)((?:alpha|beta|RC)\d+)?$/i.exec(r.ref || "");
              if (!m) return;
              var br = m[1].split(".").slice(0, 2).join(".");
              if (m[2]) (pres[br] = pres[br] || []).push({ version: m[1], pre: m[2] });
              else (finals[br] = finals[br] || []).push(m[1]);
            });
          });
          var upcoming = [];
          branches.forEach(function (b) {
            var have = b.latest && b.latest.version;
            if (!have) return;
            var tagged = (finals[b.branch] || []).filter(function (v) { return cmpVersion(v, have) > 0; }).sort(cmpVersion);
            var rc = (pres[b.branch] || []).filter(function (t) { return cmpVersion(t.version, have) > 0; });
            if (tagged.length) b.next = { version: tagged[tagged.length - 1], status: "tagged", evidence: [ev("php-src tag", "Tagged in php-src, not announced on php.net")] };
            else if (rc.length) b.next = { version: rc[rc.length - 1].version, status: "candidate", pre: rc[0].pre, evidence: [ev("php-src tag", "Release candidate tagged; not a release")] };
            if (b.next) upcoming.push(Object.assign({ kind: "patch", branches: [b.branch] }, b.next));
          });
          var known = branches.map(function (b) { return b.branch; }).sort(cmpVersion);
          var top = known[known.length - 1];
          Object.keys(finals).concat(Object.keys(pres)).forEach(function (br) {
            if (known.indexOf(br) !== -1 || (top && cmpVersion(br, top) <= 0) || upcoming.some(function (u) { return u.branches[0] === br; })) return;
            if (finals[br]) upcoming.push({ kind: "branch", branches: [br], version: finals[br].sort(cmpVersion).pop(), status: "tagged", evidence: [ev("php-src tag", "Tagged in php-src, not listed yet")] });
            else upcoming.push({ kind: "branch", branches: [br], version: pres[br][0].version, status: "development", evidence: [ev("php-src tag", "Pre-release tagged in php-src")] });
          });
          var released = branches.filter(function (b) { return b.released && b.released <= today; });
          return {
            schema: 1, mode: "browser", generated_at: now, today: today,
            newest: released.length ? released[released.length - 1].branch : null,
            branches: branches, upcoming: upcoming, timelines: [], frameworks: [], sources: sources,
            notes: ["The server feed did not answer, so php.net, its news feed, the wiki, Packagist and Laravel's support table were not read. endoflife.date can be about a day behind php.net."]
          };
        });
      });
  }

  function src(id, group, label, url, ok, at, error) {
    return { id: id, group: group, label: label, url: url, ok: ok, checked_at: at, ok_at: ok ? at : null, stale: !ok, error: error || null, browser: true };
  }

  function ev(source, label, url) { return { source: source, label: label, url: url || null }; }

  /* ------------------------------------------------------------ render */

  function render(doc, mode) {
    var prev = state.seen;
    state.doc = doc;
    state.mode = mode;
    var seen = {};
    doc.branches.forEach(function (b) { if (b.latest) seen[b.branch] = b.latest.version; });
    var fresh = {};
    doc.branches.forEach(function (b) {
      if (!b.latest) return;
      if (prev ? prev[b.branch] !== b.latest.version : state.first && b.latest.date && (dayMs(todayStr()) - dayMs(b.latest.date)) / DAY <= 1) fresh[b.branch] = true;
    });
    state.seen = seen;

    renderStatus(doc, mode);
    renderNow(doc, fresh);
    renderTimeline(doc, fresh);
    renderFit(doc);
    renderTimelines(doc);
    renderSources(doc);
    state.first = false;
  }

  function renderStatus(doc, mode) {
    var label = { live: "Live", stale: "Stale", fallback: "Fallback", down: "No data", reading: "Reading" }[mode];
    var was = els.mode.textContent;
    els.mode.textContent = label;
    els.mode.className = "stamp pl-mode pl-mode--" + mode;
    if (was !== label) {
      void els.mode.offsetWidth;
      els.mode.classList.add("is-pressed");
    }
    var n = (doc.sources || []).length;
    els.checked.textContent = mode === "down"
      ? "No source could be read"
      : "Merged " + ago(doc.generated_at) + " from " + n + " source" + (n === 1 ? "" : "s") + (mode === "fallback" ? ", in your browser" : "");

    var msg = null, cls = "pl-alert";
    if (mode === "fallback") {
      msg = "The server feed did not answer, so this page read endoflife.date and php-src's tags straight from your browser. php.net's own pages, its news feed (where upcoming security releases are announced) and the framework fit (Packagist, Laravel's support table and CREED's own framework tests) need the server feed.";
      cls += " pl-alert--fallback";
    } else if (mode === "stale") {
      msg = "The sources were last merged " + ago(doc.generated_at) + ". What you see is the last good answer, not a fresh one.";
    } else if (mode === "down") {
      msg = "Neither the server feed nor the sources this browser can reach answered. Nothing is shown rather than something made up. Try again in a few minutes, or read php.net's supported versions page.";
    }
    els.alert.hidden = !msg;
    els.alert.className = cls;
    els.alert.textContent = msg || "";
  }

  function supported(doc) {
    return doc.branches.filter(function (b) { return b.state === "active" || b.state === "security"; });
  }

  function renderNow(doc, fresh) {
    // Newest.
    var card = els.newest;
    clear(card);
    card.appendChild(h("p", { class: "pl-card__label" }, [h("span", { class: "pl-no", text: "§2A.1" }), "Newest"]));
    var nb = doc.branches.filter(function (b) { return b.branch === doc.newest; })[0];
    if (!nb) {
      card.appendChild(h("p", { class: "pl-card__empty", text: "No source said." }));
    } else {
      card.appendChild(h("p", { class: "pl-big" }, [
        h("span", { class: "pl-big__v", text: "PHP " + (nb.latest ? nb.latest.version : nb.branch) }),
        nb.is_new ? h("span", { class: "pl-new", text: "New" }) : null
      ]));
      card.appendChild(h("p", { class: "pl-sub" }, [
        "Released ", h("strong", { text: fmtDay(nb.released) }),
        nb.active_until ? ". Active support until " + nb.active_until + "." : "."
      ]));
      if (fresh[nb.branch]) card.classList.add("is-fresh"); else card.classList.remove("is-fresh");
    }

    // Patches, newest branch first.
    clear(els.patches);
    var sup = supported(doc).slice().reverse();
    if (!sup.length) els.patches.appendChild(h("li", { class: "pl-card__empty", text: "No source said." }));
    sup.forEach(function (b) {
      var l = b.latest;
      els.patches.appendChild(h("li", { class: "pl-patch" + (fresh[b.branch] ? " is-fresh" : "") }, [
        h("span", { class: "pl-patch__v", text: l ? l.version : b.branch + ".?" }),
        h("span", { class: "pl-patch__d", text: l ? (l.date || "date not given") : "no release read" }),
        l && l.security ? h("span", { class: "pl-patch__sec", text: "security fix" }) : null
      ]));
    });

    // Next known release.
    var nx = els.next;
    clear(nx);
    var all = orderUpcoming(doc.upcoming || []);
    // Lines to come read in the order they will arrive: 8.7, then 9.0.
    var lines = all.filter(function (x) { return x.status === "no-timeline"; }).sort(function (a, b) { return cmpVersion(a.branches[0], b.branches[0]); });
    var up = all.filter(function (x) { return x.status !== "no-timeline"; });
    // The way in to every release's timeline sits on the card's label line, so it costs the dashboard no height.
    nx.appendChild(h("div", { class: "pl-next__top" }, [
      h("p", { class: "pl-card__label", text: "Next known release" }),
      h("a", { class: "pl-next__more", href: "#timelines" }, ["All timelines", h("span", { "aria-hidden": "true", text: " ↓" })])
    ]));
    if (!up.length && lines.length) {
      nx.appendChild(futureLines(lines, doc, false));
      return;
    }
    if (!up.length) {
      nx.appendChild(h("p", { class: "pl-next__what", text: "Nothing announced or tagged" }));
      nx.appendChild(h("p", { class: "pl-sub", text: state.mode === "fallback"
        ? "In fallback mode only php-src's tags are read; php.net's notices and php-src master need the server feed."
        : "php.net posts security release notices ahead of time, usually the day before. This page checks every 10 minutes." }));
      return;
    }
    var u = up[0];
    // Patches tagged together read as one line: "PHP 8.5.14, 8.4.29 and 8.3.38".
    var same = up.filter(function (x) { return x.kind === "patch" && u.kind === "patch" && x.status === u.status && (x.day || "") === (u.day || ""); });
    var rest = same.length > 1 ? up.filter(function (x) { return same.indexOf(x) === -1; }) : up.slice(1);
    var what;
    if (u.kind === "security") what = "Security releases for PHP " + branchList(u.branches);
    else if (u.kind === "branch") what = "PHP " + (u.version || u.branches[0]) + (u.status === "development" ? " in the works" : "");
    else if (same.length > 1) what = "PHP " + same.map(function (x) { return x.version || x.branches[0] + ".x"; }).join(", ");
    else what = "PHP " + (u.version || (u.branches[0] + ".x"));
    var key = what + "|" + (u.day || "") + "|" + u.status;
    nx.appendChild(h("p", { class: "pl-next__what" + (state.nextKey && state.nextKey !== key ? " is-fresh" : ""), text: what }));
    state.nextKey = key;
    var when = u.day ? fmtDay(u.day) + (u.start ? ", " + fmtTime(u.start) + (u.end && u.end !== u.start ? " to " + fmtTime(u.end) : "") : "") : "Date not announced";
    nx.appendChild(h("p", { class: "pl-next__when", text: when }));
    if (u.next_step) nx.appendChild(h("p", { class: "pl-sub", text: "Next on the wiki timetable: " + u.next_step.label + ", " + fmtDay(u.next_step.date) + "." }));
    var evs = h("ul", { class: "pl-ev", "aria-label": "Evidence" });
    evs.appendChild(h("li", { class: "pl-ev--" + u.status, text: statusLabel(u.status) }));
    (u.evidence || []).forEach(function (e) {
      if (!e.url) return;
      evs.appendChild(h("li", null, [h("a", { href: e.url, text: e.source })]));
    });
    nx.appendChild(evs);
    if (rest.length) {
      // Same kind, status and day read as one entry.
      var also = [];
      rest.forEach(function (x) {
        var tail = " (" + statusLabel(x.status) + (x.day ? ", " + x.day : "") + ")";
        var name = x.kind === "security" ? "security releases for " + branchList(x.branches) : (x.version || ("PHP " + branchList(x.branches)));
        var last = also[also.length - 1];
        if (last && x.kind === "patch" && last.kind === "patch" && last.tail === tail) last.names.push(name);
        else also.push({ kind: x.kind, tail: tail, names: [name] });
      });
      nx.appendChild(h("p", { class: "pl-sub", text: "Also: " + also.slice(0, 3).map(function (l) { return l.names.join(", ") + l.tail; }).join("; ") + (also.length > 3 ? "; and more" : "") }));
    }
    if (lines.length) nx.appendChild(futureLines(lines, doc, true));
  }

  /* The lines still to come with nothing published: one row each, no estimate.
     8.7 is master's version; 9.0 the major after it. */
  var WATCH = {
    "php-src master": "php-src",
    "wiki timetables": "the wiki",
    "RFCs": "RFCs"
  };

  // "short" is the dashboard's line; "why" the timeline card's.
  function lineCopy(x) {
    if (x.role === "development") {
      return {
        tag: "next in development",
        short: "A yearly release: minor in name, with breaking changes.",
        why: "The yearly feature release: a minor by PHP's numbering, but it brings deprecations and breaking changes."
      };
    }
    return { tag: "next major", why: "RFCs already name it as their target. No release date is set." };
  }

  function futureLines(list, doc, compact) {
    var ul = h("ul", { class: "pl-lines" + (compact ? " pl-lines--compact" : ""), "aria-label": compact ? "Further out" : "Lines still to come" });
    list.forEach(function (x) {
      var c = lineCopy(x);
      var at = x.checked_at || doc.generated_at;
      ul.appendChild(h("li", { class: "pl-line" }, [
        h("p", { class: "pl-line__head" }, [
          h("span", { class: "pl-line__v", text: "PHP " + x.branches[0] }),
          h("span", { class: "pl-line__tag", text: c.tag })
        ]),
        h("p", { class: "pl-line__what" }, [
          "No timeline released yet.",
          compact || !c.short ? null : h("span", { class: "pl-line__why", title: c.why, text: " " + c.short })
        ]),
        watchLine(x, at)
      ]));
    });
    return ul;
  }

  /* "Watching php-src master, the wiki and RFCs; checked 2 min ago": what would change this row. */
  function watchLine(x, at) {
    var links = (x.evidence || []).filter(function (e) { return e.url; });
    var p = h("p", { class: "pl-line__watch" }, ["Watching "]);
    links.forEach(function (e, i) {
      if (i) p.appendChild(document.createTextNode(i === links.length - 1 ? (links.length > 2 ? ", and " : " and ") : ", "));
      p.appendChild(h("a", { href: e.url, title: e.label, text: WATCH[e.source] || e.source }));
    });
    p.appendChild(h("span", { class: "pl-line__checked", text: "; checked " + ago(at) }));
    return p;
  }

  /* Dated first (soonest first), then a new branch, then everything else by strength of evidence. */
  function orderUpcoming(list) {
    var kind = { security: 0, branch: 1, patch: 2 };
    var status = { due: 0, announced: 1, tagged: 2, scheduled: 3, candidate: 4, development: 5, "no-timeline": 6 };
    return list.slice().sort(function (a, b) {
      var da = a.start || a.day || "9999", db = b.start || b.day || "9999";
      if (da !== db) return da < db ? -1 : 1;
      var k = (kind[a.kind] || 3) - (kind[b.kind] || 3);
      if (k) return k;
      return (status[a.status] || 9) - (status[b.status] || 9) || cmpVersion(b.version || "0", a.version || "0");
    });
  }

  function renderTimeline(doc, fresh) {
    clear(els.rows);
    clear(els.axis);
    var bs = doc.branches;
    if (!bs.length) {
      els.rows.appendChild(h("tr", null, [h("td", { colspan: "4", class: "pl-card__empty", text: state.mode === "down" ? "No source could be read." : "No branches." })]));
      return;
    }
    var today = todayStr();
    var lo = Infinity, hi = -Infinity;
    bs.forEach(function (b) {
      [b.released, b.active_until, b.security_until, b.next && b.next.day].forEach(function (d) {
        if (!d) return;
        var t = dayMs(d);
        if (t < lo) lo = t;
        if (t > hi) hi = t;
      });
    });
    var t0 = dayMs(today);
    lo = Math.min(lo, t0);
    hi = Math.max(hi, t0);
    var y0 = new Date(lo).getUTCFullYear(), y1 = new Date(hi).getUTCFullYear() + 1;
    lo = Date.UTC(y0, 0, 1);
    hi = Date.UTC(y1, 0, 1);
    var pct = function (day) { return Math.max(0, Math.min(100, (dayMs(day) - lo) / (hi - lo) * 100)); };

    var todayPct = pct(today);
    var axisW = els.axis.clientWidth || 500;
    for (var y = y0; y < y1; y++) {
      var at = (Date.UTC(y, 6, 1) - lo) / (hi - lo) * 100;
      // The year under the Today label steps aside rather than print through it.
      var tick = h("span", { class: "pl-axis__tick" + (Math.abs(at - todayPct) * axisW / 100 < 46 ? " pl-axis__tick--hidden" : ""), text: String(y) });
      tick.style.setProperty("left", at + "%");
      els.axis.appendChild(tick);
    }
    var tLabel = h("span", { class: "pl-axis__today", text: "Today" });
    tLabel.style.setProperty("left", pct(today) + "%");
    els.axis.appendChild(tLabel);

    bs.forEach(function (b, i) {
      var tr = h("tr", { class: "pl-row pl-row--" + b.state + (fresh[b.branch] ? " is-fresh" : ""), "data-branch": b.branch });
      tr.style.setProperty("--i", String(i));
      var btn = h("button", { type: "button", class: "pl-branch", "aria-expanded": "false", "aria-controls": "pl-detail" }, [
        b.branch, b.is_new ? h("span", { class: "pl-branch__dot", title: "New", "aria-hidden": "true" }) : null
      ]);
      btn.appendChild(h("span", { class: "wsu-sr-only", text: ", details" }));
      tr.appendChild(h("th", { scope: "row" }, [btn]));

      var track = h("div", { class: "pl-track", "aria-hidden": "true" }, [h("span", { class: "pl-track__line" })]);
      for (var yy = y0 + 1; yy < y1; yy++) {
        var g = h("span", { class: "pl-track__grid" });
        g.style.setProperty("left", ((Date.UTC(yy, 0, 1) - lo) / (hi - lo) * 100) + "%");
        track.appendChild(g);
      }
      if (b.released && b.security_until) {
        if (b.state === "planned") {
          track.appendChild(bar("pl-bar pl-bar--planned", pct(b.released), pct(b.security_until)));
        } else if (b.state === "eol") {
          track.appendChild(bar("pl-bar", pct(b.released), pct(b.security_until)));
        } else {
          var mid = b.active_until || b.security_until;
          track.appendChild(bar("pl-bar pl-bar--active" + (mid === b.security_until ? " pl-bar--only" : ""), pct(b.released), pct(mid)));
          if (mid !== b.security_until) track.appendChild(bar("pl-bar pl-bar--security", pct(mid), pct(b.security_until)));
        }
        var rt = h("span", { class: "pl-tick" });
        rt.style.setProperty("left", pct(b.released) + "%");
        track.appendChild(rt);
      }
      if (b.next && b.next.day) {
        var mk = h("span", { class: "pl-mark" + (b.next.status === "announced" || b.next.status === "due" ? "" : " pl-mark--weak") });
        mk.style.setProperty("left", pct(b.next.day) + "%");
        track.appendChild(mk);
      }
      var tl = h("span", { class: "pl-today" });
      tl.style.setProperty("left", pct(today) + "%");
      track.appendChild(tl);
      tr.appendChild(h("td", null, [track, h("span", { class: "wsu-sr-only", text: sentence(b) })]));

      var l = b.latest;
      tr.appendChild(h("td", { class: "pl-latest" }, l
        ? [l.version, h("small", { text: l.date || "date not given" })]
        : ["none read"]));
      var left = b.state === "eol" ? "EOL" : b.state === "planned" ? "planned" : b.days_left === null ? "?" : String(b.days_left);
      tr.appendChild(h("td", { class: "pl-left" + (b.state !== "eol" && b.days_left !== null && b.days_left <= 90 ? " pl-left--soon" : "") }, [
        left, b.state !== "eol" && b.days_left !== null ? h("span", { class: "wsu-sr-only", text: " days" }) : null
      ]));
      els.rows.appendChild(tr);
    });

    els.chart.classList.remove("pl-in");
    if (state.first) {
      void els.chart.offsetWidth;
      els.chart.classList.add("pl-in");
    }
  }

  function bar(cls, from, to) {
    var el = h("span", { class: cls });
    el.style.setProperty("left", from + "%");
    el.style.setProperty("width", Math.max(0.4, to - from) + "%");
    return el;
  }

  function sentence(b) {
    var s = "PHP " + b.branch + ": " + stateLabel(b.state).toLowerCase() + ". ";
    if (b.released) s += "Released " + b.released + ". ";
    if (b.active_until) s += "Active support until " + b.active_until + ". ";
    if (b.security_until) s += "Security fixes until " + b.security_until + ". ";
    if (b.next) s += "Next: " + (b.next.version || b.branch + ".x") + ", " + statusLabel(b.next.status) + (b.next.day ? ", " + b.next.day : "") + ". ";
    (b.disagreements || []).forEach(function (d) { s += "Sources disagree on " + d.label + ": php.net says " + d.php_net + ", endoflife.date says " + d.endoflife + ". "; });
    return s.trim();
  }

  /* The detail card: shown on hover and focus, pinned by a click. */
  function showDetail(tr, pin) {
    var b = state.doc && state.doc.branches.filter(function (x) { return x.branch === tr.getAttribute("data-branch"); })[0];
    if (!b) return;
    var d = els.detail;
    clear(d);
    d.id = "pl-detail";
    d.setAttribute("role", "region");
    d.setAttribute("aria-label", "PHP " + b.branch + " details");
    d.removeAttribute("aria-hidden");
    d.appendChild(h("h3", null, ["PHP " + b.branch, h("span", { class: "pl-state pl-state--" + b.state, text: stateLabel(b.state) })]));
    var dl = h("dl");
    var row = function (k, v) { dl.appendChild(h("dt", { text: k })); dl.appendChild(h("dd", { text: v })); };
    row("Released", fmtDay(b.released));
    if (b.active_until) row("Active to", fmtDay(b.active_until));
    if (b.security_until) row("Security to", fmtDay(b.security_until));
    if (b.days_left !== null && b.state !== "eol") row("Days left", String(b.days_left));
    if (b.latest) row("Latest", b.latest.version + (b.latest.date ? ", " + b.latest.date : "") + (b.latest.security ? ", security" : ""));
    if (b.next) row("Next", (b.next.version || b.branch + ".x") + (b.next.day ? ", " + fmtDay(b.next.day) : ""));
    d.appendChild(dl);
    if (b.next) d.appendChild(h("p", { text: statusLabel(b.next.status) + "." }));
    (b.disagreements || []).forEach(function (x) {
      d.appendChild(h("p", { class: "pl-disagree", text: "Sources disagree on " + x.label + ": php.net " + x.php_net + ", endoflife.date " + x.endoflife + ". Showing php.net." }));
    });
    if (b.latest) d.appendChild(h("p", { text: "Latest from " + b.latest.source + "; dates from " + b.dates_from + "." }));
    d.hidden = false;

    var chart = els.chart.getBoundingClientRect();
    var r = tr.getBoundingClientRect();
    var below = r.bottom - chart.top + 6;
    var w = d.offsetWidth, ht = d.offsetHeight;
    var top = below + ht > chart.height + 40 && r.top - chart.top - ht - 6 > 0 ? r.top - chart.top - ht - 6 : below;
    var bx = tr.querySelector(".pl-branch").getBoundingClientRect();
    var left = Math.max(0, Math.min(chart.width - w, bx.left - chart.left + 40));
    d.style.setProperty("top", top + "px");
    d.style.setProperty("left", left + "px");

    Array.prototype.forEach.call(els.rows.querySelectorAll(".pl-row"), function (x) {
      x.classList.toggle("is-lit", x === tr);
      var bt = x.querySelector(".pl-branch");
      if (bt) bt.setAttribute("aria-expanded", pin && x === tr ? "true" : "false");
    });
    state.pinned = pin ? b.branch : state.pinned;
  }

  function hideDetail(force) {
    if (state.pinned && !force) return;
    state.pinned = null;
    els.detail.hidden = true;
    Array.prototype.forEach.call(els.rows.querySelectorAll(".pl-row"), function (x) {
      x.classList.remove("is-lit");
      var bt = x.querySelector(".pl-branch");
      if (bt) bt.setAttribute("aria-expanded", "false");
    });
  }

  els.rows.addEventListener("mouseover", function (e) {
    var tr = e.target.closest && e.target.closest(".pl-row");
    if (tr && !state.pinned) showDetail(tr, false);
  });
  els.chart.addEventListener("mouseleave", function () { hideDetail(false); });
  els.rows.addEventListener("focusin", function (e) {
    var tr = e.target.closest && e.target.closest(".pl-row");
    if (tr) { state.pinned = null; showDetail(tr, false); }
  });
  els.rows.addEventListener("focusout", function (e) {
    if (!e.relatedTarget || !els.rows.contains(e.relatedTarget)) hideDetail(true);
  });
  els.rows.addEventListener("click", function (e) {
    var bt = e.target.closest && e.target.closest(".pl-branch");
    if (!bt) return;
    var tr = bt.closest(".pl-row");
    if (state.pinned === tr.getAttribute("data-branch")) { hideDetail(true); return; }
    showDetail(tr, true);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && !els.detail.hidden) hideDetail(true);
  });

  // One framework cell. Green ("supported") needs two things: the framework announced the line
  // (Laravel's support table) AND CREED's own test of the official skeleton passed on it.
  // "announced-untested": announced, our test is missing, failed or was refused. "allowed":
  // Composer would install it, Laravel has not announced it. "unknown": Composer would install
  // it and there is no announcement a server can read (CakePHP, Slim, or Laravel's table this
  // refresh). "no": require.php refuses it. Every mark differs in shape as well as color: a
  // filled disc, a solid ring, a dashed ring, a dashed square with a question mark, a cross.
  var FIT = {
    supported: { cls: "pl-yes", label: "Supported: announced and passed our test" },
    "announced-untested": { cls: "pl-fit-ann", label: "Announced, not passed our test yet" },
    allowed: { cls: "pl-maybe", label: "Allowed by Composer, support not announced" },
    unknown: { cls: "pl-fit-unk", text: "?", label: "Allowed by Composer, support not published in a readable form" },
    no: { cls: "pl-no-fit", text: "\u00d7", label: "Not allowed" }
  };

  function fitOf(f, c) {
    if (f.fit && Object.prototype.hasOwnProperty.call(f.fit, c) && FIT[f.fit[c]]) return f.fit[c];
    // A feed from before announcements were read: what Composer allows, and no claim past it.
    var v = f.allows ? f.allows[c] : null;
    return v === true ? "unknown" : v === false ? "no" : null;
  }

  // What our own test said about this cell, in words: the title and the screen reader both get it.
  function ourTestText(f, c) {
    var t = f.our_test ? f.our_test[c] : null;
    if (!t) return "our test: not run";
    var day = t.date ? ", " + t.date : "";
    var on = "PHP " + (t.php_version || c) + (t.version ? ", " + f.name + " " + t.version : "");
    if (t.state === "pass") return "our test: pass on " + on + day;
    if (t.state === "pass-deprecations") return "our test: pass with deprecations on " + on + day;
    // A failure's first line ends in its own punctuation, so the date goes before it.
    var when = t.date ? "our test, " + t.date + ": " : "our test: ";
    if (t.state === "refused") return when + "Composer refused PHP " + (t.php_version || c) + (t.first_error ? ": " + t.first_error : "");
    return when + "fail at " + (t.step || "a step") + (t.first_error ? ": " + t.first_error : "");
  }

  function cellText(f, c, key) {
    var k = FIT[key];
    var words = k ? k.label : "Unknown: no require.php to read";
    if (key === "unknown" && f.announced_ok === false && f.announced_reason) words += " (" + f.name + "'s support table not read: " + f.announced_reason + ")";
    return "PHP " + c + ": " + words + ". " + ourTestText(f, c).replace(/^our/, "Our");
  }

  function renderFit(doc) {
    clear(els.fit);
    var fw = doc.frameworks || [];
    var cols = supported(doc).map(function (b) { return b.branch; });
    if (!fw.length || !cols.length) {
      els.fit.appendChild(h("p", { class: "pl-card__empty", text: state.mode === "fallback" || state.mode === "down"
        ? "Framework fit needs the server feed (Packagist, Laravel's support table and our own framework tests are read there)."
        : "Packagist could not be read." }));
      return;
    }
    var table = h("table", { class: "pl-fit" }, [
      h("caption", { class: "wsu-sr-only", text: "For each supported PHP branch: whether each framework's newest release allows it (require.php on Packagist), whether the framework announced it (Laravel's support table; CakePHP and Slim publish none a server can read), and what CREED's own test of the official skeleton said." }),
      h("thead", null, [h("tr", null, [h("th", { scope: "col", text: "Release" })].concat(cols.map(function (c) { return h("th", { scope: "col", text: c }); })))])
    ]);
    var tb = h("tbody");
    var legacy = [];
    var policy = null;
    fw.forEach(function (f) {
      var old = f.lifecycle === "legacy";
      // CakePHP and Slim sit under one "Legacy: being phased out" band; their fit is read like Laravel's.
      if (old && !legacy.length) {
        tb.appendChild(h("tr", { class: "pl-fit__group" }, [h("td", { colspan: String(cols.length + 1) }, [
          h("span", { class: "pl-legacy", text: "Legacy: being phased out" }),
          " Don't start new apps on CakePHP or Slim. New apps use raw PHP or Laravel (",
          h("a", { href: "../handbook/code.html#frameworks", text: "D-124" }),
          "; ",
          h("a", { href: "https://booster.apps.wayne.edu/docs/guides/framework-hops.html", title: "Booster: framework hops guide", text: "framework hops" }),
          ")."
        ])]));
      }
      if (old && legacy.indexOf(f.name) === -1) legacy.push(f.name);
      var read = f.announced_ok === true;
      if (f.announced_link && !policy) policy = f.announced_link;
      var srcTitle = read
        ? f.name + " " + f.major + ": support policy announces PHP " + (f.announced_lines || []).join(", ") + (f.announced_fetched_at ? " (read " + ago(f.announced_fetched_at) + ")" : "")
        : f.name + " " + f.major + ": support table not read" + (f.announced_reason ? " (" + f.announced_reason + ")" : "");
      var tr = h("tr", { class: old ? "pl-fit__legacy" : null }, [h("th", { scope: "row" }, [
        h("span", { class: "pl-fw" }, [
          h("a", { href: f.url, text: f.name + " " + f.major }),
          f.announced_link ? h("a", { class: "pl-fw__src" + (read ? "" : " pl-fw__src--unread"), href: f.announced_link, title: srcTitle, "aria-label": srcTitle, text: "policy" }) : null
        ]),
        old ? h("span", { class: "wsu-sr-only", text: ", legacy, being phased out" }) : null,
        h("span", { class: "pl-fw__req", title: "require.php of " + f.version, text: f.version + " · " + (f.php || "no php constraint") }),
        f.announced_ok === false ? h("span", { class: "pl-fw__note", text: "support table not read, see below" }) : null
      ])]);
      cols.forEach(function (c) {
        var key = fitOf(f, c);
        var k = FIT[key];
        var sr = cellText(f, c, key);
        var cell = k
          ? h("span", { class: k.cls, "aria-hidden": "true", title: sr, text: k.text || null })
          : h("span", { class: "pl-unknown", "aria-hidden": "true", title: sr, text: "?" });
        tr.appendChild(h("td", null, [cell, h("span", { class: "wsu-sr-only", text: sr })]));
      });
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    els.fit.appendChild(table);
    // A source that could not be used is named under the table, with its reason, in plain words.
    var why = [];
    var said = {};
    fw.forEach(function (f) {
      if (f.announced_ok !== false) return;
      var line = f.name + "'s support table could not be used" + (f.announced_reason ? ": " + f.announced_reason : "") + ". Those cells show unknown, never supported.";
      if (!said[line]) { said[line] = true; why.push(line); }
    });
    var tests = doc.framework_tests;
    if (tests && tests.ok === false) {
      why.push("CREED's own test results could not be used" + (tests.reason ? ": " + tests.reason : "") + ". Every cell says our test was not run, so none is supported.");
    }
    if (why.length) {
      els.fit.appendChild(h("ul", { class: "pl-fit__why", "aria-label": "Sources the framework fit could not use" }, why.map(function (t) { return h("li", { text: t }); })));
    }
    // The key links Laravel's table on the branch the feed read, when it read one.
    var link = document.querySelector("[data-pl-laravel-policy]");
    if (link && policy) link.setAttribute("href", policy);
  }
  /* ------------------------------------------------------------ timelines */

  var TL_STATE = { active: "Active", security: "Security only", eol: "End of life", planned: "Planned", future: "To come", unknown: "Dates not on php.net" };

  function renderTimelines(doc) {
    if (!els.tl) return;
    // A poll redraws everything; whatever the reader opened stays open.
    var open = {};
    Array.prototype.forEach.call(root.querySelectorAll("details[data-tl-key]"), function (d) { if (d.open) open[d.getAttribute("data-tl-key")] = true; });
    if (els.tlOlderWrap && els.tlOlderWrap.open) open.older = true;
    clear(els.tl);
    clear(els.tlOlder);
    var list = doc.timelines || [];
    if (!list.length) {
      els.tl.appendChild(h("p", { class: "pl-card__empty", text: state.mode === "fallback" || state.mode === "down"
        ? "Release timelines need the server feed: the php.net wiki is read there."
        : "No timetable could be read yet." }));
      if (els.tlOlderWrap) els.tlOlderWrap.hidden = true;
      return;
    }
    var lines = {};
    (doc.upcoming || []).forEach(function (u) { if (u.status === "no-timeline") lines[u.branches[0]] = u; });
    var older = 0;
    var toCome = null;
    list.forEach(function (t) {
      if (t.state === "future" && t.timetable === "none" && lines[t.branch]) {
        // Lines with nothing published stack in one cell, so they do not leave a row of empty paper.
        if (!toCome) { toCome = h("div", { class: "pl-tl__tocome" }); els.tl.appendChild(toCome); }
        toCome.insertBefore(lineCard(t, lines[t.branch], doc), toCome.firstChild); // soonest on top: 8.7, then 9.0
        return;
      }
      var card = releaseCard(t, open);
      if (t.state === "eol" || t.state === "unknown") { els.tlOlder.appendChild(card); older++; }
      else els.tl.appendChild(card);
    });
    if (els.tlOlderWrap) {
      els.tlOlderWrap.hidden = !older;
      if (open.older) els.tlOlderWrap.open = true;
      els.tlOlderCount.textContent = String(older);
    }
    reveal();
  }

  function lineCard(t, x, doc) {
    var c = lineCopy(x);
    var watch = watchLine(x, x.checked_at || doc.generated_at);
    return h("article", { class: "pl-rel pl-rel--line", "aria-labelledby": "tl-" + t.branch.replace(".", "-") }, [
      h("header", { class: "pl-rel__head" }, [
        h("h3", { class: "pl-rel__v", id: "tl-" + t.branch.replace(".", "-"), text: "PHP " + t.branch }),
        h("span", { class: "pl-state pl-state--planned", text: c.tag })
      ]),
      h("p", { class: "pl-rel__none", text: "No timeline released yet." }),
      h("p", { class: "pl-line__why", text: c.why }),
      watch
    ]);
  }

  function releaseCard(t, open) {
    var today = todayStr();
    var id = "tl-" + t.branch.replace(".", "-");
    var steps = (t.steps || []).map(function (s) { return Object.assign({}, s, { done: !s.skipped && s.date <= today }); });
    var gaDone = t.released ? t.released <= today : steps.some(function (s) { return s.kind === "ga" && s.done; });
    var first = steps.length ? steps[0].date : t.released;
    var last = t.security_until || (steps.length ? steps[steps.length - 1].date : null);
    var stateCls = t.state === "future" ? "planned" : t.state;

    var head = h("header", { class: "pl-rel__head" }, [
      h("h3", { class: "pl-rel__v", id: id, text: "PHP " + t.branch }),
      h("span", { class: "pl-state pl-state--" + stateCls, text: TL_STATE[t.state] || t.state }),
      first ? h("span", { class: "pl-rel__span", text: first + (last && last !== first ? " to " + last : "") }) : null
    ]);
    var card = h("article", { class: "pl-rel pl-rel--" + t.state, "aria-labelledby": id }, [head]);

    var phase = phaseBar(t, first, last, today);
    if (phase) card.appendChild(phase);

    // Before GA: every step in view. After: the pre-release run folds away; GA and support stay.
    var pre = steps.filter(function (s) { return gaDone && (!t.released || s.date < t.released) && s.kind !== "active-end" && s.kind !== "security-end" && s.kind !== "ga"; });
    var main = steps.filter(function (s) { return pre.indexOf(s) === -1; });
    var nextIdx = -1;
    main.forEach(function (s, i) { if (nextIdx === -1 && !s.done && !s.skipped && s.date >= today) nextIdx = i; });

    if (pre.length) {
      var key = t.branch + ":pre";
      var shipped = pre.filter(function (s) { return !s.skipped && s.kind !== "note" && s.kind !== "freeze"; });
      var sum = (shipped.length ? shipped[0].label + " to " + shipped[shipped.length - 1].label : "Pre-release") +
        ": " + pre.length + " step" + (pre.length === 1 ? "" : "s") + " from " + pre[0].date;
      card.appendChild(h("details", { class: "pl-rel__pre", "data-tl-key": key, open: !!open[key] }, [
        h("summary", { text: sum }),
        stepList(pre, -1)
      ]));
    }
    if (main.length) card.appendChild(stepList(main, nextIdx));
    if (t.timetable !== "published") {
      card.appendChild(h("p", { class: "pl-rel__fine", text: {
        "no-dates": "The wiki page for this release has no dated timetable.",
        "not-read": "The wiki timetable for this release has not been read yet; closed releases are read a few at a time.",
        none: "The php.net wiki has no timetable page for this release."
      }[t.timetable] || "" }));
    }

    card.appendChild(patchLine(t));

    var foot = h("p", { class: "pl-rel__foot" });
    if (t.page) foot.appendChild(h("a", { href: t.page, text: "Timetable on the php.net wiki" }));
    if (t.dates_from) foot.appendChild(h("span", { text: (t.page ? " · " : "") + "support dates from " + t.dates_from }));
    if (foot.firstChild) card.appendChild(foot);
    return card;
  }

  function stepList(steps, nextIdx) {
    var ol = h("ol", { class: "pl-steps" });
    steps.forEach(function (s, i) {
      var cls = "pl-step pl-step--" + s.kind + (s.skipped ? " is-skipped" : s.done ? " is-done" : " is-ahead") + (i === nextIdx ? " is-next" : "");
      var status = s.skipped ? "skipped" : s.done ? "done" : i === nextIdx ? "next" : "ahead";
      var body = [
        h("span", { class: "pl-step__label" }, [s.skipped ? h("s", { text: s.label }) : s.label]),
        h("span", { class: "wsu-sr-only", text: ", " + status + "." })
      ];
      if (i === nextIdx) body.push(h("span", { class: "pl-step__next", "aria-hidden": "true", text: "next" }));
      // A link whose words are already in the note ("see explanation") becomes those words; the rest sit after it.
      var rest = [];
      if (s.note) {
        var note = h("span", { class: "pl-step__note" });
        var text = s.note;
        var inline = [];
        (s.links || []).forEach(function (l) {
          var at = l.label ? text.lastIndexOf(l.label) : -1;
          if (at === -1 || inline.some(function (x) { return x.at === at; })) { rest.push(l); return; }
          inline.push({ at: at, l: l });
        });
        inline.sort(function (x, y) { return x.at - y.at; });
        var pos = 0;
        inline.forEach(function (x) {
          if (x.at < pos) { rest.push(x.l); return; }
          note.appendChild(document.createTextNode(text.slice(pos, x.at)));
          note.appendChild(h("a", { href: x.l.url, text: x.l.label }));
          pos = x.at + x.l.label.length;
        });
        note.appendChild(document.createTextNode(text.slice(pos)));
        body.push(note);
      } else {
        rest = s.links || [];
      }
      var links = [];
      if (s.announce) links.push(h("a", { href: s.announce, text: "Announce" }));
      rest.forEach(function (l) { links.push(h("a", { href: l.url, text: l.label })); });
      if (links.length) body.push(h("span", { class: "pl-step__links" }, links));
      ol.appendChild(h("li", { class: cls }, [
        h("time", { class: "pl-step__d", datetime: s.date, text: s.date }),
        h("span", { class: "pl-step__dot", "aria-hidden": "true" }),
        h("span", { class: "pl-step__body" }, body)
      ]));
    });
    return ol;
  }

  /* One thin bar per release: pre-release, active, security, the same inks as the ledger. */
  function phaseBar(t, first, last, today) {
    if (!first || !last || last <= first) return null;
    var lo = dayMs(first), hi = dayMs(last);
    var pct = function (d) { return Math.max(0, Math.min(100, (dayMs(d) - lo) / (hi - lo) * 100)); };
    var track = h("div", { class: "pl-phase", "aria-hidden": "true" });
    var ga = t.released || null;
    if (!ga) (t.steps || []).forEach(function (s) { if (!ga && s.kind === "ga" && !s.skipped) ga = s.date; });
    if (ga && ga > first) track.appendChild(bar("pl-phase__pre", 0, pct(ga)));
    if (t.released && t.active_until) track.appendChild(bar("pl-phase__active", pct(t.released), pct(t.active_until)));
    if (t.active_until && t.security_until) track.appendChild(bar("pl-phase__security", pct(t.active_until), pct(t.security_until)));
    if (ga && !t.security_until) {
      var gm = h("span", { class: "pl-phase__ga" });
      gm.style.setProperty("left", pct(ga) + "%");
      track.appendChild(gm);
    }
    if (today >= first && today <= last) {
      var tl = h("span", { class: "pl-phase__today" });
      tl.style.setProperty("left", pct(today) + "%");
      track.appendChild(tl);
    }
    return track;
  }

  function patchLine(t) {
    var p = t.patches || {};
    var wrap = h("div", { class: "pl-rel__patches" });
    var dated = p.dated || [];
    if (dated.length || p.tagged) {
      var line = h("p", { class: "pl-rel__dated" }, [h("span", { class: "pl-rel__k", text: "Patches" })]);
      dated.forEach(function (d, i) {
        line.appendChild(h("span", { class: "pl-rel__patch" + (d.security ? " is-security" : "") }, [
          h("strong", { text: d.version }), " " + (d.date || "date not given") + (d.security ? ", security" : "")
        ]));

      });
      if (p.tagged) line.appendChild(h("span", { class: "pl-rel__tagged", text: p.tagged + " tagged in php-src" + (p.first && p.last ? " (" + p.first + " to " + p.last + ")" : "") }));
      wrap.appendChild(line);
    }
    if (t.state === "active" || t.state === "security") {
      if (p.next) {
        var src = (p.next.evidence || []).filter(function (e) { return e.url; })[0];
        wrap.appendChild(h("p", { class: "pl-rel__nextpatch" }, [
          "Next patch " + (p.next.version ? p.next.version + " " : "") + statusLabel(p.next.status) + ": ",
          h("strong", { text: fmtDay(p.next.day) + (p.next.start ? ", " + fmtTime(p.next.start) : "") }),
          src ? " " : null,
          src ? h("a", { href: src.url, text: "notice" }) : null
        ]));
      } else {
        wrap.appendChild(h("p", { class: "pl-rel__quiet", text: "No date is published for the next patch. php.net announces each one shortly before it ships." }));
      }
    }
    return wrap;
  }

  /* Cards settle in as they scroll into view, once. Everyone else gets them printed. */
  var seenCards = typeof WeakSet === "function" ? new WeakSet() : null;
  var io = "IntersectionObserver" in window && document.documentElement.classList.contains("motion")
    ? new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (!e.isIntersecting) return;
          e.target.classList.add("is-in");
          io.unobserve(e.target);
        });
      }, { rootMargin: "0px 0px -8% 0px" })
    : null;

  function reveal() {
    Array.prototype.forEach.call(root.querySelectorAll(".pl-rel"), function (c, i) {
      if (!io || (seenCards && seenCards.has(c))) { c.classList.add("is-in"); return; }
      if (seenCards) seenCards.add(c);
      if (!state.first) { c.classList.add("is-in"); return; }
      c.style.setProperty("--k", String(i % 6));
      c.classList.add("is-waiting");
      io.observe(c);
    });
  }

  function sourceStatus(s) { return s.ok && !s.stale ? "ok" : s.ok_at ? "stale" : "down"; }

  function renderSources(doc) {
    clear(els.sources);
    clear(els.sourcesAll);
    clear(els.notes);
    var groups = {};
    var order = [];
    (doc.sources || []).forEach(function (s) {
      if (!groups[s.group]) { groups[s.group] = []; order.push(s.group); }
      groups[s.group].push(s);
    });
    var names = { "php.net": "php.net", "php-src": "php-src", wiki: "php.net wiki", "cross-check": "endoflife.date", frameworks: "Packagist", announced: "Laravel support table" };
    var rank = { ok: 0, stale: 1, down: 2 };
    order.forEach(function (g) {
      var list = groups[g];
      // The wiki answers "no page yet" for a branch nobody has planned; that is an answer, so ok.
      var worst = list.reduce(function (w, s) { var st = sourceStatus(s); return rank[st] > rank[w] ? st : w; }, "ok");
      var newest = list.map(function (s) { return s.ok_at || ""; }).sort().pop();
      els.sources.appendChild(h("li", null, [
        h("span", { class: "pl-dot pl-dot--" + worst, "aria-hidden": "true" }),
        h("a", { href: list[0].url, text: names[g] || g }),
        " " + (worst === "down" ? "down" : (worst === "stale" ? "stale, read " : "read ") + ago(newest))
      ]));
    });
    (doc.sources || []).forEach(function (s) {
      var st = sourceStatus(s);
      els.sourcesAll.appendChild(h("li", null, [
        h("span", { class: "pl-dot pl-dot--" + st, "aria-hidden": "true" }),
        h("a", { href: s.url, text: s.label }),
        " " + (st === "ok" ? "read " + ago(s.ok_at) : st === "stale" ? "stale, last read " + ago(s.ok_at) + (s.error ? " (" + s.error + ")" : "") : "down" + (s.error ? " (" + s.error + ")" : ""))
      ]));
    });
    (doc.notes || []).forEach(function (n) { els.notes.appendChild(h("li", { text: n })); });
    doc.branches.forEach(function (b) {
      (b.disagreements || []).forEach(function (d) {
        els.notes.appendChild(h("li", { text: "PHP " + b.branch + ": sources disagree on " + d.label + " (php.net " + d.php_net + ", endoflife.date " + d.endoflife + "). The page shows php.net's." }));
      });
    });
  }

  /* ------------------------------------------------------------ run */

  load();
  setInterval(load, POLL_MS);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible" && state.doc && Date.now() - Date.parse(state.doc.generated_at) > POLL_MS) load();
  });
  // Keep "N min ago" honest between polls.
  setInterval(function () { if (state.doc) { renderStatus(state.doc, state.mode); renderSources(state.doc); } }, 60000);
  window.addEventListener("resize", function () { if (!els.detail.hidden) hideDetail(true); });
})();
