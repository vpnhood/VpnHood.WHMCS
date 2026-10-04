// admin-page-load.mjs — WHMCS runs an addon's upgrade, and records the version it deployed, only when
// an admin page loads. After addon files are deployed this opens the admin login in a headed browser
// for you to sign in (a scripted sign-in can fail the login page's reCAPTCHA), then loads Addon
// Modules and the dashboard, saves a picture of the addon list and closes. Waits 10 minutes at most.
//
//   node admin-page-load.mjs <server>
//
// The admin URL is the "adminUrl" entry of <Vh root>/.user/<server>/secrets.json (or secrets.txt).
// The browser profile and the picture are kept in <temp>/vh/whmcs-admin/<server>/; a live admin
// session in that profile skips the sign-in.

import { chromium } from 'playwright';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const server = process.argv[2];
if (!server) {
  throw new Error('usage: node admin-page-load.mjs <server>');
}

const HERE = dirname(fileURLToPath(import.meta.url));
const userDir = join(HERE, '..', '..', '..', '.user', server);
const secretsPath = ['secrets.json', 'secrets.txt'].map((name) => join(userDir, name)).find((path) => existsSync(path));
const adminUrl = secretsPath && (readFileSync(secretsPath, 'utf8').match(/"adminUrl"\s*:\s*"([^"]*)"/) ?? [])[1];
if (!adminUrl) {
  throw new Error(`no "adminUrl" in ${join(userDir, 'secrets.json')} or secrets.txt`);
}
const base = adminUrl.endsWith('/') ? adminUrl : `${adminUrl}/`;
const outDir = join(tmpdir(), 'vh', 'whmcs-admin', server);
mkdirSync(outDir, { recursive: true });
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const context = await chromium.launchPersistentContext(join(outDir, 'profile'), {
  headless: false,
  args: ['--disable-blink-features=AutomationControlled'],
  viewport: { width: 1280, height: 900 },
});
const page = context.pages()[0] ?? await context.newPage();
try {
  await page.goto(base, { waitUntil: 'domcontentloaded' });
  console.log('WAITING FOR LOGIN');
  const deadline = Date.now() + 10 * 60 * 1000;
  while (await page.locator('input[name="password"]').count()) {
    if (Date.now() > deadline) {
      throw new Error('no login within 10 minutes');
    }
    await pause(1500);
  }
  await page.waitForLoadState('domcontentloaded');
  await pause(1500);
  await page.goto(`${base}configaddonmods.php`, { waitUntil: 'domcontentloaded' });
  await pause(3000);
  const picture = join(outDir, 'addonmods.png');
  await page.screenshot({ path: picture, fullPage: true });
  await page.goto(`${base}index.php`, { waitUntil: 'domcontentloaded' });
  await pause(1500);
  console.log(`ADMIN PAGES LOADED; addon list: ${picture}`);
} finally {
  await context.close();
}
