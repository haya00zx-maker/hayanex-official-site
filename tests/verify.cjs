// 実行方法はREADME.mdを参照。サイト配信時の依存関係ではありません。
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");
const root = path.resolve(__dirname, "..");
const files = fs
  .readdirSync(root)
  .filter((f) => f.endsWith(".html"))
  .sort();
const base = process.env.SITE_URL || "http://127.0.0.1:8000/";
const artifacts = process.env.QA_ARTIFACTS || "/tmp/hayanex-review-2026-10-20";
const axeSource = fs.readFileSync(
  process.env.AXE_CORE_PATH || require.resolve("axe-core/axe.min.js"),
  "utf8",
);
fs.mkdirSync(artifacts, { recursive: true });
(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  const report = {
    generatedAt: new Date().toISOString(),
    base,
    pages: files,
    checks: [],
    accessibility: [],
  };
  const failures = [];
  const checkedLinks = new Set();
  const monitor = (page) => {
    page.on("pageerror", (e) => failures.push(e.message));
    page.on("console", (m) => {
      if (m.type() === "error") failures.push(m.text());
    });
    page.on("response", (r) => {
      if (r.status() >= 400) failures.push(`${r.status()} ${r.url()}`);
    });
    page.on("request", (r) => {
      if (new URL(r.url()).origin !== new URL(base).origin)
        failures.push(`外部通信: ${r.url()}`);
    });
  };
  const noOverflow = async (page, label) => {
    const bad = await page.evaluate(() =>
      [...document.querySelectorAll("body *")]
        .filter((e) => {
          const r = e.getBoundingClientRect();
          return (
            r.width > 0 &&
            (r.right > innerWidth + 1 || r.left < -1) &&
            !e.classList.contains("skip")
          );
        })
        .map((e) => `${e.tagName}.${e.className}`),
    );
    assert.deepEqual(bad, [], label);
  };
  const audit = async (page, label) => {
    await page.evaluate(axeSource);
    const result = await page.evaluate(async () =>
      axe.run(document, {
        runOnly: {
          type: "tag",
          values: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"],
        },
      }),
    );
    const violations = result.violations.map((v) => ({
      id: v.id,
      nodes: v.nodes.map((n) => n.target),
    }));
    report.accessibility.push({ label, violations });
    assert.deepEqual(violations, [], label);
  };
  try {
    assert.equal(
      files.length,
      11,
      "想定11ページ。追加・削除時は公開対象とテストを見直してください。",
    );
    for (const width of [320, 375, 390, 480, 760, 768, 1024, 1440]) {
      const context = await browser.newContext({
        viewport: { width, height: 900 },
      });
      const page = await context.newPage();
      monitor(page);
      for (const file of files) {
        const label = `${file} ${width}px`;
        assert.equal((await page.goto(new URL(file, base).href)).status(), 200);
        await page.evaluate(() => document.fonts.ready);
        assert.equal(await page.locator("h1").count(), 1, label);
        assert.equal(
          await page.locator('meta[name="robots"]').getAttribute("content"),
          "noindex, nofollow",
        );
        assert.equal(
          await page.locator('meta[name="referrer"]').getAttribute("content"),
          "strict-origin-when-cross-origin",
        );
        const csp = await page
          .locator('meta[http-equiv="Content-Security-Policy"]')
          .getAttribute("content");
        for (const directive of [
          "default-src 'self'",
          "connect-src 'none'",
          "object-src 'none'",
          "base-uri 'none'",
          "form-action 'none'",
        ])
          assert(csp.includes(directive), label);
        assert(!/unsafe-inline|unsafe-eval/.test(csp));
        assert.equal(
          await page.locator('form,iframe,input[type="password"]').count(),
          0,
        );
        assert.equal(
          await page.evaluate(() => getComputedStyle(document.body).color),
          "rgb(25, 47, 73)",
          "CSS未読込",
        );
        assert(
          await page
            .locator("img")
            .evaluateAll((imgs) =>
              imgs.every((i) => i.complete && i.naturalWidth > 0),
            ),
        );
        await noOverflow(page, label);
        const links = await page
          .locator("a")
          .evaluateAll((as) => as.map((a) => a.href));
        for (const href of new Set(links)) {
          if (checkedLinks.has(href)) continue;
          const url = new URL(href);
          assert.equal(url.origin, new URL(base).origin);
          const response = await context.request.get(href);
          assert.equal(response.status(), 200, href);
          if (url.hash)
            assert(
              (await response.text()).includes(`id="${url.hash.slice(1)}"`),
              href,
            );
          checkedLinks.add(href);
        }
        const toggle = page.locator(".nav-toggle");
        if (width <= 760) {
          assert.equal(await page.locator("#primary-nav").isVisible(), false);
          const box = await toggle.boundingBox();
          assert(box.width >= 44 && box.height >= 44, label);
          await toggle.focus();
          await page.keyboard.press("Enter");
          assert.equal(await toggle.getAttribute("aria-expanded"), "true");
          await page.keyboard.press("Tab");
          assert.equal(
            await page.evaluate(() =>
              document.activeElement.getAttribute("href"),
            ),
            "index.html#business",
          );
          await page.keyboard.press("Escape");
          assert.equal(await toggle.getAttribute("aria-expanded"), "false");
          assert(await toggle.evaluate((e) => e === document.activeElement));
          await toggle.focus();
          await page.keyboard.press("Space");
          assert.equal(await toggle.getAttribute("aria-expanded"), "true");
          await audit(page, `${label} メニュー展開`);
          await page.keyboard.press("Escape");
        } else {
          assert.equal(await toggle.isVisible(), false);
          assert.equal(await page.locator("#primary-nav").isVisible(), true);
        }
        if (file === "pricing.html") {
          assert.equal(await page.locator(".plan").count(), 6);
          assert.equal(
            await page
              .locator(".plan-price")
              .filter({ hasText: "料金未確定" })
              .count(),
            6,
          );
          assert(
            (await page.locator("main").innerText()).includes(
              "月額保守契約は任意",
            ),
          );
        }
        const faq = page.locator(".faq-list summary").first();
        if (await faq.count()) {
          await faq.focus();
          await page.keyboard.press("Enter");
          assert(await faq.evaluate((e) => e.parentElement.open));
          await page.keyboard.press("Enter");
          assert.equal(await faq.evaluate((e) => e.parentElement.open), false);
        }
        await audit(page, label);
        // 拡大しても全ページの内容が横へはみ出さないことを確認。
        await page.evaluate(() => {
          document.documentElement.style.fontSize = "200%";
        });
        await noOverflow(page, `${label} 文字200%`);
        await page.evaluate(() => {
          document.documentElement.style.fontSize = "";
        });
        if ([375, 1440].includes(width)) {
          await page.evaluate(() => {
            document.activeElement.blur();
            scrollTo(0, 0);
          });
          await page.screenshot({
            path: path.join(
              artifacts,
              `${file.replace(".html", "")}-${width}.png`,
            ),
            fullPage: true,
          });
        }
        report.checks.push(
          `${label}: 読込・見出し・CSP・画像・リンク・メニュー・拡大 合格`,
        );
      }
      await context.close();
    }
    // 全ページでJavaScriptなしでもナビゲーションを利用可能。検証入力は無効のまま。
    const nojs = await browser.newContext({
      viewport: { width: 375, height: 812 },
      javaScriptEnabled: false,
    });
    const nj = await nojs.newPage();
    for (const file of files) {
      await nj.goto(new URL(file, base).href);
      assert(await nj.locator("#primary-nav").isVisible());
      assert.equal(await nj.locator(".nav-toggle").isVisible(), false);
      if (file === "contact.html") {
        assert.equal(
          await nj.locator("#demo-fields").getAttribute("disabled"),
          "",
        );
        assert(await nj.locator("#demo-name").isDisabled());
        assert(await nj.locator("#demo-check").isDisabled());
      }
    }
    await nojs.close();
    report.checks.push("全11ページ: JavaScript無効の導線と入力停止 合格");
    const context = await browser.newContext({
      viewport: { width: 375, height: 812 },
    });
    const page = await context.newPage();
    monitor(page);
    await page.goto(new URL("index.html", base).href);
    await page.locator(".nav-toggle").click();
    await page.locator('#primary-nav a[href="index.html#business"]').click();
    assert.equal(
      await page.evaluate(() => document.activeElement.id),
      "business",
    );
    await page.locator(".nav-toggle").click();
    await page.locator(".hero h1").click();
    assert.equal(
      await page.locator(".nav-toggle").getAttribute("aria-expanded"),
      "false",
    );
    await page.locator(".nav-toggle").click();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.setViewportSize({ width: 375, height: 812 });
    assert.equal(
      await page.locator(".nav-toggle").getAttribute("aria-expanded"),
      "false",
    );
    await page.emulateMedia({ reducedMotion: "reduce" });
    assert.equal(
      await page.evaluate(
        () => getComputedStyle(document.documentElement).scrollBehavior,
      ),
      "auto",
    );
    report.checks.push(
      "アンカーのフォーカス・外側クリック・リサイズ・動きを減らす設定 合格",
    );
    await page.goto(new URL("contact.html", base).href);
    await page.waitForLoadState("networkidle");
    const requests = [];
    page.on("request", (r) =>
      requests.push({ url: r.url(), method: r.method() }),
    );
    const check = page.locator("#demo-check");
    await check.click();
    assert.equal(await page.locator('[aria-invalid="true"]').count(), 5);
    assert.equal(
      await page.evaluate(() => document.activeElement.id),
      "demo-name",
    );
    await page.locator("#demo-sample").click();
    await check.click();
    assert.equal(await page.locator('[aria-invalid="true"]').count(), 1);
    await page.locator("#demo-test-only").check();
    await check.click();
    assert(
      (await page.locator("#demo-status").innerText()).includes(
        "送信・保存・受付は行っていません",
      ),
    );
    await page.locator("#demo-email").fill("not-an-email");
    await check.click();
    assert.equal(
      await page.locator("#demo-email").getAttribute("aria-invalid"),
      "true",
    );
    await page.locator("#demo-email").fill("sample@example.com");
    for (const [id, max] of [
      ["demo-name", 50],
      ["demo-message", 2000],
      ["demo-email", 254],
    ]) {
      await page.locator("#" + id).evaluate((e, n) => {
        e.value = "あ".repeat(n + 1);
      }, max);
      await check.click();
      assert.equal(
        await page.locator("#" + id).getAttribute("aria-invalid"),
        "true",
      );
      await page.locator("#demo-sample").click();
      await page.locator("#demo-test-only").check();
    }
    await page.locator("#demo-name").fill("   ");
    await check.click();
    assert.equal(
      await page.locator("#demo-name").getAttribute("aria-invalid"),
      "true",
    );
    await page.locator("#demo-name").fill("あ".repeat(50));
    await page.locator("#demo-message").fill("あ".repeat(2000));
    await check.click();
    assert.equal(await page.locator('[aria-invalid="true"]').count(), 0);
    const payload = '<img src=x onerror="window.injected=true">';
    await page.locator("#demo-message").fill(payload);
    await check.click();
    assert.equal(await page.evaluate(() => window.injected), undefined);
    assert.equal(await page.locator("#demo-status img").count(), 0);
    await page.locator("#demo-clear").click();
    assert.equal(await page.locator("#demo-email").inputValue(), "");
    assert.equal(await page.locator("#demo-test-only").isChecked(), false);
    assert.equal(
      await page.evaluate(() => localStorage.length + sessionStorage.length),
      0,
    );
    assert.deepEqual(await context.cookies(), []);
    assert.deepEqual(requests, [], "入力検証中の通信がある");
    await page.locator("#demo-check").click();
    await audit(page, "入力エラー表示");
    await page.locator("#demo-sample").click();
    await page.locator("#demo-test-only").check();
    await check.click();
    await audit(page, "入力確認完了表示");
    await page.reload();
    assert.equal(await page.locator("#demo-name").inputValue(), "");
    await context.close();
    report.checks.push(
      "検証入力: 必須・メール・文字数境界・空白・XSS・消去・再読込・通信なし・保存なし 合格",
    );
    assert.deepEqual(failures, [], "ブラウザーエラー");
    report.checkedLinks = checkedLinks.size;
    fs.writeFileSync(
      path.join(artifacts, "results.json"),
      JSON.stringify(report, null, 2),
    );
    console.log(
      `PASS: ${report.checks.length}検証、${report.accessibility.length}回axe、${checkedLinks.size}リンク。${artifacts}`,
    );
  } catch (e) {
    fs.writeFileSync(
      path.join(artifacts, "failure.json"),
      JSON.stringify({ message: e.message, report, failures }, null, 2),
    );
    throw e;
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
