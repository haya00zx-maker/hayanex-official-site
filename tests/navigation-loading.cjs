const assert = require("node:assert/strict");
const { chromium } = require("playwright");
const base = process.env.SITE_URL || "http://127.0.0.1:8000/";
(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  try {
    for (const mode of ["遅延", "失敗"]) {
      const context = await browser.newContext({
        viewport: { width: 375, height: 812 },
      });
      await context.addInitScript(() => {
        window.layoutShift = 0;
        new PerformanceObserver((list) => {
          for (const e of list.getEntries())
            if (!e.hadRecentInput) window.layoutShift += e.value;
        }).observe({ type: "layout-shift", buffered: true });
      });
      await context.route("**/assets/js/site.js", async (route) => {
        if (mode === "失敗") await route.abort("failed");
        else {
          await new Promise((resolve) => setTimeout(resolve, 1200));
          await route.continue();
        }
      });
      const page = await context.newPage();
      await page.goto(new URL("pricing.html", base).href, {
        waitUntil: "networkidle",
      });
      if (mode === "遅延") {
        assert.equal(await page.evaluate(() => window.layoutShift), 0);
        await page.locator(".nav-toggle").click();
        assert.equal(
          await page.locator(".nav-toggle").getAttribute("aria-expanded"),
          "true",
        );
      } else {
        assert(await page.locator("#primary-nav").isVisible());
        assert.equal(await page.locator(".nav-toggle").isVisible(), false);
      }
      console.log(`${mode}: 合格`);
      await context.close();
    }
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
