#!/usr/bin/env node
"use strict";
/**
 * One-command static development gate, for locally checked-out develop only.
 * Does not deploy, test remote staging, validate legal content or authorize release.
 * Run: node dev-only/tests/run-site-static-checks.js
 */
const { spawnSync } = require("node:child_process");
const path = require("node:path");
const tests = ["check-page-links.js", "check-page-assets.js", "check-content-consistency.js"];
let failed = false;
for (const test of tests) {
  process.stdout.write("\n=== " + test + " ===\n");
  const res = spawnSync(process.execPath,[path.join(__dirname,test)],{
    stdio:"inherit",shell:false,timeout:30000
  });
  if(res.error || res.status!==0) {
    failed = true;
    console.error("FAIL "+test+": "+(res.error?.message ?? "exit="+res.status));
  } else console.log("PASS "+test);
}
if(failed) {
  console.error("INCOMPLETE/FAIL: static release checks did not all pass.");
  process.exit(1);
}
console.log("\nPASS: all source-level checks completed.");
console.log("NOT VERIFIED: XServer sync, browser/mobile rendering, HTTP, privacy/legal approval, real inquiry processing.");
