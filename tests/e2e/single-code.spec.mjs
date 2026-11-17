// A single code in the client area reads the way the app shows one (AccessCodeUtils.Format: a dash
// after every four characters), and stays left to right on a right-to-left page.
import { test, expect } from '@playwright/test';
import { setState } from './lib/state.mjs';
import { login } from './lib/login.mjs';

test.describe.configure({ mode: 'serial' });

let serviceId = 0;

test.beforeAll(() => {
  setState('clean');
  ({ serviceId } = setState('paid-key')); // a real key at the access manager
});
test.afterAll(() => { setState('clean'); }); // clean terminates the key

test('the service page shows the code in groups of four, left to right', async ({ page }) => {
  await login(page);
  await page.goto(`/clientarea.php?action=productdetails&id=${serviceId}`);
  await page.locator('#getPremiumCode').click();
  const code = page.locator('#resultBox .alert-success');
  await expect(code).toHaveText(/^[A-Za-z0-9]{4}(-[A-Za-z0-9]{4})+$/);
  await expect(code).toHaveAttribute('dir', 'ltr');
});
