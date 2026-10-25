#!/usr/bin/env node
"use strict";
// Static local asset check. No network, deployment or production changes.
// Run: node dev-only/tests/check-page-assets.js from repository root.
const fs = require("node:fs");
const path = require("node:path");
const root = path.resolve(__dirname, "../..");
const pages = ["index.html","about.html","business-web.html","business-system.html","business-support.html","pricing.html","faq.html","activities.html","contact.html","privacy.html","legal.html"];
const failures = [];
const resources = new Map();
for (const page of pages) {
  const html = fs.readFileSync(path.join(root,page), "utf8");
  const found = [...html.matchAll(/\b(?:src|href)="(assets\/[^"#?]+)"/g)].map(m=>m[1]);
  for (const match of html.matchAll(/\bsrcset="([^"]+)"/g)) {
    for (const item of match[1].split(",")) {
      const file = item.trim().split(/\s+/)[0];
      if (file.startsWith("assets/")) found.push(file);
    }
  }
  for (const file of found) {
    if (!resources.has(file)) resources.set(file,new Set());
    resources.get(file).add(page);
  }
}
for (const [file,usedBy] of resources) {
  const target = path.resolve(root,file);
  if (!target.startsWith(root+path.sep)) { failures.push("Unsafe asset path: "+file);continue; }
  if (!fs.existsSync(target) || !fs.statSync(target).isFile()) {
    failures.push("Missing asset "+file+" referenced by "+[...usedBy].join(", "));
  } else if (file.endsWith(".webp")) {
    const magic=fs.readFileSync(target).subarray(0,12);
    if (magic.toString("ascii",0,4)!=="RIFF" || magic.toString("ascii",8,12)!=="WEBP") {
      failures.push("Invalid WebP header: "+file);
    }
  }
}
for(const issue of failures) console.error("FAIL "+issue);
console.log("Checked "+pages.length+" pages and "+resources.size+" unique local assets.");
console.log("Note: file integrity alone does not prove appearance, security, or browser compatibility.");
process.exit(failures.length ? 1 : 0);
