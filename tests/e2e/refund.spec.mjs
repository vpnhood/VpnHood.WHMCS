// The admin Refund form decides a full refund (lifecycle §8): Reverse Payment ticked ends the
// key, unticked keeps it. The hook reads the box from the form's own request, so this drives the
// real form — a WHMCS update that renames the field fails here before it reaches production.
import { test, expect } from '@playwright/test';
import { setState } from './lib/state.mjs';
import { adminLogin } from './lib/admin-login.mjs';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => { setState('clean'); });
test.afterAll(() => { setState('clean'); }); // clean terminates the kept key

/** Refunds the whole invoice through its Refund tab: manual refund, no email. */
async function refundInFull(page, invoiceId, total, reverse) {
  await page.goto(`/admin/invoices.php?action=edit&id=${invoiceId}`);
  const form = page.locator('form:has(input[name="sub"][value="refund"])');
  await page.locator('a[data-toggle="tab"], a[role="tab"]').filter({ hasText: /^\s*Refund\s*$/ }).first().click();

  // the field the hook reads: a hidden 0 the ticked box overrides with 1
  await expect(form.locator('input[type="hidden"][name="reverse"]')).toHaveValue('0');
  await expect(form.locator('input[type="checkbox"][name="reverse"]')).toHaveValue('1');

  const transactions = form.locator('select[name="transid"]');
  const payment = await transactions.locator('option').evaluateAll(os => os.map(o => o.value).find(v => v !== ''));
  await transactions.selectOption(payment);
  await form.locator('input[name="amount"]').fill(total);
  await form.locator('select[name="refundtype"]').selectOption(''); // Manual Refund Processed Externally
  await form.locator('input[type="checkbox"][name="sendemail"]').setChecked(false);
  await form.locator('input[type="checkbox"][name="reverse"]').setChecked(reverse);
  page.once('dialog', dialog => dialog.accept());
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    form.locator('button[type="submit"], input[type="submit"]').first().click()
  ]);
  await expect(page.getByText('Refund Successful')).toBeVisible();
}

test('Reverse Payment unticked keeps the key running', async ({ page }) => {
  const { serviceId, invoiceId, total } = setState('paid-key');
  await adminLogin(page);
  await refundInFull(page, invoiceId, total, false);
  expect(setState(`service-state ${serviceId}`)).toEqual({ status: 'Active', keyEnabled: true });
});

test('Reverse Payment ticked ends the key', async ({ page }) => {
  const { serviceId, invoiceId, total } = setState('paid-key');
  await adminLogin(page);
  await refundInFull(page, invoiceId, total, true);
  expect(setState(`service-state ${serviceId}`)).toEqual({ status: 'Terminated', keyEnabled: false });
});
