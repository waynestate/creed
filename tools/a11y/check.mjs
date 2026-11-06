#!/usr/bin/env node
/**
 * Site accessibility gate.
 *
 * Serves the site folder on a local port, opens each page in a real Chromium
 * browser and runs axe-core against it with the WCAG 2.2 AA tag set, in light
 * mode and in dark mode (colorScheme emulation) at a narrow (360 px) and a
 * wide (1280 px) viewport. Each page is analyzed with reduced motion, which is
 * the page's own static, fully visible state, so scroll reveals never hide
 * content from the check.
 *
 * Usage:  node tools/a11y/check.mjs [--root=site] [--page=index.html]
 *         --page takes a comma separated list of pages; an entry may be a
 *         literal path or a glob (** crosses folders, * and ? stay inside one).
 *         The default, index.html alone, checks the front page only.
 * Exit:   0 clean, 1 violations found, 2 tool error.
 */
import { createServer } from "node:http";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

import { chromium } from "playwright";
import AxeBuilder from "@axe-core/playwright";

const TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];
const MODES = ["light", "dark"];
const WIDTHS = [360, 1280];
const HEIGHT = 900;

const MIME = {
  ".css": "text/css; charset=utf-8",
  ".gif": "image/gif",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".md": "text/markdown; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".txt": "text/plain; charset=utf-8",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".xml": "application/xml; charset=utf-8",
};

function option(name, fallback) {
  const prefix = `--${name}=`;
  const hit = process.argv.slice(2).find((arg) => arg.startsWith(prefix));
  return hit ? hit.slice(prefix.length) : fallback;
}

/** Turn a glob spec into a regex over slash separated relative paths. */
function globToRegExp(spec) {
  const parts = spec.split("/").map((part) => {
    if (part === "**") return null;
    return part.replace(/[.+^${}()|[\]\\]/g, "\\$&").replace(/\*/g, "[^/]*").replace(/\?/g, "[^/]");
  });
  let re = "";
  for (let i = 0; i < parts.length; i++) {
    if (parts[i] === null) {
      re += "(?:.*/)?";
    } else {
      re += parts[i];
      if (i < parts.length - 1) re += "/";
    }
  }
  return new RegExp(`^${re}$`);
}

/** Every .html file under dir, as sorted slash separated paths relative to base. */
async function listHtmlFiles(dir, base, out) {
  const entries = await readdir(dir, { withFileTypes: true });
  entries.sort((a, b) => a.name.localeCompare(b.name));
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      await listHtmlFiles(full, base, out);
    } else if (entry.isFile() && entry.name.endsWith(".html")) {
      out.push(path.relative(base, full).split(path.sep).join("/"));
    }
  }
}

/** Expand the comma separated --page value into concrete page paths. */
async function expandPages(rootDir, specList) {
  const specs = specList.split(",").map((spec) => spec.trim()).filter(Boolean);
  const pages = [];
  for (const spec of specs) {
    if (spec.includes("*") || spec.includes("?")) {
      const files = [];
      await listHtmlFiles(rootDir, rootDir, files);
      const re = globToRegExp(spec);
      for (const file of files) {
        if (re.test(file)) pages.push(file);
      }
    } else {
      pages.push(spec);
    }
  }
  return [...new Set(pages)];
}

/** Serve rootDir over http on an ephemeral port; "/" maps to firstPage. */
async function serve(rootDir, firstPage) {
  const server = createServer((req, res) => {
    (async () => {
      try {
        const url = new URL(req.url, "http://127.0.0.1");
        let pathname = decodeURIComponent(url.pathname);
        if (pathname === "/") pathname = `/${firstPage}`;
        const filePath = path.normalize(path.join(rootDir, pathname));
        if (filePath !== rootDir && !filePath.startsWith(rootDir + path.sep)) {
          throw new Error(`outside the site root: ${pathname}`);
        }
        const body = await readFile(filePath);
        const type = MIME[path.extname(filePath).toLowerCase()] || "application/octet-stream";
        res.writeHead(200, { "content-type": type });
        res.end(body);
      } catch {
        res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
        res.end("not found");
      }
    })();
  });
  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", resolve);
  });
  return { server, base: `http://127.0.0.1:${server.address().port}` };
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const rootDir = path.resolve(repoRoot, option("root", "site"));
  const pages = await expandPages(rootDir, option("page", "index.html"));
  if (pages.length === 0) {
    console.error("no pages matched the --page value");
    process.exit(2);
  }

  const { server, base } = await serve(rootDir, pages[0]);
  const browser = await chromium.launch();
  const findings = new Map();
  let total = 0;
  try {
    for (const scheme of MODES) {
      for (const width of WIDTHS) {
        const context = await browser.newContext({
          colorScheme: scheme,
          reducedMotion: "reduce",
          viewport: { width, height: HEIGHT },
        });
        const page = await context.newPage();
        for (const pageName of pages) {
          const response = await page.goto(`${base}/${pageName}`, { waitUntil: "load" });
          if (!response || response.status() !== 200) {
            const status = response ? response.status() : "no response";
            console.error(`tool error: ${pageName} did not load (status ${status})`);
            process.exit(2);
          }
          await page.evaluate(() => document.fonts.ready);
          const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
          const entry = findings.get(pageName) ?? { count: 0, items: [] };
          entry.count += results.violations.length;
          for (const violation of results.violations) {
            entry.items.push({
              scheme,
              width,
              id: violation.id,
              impact: violation.impact,
              help: violation.help,
              nodes: violation.nodes.map((node) => node.target.join(" ")),
            });
          }
          findings.set(pageName, entry);
        }
        await context.close();
      }
    }
  } finally {
    await browser.close();
    server.close();
  }
  for (const pageName of pages) {
    const entry = findings.get(pageName);
    total += entry.count;
    console.log(`${pageName}: ${entry.count === 0 ? "0 violations" : `${entry.count} violation${entry.count === 1 ? "" : "s"}`}`);
    for (const item of entry.items) {
      console.log(`  ${item.scheme} ${item.width}px: ${item.id} (${item.impact}): ${item.help}`);
      for (const node of item.nodes) {
        console.log(`    ${node}`);
      }
    }
  }
  console.log(total === 0 ? "0 violations" : `${total} violations`);
  process.exit(total === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(`tool error: ${err && err.message ? err.message : err}`);
  process.exit(2);
});
