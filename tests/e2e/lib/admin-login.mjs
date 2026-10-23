// Admin-area login for the dev WHMCS, with the adminUser/adminPassword run-e2e.sh reads from
// secrets.json.
export async function adminLogin(page) {
  const user = process.env.E2E_ADMIN_USER ?? '';
  const password = process.env.E2E_ADMIN_PASSWORD ?? '';
  if (!user || !password) throw new Error('E2E_ADMIN_USER / E2E_ADMIN_PASSWORD are not set — run through run-e2e.sh');

  await page.goto('/admin/login.php');
  await page.fill('input[name="username"]', user);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForURL(url => !url.pathname.endsWith('/login.php'), { timeout: 30_000 }),
    page.locator('button[type="submit"], input[type="submit"]').first().click()
  ]);
}
