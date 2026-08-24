// The reseller's bulk delivery (lifecycle §8: stock, delivered as a file): the
// client area shows the Download button — never a single code — and the CSV it
// serves carries the whole batch.
import { test, expect } from '@playwright/test';
import { setState } from './lib/state.mjs';
import { login } from './lib/login.mjs';

test.describe.configure({ mode: 'serial' });

let serviceId = 0;

test.beforeAll(() => {
  setState('clean');
  ({ serviceId } = setState('bulk')); // real qty-2 batch at the access manager
});
test.afterAll(() => { setState('clean'); }); // clean expires + disables the batch tokens

test('the service page offers the CSV download, not a single code', async ({ page }) => {
  await login(page);
  await page.goto(`/clientarea.php?action=productdetails&id=${serviceId}`);
  await expect(page.locator('#getPremiumCode')).toBeVisible();
  await expect(page.locator('#getPremiumCode')).toContainText('Download CSV');
});

test('the download answers the whole batch as CSV', async ({ page }) => {
  await login(page);
  // the button fires an XMLHttpRequest to the same page; replay it with the
  // session's cookies, exactly as assets/ajax-request.js does
  const response = await page.request.get(`/clientarea.php?action=productdetails&id=${serviceId}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type'] ?? '').toContain('text/csv');

  const csv = await response.text();
  const lines = csv.split('\n').map(l => l.trim()).filter(l => l !== '');
  expect(lines.length).toBeGreaterThanOrEqual(3); // header + the 2 batch rows
  expect(lines[0]).toContain('AccessCode');
  const dashedCodes = csv.match(/\b\d{4}-\d{4}-\d{4}-\d{4}-\d{4}\b/g) ?? [];
  expect(new Set(dashedCodes).size).toBeGreaterThanOrEqual(2);
});
