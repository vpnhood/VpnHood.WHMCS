// Clear Ticket IP Addresses (includes/hooks/vpnhood-ticket-ip.php) on the path where WHMCS records
// an IP itself: a signed-in client opening a ticket from the client area. Off, the IP is stored and
// shown under the first message; on, the ticket keeps none and the page shows none.
// tests/integration/ticket-ip.test.php covers the hooks by name and the daily run.
import { test, expect } from '@playwright/test';
import { login } from './lib/login.mjs';
import { setState } from './lib/state.mjs';

const IP_LINE = 'IP Address'; // support.ipAddress, printed under a message only when it carries an IP
const DEPARTMENT_ID = 1; // dev's visible department

let previous;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => { previous = setState('ticket-ip-setting off').previous; setState('drop-tickets'); });
test.afterAll(() => { setState(`ticket-ip-setting ${previous}`); setState('drop-tickets'); });

async function openTicket(page, subject) {
  await page.goto(`/submitticket.php?step=2&deptid=${DEPARTMENT_ID}`);
  await page.locator('input[name="subject"]').fill(subject);
  await page.locator('textarea[name="message"]').fill('vhtest ticket ip: opened from the client area');
  await page.locator('#openTicketSubmit').click();
  // the confirmation answers on the form's own URL, with a link to the new ticket
  await page.locator('a[href*="viewticket.php?tid="]').first().waitFor({ timeout: 30_000 });
  const ticket = setState('newest-ticket');
  await page.goto(`/viewticket.php?tid=${ticket.tid}&c=${ticket.c}`);
  await expect(page.locator('body')).toContainText(subject);
  return ticket;
}

test('off: WHMCS keeps the opening client\'s IP and shows it', async ({ page }) => {
  await login(page);
  const ticket = await openTicket(page, 'vhtest ticket ip: off');
  expect(ticket.hasIp).toBe(true);
  await expect(page.locator('body')).toContainText(IP_LINE);
});

test('on: the ticket keeps no IP and the page shows none', async ({ page }) => {
  setState('ticket-ip-setting on');
  await login(page);
  const ticket = await openTicket(page, 'vhtest ticket ip: on');
  expect(ticket.hasIp).toBe(false);
  await expect(page.locator('body')).not.toContainText(IP_LINE);
});
