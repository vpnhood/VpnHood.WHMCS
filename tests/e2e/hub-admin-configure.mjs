// hub-admin-configure.mjs — visual admin prep for the VpnHood! Partner Hub addon.
//
// Opens a headed Chromium, logs into the dev WHMCS admin, goes to
// System Settings → Addon Modules, activates the Partner Hub if needed,
// opens its Configure form and pre-fills it:
//   - unchecks "Require IP Allowlist"
//   - fills "Order Payment Gateway"
//   - checks Access Control "Full Administrator"
// It does NOT save — the browser stays open so you can review and click
// Save Changes yourself. Ctrl+C in the terminal closes it.
//
// Credentials: <Vh root>/.user/account-dev.vpnhood.com/secrets.json
//   { "adminUser": "...", "adminPassword": "..." }
// (or env WHMCS_ADMIN_USER / WHMCS_ADMIN_PASSWORD).
//
// Config (env, optional):
//   WHMCS_DEV_URL       default https://whmcs-dev.vpnhood.com
//   HUB_ORDER_GATEWAY   default banktransfer

import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SECRETS_PATH = join(HERE, '..', '..', '..', '.user', 'account-dev.vpnhood.com', 'secrets.json');

const BASE_URL = (process.env.WHMCS_DEV_URL ?? 'https://whmcs-dev.vpnhood.com').replace(/\/$/, '');
const ORDER_GATEWAY = process.env.HUB_ORDER_GATEWAY ?? 'banktransfer';
const MODULE = 'vpnhoodpartnerhub';

function loadCredentials() {
  let user = process.env.WHMCS_ADMIN_USER;
  let password = process.env.WHMCS_ADMIN_PASSWORD;
  if (!user || !password) {
    try {
      const s = JSON.parse(readFileSync(SECRETS_PATH, 'utf8'));
      user ??= s.adminUser;
      password ??= s.adminPassword;
    } catch {
      // fall through to the error below
    }
  }
  if (!user || !password) {
    console.error(`No admin credentials. Add adminUser/adminPassword to ${SECRETS_PATH}`);
    console.error('or set WHMCS_ADMIN_USER / WHMCS_ADMIN_PASSWORD.');
    process.exit(1);
  }
  return { user, password };
}

const step = (msg) => console.log(`\n== ${msg}`);
const note = (msg) => console.log(`   ${msg}`);

async function login(page, creds) {
  step('Logging in to admin');
  await page.goto(`${BASE_URL}/admin/login.php`);
  await page.fill('input[name="username"]', creds.user);
  await page.fill('input[name="password"]', creds.password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);

  // If we are still on the login page (bad password, 2FA, …) let the human
  // finish logging in inside the open browser, and poll until it works.
  for (let attempt = 0; attempt < 60; attempt++) {
    await page.goto(`${BASE_URL}/admin/configaddonmods.php`, { waitUntil: 'domcontentloaded' });
    if (!page.url().includes('login.php')) return;
    if (attempt === 0) note('Not logged in yet (2FA or wrong password?) — finish the login in the browser, I will keep checking…');
    await page.waitForTimeout(5000);
  }
  throw new Error('Gave up waiting for a successful admin login.');
}

// WHMCS renders one row per addon: an anchor <a name="MODULE">, buttons
// #MODULE_disable / #MODULE_configure, and a hidden inline config form in
// <td id="MODULEconfig"> that showConfig() reveals. Field inputs are named
// fields[MODULE][Setting]; access checkboxes access[MODULE][roleId].
async function activateIfNeeded(page) {
  step(`Locating "${MODULE}" on Addon Modules page`);
  // the named anchor is an empty (zero-size) element — wait for presence, not visibility
  await page.locator(`a[name="${MODULE}"]`).first().waitFor({ state: 'attached', timeout: 15000 });

  if (await page.locator(`#${MODULE}_disable`).count()) {
    note('Already active.');
    return;
  }
  step('Activating the Hub addon');
  const row = page.locator(`a[name="${MODULE}"]`).first().locator('xpath=ancestor::tr[1]');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    row.locator('input[value="Activate"]:not([disabled])').click(),
  ]);
  await page.locator(`#${MODULE}_configure`).waitFor({ timeout: 15000 });
  note('Activated.');
}

async function openConfigure(page) {
  step('Opening Configure');
  const form = page.locator(`#${MODULE}config`);
  if (!(await form.isVisible())) {
    await page.locator(`#${MODULE}_configure`).click();
    await form.waitFor({ state: 'visible', timeout: 10000 });
  }
  await form.scrollIntoViewIfNeeded();
}

async function fillForm(page) {
  step('Pre-filling the Hub configuration (NOT saving)');

  await page
    .locator(`input[type="checkbox"][name="fields[${MODULE}][RequireIpAllowlist]"]`)
    .uncheck();
  note('Require IP Allowlist: unchecked');

  await page.locator(`input[name="fields[${MODULE}][OrderGateway]"]`).fill(ORDER_GATEWAY);
  note(`Order Payment Gateway: "${ORDER_GATEWAY}"`);

  await page.locator(`input[type="checkbox"][name="access[${MODULE}][1]"]`).check();
  note('Access Control: Full Administrator checked');
}

const creds = loadCredentials();
const browser = await chromium.launch({ headless: false });
const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: null });

// WHMCS confirms a successful save with a banner at the top of the page.
// Wait (indefinitely) for the human to click Save Changes, then report.
async function awaitSaveConfirmation(page) {
  step('Done pre-filling — review the form and click "Save Changes" yourself.');
  note('Waiting for the "Changes Saved Successfully" banner…');
  await page.getByText('Changes Saved Successfully').first().waitFor({ timeout: 0 });
  step('PASS — "Changes Saved Successfully" banner appeared.');
  note('The browser stays open. Ctrl+C here closes it.');
}

try {
  await login(page, creds);
  await activateIfNeeded(page);
  await openConfigure(page);
  await fillForm(page);
  await awaitSaveConfirmation(page);
} catch (err) {
  console.error(`\n!! ${err.message}`);
  console.error('Leaving the browser open so you can continue manually.');
}

// Keep the browser open until the user closes it or kills this process.
await new Promise((resolve) => {
  browser.on('disconnected', resolve);
  process.on('SIGINT', async () => { await browser.close(); resolve(); });
});
