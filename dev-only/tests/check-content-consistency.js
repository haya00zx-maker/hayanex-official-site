#!/usr/bin/env node
"use strict";
/**
 * Development-only consistency checks. Run from the repository checkout:
 * node dev-only/tests/check-content-consistency.js
 * Checks known outdated statements; NOT a legal, pricing or publication approval.
 */
const fs = require("node:fs");
const path = require("node:path");
const root = path.resolve(__dirname, "../..");
const pages = ["index.html","about.html","business-web.html","business-system.html","business-support.html","pricing.html","faq.html","activities.html","contact.html","privacy.html","legal.html"];
const issues=[];
const content={};
for(const page of pages){
  content[page]=fs.readFileSync(path.join(root,page),"utf8");
  if(!content[page].includes('content="noindex, nofollow"')) issues.push(page+": review noindex missing");
}
const checks=[
  ["index.html","制作3・保守3・買い切り",/サイト制作3プラン、月額保守3プラン、買い切り制作方式/],
  ["faq.html","支払原則一括",/制作費のお支払いは原則一括払い/],
  ["faq.html","関連団体の独立性",/両者は独立した活動・事業主体/],
  ["legal.html","法的下書き明記",/確認用下書き/],
  ["privacy.html","ポリシー下書き明記",/確認用下書き/],
  ["contact.html","試作非送信",/送信機能はまだありません/]
];
for(const [page,label,pattern] of checks){
  if(!pattern.test(content[page]))issues.push(page+": "+label+" missing");
}
const stale=[
  [/契約時50％・納品時50％/,"outdated fixed 50/50 payment text"],
  [/6プランの制作案/,"outdated six production plans text"],
  [/制作費の支払時期・支払割合は現在検討中/,"outdated payment undecided text"]
];
for(const page of pages){
  for(const [pattern,label] of stale)if(pattern.test(content[page]))issues.push(page+": "+label);
}
for(const issue of issues)console.error("FAIL "+issue);
console.log("Reviewed "+pages.length+" development pages for known policy drift.");
if(issues.length)process.exit(1);
console.log("PASS known content consistency checks; no legal or release approval implied.");
