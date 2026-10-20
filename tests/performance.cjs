const assert = require("node:assert/strict");
const { chromium } = require("playwright");
const fs = require("node:fs");
const path = require("node:path");
const files = fs
  .readdirSync(path.resolve(__dirname, ".."))
  .filter((f) => f.endsWith(".html"))
  .sort();
(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  try {
    for (const width of [375, 1440]) {
      for (const file of files) {
        const context = await browser.newContext({
          viewport: { width, height: 900 },
        });
        await context.addInitScript(() => {
          window.metrics = { cls: 0, lcpMs: null };
          new PerformanceObserver((list) => {
            for (const e of list.getEntries())
              if (!e.hadRecentInput) window.metrics.cls += e.value;
          }).observe({ type: "layout-shift", buffered: true });
          new PerformanceObserver((list) => {
            const entries = list.getEntries();
            window.metrics.lcpMs = entries[entries.length - 1].startTime;
          }).observe({ type: "largest-contentful-paint", buffered: true });
        });
        const page = await context.newPage();
        await page.goto(
          new URL(file, process.env.SITE_URL || "http://127.0.0.1:8000/").href,
          {
            waitUntil: "networkidle",
          },
        );
        const values = await page.evaluate(() => ({
          ...window.metrics,
          width: innerWidth,
          fcpMs: performance.getEntriesByName("first-contentful-paint")[0]
            ?.startTime,
          bytes:
            performance.getEntriesByType("navigation")[0].decodedBodySize +
            performance
              .getEntriesByType("resource")
              .reduce((sum, r) => sum + r.decodedBodySize, 0),
          image: (
            document.querySelector(".logo-card img") ||
            document.querySelector("img")
          ).currentSrc
            .split("/")
            .pop(),
          fonts: document.fonts.status,
          cssRules: [...document.styleSheets].reduce(
            (n, s) => n + s.cssRules.length,
            0,
          ),
        }));
        assert(values.lcpMs !== null);
        assert.equal(values.cls, 0);
        assert(values.bytes < 250000);
        assert(values.cssRules > 0);
        console.log(JSON.stringify({ file, ...values }));
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
