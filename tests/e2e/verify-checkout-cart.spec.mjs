// The checkout hold, driven through the real cart (vpnhoodverify): a visitor buys as a
// new customer on the checkout form, is held at the confirmation page with the order
// saved and unpaid, confirms the address through the link WHMCS mails, and the invoice
// then opens for payment. Paying it happens on the gateway's own page, outside this suite.
//
// Requires the dev addon active with Enforce Verification = Yes, and PID_CART in a product
// group that leaves at least one payment method (WHMCS refuses the order otherwise).
import { test, expect } from '@playwright/test';
import { setState } from './lib/state.mjs';

const PID_CART = 3;
const GATE_URL = /index\.php\?m=vpnhoodverify&invoice=(\d+)/;
const HELD_TEXT = 'Your order is saved and nothing has been charged';

test.afterAll(() => { setState('drop-verify-clients'); });

test('a new customer is held at checkout, confirms the address, and the invoice opens', async ({ page }) => {
  // the e2e-verify-* pattern is what drop-verify-clients cleans up
  const email = `e2e-verify-${Math.random().toString(16).slice(2, 10)}@vpnhood.test`;
  const password = process.env.E2E_CLIENT_PASSWORD ?? '';
  expect(password, 'E2E_CLIENT_PASSWORD is set by run-e2e.sh').not.toBe('');

  // the product joins the cart only when its configure form is submitted; the theme posts it
  // by script and forwards through the cart to the checkout by itself
  await page.goto(`/cart.php?a=add&pid=${PID_CART}`, { waitUntil: 'networkidle' });
  const configure = page.locator('#btnCompleteProductConfig, #btnCompleteProductConfigMob').filter({ visible: true }).first();
  await configure.waitFor({ timeout: 30_000 });
  await configure.click();
  await page.waitForURL(/a=(checkout|view|confdomains)/, { timeout: 30_000 });
  await page.waitForLoadState('networkidle');
  if (!page.url().includes('a=checkout')) {
    await page.goto('/cart.php?a=checkout', { waitUntil: 'networkidle' });
  }

  // the new-customer form
  const newCustomer = page.locator('input[name="custtype"][value="new"]');
  if (await newCustomer.count()) await newCustomer.check({ force: true });
  await page.fill('#inputFirstName', 'E2E');
  await page.fill('#inputLastName', 'Cart');
  await page.fill('#inputEmail', email);
  await page.fill('#inputNewPassword1', password);
  await page.fill('#inputNewPassword2', password);
  for (const question of await page.locator('select[name^="customfield["]').all()) {
    await question.selectOption({ index: 1 }); // any real answer to a required question
  }
  const gateway = page.locator('input[name="paymentmethod"]');
  if (await gateway.count()) await gateway.first().check({ force: true });
  const tos = page.locator('#accepttos').filter({ visible: true });
  if (await tos.count()) await tos.check();

  // placing the order lands on the confirmation page, not on the gateway
  await page.locator('#checkout, #btnCompleteOrder').filter({ visible: true }).first().click();
  await page.waitForURL(GATE_URL, { timeout: 60_000 });
  const invoiceId = Number(page.url().match(GATE_URL)[1]);
  await expect(page.locator('body')).toContainText(HELD_TEXT);

  // the mailed link confirms the address
  const { token } = setState(`verify-token ${email}`);
  await page.goto(`/index.php?rp=/user/verify/${token}`);

  // the invoice is no longer behind the gate, and nothing has been charged
  await page.goto(`/viewinvoice.php?id=${invoiceId}`);
  expect(page.url()).not.toMatch(/m=vpnhoodverify/);
  await expect(page.locator('body')).toContainText(/Unpaid/i);
});
