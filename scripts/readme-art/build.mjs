#!/usr/bin/env node
/* ============================================================================
   CREED README art kit.

   Renders the README images from this repo's own front page (site/), brand
   files and policy (policy/policy.json), and writes them under docs/readme/.
   Nothing is drawn by hand: the hero and the gallery are screenshots of the
   real page, the cast is the marks resized (WayneUI composed on its green
   tile), the badges read their facts from composer.json and policy.json, and
   the Form CR-1 certificate is filled in from real policy rows, with the
   wording the page's own inspector writes for them.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this
const FORBIDDEN = /noug[a]t|snu[.]gs|citadel/i; // internal names never ship in art

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

function assertTextClean(text, what) {
  if (FORBIDDEN.test(text)) fail(`forbidden text matched ${FORBIDDEN} in ${what}`);
}

/* --------------------------------------------------------- CREED's palette */

// Read from site/assets/creed.css, not retyped: if the book changes its ink,
// the README changes with it.
const css = readFileSync(path.join(siteDir, "assets", "creed.css"), "utf8");
function token(name) {
  const m = css.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{3,8})`));
  if (!m) fail(`creed.css has no --${name} colour`);
  return m[1];
}
const C = {
  paper: token("paper"),
  paperHi: token("paper-hi"),
  paperLo: token("paper-lo"),
  ink: token("ink"),
  ink2: token("ink-2"),
  rule: token("rule"),
  green: token("green"),
  greenDeep: token("green-deep"),
  gold: token("gold"),
  ochre: token("ochre"),
  red: token("red"),
};

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 40 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  // CREED's own mark is not in assets/marks/ (the book carries it in brand/).
  if (name === "creed") return path.join(siteDir, "brand", "creed-avatar-256.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox. The visible ink spans
// 1546 x 1551 of the 1980 x 1989 viewBox and is centred in it, so this scale
// makes the mark 352 px tall on the 512 tile (about 69%), matching the square
// marks WELD and SESAME.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  record("cast/wayneui.svg", tile);
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) fail(`cast/${name}.png: missing source ${path.relative(repoRoot, src)}`);
    const png = await sharp(readFileSync(src))
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------------------- 2. badges */

// Facts are read, never typed: composer.json for the package, policy.json for
// the policy. A badge that lies would be a violation of §1.4.
function buildBadges(policy) {
  console.log("badges:");
  const composer = JSON.parse(readFileSync(path.join(repoRoot, "composer.json"), "utf8"));
  const defs = [
    { key: "php", label: "php", message: composer.require?.php },
    { key: "license", label: "licence", message: composer.license },
    {
      key: "policy",
      label: "policy",
      message:
        Number.isInteger(policy.schema_version) && policy.components
          ? `schema v${policy.schema_version} · ${Object.keys(policy.components).length} components`
          : undefined,
    },
  ];
  for (const d of defs) {
    if (!d.message) fail(`no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: C.green,
      color: C.paper,
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, svg);
  }
}

/* ------------------------------------------------------- 3. the policy itself */

