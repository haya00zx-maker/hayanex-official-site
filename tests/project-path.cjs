// 起動済みの親ディレクトリ配信サーバーで、プロジェクト配下の相対URLを実測。
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");
const base =
  process.env.PROJECT_URL || "http://127.0.0.1:8011/hayanex-official-site/";
const files = fs
  .readdirSync(path.resolve(__dirname, ".."))
  .filter((f) => f.endsWith(".html"));
(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  try {
    let count = 0;
    for (const width of [375, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      page.on("console", (m) => {
        if (m.type() === "error") errors.push(m.text());
      });
      page.on("response", (r) => {
        if (r.status() >= 400) errors.push(`${r.status()} ${r.url()}`);
      });
      for (const file of files) {
        const r = await page.goto(new URL(file, base).href);
        assert.equal(r.status(), 200);
        assert.equal(
          await page.evaluate(() => getComputedStyle(document.body).color),
          "rgb(25, 47, 73)",
        );
        assert(
          await page
            .locator("img")
            .evaluateAll((imgs) =>
              imgs.every((i) => i.complete && i.naturalWidth > 0),
            ),
        );
        const urls = await page
          .locator("a[href],script[src],link[href],img[src]")
          .evaluateAll((es) => es.map((e) => e.href || e.src));
        for (const url of urls) assert(url.startsWith(base), url);
        if (width === 375) {
          await page.locator(".nav-toggle").click();
          assert(await page.locator("#primary-nav").isVisible());
          await page.keyboard.press("Escape");
          assert.equal(
            await page.locator(".nav-toggle").getAttribute("aria-expanded"),
            "false",
          );
        }
        count++;
      }
      assert.deepEqual(errors, []);
      await page.close();
    }
    console.log(
      `PASS: プロジェクト配下 ${count}ページ表示、CSS/JS/画像/相対URL/メニュー`,
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
