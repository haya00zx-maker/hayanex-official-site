#!/usr/bin/env node
"use strict";
/**
 * Local-only static source check. Does not connect to the network or edit files.
 * Run from the repository root: node dev-only/tests/check-page-links.js
 * This is not browser testing, accessibility testing, or a deployment check.
 */
const fs = require("node:fs");
const path = require("node:path");
const pages = [
  "index.html", "about.html", "business-web.html", "business-system.html",
  "business-support.html", "pricing.html", "faq.html", "activities.html",
  "contact.html", "privacy.html", "legal.html"
];
const root = path.resolve(__dirname, "../..");
const docs = new Map();
const ids = new Map();
const problems = [];
let checked = 0;
for (const page of pages) {
  const content = fs.readFileSync(path.join(root, page), "utf8");
  docs.set(page, content);
  const allIds = [...content.matchAll(/\bid="([^"]+)"/g)].map(m => m[1]);
  const unique = new Set(allIds);
  if (unique.size !== allIds.length) problems.push(page + ": duplicate ID");
  ids.set(page, unique);
  if (!/<h1(?:\s|>)/.test(content)) problems.push(page + ": missing h1");
  if (!/<meta\s+name="robots"\s+content="noindex, nofollow"/.test(content)) {
    problems.push(page + ": staging noindex missing");
  }
}
for (const [page, content] of docs) {
  for (const [, url] of content.matchAll(/\bhref="([^"]+)"/g)) {
    if (url.startsWith("http:") || url.startsWith("https:") ||
        url.startsWith("mailto:") || url.startsWith("tel:")) continue;
    const [file, anchor] = url.split("#");
    if (file && !file.endsWith(".html")) continue;
    if (!file && !anchor) continue;
    checked++;
    const target = file || page;
    if (!docs.has(target)) { problems.push(page + ": missing page " + target); continue; }
    if (anchor && !ids.get(target).has(anchor)) {
      problems.push(page + ": missing anchor " + target + "#" + anchor);
    }
  }
}
for (const problem of problems) console.error("FAIL " + problem);
console.log("Checked " + pages.length + " pages and " + checked + " local HTML links/anchors");
if (problems.length) process.exit(1);
console.log("PASS static links and staging safeguards; browser and server tests not performed");