// The same answer, in the same order, as Waynestate\Creed\Policy::stateOf
// (src/Policy.php), for the rows the certificate shows. The page carries its
// own copy of the dates; this reads policy/policy.json, and the build fails if
// the two ever disagree on a row we print.
function addDays(iso, n) {
  const d = new Date(`${iso}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

function stateOf(policy, component, version, day) {
  const versions = policy.components?.[component]?.versions;
  if (!versions) fail(`policy.json has no component ${component}`);
  const entry = versions[version];
  if (!entry) fail(`policy.json has no ${component} ${version}`);
  if (entry.current === true) return "State::Active";
  if (typeof entry.security_until !== "string") fail(`${component} ${version} has no security_until`);
  if (typeof entry.active_until === "string" && day <= entry.active_until) return "State::Active";
  if (day <= entry.security_until) return "State::Security";
  if (!Number.isInteger(policy.rules?.eol_grace_days)) fail("policy.json rules lack eol_grace_days");
  if (day <= addDays(entry.security_until, policy.rules.eol_grace_days)) return "State::Eol";
  return "State::EolGraceExpired";
}

// The day the policy was last checked: the newest latest_patch_checked in the
// file. Using it (not the wall clock) keeps the art reproducible.
function policyDay(policy) {
  const days = [];
  for (const c of Object.values(policy.components)) {
    for (const v of Object.values(c.versions)) if (v.latest_patch_checked) days.push(v.latest_patch_checked);
  }
  if (!days.length) fail("policy.json has no latest_patch_checked dates");
  return days.sort().at(-1);
}

/* ------------------------------------------------------------- 4. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2, // crisp images
    reducedMotion: "reduce", // the printed book: every stamp already inked, nothing moving
  });
  // file:// only. Anything else is a network call, and the build refuses it.
  await context.route(/^(?!file:|data:|about:)/, (route) => {
    console.error(`FAIL: blocked a network request to ${route.request().url()}`);
    process.exitCode = 1;
    return route.abort();
  });
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, { waitUntil: "load" });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

async function assertClean(page, selector, what) {
  const text = await page.locator(selector).innerText();
  assertTextClean(text, what);
}

/* ------------------------------------------------------------------ 5. hero */

async function buildHero(page) {
  console.log("hero:");
  // The running head, the binding strip and the skip link are page chrome.
  await page.addStyleTag({
    content: ".runhead, .binding, .thumbs, .wsu-skip-link { display: none !important; }",
  });
  await assertClean(page, ".title-page", "hero");
  const png = await page.locator(".title-page").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("hero.jpg", jpg);
}

/* -------------------------------------------------- 6. Form CR-1, inspected */

// Four inspections, in the order a version lives them: due for a move, in
// grace, past grace, and a pass. Each is a real row of policy.json, on the day
// the policy was last checked, plus one day the page's own chips offer.
function inspections(day) {
  return [
    { c: "php", v: "8.2", day },
    { c: "php", v: "8.2", day: "2027-01-01" },
    { c: "php", v: "8.1", day },
    { c: "laravel", v: "13", day },
  ];
}

// Fill the page's own Form CR-1 and read the certificate it writes. The
// finding, the clause and the stamp's words are the page's; the verdict is
// checked against policy.json.
async function inspectOnPage(page, policy, list) {
  const chips = await page.$$eval("[data-day]", (els) => els.map((e) => e.getAttribute("data-day")));
  const results = [];
  for (const it of list) {
    if (it.day !== list[0].day && !chips.includes(it.day)) fail(`the page offers no chip for ${it.day}`);
    await page.check(`input[name=component][value="${it.c}"]`);
    await page.selectOption("[data-inspect-version]", it.v);
    await page.fill("[data-inspect-date]", it.day);
    await page.dispatchEvent("[data-inspect-date]", "change");
    await page.click(".btn--stamp");
    const r = await page.evaluate(() => {
      const t = (s) => document.querySelector(s).textContent.trim();
      return {
        runtime: document.querySelector("input[name=component]:checked").parentElement.textContent.trim(),
        serial: t("[data-cert-serial]"),
        what: t("[data-cert-what]"),
        dayOut: t("[data-cert-day]"),
        call: t("[data-cert-call]"),
        returns: t("[data-cert-returns]"),
        finding: t("[data-cert-finding]"),
        clause: t("[data-cert-clause]"),
        sign: t(".certificate__line"),
        state: document.querySelector("[data-inkstamp]").className.replace(/.*inkstamp--(\S+).*/, "$1"),
        top: t("[data-ink-top]"),
        word: t("[data-ink-word]"),
        sub: t("[data-ink-sub]"),
      };
    });
    const want = stateOf(policy, it.c, it.v, it.day);
    if (r.returns !== want) fail(`the page says ${r.returns} for ${it.c} ${it.v} on ${it.day}; policy.json says ${want}`);
    for (const v of Object.values(r)) assertTextClean(v, "Form CR-1");
    results.push({ ...it, ...r });
  }
  return results;
}

function esc(s) {
  return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// Wrap text with the page's own fonts, measured by the browser, so the lines
// break where the type actually runs out of room.
async function wrapper(page) {
  return async (text, font, width) =>
    page.evaluate(
      async ({ text, font, width }) => {
        await document.fonts.load(font);
        const ctx = document.createElement("canvas").getContext("2d");
        ctx.font = font;
        const lines = [];
        let line = "";
        for (const word of text.split(/\s+/)) {
          const next = line ? `${line} ${word}` : word;
          if (line && ctx.measureText(next).width > width) {
            lines.push(line);
            line = word;
          } else line = next;
        }
        if (line) lines.push(line);
        return lines;
      },
      { text, font, width },
    );
}

function fontFaces() {
  const f = (file) => readFileSync(path.join(siteDir, "assets", "fonts", file)).toString("base64");
  const face = (family, weight, file) =>
    `@font-face { font-family: "${family}"; font-weight: ${weight}; font-style: normal; src: url(data:font/woff2;base64,${f(file)}) format("woff2"); }`;
  return [
    face("CR Serif", 400, "source-serif-4-latin-400-normal.woff2"),
    face("CR Serif", 700, "source-serif-4-latin-700-normal.woff2"),
    face("CR Mono", 400, "ibm-plex-mono-latin-400-normal.woff2"),
    face("CR Mono", 600, "ibm-plex-mono-latin-600-normal.woff2"),
  ].join("\n    ");
}

const STAMP_INK = { active: "green", security: "ochre", eol: "red", eol_grace_expired: "red", refused: "ink" };
const STAMP_ROT = { active: -7, security: -9, eol: -11, eol_grace_expired: -6, refused: -8 }; // as creed.css

// IBM Plex Mono advances every glyph 600/1000 em: widths are arithmetic.
function monoWidth(text, size, tracking) {
  return text.length * (0.6 * size + tracking) - tracking;
}

async function buildCertificateSvg(results, wrap, kicker, chips) {
  const W = 1280;
  const SLOT = 7; // seconds per inspection
  const TOTAL = SLOT * results.length;
  const pct = (t) => `${((t / TOTAL) * 100).toFixed(3)}%`;

  const serif = `"CR Serif", "Source Serif 4", Georgia, "Times New Roman", serif`;
  const mono = `"CR Mono", "IBM Plex Mono", Consolas, Menlo, monospace`;

  // Geometry: the form on the left, the certificate on the right, as on the page.
  const formX = 56, formY = 176, formW = 336, formMin = 372;
  const certX = 432, certY = 176, certW = 792;
  const padX = 34;
  const dtX = certX + padX, ddX = certX + padX + 148;
  const rowTop = certY + 76, rowH = 40;
  const findW = 400; // the stamp has the rest of the line
  const findY = rowTop + 4 * rowH + 40;

  // Break every finding and clause first: the longest one sets the form's depth.
  for (const r of results) {
    r.findLines = await wrap(r.finding, `400 19px "Source Serif 4"`, findW);
    r.clauseY = findY + r.findLines.length * 27 + 14;
    r.clauseLines = await wrap(`Applicable clause: ${r.clause}`, `400 15px "Source Serif 4"`, findW);
  }
  const lastLine = Math.max(...results.map((r) => r.clauseY + (r.clauseLines.length - 1) * 21));
  const signY = lastLine + 46;
  const certH = Math.max(formMin, signY + 34 - certY);
  const formH = certH; // the form and the certificate sit square on the desk

  // The page's "days worth inspecting" chips, under box 3, in its own order.
  const chipBoxes = [];
  {
    let x = formX + 24, y = formY + 50 + 2 * 92 + 74;
    for (const label of chips) {
      const w = monoWidth(label, 12, 0) + 18;
      if (x + w > formX + formW - 24) { x = formX + 24; y += 34; }
      chipBoxes.push({ label, x, y, w });
      x += w + 6;
    }
  }
  const chipSvg = (b, on) =>
    `<rect x="${b.x + 0.5}" y="${b.y + 0.5}" width="${b.w - 1}" height="27" rx="2" fill="${on ? C.ink : C.paper}" stroke="${on ? C.ink : C.rule}"/>` +
    `<text x="${b.x + b.w / 2}" y="${b.y + 18.5}" text-anchor="middle" font-family='${mono}' font-size="12" fill="${on ? C.paperHi : C.ink}">${esc(b.label)}</text>`;
  const H = certY + certH + 44;
  const stampCx = certX + certW - 212;
  const stampCy = (rowTop + 4 * rowH + certY + certH) / 2 + 6;

  const kf = [];
  let kfN = 0;
  // A typewriter: the clip opens one character at a time between a and b.
  const typeAnim = (a, b, chars) => {
    const name = `t${kfN++}`;
    kf.push(
      `@keyframes ${name} { 0%, ${pct(a)} { clip-path: inset(-8px 100% -8px -2px); animation-timing-function: steps(${Math.max(1, chars)}, end); } ${pct(b)}, 100% { clip-path: inset(-8px -4px -8px -2px); } }`,
    );
    return name;
  };
  const fadeAnim = (a, b) => {
    const name = `f${kfN++}`;
    kf.push(`@keyframes ${name} { 0%, ${pct(a)} { opacity: 0; } ${pct(b)}, 100% { opacity: 1; } }`);
    return name;
  };
  const anim = (name, delay) => `animation: ${name} ${TOTAL}s ${delay}s infinite both;`;

  // Shared timings within one slot, in seconds.
  const T = {
    in: [0, 0.35], f1: [0.45, 0.8], f2: [0.9, 1.15], f3: [1.25, 1.85], press: [1.95, 2.2],
    serial: [2.2, 2.35], r1: [2.3, 2.6], r2: [2.65, 3.0], r3: [3.05, 3.85], r4: [3.9, 4.25],
    find: [4.3, 4.6], clause: [4.6, 4.85], stamp: 4.9, out: [6.7, 7],
  };

  // The group: visible for its own slot only.
  const g = `@keyframes slot { 0% { opacity: 0; } ${pct(T.in[1])} { opacity: 1; } ${pct(T.out[0])} { opacity: 1; } ${pct(T.out[1])}, 100% { opacity: 0; } }`;
  kf.push(g);
  // The thunk, from creed.css: big and blurred, overshoot, settle.
  const stampDur = 0.46;
  kf.push(
    `@keyframes thunk { 0%, ${pct(T.stamp)} { opacity: 0; transform: scale(1.6) translateY(-14px); } ${pct(T.stamp + stampDur * 0.52)} { opacity: 1; transform: scale(.94); } ${pct(T.stamp + stampDur * 0.7)} { opacity: 1; transform: scale(1.03); } ${pct(T.stamp + stampDur)}, 100% { opacity: 1; transform: scale(1); } }`,
  );
  kf.push(
    `@keyframes press { 0%, ${pct(T.press[0])} { opacity: 0; } ${pct(T.press[0] + 0.05)}, ${pct(T.press[1])} { opacity: 1; } ${pct(T.press[1] + 0.05)}, 100% { opacity: 0; } }`,
  );
  // The desk jolts when the stamp lands, once per slot.
  const joltAt = (s) => [
    `${pct(s + T.stamp + stampDur * 0.48)} { transform: none; }`,
    `${pct(s + T.stamp + stampDur * 0.55)} { transform: translateY(3px); }`,
    `${pct(s + T.stamp + stampDur * 0.7)} { transform: translateY(-1px); }`,
    `${pct(s + T.stamp + stampDur)} { transform: none; }`,
  ];
  kf.push(
    `@keyframes jolt { 0% { transform: none; } ${results.flatMap((_, i) => joltAt(i * SLOT)).join(" ")} 100% { transform: none; } }`,
  );

  const groups = [];
  for (let i = 0; i < results.length; i++) {
    const r = results[i];
    const delay = i * SLOT;
    const ink = C[STAMP_INK[r.state]];
    const rot = STAMP_ROT[r.state];
    const fieldVals = [r.runtime, r.v, r.day];
    const parts = [];

    // Form fields, typed in.
    fieldVals.forEach((val, k) => {
      const y = formY + 96 + k * 92;
      const [a, b] = [T.f1, T.f2, T.f3][k];
      parts.push(
        `<text x="${formX + 28}" y="${y}" font-family='${mono}' font-size="17" fill="${C.ink}" style="${anim(typeAnim(a, b, val.length), delay)}">${esc(val)}</text>`,
      );
    });
    // The chip for this day, if the page offers one, is pressed.
    for (const b of chipBoxes) {
      if (b.label === r.day) parts.push(`<g style="${anim(fadeAnim(...T.f3), delay)}">${chipSvg(b, true)}</g>`);
    }
    // The button goes down.
    parts.push(
      `<rect x="${formX + 26}" y="${formY + formH - 72 + 3}" width="148" height="44" rx="3" fill="${C.greenDeep}" class="press" style="${anim("press", delay)}"/>`,
      `<text x="${formX + 100}" y="${formY + formH - 72 + 30}" text-anchor="middle" font-family='${mono}' font-weight="600" font-size="14" letter-spacing="0.7" fill="${C.paperHi}" class="press" style="${anim("press", delay)}">STAMP IT</text>`,
    );

    // The certificate. The page numbers every stamp it has made in this
    // session; the README's four are numbered from the page's first, No. 0001.
    parts.push(
      `<text x="${certX + certW - padX}" y="${certY + 50}" text-anchor="end" font-family='${mono}' font-size="14" fill="${C.red}" style="${anim(fadeAnim(...T.serial), delay)}">${esc(r.serial.replace(/\d+/, String(i + 1).padStart(4, "0")))}</text>`,
    );
    const rows = [
      { v: r.what, t: T.r1, f: serif, s: 19 },
      { v: r.dayOut, t: T.r2, f: serif, s: 19 },
      { v: r.call, t: T.r3, f: mono, s: 13.5 },
      { v: r.returns, t: T.r4, f: mono, s: 13.5 },
    ];
    rows.forEach((row, k) => {
      const y = rowTop + k * rowH + 26;
      parts.push(
        `<text x="${ddX}" y="${y}" font-family='${row.f}' font-size="${row.s}" fill="${C.ink}" xml:space="preserve" style="${anim(typeAnim(row.t[0], row.t[1], row.v.length), delay)}">${esc(row.v)}</text>`,
      );
    });

    const findLines = r.findLines;
    const findAnim = anim(fadeAnim(...T.find), delay);
    parts.push(
      `<text font-family='${serif}' font-size="19" fill="${C.ink}" style="${findAnim}">${findLines
        .map((l, k) => `<tspan x="${dtX}" y="${findY + k * 27}">${esc(l)}</tspan>`)
        .join("")}</text>`,
    );
    const { clauseY, clauseLines } = r;
    parts.push(
      `<text font-family='${serif}' font-size="15" fill="${C.ink}" style="${anim(fadeAnim(...T.clause), delay)}">${clauseLines
        .map((l, k) => {
          // The clause title reads as the page's link: green, underlined.
          const lead = k === 0 ? "Applicable clause: " : "";
          const link = `<tspan fill="${C.green}" text-decoration="underline">${esc(l.slice(lead.length))}</tspan>`;
          return `<tspan x="${dtX}" y="${clauseY + k * 21}">${lead}${link}</tspan>`;
        })
        .join("")}</text>`,
    );

    // The stamp: CREED's rubber stamp from creed.css, in its own ink and tilt.
    const top = r.top.toUpperCase();
    const word = r.word.toUpperCase();
    const sub = r.sub.toUpperCase();
    const wordW = monoWidth(word, 30, 2.4);
    const topW = monoWidth(top, 11, 2.2);
    const sW = Math.max(wordW + 16, topW, monoWidth(sub, 11, 2.2)) + 40;
    const sH = 118;
    parts.push(`<g clip-path="url(#cert)"><g transform="translate(${stampCx} ${stampCy}) rotate(${rot})">
      <g class="stamp" style="${anim("thunk", delay)}">
        <g filter="url(#wear)" fill="${ink}">
          <rect x="${-sW / 2 + 2}" y="${-sH / 2 + 2}" width="${sW - 4}" height="${sH - 4}" rx="9" fill="none" stroke="${ink}" stroke-width="4"/>
          <rect x="${-sW / 2 + 8}" y="${-sH / 2 + 8}" width="${sW - 16}" height="${sH - 16}" rx="5" fill="none" stroke="${ink}" stroke-width="2"/>
          <text x="0" y="-27" text-anchor="middle" font-family='${mono}' font-weight="600" font-size="11" letter-spacing="2.2">${esc(top)}</text>
          <rect x="${-wordW / 2 - 6}" y="-17" width="${wordW + 12}" height="2"/>
          <text x="0" y="14" text-anchor="middle" font-family='${mono}' font-weight="600" font-size="30" letter-spacing="2.4">${esc(word)}</text>
          <rect x="${-wordW / 2 - 6}" y="23" width="${wordW + 12}" height="2"/>
          <text x="0" y="44" text-anchor="middle" font-family='${mono}' font-weight="600" font-size="11" letter-spacing="2.2">${esc(sub)}</text>
        </g>
      </g>
    </g></g>`);

    groups.push(
      `<g class="slot${i ? " later" : ""}" style="${anim("slot", delay)}">\n    ${parts.join("\n    ")}\n  </g>`,
    );
  }

  // Static frame: the parts every certificate shares.
  const labels = ["Runtime", "Version", "On this day"];
  const formFrame = labels
    .map((l, k) => {
      const y = formY + 50 + k * 92;
      return `<rect x="${formX + 24}" y="${y - 15}" width="20" height="20" rx="2" fill="${C.ink}"/>
  <text x="${formX + 34}" y="${y}" text-anchor="middle" font-family='${mono}' font-weight="600" font-size="11" fill="${C.paperHi}">${k + 1}</text>
  <text x="${formX + 54}" y="${y}" font-family='${mono}' font-weight="600" font-size="12" letter-spacing="1.7" fill="${C.ink}">${esc(l.toUpperCase())}</text>
  <rect x="${formX + 24}" y="${y + 14}" width="${formW - 48}" height="44" fill="${C.paperHi}"/>
  <rect x="${formX + 24}" y="${y + 56}" width="${formW - 48}" height="2" fill="${C.ink}"/>`;
    })
    .join("\n  ");
  const btnY = formY + formH - 72;
  const certRows = ["Runtime", "Inspected on", "Reader call", "Returns"]
    .map((l, k) => {
      const y = rowTop + k * rowH;
      return `<text x="${dtX}" y="${y + 25}" font-family='${mono}' font-size="11" letter-spacing="1.3" fill="${C.ink2}">${esc(l.toUpperCase())}</text>
  <line x1="${dtX}" y1="${y + rowH}" x2="${certX + certW - padX}" y2="${y + rowH}" stroke="${C.rule}" stroke-width="1.2" stroke-dasharray="1.2 3" stroke-linecap="round"/>`;
    })
    .join("\n  ");
  const sign = results[0].sign;

  const desc =
    `Form CR-1, CREED's field inspection, filled in four times from policy/policy.json. ` +
    results
      .map((r) => `${r.what} on ${r.day}: ${r.returns}, stamped ${r.word}. ${r.finding}`)
      .join(" ");

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-labelledby="cr1-title cr1-desc">
  <title id="cr1-title">Form CR-1: a version inspected and stamped</title>
  <desc id="cr1-desc">${esc(desc)}</desc>
  <style>
    ${fontFaces()}
    ${kf.join("\n    ")}
    .slot.later, .press { opacity: 0; }
    .stamp { transform-box: fill-box; transform-origin: 50% 60%; mix-blend-mode: multiply; }
    .cert { animation: jolt ${TOTAL}s infinite both; }
    @media (prefers-reduced-motion: reduce) {
      * { animation: none !important; }
    }
  </style>
  <defs>
    <clipPath id="card"><rect width="${W}" height="${H}" rx="18"/></clipPath>
    <!-- The stamp comes down inside the certificate, never over its edge. -->
    <clipPath id="cert"><rect x="${certX + 1}" y="${certY + 1}" width="${certW - 2}" height="${certH - 2}"/></clipPath>
    <linearGradient id="binding" x1="0" x2="1" y1="0" y2="0">
      <stop offset="0" stop-color="${C.greenDeep}"/><stop offset=".7" stop-color="${C.green}"/><stop offset="1" stop-color="#0f5f53"/>
    </linearGradient>
    <!-- The stamp wear: the same noise as the wear mask in creed.css. -->
    <filter id="wear" x="-10%" y="-20%" width="120%" height="140%">
      <feTurbulence type="fractalNoise" baseFrequency=".75" numOctaves="3" seed="7" result="n"/>
      <feColorMatrix in="n" values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  -1.9 0 0 0 1.85" result="m"/>
      <feComposite in="SourceGraphic" in2="m" operator="in"/>
    </filter>
  </defs>

  <g clip-path="url(#card)">
  <rect width="${W}" height="${H}" fill="${C.paper}"/>
  <rect width="12" height="${H}" fill="url(#binding)"/>
  <rect x="12" width="1.5" height="${H}" fill="${C.gold}"/>
  </g>
  <rect x=".5" y=".5" width="${W - 1}" height="${H - 1}" rx="18" fill="none" stroke="${C.rule}"/>

  <text x="56" y="52" font-family='${mono}' font-size="12" letter-spacing="1.7" fill="${C.ink2}">CREED 2026</text>
  <text x="1224" y="52" text-anchor="end" font-family='${mono}' font-size="12" letter-spacing="1.7" fill="${C.ink2}">FORM CR-1</text>
  <line x1="56" y1="66" x2="1224" y2="66" stroke="${C.rule}"/>
  <text x="56" y="104" font-family='${mono}' font-size="12.5" letter-spacing="2" fill="${C.ochre}">${esc(kicker.toUpperCase())}</text>
  <text x="56" y="144" font-family='${serif}' font-weight="700" font-size="34" fill="${C.ink}">Inspect a version</text>

  <rect x="${formX + 6}" y="${formY + 6}" width="${formW}" height="${formH}" fill="${C.rule}"/>
  <rect x="${formX + 0.5}" y="${formY + 0.5}" width="${formW - 1}" height="${formH - 1}" fill="${C.paperHi}" stroke="${C.ink}"/>
  ${formFrame}
  ${chipBoxes.map((b) => chipSvg(b, false)).join("\n  ")}
  <rect x="${formX + 26}" y="${btnY + 3}" width="148" height="44" rx="3" fill="${C.greenDeep}"/>
  <rect x="${formX + 26}" y="${btnY}" width="148" height="44" rx="3" fill="${C.green}"/>
  <text x="${formX + 100}" y="${btnY + 27}" text-anchor="middle" font-family='${mono}' font-weight="600" font-size="14" letter-spacing="0.7" fill="${C.paperHi}">STAMP IT</text>

  <g class="cert">
  <rect x="${certX + 0.5}" y="${certY + 0.5}" width="${certW - 1}" height="${certH - 1}" fill="${C.paperHi}" stroke="${C.ink}"/>
  <rect x="${certX + 8.5}" y="${certY + 8.5}" width="${certW - 17}" height="${certH - 17}" fill="none" stroke="${C.rule}"/>
  <text x="${dtX}" y="${certY + 50}" font-family='${serif}' font-weight="700" font-size="25" fill="${C.ink}">Certificate of inspection</text>
  <line x1="${dtX}" y1="${certY + 63}" x2="${certX + certW - padX}" y2="${certY + 63}" stroke="${C.ink}"/>
  <line x1="${dtX}" y1="${certY + 66}" x2="${certX + certW - padX}" y2="${certY + 66}" stroke="${C.ink}"/>
  ${certRows}
  <text x="${dtX}" y="${signY}" font-family='${mono}' font-size="11" letter-spacing="1.3" fill="${C.ink2}">INSPECTOR</text>
  <line x1="${dtX + 96}" y1="${signY + 5}" x2="${dtX + 330}" y2="${signY + 5}" stroke="${C.ink}"/>
  <text x="${dtX + 100}" y="${signY}" font-family='${serif}' font-style="italic" font-size="16" fill="${C.ink}">${esc(sign)}</text>
  ${groups.join("\n  ")}
  </g>
</svg>
`;
}

async function buildCertificate(page, policy) {
  console.log("form cr-1:");
  await assertClean(page, "#inspection", "Form CR-1");
  const kicker = (await page.locator("#inspection .chap-head__kicker").innerText()).trim();
  const results = await inspectOnPage(page, policy, inspections(policyDay(policy)));
  for (const r of results) console.log(`  ${r.what} on ${r.day}: ${r.returns} (${r.word})`);
  const chips = await page.$$eval("[data-day]", (els) => els.map((e) => e.textContent.trim()));
  const svg = await buildCertificateSvg(results, await wrapper(page), kicker, chips);
  assertTextClean(svg.replace(/base64,[^)]+/g, ""), "form-cr1.svg");
  record("form-cr1.svg", Buffer.from(svg, "utf8"));
}

/* -------------------------------------------------------------- 7. gallery */

// A contact sheet of three existing mobile crops, laid on the book's shaded
// stock: the contents, Table 2-1 and the errata slip.
async function buildGallery(page) {
  console.log("gallery:");
  const crops = ["mobile-03-contents-a.png", "mobile-06-ch-2-b.png", "mobile-12-errata.png"];
  for (const c of crops) {
    if (!existsSync(path.join(siteDir, "screenshots", "sections", c))) fail(`missing crop ${c}`);
  }
  // Data URIs: the sheet is set on about:blank, which may not load file:// images.
  const src = (c) =>
    `data:image/png;base64,${readFileSync(path.join(siteDir, "screenshots", "sections", c)).toString("base64")}`;
  const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; }
  .sheet { width: fit-content; background: ${C.paperLo}; padding: 40px 46px 46px 40px; display: flex; gap: 34px; }
  img {
    box-sizing: border-box;
    display: block;
    width: 360px;
    height: 640px;
    object-fit: cover;
    object-position: top;
    border: 1px solid ${C.ink};
    box-shadow: 6px 6px 0 ${C.rule};
  }
</style>
</head>
<body>
<div class="sheet">
${crops.map((c) => `  <img src="${src(c)}" alt="">`).join("\n")}
</div>
</body>
</html>`;
  await page.setContent(html, { waitUntil: "load" });
  const png = await page.locator(".sheet").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("gallery.jpg", jpg);
}

/* ---------------------------------------------------------------- 8. board */

async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "creed-board.png");
  if (!existsSync(src)) fail("missing site/brand/creed-board.png");
  // Step the quality down until the board fits; it never goes below 60.
  for (let q = 84; q >= 60; q -= 4) {
    const jpg = await sharp(readFileSync(src)).resize({ width: 1280 }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
    if (jpg.length <= MAX_BYTES || q === 60) {
      record("board.jpg", jpg);
      return;
    }
  }
}

/* -------------------------------------------------------------------- main */

async function main() {
  const policy = JSON.parse(readFileSync(path.join(repoRoot, "policy", "policy.json"), "utf8"));
  await buildCast();
  buildBadges(policy);
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const { context, page } = await openPage(browser);
    await buildCertificate(page, policy); // before the hero: it uses the live form
    await buildHero(page);
    await buildGallery(page);
    await context.close();
  } finally {
    await browser.close();
  }
  if (process.exitCode) fail("a network request was attempted");

  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
