#!/usr/bin/env node
"use strict";
/** Offline, temporary fixtures only. Never touches production or real site data. */
const fs=require("node:fs");
const os=require("node:os");
const path=require("node:path");
const {spawnSync}=require("node:child_process");
const root=fs.mkdtempSync(path.join(os.tmpdir(),"hayanex-release-guard-"));
const pages=["index.html","about.html","business-web.html","business-system.html","business-support.html","pricing.html","faq.html","activities.html"];
let passes=0,failures=0;
try {
  const run=(link,shouldBlock)=>{
    for(const page of pages)fs.writeFileSync(path.join(root,page),"<html><a href=\""+link+"\">sample</a></html>");
    const r=spawnSync(process.execPath,[path.join(__dirname,"check-release-scope.js"),root],{encoding:"utf8",timeout:10000});
    const blocked=r.status!==0;
    if(blocked===shouldBlock){passes++;console.log("PASS "+link);}
    else{failures++;console.error("FAIL "+link+" exit="+r.status+" stderr="+r.stderr);}
  };
  for(const url of ["contact.html","/contact.html","./privacy.html","../legal.html","/nested/contact.html","legal.html?draft=1","privacy.html#policy","%63ontact.html","/%70rivacy.html"])run(url,true);
  run("about.html",false);
  run("missing-page.html",true);
  run("/missing-page.html",true);
  run("javascript:alert(1)",true);
  fs.writeFileSync(path.join(root,"legal.html"),"sample");
  const r=spawnSync(process.execPath,[path.join(__dirname,"check-release-scope.js"),root],{encoding:"utf8",timeout:10000});
  if(r.status!==0){passes++;console.log("PASS excluded file blocked");}
  else{failures++;console.error("FAIL excluded file was not blocked");}
} finally {
  fs.rmSync(root,{recursive:true,force:true});
}
console.log("Release scope guard cases: "+passes+" passed, "+failures+" failed");
process.exit(failures?1:0);
