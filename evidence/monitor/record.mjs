// Records the Cacheer Monitor walkthrough: a 1440×900 silent video plus one
// screenshot per stop. Run through record.sh, which starts the dashboard and
// the traffic generator first; the timings below follow traffic.php's
// 75-second run, whose Redis outage spans roughly 34s–45s.
import { chromium } from 'playwright';
import { mkdirSync, readdirSync, renameSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const media = join(here, 'media');
const videoTmp = join(here, 'var', 'video');
const url = process.env.MONITOR_URL ?? 'http://127.0.0.1:9966/';
mkdirSync(media, { recursive: true });
rmSync(videoTmp, { recursive: true, force: true });

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  recordVideo: { dir: videoTmp, size: { width: 1440, height: 900 } },
});
const page = await context.newPage();
const started = Date.now();

const at = async (seconds) => {
  const wait = seconds * 1000 - (Date.now() - started);
  if (wait > 0) await page.waitForTimeout(wait);
};
const shot = (name) => page.screenshot({ path: join(media, `${name}.png`) });
const goTo = async (selector) => {
  await page.evaluate((s) => document.querySelector(s)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), selector);
  await page.waitForTimeout(1200);
};
const filter = async (value) => {
  await page.selectOption('#typeFilter', value);
  await page.waitForTimeout(800);
};

await page.goto(url);
await at(14); await goTo('#metricsSection'); await shot('01-overview');
await at(20); await goTo('#chartsSection'); await shot('02-performance');
await at(26); await goTo('#metricsSection');
await page.getByRole('button', { name: /Promotions/ }).click();
await page.waitForTimeout(1500); await shot('03-promotions');
await filter('');
await at(42); await goTo('#eventsSection'); await filter('error'); await shot('04-outage-errors');
await at(47); await goTo('#metricsSection'); await shot('05-overview-after-outage');
await at(53); await filter(''); await goTo('#deepDiveSection'); await shot('06-cache-explorer');
await at(59); await goTo('#eventsSection');
const key = page.locator('#eventsSection button.key-link', { hasText: 'tenant-acme/product:1' }).first();
await ((await key.count()) ? key : page.locator('#eventsSection button.key-link').first()).click();
await page.waitForTimeout(2000); await shot('07-key-inspector');
await at(68);

await context.close();
await browser.close();
const [video] = readdirSync(videoTmp).filter((f) => f.endsWith('.webm'));
renameSync(join(videoTmp, video), join(media, 'walkthrough.webm'));
rmSync(videoTmp, { recursive: true, force: true });
console.log(`Recorded ${Math.round((Date.now() - started) / 1000)}s to media/walkthrough.webm`);
