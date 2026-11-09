"use strict";
const assert = require("node:assert/strict"),
  https = require("node:https"),
  fs = require("node:fs"),
  path = require("node:path"),
  { spawnSync } = require("node:child_process");
const dir = process.env.HAYANEX_DEV_RUNTIME || "/tmp/hayanex-private-20261109";
const ca = fs.readFileSync(path.join(dir, "tls.crt"));
const env = { ...process.env };
for (const k of [
  "DOCKER_HOST",
  "DOCKER_CONTEXT",
  "DOCKER_TLS",
  "DOCKER_TLS_VERIFY",
  "DOCKER_CERT_PATH",
])
  delete env[k];
function run(action) {
  const r = spawnSync("python3", ["dev-only/runtime/local.py", action], {
    env,
    encoding: "utf8",
  });
  assert(r.status === 0, "Private runtime control failed");
  return r.stdout;
}
function control(op, extra = {}) {
  const r = spawnSync(
    "docker",
    [
      "--host=unix:///var/run/docker.sock",
      "exec",
      "-i",
      "hayanex-isolated-php-20261109",
      "php",
      "dev-only/runtime/support.php",
    ],
    { env, input: JSON.stringify({ op, ...extra }), encoding: "utf8" },
  );
  assert(r.status === 0, "Private fixture failed");
  return JSON.parse(r.stdout);
}
let cookie = "";
function req(method, data) {
  return new Promise((resolve, reject) => {
    const body = data ? new URLSearchParams(data).toString() : "";
    const q = https.request(
      {
        host: "127.0.0.1",
        port: 24443,
        path: "/contact",
        method,
        ca,
        headers: {
          Cookie: cookie,
          Origin: "https://127.0.0.1:24443",
          "Content-Type": "application/x-www-form-urlencoded",
          "Content-Length": Buffer.byteLength(body),
        },
      },
      (r) => {
        for (const c of r.headers["set-cookie"] || []) cookie = c.split(";")[0];
        let text = "";
        r.on("data", (d) => (text += d));
        r.on("end", () => resolve({ code: r.statusCode, text }));
      },
    );
    q.on("error", reject);
    q.end(body);
  });
}
(async () => {
  const page = await req("GET");
  const csrf = page.text.match(/name="csrf" value="([a-f0-9]{64})"/)[1],
    token = page.text.match(/name="request_token" value="([a-f0-9]{64})"/)[1];
  const payload = {
    csrf,
    request_token: token,
    inquiry_type: "consult",
    client_type: "person",
    person_name: "架空の障害試験",
    email: "failure@example.invalid",
    service: "web",
    message: "障害時の架空検証。外部送信なし。",
    consent: "yes",
  };
  const before = control("counts");
  try {
    run("lock-db");
    const r = await req("POST", payload);
    assert(
      r.code === 503 && !r.text.includes('id="receipt"'),
      "Database failure reported success",
    );
  } finally {
    run("unlock-db");
  }
  assert(
    control("counts").inquiries === before.inquiries,
    "Failure wrote inquiry",
  );
  console.log("PASS: DB接続拒否で503、受付・通知を成功扱いしない");
  const retry = await req("POST", payload);
  assert(
    retry.code === 200 && control("counts").inquiries === before.inquiries + 1,
  );
  console.log("PASS: DB復旧後に同じ架空リクエストを正常登録");
  for (let i = 0; i < 5; i++) control("worker", { fail: true });
  let queue = control("queue");
  assert(queue.some((r) => r.status === "failed" && Number(r.attempts) === 5));
  while (control("worker").processed) {}
  queue = control("queue");
  assert(queue.some((r) => r.status === "failed" && Number(r.attempts) === 5));
  console.log("PASS: 通知失敗は5回で自動再試行停止、外部未送信");
  console.log(run("backup-check").trim());
})().catch((e) => {
  console.error("FAIL: " + e.message);
  process.exitCode = 1;
});
