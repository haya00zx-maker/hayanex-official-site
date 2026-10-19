// QA tooling only; no runtime dependencies. See README.md for reproduction.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");
const axePath =
  process.env.AXE_CORE_PATH || require.resolve("axe-core/axe.min.js");
const base = process.env.SITE_URL || "http://127.0.0.1:8000/";
const artifacts = process.env.QA_ARTIFACTS || "/tmp/hayanex-review";
fs.mkdirSync(artifacts, { recursive: true });
(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
    headless: true,
    args: ["--no-sandbox"],
  });
  const failures = [];
  const report = {
    base,
    generatedAt: new Date().toISOString(),
    checks: [],
    performance: [],
    accessibility: [],
  };
  try {
    for (const width of [320, 375, 390, 480, 760, 768, 1024, 1440]) {
      const context = await browser.newContext({
        viewport: { width, height: 900 },
        deviceScaleFactor: 1,
      });
      const page = await context.newPage();
      page.on("pageerror", (e) => failures.push(e.message));
      page.on("console", (msg) => {
        if (msg.type() === "error") failures.push(msg.text());
      });
      page.on("response", (r) => {
        if (r.status() >= 400) failures.push(`${r.status()} ${r.url()}`);
      });
      const external = [];
      page.on("request", (r) => {
        if (!r.url().startsWith(new URL(base).origin)) external.push(r.url());
      });
      for (const file of ["index.html", "privacy.html", "legal.html"]) {
        const response = await page.goto(new URL(file, base).href);
        assert.equal(response.status(), 200);
        await page.evaluate(() => document.fonts.ready);
        assert.equal(await page.locator("h1").count(), 1);
        assert.equal(
          await page.locator('meta[name="robots"]').getAttribute("content"),
          "noindex, nofollow",
        );
        assert.equal(await page.locator("form, input, iframe").count(), 0);
        const overflow = await page.evaluate(() => {
          const max = document.documentElement.clientWidth;
          return [...document.querySelectorAll("body *")]
            .filter((e) => {
              const r = e.getBoundingClientRect();
              return (
                r.width > 0 &&
                (r.right > max + 1 || r.left < -1) &&
                !e.classList.contains("skip")
              );
            })
            .map((e) => e.tagName + "." + e.className);
        });
        assert.deepEqual(overflow, [], `overflow ${width} ${file}`);
        assert(
          await page
            .locator("img")
            .evaluateAll((imgs) =>
              imgs.every((i) => i.complete && i.naturalWidth > 0),
            ),
        );
        await page.evaluate(fs.readFileSync(axePath, "utf8"));
        const a11y = await page.evaluate(async () =>
          axe.run(document, {
            runOnly: {
              type: "tag",
              values: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"],
            },
          }),
        );
        report.accessibility.push({
          width,
          file,
          violations: a11y.violations.map((v) => ({
            id: v.id,
            impact: v.impact,
            nodes: v.nodes.map((n) => n.target),
          })),
        });
        assert.equal(
          a11y.violations.length,
          0,
          `axe ${width} ${file}: ${JSON.stringify(a11y.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) })))}`,
        );
        // Follow every same-origin link, including relative paths under a Pages project prefix.
        const hrefs = await page
          .locator("a")
          .evaluateAll((as) => as.map((a) => a.href));
        for (const href of new Set(hrefs)) {
          const url = new URL(href);
          assert.equal(url.origin, new URL(base).origin);
          const check = await context.request.get(url.href);
          assert.equal(check.status(), 200, href);
          if (url.hash) {
            const content = await check.text();
            assert(content.includes(`id="${url.hash.slice(1)}"`), href);
          }
        }
        if (file === "index.html") {
          const button = page.locator(".nav-toggle");
          if (width <= 760) {
            assert.equal(await page.locator("#primary-nav").isVisible(), false);
            await button.focus();
            await page.keyboard.press("Enter");
            assert.equal(await button.getAttribute("aria-expanded"), "true");
            await page.keyboard.press("Tab");
            assert.equal(
              await page.evaluate(() =>
                document.activeElement.getAttribute("href"),
              ),
              "#about",
            );
            await page.keyboard.press("Escape");
            assert.equal(await button.getAttribute("aria-expanded"), "false");
            assert.equal(
              await button.evaluate((e) => e === document.activeElement),
              true,
            );
            await button.click();
            await page.locator('#primary-nav a[href="#business"]').click();
            assert.equal(await button.getAttribute("aria-expanded"), "false");
            assert.equal(
              await page.evaluate(() => document.activeElement.id),
              "business",
            );
            await button.click();
            await page.locator(".hero h1").click();
            assert.equal(await button.getAttribute("aria-expanded"), "false");
          } else {
            assert.equal(await button.isVisible(), false);
            assert.equal(await page.locator("#primary-nav").isVisible(), true);
          }
          const summary = page.locator(".faq-list summary").first();
          await summary.focus();
          await page.keyboard.press("Enter");
          assert.equal(
            await summary.evaluate((e) => e.parentElement.open),
            true,
          );
          await page.keyboard.press("Enter");
          assert.equal(
            await summary.evaluate((e) => e.parentElement.open),
            false,
          );
          report.performance.push(
            await page.evaluate(() => ({
              width: innerWidth,
              domContentLoadedMs:
                performance.getEntriesByType("navigation")[0]
                  .domContentLoadedEventEnd,
              resources: performance
                .getEntriesByType("resource")
                .filter((r) => !r.name.includes("favicon"))
                .map((r) => ({
                  name: r.name.split("/").pop(),
                  bytes: r.decodedBodySize,
                })),
            })),
          );
          await page.goto(new URL(file, base).href);
          if ([375, 768, 1440].includes(width))
            await page.screenshot({
              path: path.join(artifacts, `home-${width}.png`),
              fullPage: true,
            });
        }
        report.checks.push(
          `${width}px ${file}: layout / images / links / axe / draft state passed`,
        );
      }
      assert.deepEqual(external, [], "unexpected external request");
      await context.close();
    }
    const context = await browser.newContext({
      viewport: { width: 375, height: 812 },
      javaScriptEnabled: false,
    });
    const page = await context.newPage();
    await page.goto(base);
    assert.equal(await page.locator("#primary-nav").isVisible(), true);
    assert.equal(await page.locator(".nav-toggle").isVisible(), false);
    await context.close();
    report.checks.push("JavaScript disabled: navigation remains accessible");
    const zoom = await browser.newContext({
      viewport: { width: 1280, height: 900 },
    });
    const zp = await zoom.newPage();
    await zp.goto(base);
    await zp.setViewportSize({ width: 375, height: 900 });
    await zp.locator(".nav-toggle").click();
    await zp.setViewportSize({ width: 1280, height: 900 });
    await zp.setViewportSize({ width: 375, height: 900 });
    assert.equal(
      await zp.locator(".nav-toggle").getAttribute("aria-expanded"),
      "false",
    );
    await zp.emulateMedia({ reducedMotion: "reduce" });
    assert.equal(
      await zp.evaluate(
        () => getComputedStyle(document.documentElement).scrollBehavior,
      ),
      "auto",
    );
    // Text enlargement tests reflow without changing the physical viewport.
    await zp.evaluate(() => {
      document.documentElement.style.fontSize = "200%";
    });
    assert(
      await zp.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "200% text overflow",
    );
    await zoom.close();
    report.checks.push(
      "Resize state reset / reduced motion / 200% text reflow passed",
    );
    assert.deepEqual(failures, [], "browser errors");
    fs.writeFileSync(
      path.join(artifacts, "results.json"),
      JSON.stringify(report, null, 2),
    );
    console.log(
      `PASS: ${report.checks.length} checks, ${report.accessibility.length} axe audits, no browser errors. Artifacts: ${artifacts}`,
    );
  } catch (error) {
    fs.writeFileSync(
      path.join(artifacts, "failure.json"),
      JSON.stringify({ message: error.message, report, failures }, null, 2),
    );
    throw error;
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
