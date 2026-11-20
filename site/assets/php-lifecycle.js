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
      scheduled: "on the wiki schedule"
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
            branches: branches, upcoming: upcoming, frameworks: [], sources: sources,
            notes: ["The server feed did not answer, so php.net, its news feed, the wiki and Packagist were not read. endoflife.date can be about a day behind php.net."]
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
      msg = "The server feed did not answer, so this page read endoflife.date and php-src's tags straight from your browser. php.net's own pages, its news feed (where upcoming security releases are announced) and the framework fit need the server feed.";
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
    nx.appendChild(h("p", { class: "pl-card__label", text: "Next known release" }));
    var up = orderUpcoming(doc.upcoming || []);
    if (!up.length) {
      nx.appendChild(h("p", { class: "pl-next__what", text: "Nothing announced or tagged" }));
      nx.appendChild(h("p", { class: "pl-sub", text: state.mode === "fallback"
        ? "In fallback mode only php-src's tags are read; php.net's notices need the server feed."
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
    var evs = h("ul", { class: "pl-ev", "aria-label": "Evidence" });
    evs.appendChild(h("li", { class: "pl-ev--" + u.status, text: statusLabel(u.status) }));
    (u.evidence || []).forEach(function (e) {
      if (!e.url) return;
      evs.appendChild(h("li", null, [h("a", { href: e.url, text: e.source })]));
    });
    nx.appendChild(evs);
    if (rest.length) {
      // Same kind, status and day read as one entry.
      var lines = [];
      rest.forEach(function (x) {
        var tail = " (" + statusLabel(x.status) + (x.day ? ", " + x.day : "") + ")";
        var name = x.kind === "security" ? "security releases for " + branchList(x.branches) : (x.version || ("PHP " + branchList(x.branches)));
        var last = lines[lines.length - 1];
        if (last && x.kind === "patch" && last.kind === "patch" && last.tail === tail) last.names.push(name);
        else lines.push({ kind: x.kind, tail: tail, names: [name] });
      });
      nx.appendChild(h("p", { class: "pl-sub", text: "Also: " + lines.slice(0, 3).map(function (l) { return l.names.join(", ") + l.tail; }).join("; ") + (lines.length > 3 ? "; and more" : "") }));
    }
  }

  /* Dated first (soonest first), then a new branch, then everything else by strength of evidence. */
  function orderUpcoming(list) {
    var kind = { security: 0, branch: 1, patch: 2 };
    var status = { due: 0, announced: 1, tagged: 2, scheduled: 3, candidate: 4, development: 5 };
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

  function renderFit(doc) {
    clear(els.fit);
    var fw = doc.frameworks || [];
    var cols = supported(doc).map(function (b) { return b.branch; });
    if (!fw.length || !cols.length) {
      els.fit.appendChild(h("p", { class: "pl-card__empty", text: state.mode === "fallback" || state.mode === "down"
        ? "Framework fit needs the server feed (Packagist is read there)."
        : "Packagist could not be read." }));
      return;
    }
    var table = h("table", { class: "pl-fit" }, [
      h("caption", { class: "wsu-sr-only", text: "Which supported PHP branches each framework's newest releases allow, from require.php on Packagist." }),
      h("thead", null, [h("tr", null, [h("th", { scope: "col", text: "Release" })].concat(cols.map(function (c) { return h("th", { scope: "col", text: c }); })))])
    ]);
    var tb = h("tbody");
    fw.forEach(function (f) {
      var tr = h("tr", null, [h("th", { scope: "row" }, [
        h("span", { class: "pl-fw" }, [h("a", { href: f.url, text: f.name + " " + f.major })]),
        h("span", { class: "pl-fw__req", title: "require.php of " + f.version, text: f.version + " · " + (f.php || "no php constraint") })
      ])]);
      cols.forEach(function (c) {
        var v = f.allows ? f.allows[c] : null;
        var cell = v === true
          ? h("span", { class: "pl-yes", "aria-hidden": "true" })
          : v === false ? h("span", { class: "pl-no-fit", "aria-hidden": "true", text: "×" }) : h("span", { class: "pl-unknown", "aria-hidden": "true", text: "?" });
        tr.appendChild(h("td", null, [cell, h("span", { class: "wsu-sr-only", text: v === true ? "allows PHP " + c : v === false ? "does not allow PHP " + c : "unknown for PHP " + c })]));
      });
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    els.fit.appendChild(table);
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
    var names = { "php.net": "php.net", "php-src": "php-src tags", wiki: "php.net wiki", "cross-check": "endoflife.date", frameworks: "Packagist" };
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
