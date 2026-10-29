/* CREED handbook pages. Small vanilla JS, no build step.

   1. the Chapters drawer (narrow screens)   4. back to top
   2. "On this page": the section you are in  5. § links copy themselves
   3. search across every chapter (MiniSearch, over search-index.js)

   All of it is a nicety. With the script off every chapter, heading and link
   is still there: the chapter list sits above the page and the search simply
   is not offered. Creed Bratton would call that a solid alibi. */
(function () {
  "use strict";

  var root = document.documentElement;
  root.classList.add("js");
  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };
  var head = $(".hb-runhead");

  function headHeight() { return head ? head.getBoundingClientRect().height : 0; }
  function syncHead() { root.style.setProperty("--hb-head-live", Math.round(headHeight()) + "px"); }
  syncHead();
  window.addEventListener("resize", syncHead);

  /* --------------------------------------------------- toast */
  var toast = document.createElement("div");
  toast.className = "toast";
  toast.setAttribute("role", "status");
  toast.setAttribute("aria-live", "polite");
  document.body.appendChild(toast);
  var toastTimer;
  function say(text) {
    toast.textContent = text;
    toast.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }

  /* ---------------------------------------- 1. the Chapters drawer */
  var menu = $("[data-hb-menu]");
  var nav = $("[data-hb-nav]");
  var narrow = window.matchMedia("(max-width: 999px)");
  if (menu && nav) {
    menu.hidden = false;
    // While the sheet is open on a narrow screen it covers the page, so the
    // page behind it is inert: no stray focus, no clicks through the paper.
    var behind = $$(".hb-main, .colophon");
    var setOpen = function (open, refocus) {
      nav.classList.toggle("is-open", open);
      behind.forEach(function (el) { el.inert = open && narrow.matches; });
      menu.setAttribute("aria-expanded", open ? "true" : "false");
      if (open) {
        syncHead();
        var first = $("[aria-current=page]", nav) || $("a", nav);
        if (first) first.focus();
      } else if (refocus) {
        menu.focus();
      }
    };
    menu.addEventListener("click", function () { setOpen(!nav.classList.contains("is-open"), true); });
    nav.addEventListener("keydown", function (e) {
      if (e.key === "Escape") { e.preventDefault(); setOpen(false, true); }
    });
    nav.addEventListener("click", function (e) {
      if (e.target.closest && e.target.closest("a") && narrow.matches) setOpen(false, false);
    });
    // Tab out of the sheet, or click past it, and it folds away.
    nav.addEventListener("focusout", function (e) {
      if (!narrow.matches || !nav.classList.contains("is-open")) return;
      if (e.relatedTarget && !nav.contains(e.relatedTarget) && e.relatedTarget !== menu) setOpen(false, false);
    });
    document.addEventListener("click", function (e) {
      if (nav.classList.contains("is-open") && !nav.contains(e.target) && !menu.contains(e.target)) setOpen(false, false);
    });
    narrow.addEventListener("change", function () { setOpen(false, false); });
  }

  /* ------------------------------- 2. the section you are in */
  var prose = $("[data-hb-prose]");
  var marks = $$("[data-toc]");
  if (prose && marks.length) {
    var headings = $$("h2[id], h3[id]", prose);
    var byId = {};
    marks.forEach(function (a) {
      var id = a.getAttribute("data-toc");
      (byId[id] = byId[id] || []).push(a);
    });
    var current = null;
    var spy = function () {
      var line = headHeight() + 24;
      var here = null;
      for (var i = 0; i < headings.length; i++) {
        if (headings[i].getBoundingClientRect().top - line <= 0) here = headings[i].id; else break;
      }
      // At the very bottom the last section is the one you are reading.
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4 && headings.length) {
        here = headings[headings.length - 1].id;
      }
      // An h3 lights its h2 in the sidebar, which lists h2s only.
      if (here === current) return;
      current = here;
      marks.forEach(function (a) { a.classList.remove("is-here"); a.removeAttribute("aria-current"); });
      if (!here) return;
      var parentH2 = null;
      for (var j = 0; j < headings.length && headings[j].id !== here; j++) {
        if (headings[j].tagName === "H2") parentH2 = headings[j].id;
      }
      if ($("#" + CSS.escape(here)).tagName === "H2") parentH2 = here;
      (byId[here] || []).concat(here !== parentH2 && parentH2 ? (byId[parentH2] || []).filter(function (a) { return a.closest(".hb-nav"); }) : [])
        .forEach(function (a) { a.classList.add("is-here"); a.setAttribute("aria-current", "true"); });
    };
    var ticking = false;
    window.addEventListener("scroll", function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () { ticking = false; spy(); });
    }, { passive: true });
    spy();
  }

  /* --------------------------------------------- 4. back to top */
  var top = $("[data-hb-top]");
  if (top) {
    var showTop = function () { top.classList.toggle("is-on", window.scrollY > 700); };
    window.addEventListener("scroll", showTop, { passive: true });
    showTop();
    top.addEventListener("click", function (e) {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: root.classList.contains("motion") ? "smooth" : "auto" });
      var main = $("#main");
      if (main) main.focus({ preventScroll: true });
      history.replaceState(null, "", location.pathname + location.search);
    });
  }

  /* ----------------------------------- 5. § links copy themselves */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return Promise.reject(new Error("no clipboard"));
  }
  $$(".hb-sec").forEach(function (a) {
    a.addEventListener("click", function () {
      var url = location.href.split("#")[0] + a.getAttribute("href");
      copyText(url).then(function () { say("Link to " + a.textContent + " copied. Cite it."); }, function () { /* the link still works */ });
    });
  });

  /* ------------------------------------------------ 3. search */
  var form = $("[data-hb-search]");
  var docs = window.CREED_HANDBOOK_INDEX;
  if (!form || !window.MiniSearch || !docs || !docs.length) return;

  var input = $("[data-hb-q]", form);
  var panel = $("[data-hb-results]", form);
  var status = $("[data-hb-status]", form);
  var list = $("[data-hb-list]", form);

  var engine = new window.MiniSearch({
    fields: ["title", "text", "chapterTitle"],
    storeFields: ["url", "chapter", "chapterTitle", "number", "title", "text"],
    searchOptions: { boost: { title: 3, chapterTitle: 1.5 }, prefix: true, fuzzy: 0.2, combineWith: "AND" }
  });
  engine.addAll(docs);
  form.hidden = false;

  function terms(q) { return q.toLowerCase().split(/[^a-z0-9._-]+/).filter(function (t) { return t.length > 1; }); }

  /* Text with the query's words in <mark>, built as nodes, never as HTML. */
  function highlighted(text, words) {
    var frag = document.createDocumentFragment();
    if (!words.length) { frag.appendChild(document.createTextNode(text)); return frag; }
    var re = new RegExp("(" + words.map(function (w) { return w.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"); }).join("|") + ")", "ig");
    var last = 0;
    text.replace(re, function (m, _g, at) {
      if (at > last) frag.appendChild(document.createTextNode(text.slice(last, at)));
      var mark = document.createElement("mark");
      mark.textContent = m;
      frag.appendChild(mark);
      last = at + m.length;
      return m;
    });
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    return frag;
  }
  function snippet(text, words) {
    var lower = text.toLowerCase();
    var at = -1;
    for (var i = 0; i < words.length && at < 0; i++) at = lower.indexOf(words[i]);
    if (at < 0) at = 0;
    var start = Math.max(0, at - 50);
    var cut = text.slice(start, start + 170);
    return (start > 0 ? "… " : "") + cut + (start + 170 < text.length ? " …" : "");
  }

  function close() { panel.hidden = true; }
  function run() {
    var q = input.value.trim();
    if (q.length < 2) { close(); list.textContent = ""; status.textContent = ""; return; }
    var hits = engine.search(q);
    if (!hits.length) hits = engine.search(q, { combineWith: "OR" });
    hits = hits.slice(0, 8);
    var words = terms(q);
    list.textContent = "";
    hits.forEach(function (h) {
      var li = document.createElement("li");
      var a = document.createElement("a");
      a.href = h.url;
      var no = document.createElement("span");
      no.className = "hb-result__no";
      no.textContent = h.number ? "§" + h.number : "Ch. " + h.chapter;
      var title = document.createElement("span");
      title.className = "hb-result__title";
      title.appendChild(highlighted(h.title, words));
      var chapter = document.createElement("span");
      chapter.className = "hb-result__chapter";
      chapter.textContent = "Chapter " + h.chapter + " · " + h.chapterTitle;
      var snip = document.createElement("span");
      snip.className = "hb-result__snippet";
      snip.appendChild(highlighted(snippet(h.text, words), words));
      a.appendChild(no); a.appendChild(title); a.appendChild(chapter); a.appendChild(snip);
      li.appendChild(a);
      list.appendChild(li);
    });
    status.textContent = hits.length
      ? hits.length + (hits.length === 1 ? " section" : " sections") + " for “" + q + "”"
      : "Nothing in the book for “" + q + "”. Creed would make something up; the handbook won't.";
    panel.hidden = false;
  }

  var debounce;
  input.addEventListener("input", function () { clearTimeout(debounce); debounce = setTimeout(run, 80); });
  input.addEventListener("focus", function () { if (input.value.trim().length >= 2) run(); });
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var first = $("a", list);
    if (first) first.click();
  });

  function links() { return $$("a", list); }
  form.addEventListener("keydown", function (e) {
    var all = links();
    var i = all.indexOf(document.activeElement);
    if (e.key === "ArrowDown") {
      if (!all.length) return;
      e.preventDefault();
      all[Math.min(i + 1, all.length - 1)].focus();
    } else if (e.key === "ArrowUp" && i >= 0) {
      e.preventDefault();
      if (i === 0) input.focus(); else all[i - 1].focus();
    } else if (e.key === "Escape") {
      e.preventDefault();
      if (panel.hidden) { input.value = ""; } else { close(); }
      input.focus();
    }
  });
  list.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("a");
    if (!a) return;
    // A hit on this very page: no reload, so fold the panel ourselves.
    if (a.pathname === location.pathname) close();
  });
  document.addEventListener("click", function (e) { if (!form.contains(e.target)) close(); });
  form.addEventListener("focusout", function (e) { if (e.relatedTarget && !form.contains(e.relatedTarget)) close(); });

  // "/" opens the search from anywhere that is not already a text field.
  document.addEventListener("keydown", function (e) {
    if (e.key !== "/" || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target;
    if (t && (t.isContentEditable || /^(input|textarea|select)$/i.test(t.tagName))) return;
    e.preventDefault();
    input.focus();
    input.select();
  });
})();
