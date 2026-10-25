#!/usr/bin/env node
"use strict";
/**
 * Non-deploying release-scope guard.
 * Run against an explicitly prepared, separate candidate directory, never
 * use the GitHub develop source directly as proof of publishability.
 *
 * node dev-only/tests/check-release-scope.js /absolute/path/to/release-candidate
 */
const fs=require("node:fs");
const path=require("node:path");
const candidate=process.argv[2]&&path.resolve(process.argv[2]);
if (!candidate || !fs.existsSync(candidate) || !fs.statSync(candidate).isDirectory()) {
  console.error("BLOCKED: explicitly supply an existing candidate directory.");
  process.exit(2);
}
const pages=["index.html","about.html","business-web.html","business-system.html","business-support.html","pricing.html","faq.html","activities.html"];
const banned=new Set(["contact.html","privacy.html","legal.html"]);
let links=0;
const errors=[];
for(const page of pages) {
  const filename=path.join(candidate,page);
  if(!fs.existsSync(filename)){errors.push("Missing release candidate page: "+page);continue;}
  const html=fs.readFileSync(filename,"utf8");
  for(const [,value] of html.matchAll(/\bhref\s*=\s*["']([^"']+)["']/gi)){
    let ref=value.trim();
    if(ref.startsWith("#")||/^(?:https?:|mailto:|tel:|javascript:|data:)/i.test(ref)) continue;
    ref=ref.split(/[?#]/)[0].replace(/\\/g,"/");
    try { ref=decodeURIComponent(ref); } catch { errors.push(page+" invalid URL encoding: "+value); continue; }
    const normalized=path.posix.normalize("/"+ref.replace(/^\/+/, ""));
    const basename=path.posix.basename(normalized).toLowerCase();
    if(banned.has(basename)) {
      links++;
      errors.push(page+" still links to excluded "+ref);
    }
  }
  if(/代表確認用の制作版|確認用下書き/.test(html))errors.push(page+" still displays internal review wording");
  if(/<form\\b/i.test(html))errors.push(page+" contains a form requiring separate approval");
  for(const [,value] of html.matchAll(/\\bhref\\s*=\\s*["']([^"']+)["']/gi)){
    let ref=value.trim();
    if(!ref || ref.startsWith("#") || /^(?:https?:|mailto:|tel:)/i.test(ref))continue;
    if(/^(?:javascript:|data:|file:)/i.test(ref)){errors.push(page+" unsafe link scheme");continue;}
    ref=ref.split(/[?#]/)[0].replace(/\\\\/g,"/");
    try{ref=decodeURIComponent(ref);}catch{continue;}
    if(!/\\.html$/i.test(ref))continue;
    const target=path.posix.normalize("/"+ref.replace(/^\\/+/, "")).slice(1);
    if(target.startsWith("../") || !fs.existsSync(path.join(candidate,target))){
      errors.push(page+" links to absent release page "+target);
    }
  }
}
for(const page of banned)if(fs.existsSync(path.join(candidate,page)))errors.push("Excluded file present in candidate: "+page);
errors.forEach(e=>console.error("BLOCKED "+e));
console.log("Candidate pages examined: "+pages.length+"; disallowed links found: "+links);
if(errors.length)process.exit(1);
console.log("PASS scope guard only. Not legal, accessibility, server, or representative release approval.");
