// The checkout hold, as the held client sees it (vpnhoodverify): a fresh
// unconfirmed client with an unpaid order — what the AfterShoppingCartCheckout
// hook leaves behind (tests/integration/verify-checkout.test.php proves the
// hook itself) — signs in and finds every door leads to the confirmation
// page, which knows about the waiting invoice and never about anyone else's.
//
// Requires the dev addon active with Enforce Verification = Yes and
// Applies To = New clients only (the held client is created today).
//
// Why not drive the real cart: the dev install currently rejects every
// register-at-checkout POST with "No payment gateways available" (reproduced
// with every VpnHood hook disabled — a dev gateway-config problem, not code),
// so the client and order come from the state driver instead.
import { test, expect } from '@playwright/test';
import { setState } from './lib/state.mjs';

const GATE_URL = /index\.php\?m=vpnhoodverify/;
const GATE_TITLE = 'Confirm your email address';
const HELD_TEXT = 'Your order is saved and nothing has been charged';
const PLAIN_TEXT = 'nothing further is owed';

let held; // { clientId, email, orderId, invoiceId } — password is E2E_CLIENT_PASSWORD

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => { setState('drop-verify-clients'); held = setState('held-client'); });
test.afterAll(() => { setState('drop-verify-clients'); });

async function loginHeld(page) {
  await page.goto('/index.php?rp=/login');
  await page.locator('#inputEmail, input[name="username"]').first().fill(held.email);
  await page.locator('#inputPassword, input[name="password"]').first().fill(process.env.E2E_CLIENT_PASSWORD ?? '');
  await Promise.all([
    page.waitForURL(GATE_URL, { timeout: 30_000 }), // the client-area gate meets them at the door
    page.locator('#login, button[type="submit"]').first().click()
  ]);
}

test('the confirmation page names the waiting order when it carries the invoice', async ({ page }) => {
  await loginHeld(page);
  await page.goto(`/index.php?m=vpnhoodverify&invoice=${held.invoiceId}`);
  await expect(page.locator('body')).toContainText(GATE_TITLE);
  await expect(page.locator('body')).toContainText(HELD_TEXT);
  await expect(page.locator('body')).not.toContainText(PLAIN_TEXT);
  // WHMCS's own banner at the top of the page gets the spam hint appended
  await expect(page.locator('.email-verification')).toContainText('Check your spam or junk folder');
  // the resend form posts the invoice back so the pointer survives a round trip
  await expect(page.locator('input[name="invoice"]')).toHaveValue(String(held.invoiceId));
});

test('an invoice that is not theirs is ignored', async ({ page }) => {
  await loginHeld(page);
  await page.goto(`/index.php?m=vpnhoodverify&invoice=${held.invoiceId + 100000}`);
  await expect(page.locator('body')).toContainText(PLAIN_TEXT);
  await expect(page.locator('input[name="invoice"]')).toHaveCount(0);
});

test('the invoice itself stays behind the gate until the address is confirmed', async ({ page }) => {
  await loginHeld(page);
  await page.goto(`/viewinvoice.php?id=${held.invoiceId}`);
  await page.waitForURL(GATE_URL, { timeout: 30_000 });
  await expect(page.locator('body')).toContainText(GATE_TITLE);
});
