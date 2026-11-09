"use strict";
const https = require("node:https"),
  http = require("node:http"),
  fs = require("node:fs"),
  path = require("node:path"),
  crypto = require("node:crypto"),
  { spawnSync } = require("node:child_process"),
  assert = require("node:assert/strict");
const dir = process.env.HAYANEX_DEV_RUNTIME || "/tmp/hayanex-private-20261109";
const fixture = JSON.parse(
  fs.readFileSync(path.join(dir, "fixtures.json"), "utf8"),
);
const ca = fs.readFileSync(path.join(dir, "tls.crt"));
const report = [];
function check(ok, label) {
  assert(ok, label);
  report.push(label);
  console.log("PASS: " + label);
}
function control(data) {
  const r = spawnSync(
    "docker",
    [
      "--host=unix:///var/run/docker.sock",
      "exec",
      "-i",
      "hayanex-isolated-php-20261109",
      "php",
      "-d",
      "pdo_mysql.default_socket=/runtime/mysql/mysql.sock",
      "dev-only/runtime/support.php",
    ],
    {
      input: JSON.stringify(data),
      encoding: "utf8",
      env: {
        ...process.env,
        DOCKER_HOST: undefined,
        DOCKER_CONTEXT: undefined,
        DOCKER_TLS: undefined,
        DOCKER_TLS_VERIFY: undefined,
        DOCKER_CERT_PATH: undefined,
      },
    },
  );
  assert(r.status === 0, "Private fixture command failed (values suppressed)");
  return JSON.parse(r.stdout);
}
class Client {
  constructor() {
    this.cookie = "";
  }
  req(url, method = "GET", data = null, extra = {}) {
    return new Promise((resolve, reject) => {
      const body = data === null ? "" : new URLSearchParams(data).toString();
      const headers = { ...extra };
      if (this.cookie) headers.Cookie = this.cookie;
      if (method === "POST") {
        headers.Origin = "https://127.0.0.1:24443";
        headers["Content-Type"] = "application/x-www-form-urlencoded";
        headers["Content-Length"] = Buffer.byteLength(body);
        Object.assign(headers, extra);
      }
      const q = https.request(
        { hostname: "127.0.0.1", port: 24443, path: url, method, ca, headers },
        (r) => {
          for (const c of r.headers["set-cookie"] || []) {
            const pair = c.split(";")[0];
            this.cookie = pair;
          }
          const chunks = [];
          r.on("data", (x) => chunks.push(x));
          r.on("end", () =>
            resolve({
              code: r.statusCode,
              headers: r.headers,
              body: Buffer.concat(chunks).toString("utf8"),
            }),
          );
        },
      );
      q.on("error", reject);
      q.end(body);
    });
  }
  async token(url) {
    const r = await this.req(url);
    assert(r.code === 200, "Token page unavailable");
    const token = r.body.match(/name="csrf" value="([a-f0-9]{64})"/);
    assert(token, "CSRF missing");
    return { r, csrf: token[1] };
  }
}
function totp(secret) {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = "";
  for (const c of secret) bits += chars.indexOf(c).toString(2).padStart(5, "0");
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8)
    bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const n = Buffer.alloc(8);
  n.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = crypto.createHmac("sha1", Buffer.from(bytes)).update(n).digest();
  const off = hash[19] & 15;
  return String((hash.readUInt32BE(off) & 0x7fffffff) % 1000000).padStart(
    6,
    "0",
  );
}
async function password(client, name) {
  const { csrf } = await client.token("/staff/login");
  return client.req("/staff/login", "POST", {
    csrf,
    login: fixture[name].login,
    password: fixture[name].password,
  });
}
async function login(client, name) {
  const r = await password(client, name);
  assert(r.code === 303, "Password rejected");
  if (fixture[name].secret) {
    const { csrf } = await client.token("/staff/mfa");
    const r2 = await client.req("/staff/mfa", "POST", {
      csrf,
      code: totp(fixture[name].secret),
    });
    assert(r2.code === 303, "MFA rejected");
  }
  return client;
}
(async () => {
  const anon = new Client();
  let r = await anon.req("/staff");
  check(r.code === 401, "未認証の管理画面を拒否");
  for (const route of [
    "/docs/inquiry-dev-schema.sql",
    "/dev-only/inquiry/StaffAuth.php",
    "/.git/config",
    "/runtime/fixtures.json",
  ])
    check(
      (await anon.req(route)).code === 404,
      "私用ファイルのHTTP露出を拒否: " + route,
    );
  const direct = await new Promise((resolve, reject) => {
    http
      .get(
        {
          host: "127.0.0.1",
          port: 18880,
          path: "/staff/login",
          headers: { "X-Forwarded-Proto": "https" },
        },
        (r) => {
          r.resume();
          r.on("end", () => resolve(r.statusCode));
        },
      )
      .on("error", reject);
  });
  check(direct === 403, "偽TLSヘッダーと直接HTTPでゲートを回避できない");
  const initial = await anon.token("/staff/login");
  check(
    initial.r.headers["cache-control"].includes("no-store") &&
      initial.r.headers["x-frame-options"] === "DENY" &&
      initial.r.headers["content-security-policy"].includes(
        "frame-ancestors 'none'",
      ),
    "HTTPセキュリティ・キャッシュ禁止ヘッダー",
  );
  const cookies =
    (await new Client().req("/staff/login")).headers["set-cookie"] || [];
  check(
    cookies.some(
      (c) =>
        /secure/i.test(c) && /httponly/i.test(c) && /samesite=strict/i.test(c),
    ),
    "Secure/HttpOnly/SameSite=Strict Cookie",
  );
  check(
    (
      await anon.req("/staff/login", "POST", {
        csrf: "0".repeat(64),
        login: "x",
        password: "x",
      })
    ).code === 403,
    "CSRF不一致を拒否",
  );
  check(
    (
      await anon.req(
        "/staff/login",
        "POST",
        { csrf: initial.csrf, login: "x", password: "x" },
        { Origin: "https://attacker.invalid" },
      )
    ).code === 403,
    "異なるOriginを拒否",
  );
  check(
    (
      await anon.req(
        "/staff/login",
        "POST",
        { csrf: initial.csrf },
        { "Content-Type": "application/json" },
      )
    ).code === 403,
    "不正Content-Typeを拒否",
  );
  check((await anon.req("/staff/logout")).code === 405, "GETログアウトを拒否");
  check(
    (
      await anon.req("/staff/login", "POST", {
        csrf: initial.csrf,
        login: "x",
        password: "x".repeat(9000),
      })
    ).code === 413,
    "過大な認証リクエストを拒否",
  );
  const owner = new Client();
  const before = (await owner.token("/staff/login"), owner.cookie);
  r = await password(owner, "owner");
  check(
    r.code === 303 && r.headers.location === "/staff/mfa",
    "ownerはパスワード後にMFAへ遷移",
  );
  check(before !== owner.cookie, "パスワード段階でセッションIDを更新");
  check((await owner.req("/staff")).code === 401, "MFA未完了の管理画面を拒否");
  let challenge = await owner.token("/staff/mfa");
  r = await owner.req("/staff/mfa", "POST", {
    csrf: challenge.csrf,
    code: "invalid",
  });
  check(r.code === 401, "不正MFAを拒否");
  challenge = await owner.token("/staff/mfa");
  const pendingCookie = owner.cookie;
  r = await owner.req("/staff/mfa", "POST", {
    csrf: challenge.csrf,
    code: totp(fixture.owner.secret),
  });
  check(
    r.code === 303 && r.headers.location === "/staff",
    "MFAとDB検証完了後のみログイン",
  );
  check(owner.cookie !== pendingCookie, "認証完了時にもセッションIDを更新");
  const replay = new Client();
  await password(replay, "owner");
  const replayToken = await replay.token("/staff/mfa");
  check(
    (
      await replay.req("/staff/mfa", "POST", {
        csrf: replayToken.csrf,
        code: totp(fixture.owner.secret),
      })
    ).code === 401,
    "消費済みTOTPの再利用を拒否",
  );
  const fixed = new Client();
  fixed.cookie = "HAYANEX_STAFF=attackerchosen12345678901234567890";
  const fixedOld = fixed.cookie;
  await fixed.token("/staff/login");
  check(fixed.cookie !== fixedOld, "厳格セッションで未発行IDの固定を拒否");
  const admin = await login(new Client(), "admin");
  const staff = await login(new Client(), "staff-a");
  const other = await login(new Client(), "staff-b");
  check(
    (await admin.req("/staff/accounts")).code === 403,
    "adminによるスタッフ管理を拒否",
  );
  check(
    (await staff.req("/staff/audit")).code === 403,
    "staffによる監査閲覧を拒否",
  );
  check(
    (await owner.req("/staff?page=-1")).code === 400,
    "不正なページ指定を拒否",
  );
  check(
    (await owner.req("/staff?page=2")).code === 200,
    "ページ送りを認可範囲内で表示",
  );
  check(
    (await owner.req("/staff/accounts")).code === 200,
    "ownerのみスタッフ管理画面を表示",
  );
  const customer = new Client();
  const form = await customer.token("/contact");
  const token = form.r.body.match(
    /name="request_token" value="([a-f0-9]{64})"/,
  )[1];
  const payload = {
    csrf: form.csrf,
    request_token: token,
    inquiry_type: "estimate",
    client_type: "person",
    person_name: "架空の利用者",
    email: "synthetic@example.invalid",
    service: "web",
    message: "<img src=x onerror=alert(1)>架空の相談",
    consent: "yes",
  };
  const countBefore = control({ op: "counts" });
  check(
    (await customer.req("/contact", "POST", { ...payload, email: "not-valid" }))
      .code === 400,
    "サーバーでメール形式を検証",
  );
  check(
    (
      await customer.req("/contact", "POST", {
        ...payload,
        email: "real@example.com",
      })
    ).code === 400,
    "私用環境では実メール宛先を拒否",
  );
  check(
    (
      await customer.req("/contact", "POST", {
        ...payload,
        client_type: "corporate",
      })
    ).code === 400,
    "法人の組織名必須をサーバーで検証",
  );
  check(
    (
      await customer.req("/contact", "POST", {
        ...payload,
        message: "あ".repeat(2001),
      })
    ).code === 400,
    "サーバーで本文上限を検証",
  );
  check(
    (await customer.req("/contact", "POST", { ...payload, consent: "no" }))
      .code === 400,
    "架空データ確認なしの登録を拒否",
  );
  r = await customer.req("/contact", "POST", payload);
  check(
    r.code === 200 && r.body.includes('id="receipt"'),
    "HTTPSからDBへ架空受付を登録",
  );
  const receipt = r.body.match(/id="receipt">([^<]+)/)[1];
  const after = control({ op: "counts" });
  check(
    after.inquiries === countBefore.inquiries + 1 &&
      after.notification_outbox === countBefore.notification_outbox + 2,
    "受付・冪等キー・通知2件が原子的に保存",
  );
  r = await customer.req("/contact", "POST", payload);
  check(
    r.code === 200 &&
      r.body.includes(receipt) &&
      control({ op: "counts" }).inquiries === after.inquiries,
    "HTTP再送で受付と通知を重複登録しない",
  );
  check(
    (
      await customer.req("/contact", "POST", {
        ...payload,
        message: "変更された架空本文",
      })
    ).code === 400,
    "同じキーで異なる内容を拒否",
  );
  const inquiry = control({ op: "inquiries" })[0];
  check(
    !(await staff.req("/staff")).body.includes(receipt),
    "担当外の受付番号を一覧に出さない",
  );
  check(
    (await staff.req("/staff/inquiries/" + inquiry.id)).code === 404,
    "担当外の直接詳細URLを拒否",
  );
  const detail = await owner.token("/staff/inquiries/" + inquiry.id);
  check(
    detail.r.body.includes("&lt;img src=x") &&
      !detail.r.body.includes("<img src=x"),
    "保存した入力をHTMLエスケープ",
  );
  r = await owner.req("/staff/assign", "POST", {
    csrf: detail.csrf,
    inquiry_id: inquiry.id,
    staff_id: fixture["staff-a"].id,
  });
  check(r.code === 303, "ownerからDB上の担当者を割当");
  check(
    (await staff.req("/staff")).body.includes(receipt),
    "割当済み案件をスタッフ一覧へ反映",
  );
  let staffDetail = await staff.token("/staff/inquiries/" + inquiry.id);
  r = await staff.req("/staff/status", "POST", {
    csrf: staffDetail.csrf,
    inquiry_id: inquiry.id,
    status: "in_progress",
  });
  check(
    r.code === 303 && control({ op: "inquiries" })[0].status === "in_progress",
    "担当staffの状況更新を保存",
  );
  const statusCount = control({ op: "counts" }).inquiry_audit_events;
  staffDetail = await staff.token("/staff/inquiries/" + inquiry.id);
  await staff.req("/staff/status", "POST", {
    csrf: staffDetail.csrf,
    inquiry_id: inquiry.id,
    status: "in_progress",
  });
  check(
    control({ op: "counts" }).inquiry_audit_events === statusCount,
    "同一状況の再送で監査を重複記録しない",
  );
  const otherToken = await other.token("/staff");
  check(
    (
      await other.req("/staff/status", "POST", {
        csrf: otherToken.csrf,
        inquiry_id: inquiry.id,
        status: "completed",
      })
    ).code === 403,
    "担当外staffの更新を拒否",
  );
  check(
    (
      await staff.req("/staff/assign", "POST", {
        csrf: staffDetail.csrf,
        inquiry_id: inquiry.id,
        staff_id: fixture["staff-b"].id,
      })
    ).code === 403,
    "staffによる担当割当を拒否",
  );
  check(
    (await owner.req("/staff/audit")).body.includes("担当変更"),
    "割当・状況の監査を画面で確認",
  );
  check(
    control({ op: "audit-tamper" }).denied,
    "アプリDB権限では監査履歴を書き換えられない",
  );
  const adminToken = await admin.token("/staff");
  check(
    (await admin.req("/staff/notifications", "POST", { csrf: adminToken.csrf }))
      .code === 403,
    "adminによるシステム通知操作を拒否",
  );
  const ownAudit = await owner.token("/staff/audit");
  check(
    (await owner.req("/staff/notifications", "POST", { csrf: ownAudit.csrf }))
      .code === 303,
    "ownerが通知をローカルsinkへ記録",
  );
  control({ op: "worker", fail: true });
  check(
    control({ op: "queue" }).some((x) => x.status === "failed"),
    "通知失敗を成功とせず再試行可能な状態にする",
  );
  control({ op: "worker" });
  const queue = control({ op: "queue" });
  check(
    queue.every((x) => x.status === "simulated") &&
      control({ op: "counts" }).dev_notification_deliveries === 2,
    "通知再試行でローカル記録2件、外部未送信を区別",
  );
  check(
    control({ op: "worker" }).processed === false,
    "処理済み通知を再配送しない",
  );
  let ownAccounts = await owner.token("/staff/accounts");
  check(
    (
      await owner.req("/staff/account", "POST", {
        csrf: ownAccounts.csrf,
        staff_id: fixture.owner.id,
        role: "staff",
        active: "0",
      })
    ).code === 403,
    "自己無効化・自己降格を拒否",
  );
  check(
    (
      await owner.req("/staff/account", "POST", {
        csrf: ownAccounts.csrf,
        staff_id: fixture["staff-a"].id,
        role: "admin",
        active: "1",
      })
    ).code === 409,
    "MFA未登録の管理者昇格を拒否",
  );
  check(
    (
      await owner.req("/staff/account", "POST", {
        csrf: ownAccounts.csrf,
        staff_id: fixture["staff-b"].id,
        role: "staff",
        active: "0",
      })
    ).code === 303,
    "架空スタッフを無効化",
  );
  check(
    (await other.req("/staff")).code === 401,
    "無効化済みアカウントの既存セッションを失効",
  );
  check(
    (
      await owner.req("/staff/account", "POST", {
        csrf: ownAccounts.csrf,
        staff_id: fixture.admin.id,
        role: "staff",
        active: "1",
      })
    ).code === 303,
    "ownerが権限を変更",
  );
  check(
    (await admin.req("/staff")).code === 401,
    "権限変更を既存セッションへ即時反映",
  );
  const idle = await login(new Client(), "staff-a");
  control({
    op: "expire-session",
    id: idle.cookie.split("=")[1],
    kind: "idle",
  });
  check(
    (await idle.req("/staff")).code === 401,
    "アイドル15分の期限をHTTPで強制",
  );
  const absolute = await login(new Client(), "staff-a");
  control({
    op: "expire-session",
    id: absolute.cookie.split("=")[1],
    kind: "absolute",
  });
  check(
    (await absolute.req("/staff")).code === 401,
    "絶対8時間の期限をHTTPで強制",
  );
  const logout = await login(new Client(), "staff-a");
  const logoutToken = await logout.token("/staff");
  check(
    (await logout.req("/staff/logout", "POST", { csrf: logoutToken.csrf }))
      .code === 303 && (await logout.req("/staff")).code === 401,
    "POSTログアウトでサーバー側セッションを破棄",
  );
  const changed = await login(new Client(), "staff-a");
  control({ op: "change-password" });
  check(
    (await changed.req("/staff")).code === 401,
    "パスワード変更で既存セッションを失効",
  );
  control({ op: "reset-limiter" });
  const failures = await Promise.all(
    Array.from({ length: 7 }, async () => {
      const c = new Client();
      const { csrf } = await c.token("/staff/login");
      return c.req("/staff/login", "POST", {
        csrf,
        login: "synthetic-missing",
        password: "invalid",
      });
    }),
  );
  check(
    failures.filter((r) => r.code === 401).length === 5 &&
      failures.filter((r) => r.code === 429).length === 2,
    "並列ログイン失敗を5回までに原子的に制限",
  );
  const limited = new Client();
  const limitedToken = await limited.token("/staff/login");
  check(
    (
      await limited.req(
        "/staff/login",
        "POST",
        {
          csrf: limitedToken.csrf,
          login: "different-unknown",
          password: "invalid",
        },
        { "X-Forwarded-For": "203.0.113.1" },
      )
    ).code === 429,
    "未知ユーザー・偽接続元ヘッダーでIP制限を回避できない",
  );
  control({ op: "reset-limiter" });
  control({ op: "reset-mfa" });
  const m1 = new Client(),
    m2 = new Client();
  await password(m1, "owner");
  await password(m2, "owner");
  const t1 = await m1.token("/staff/mfa"),
    t2 = await m2.token("/staff/mfa");
  const code = totp(fixture.owner.secret);
  const races = await Promise.all([
    m1.req("/staff/mfa", "POST", { csrf: t1.csrf, code }),
    m2.req("/staff/mfa", "POST", { csrf: t2.csrf, code }),
  ]);
  check(
    races.filter((r) => r.code === 303).length === 1 &&
      races.filter((r) => r.code === 401).length === 1,
    "並列MFAで同じカウンターを1回だけ消費",
  );
  fs.writeFileSync(
    path.join(dir, "http-results.json"),
    JSON.stringify({ passed: report.length, checks: report }, null, 2),
  );
  console.log(`PASS: ${report.length} private HTTPS/DB integration checks`);
})().catch((e) => {
  console.error("FAIL: " + e.message);
  process.exitCode = 1;
});
