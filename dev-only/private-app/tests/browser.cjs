"use strict";
const fs = require("node:fs"),
  path = require("node:path"),
  crypto = require("node:crypto"),
  assert = require("node:assert/strict"),
  { spawnSync } = require("node:child_process"),
  { chromium } = require("playwright");
const dir = process.env.HAYANEX_DEV_RUNTIME || "/tmp/hayanex-private-20261109";
const fixture = JSON.parse(
  fs.readFileSync(path.join(dir, "fixtures.json"), "utf8"),
);
const artifacts =
  process.env.QA_ARTIFACTS || "/tmp/hayanex-private-browser-20261109";
fs.mkdirSync(artifacts, { recursive: true });
const cert = fs.readFileSync(path.join(dir, "tls.crt"));
const spki = new crypto.X509Certificate(cert).publicKey.export({
  type: "spki",
  format: "der",
});
const pin = crypto.createHash("sha256").update(spki).digest("base64");
const axe = fs.readFileSync(
  process.env.AXE_CORE_PATH ||
    "/tmp/hayanex-qa/node_modules/axe-core/axe.min.js",
  "utf8",
);
function resetMfa() {
  const env = { ...process.env };
  for (const k of [
    "DOCKER_HOST",
    "DOCKER_CONTEXT",
    "DOCKER_TLS",
    "DOCKER_TLS_VERIFY",
    "DOCKER_CERT_PATH",
  ])
    delete env[k];
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
    { input: '{"op":"reset-mfa"}', env, encoding: "utf8" },
  );
  assert(r.status === 0, "Private fixture reset failed");
}
function totp(secret) {
  let bits = "";
  for (const c of secret)
    bits += "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"
      .indexOf(c)
      .toString(2)
      .padStart(5, "0");
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8)
    bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = crypto
    .createHmac("sha1", Buffer.from(bytes))
    .update(counter)
    .digest();
  return String((h.readUInt32BE(h[19] & 15) & 0x7fffffff) % 1000000).padStart(
    6,
    "0",
  );
}
(async () => {
  // Trust this generated local test certificate's exact public key; do not disable TLS verification globally.
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox", "--ignore-certificate-errors-spki-list=" + pin],
  });
  const { HtmlValidate } = require(
    process.env.HTML_VALIDATE_PATH ||
      "/tmp/hayanex-qa/node_modules/html-validate",
  );
  const validator = new HtmlValidate({
    extends: ["html-validate:recommended"],
  });
  const results = [],
    errors = [];
  try {
    for (const width of [320, 375, 768, 1440]) {
      resetMfa();
      const context = await browser.newContext({
        viewport: { width, height: 900 },
      });
      const page = await context.newPage();
      let mainDocument = Promise.resolve("");
      page.on("response", (response) => {
        if (
          response.request().isNavigationRequest() &&
          response.request().frame() === page.mainFrame() &&
          response.status() === 200 &&
          (response.headers()["content-type"] || "").includes("text/html")
        ) {
          mainDocument = response.text();
          // Mark teardown rejection handled; audits still await the original promise.
          mainDocument.catch(() => {});
        }
      });
      page.on("pageerror", (e) => errors.push(e.message));
      page.on("console", (m) => {
        if (m.type() === "error") errors.push(m.text());
      });
      page.on("response", (r) => {
        if (r.status() >= 400) errors.push(String(r.status()));
      });
      async function audit(name) {
        assert.equal(await page.locator("h1").count(), 1, name);
        const markup = await validator.validateString(
          await mainDocument,
          name + ".html",
        );
        assert(
          markup.valid,
          name +
            " HTML: " +
            JSON.stringify(
              markup.results.flatMap((r) =>
                r.messages.map((m) => ({
                  rule: m.ruleId,
                  line: m.line,
                  message: m.message,
                })),
              ),
            ),
        );
        await page.evaluate(axe);
        const result = await page.evaluate(async () =>
          axe.run(document, {
            runOnly: {
              type: "tag",
              values: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"],
            },
          }),
        );
        assert(
          result.violations.length === 0,
          `${name} axe: ` +
            JSON.stringify(
              result.violations.map((x) => ({
                id: x.id,
                nodes: x.nodes.map((n) => ({
                  target: n.target,
                  summary: n.failureSummary,
                })),
              })),
            ),
        );
        assert(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          name + " overflow",
        );
        await page.evaluate(
          () => (document.documentElement.style.fontSize = "200%"),
        );
        assert(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          name + " 200% overflow",
        );
        await page.evaluate(
          () => (document.documentElement.style.fontSize = ""),
        );
        if ([375, 1440].includes(width))
          await page.screenshot({
            path: path.join(artifacts, `${name}-${width}.png`),
            fullPage: true,
            mask: [
              page.locator('input[type="password"]'),
              page.locator('input[name="code"]'),
            ],
          });
        results.push({ name, width, axeViolations: 0 });
      }
      await page.goto("https://127.0.0.1:24443/contact");
      await audit("contact");
      await page.goto("https://127.0.0.1:24443/staff/login");
      await audit("login");
      await page.getByLabel("スタッフID").fill(fixture.owner.login);
      await page
        .getByLabel("パスワード", { exact: true })
        .fill(fixture.owner.password);
      await page.getByLabel("パスワード", { exact: true }).press("Enter");
      await page.waitForURL("**/staff/mfa", { timeout: 8000 });
      await audit("mfa");
      await page
        .getByLabel("認証アプリの6桁コード")
        .fill(totp(fixture.owner.secret));
      await page.getByRole("button", { name: "追加認証を確認する" }).click();
      await page.waitForURL("**/staff");
      await audit("dashboard");
      const link = page.locator('a[href^="/staff/inquiries/"]').first();
      assert(await link.count());
      await link.click();
      await audit("detail");
      await page.getByLabel("状況", { exact: true }).selectOption("reviewing");
      await Promise.all([
        page.waitForNavigation(),
        page
          .getByRole("button", { name: "状況を更新する", exact: true })
          .click(),
      ]);
      assert.equal(
        await page.getByLabel("状況", { exact: true }).inputValue(),
        "reviewing",
      );
      assert.equal(await page.locator("img[src=x]").count(), 0);
      await page.getByRole("link", { name: "監査履歴", exact: true }).click();
      await audit("audit");
      await page
        .getByRole("link", { name: "スタッフ管理", exact: true })
        .click();
      await audit("accounts");
      await page
        .getByRole("button", { name: "ログアウト", exact: true })
        .click();
      await page.waitForURL("**/staff/login");
      await mainDocument;
      await context.close();
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(
      path.join(artifacts, "results.json"),
      JSON.stringify({ checks: results.length, results }, null, 2),
    );
    console.log(
      "PASS: " + results.length + " private browser / axe / responsive checks",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error("FAIL: " + e.message);
  process.exitCode = 1;
});
