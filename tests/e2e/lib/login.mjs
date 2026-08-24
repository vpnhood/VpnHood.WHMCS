// Client-area login for the dedicated e2e client. WHMCS's stock (Twenty-One)
// login form uses #inputEmail/#inputPassword; fall back to the field names so
// a re-themed install still logs in.
export const E2E_EMAIL = 'e2e-buyer@vpnhood.test';

export async function login(page) {
  const password = process.env.E2E_CLIENT_PASSWORD ?? '';
  if (!password) throw new Error('E2E_CLIENT_PASSWORD is not set — run through run-e2e.sh');

  await page.goto('/index.php?rp=/login');
  const email = page.locator('#inputEmail, input[name="username"]').first();
  const pass = page.locator('#inputPassword, input[name="password"]').first();
  await email.fill(E2E_EMAIL);
  await pass.fill(password);
  await Promise.all([
    page.waitForURL(/clientarea/i, { timeout: 30_000 }),
    page.locator('#login, button[type="submit"]').first().click()
  ]);
}
