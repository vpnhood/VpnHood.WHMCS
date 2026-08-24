// The checkout warning (lifecycle §8): a signed-in client who already holds
// something active is told what they have before they pay — and never blocked.
// It stays SILENT when buying again is the correct action, and the wording
// follows whether what they hold continues on its own.
import { test, expect } from '@playwright/test';
import { setState } from './lib/state.mjs';
import { login } from './lib/login.mjs';

const CART_URL = '/cart.php?a=add&pid=15'; // any interactive cart page; the hook keys on filename=cart

// the two exact voices of the notice (vpnhoodstore-cart-notice.php)
const ACTIVE_KEY_TEXT = 'You already have an active key';
const RENEWING_TEXT = 'renews on its own';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => { setState('clean'); });
test.afterAll(() => { setState('clean'); });

test('holding nothing, the cart says nothing', async ({ page }) => {
  await login(page);
  await page.goto(CART_URL);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('body')).not.toContainText(ACTIVE_KEY_TEXT);
  await expect(page.locator('body')).not.toContainText(RENEWING_TEXT);
});

test('an active one-time key warns — a new key never extends the old one', async ({ page }) => {
  setState('onetime-key');
  await login(page);
  await page.goto(CART_URL);
  const notice = page.locator('.alert-warning', { hasText: ACTIVE_KEY_TEXT });
  await expect(notice).toBeVisible();
  await expect(notice).toContainText('a new, separate key');
  // never blocks: the order form is still there, one step past the warning
  await expect(page.locator('#order-standard_cart, .main-content').first()).toBeVisible();
});

test('a self-renewing subscription warns with its renewal date', async ({ page }) => {
  setState('clean');
  setState('renewing');
  await login(page);
  await page.goto(CART_URL);
  const notice = page.locator('.alert-warning', { hasText: RENEWING_TEXT });
  await expect(notice).toBeVisible();
  await expect(notice).toContainText('does not extend or upgrade');
});
