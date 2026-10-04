import { defineConfig } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseURL = process.env.WHMCS_DEV_URL ?? 'https://whmcs-dev.vpnhood.com';

// browser tests run ONLY against the dev install — never production
if (baseURL.includes('account.vpnhood.com') || !baseURL.includes('whmcs-dev')) {
  throw new Error(`REFUSED: e2e tests run only against whmcs-dev, got: ${baseURL}`);
}

export default defineConfig({
  testDir: '.',
  // failure screenshots, traces and Playwright's run state go to the temp folder, not the repo
  outputDir: join(tmpdir(), 'vh', 'whmcs-e2e'),
  timeout: 90_000,
  workers: 1, // the specs share one WHMCS client's state — strictly serial
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    // watching a run: `run-e2e.sh --headed` with E2E_SLOWMO=500 makes each step visible
    launchOptions: { slowMo: Number(process.env.E2E_SLOWMO ?? 0) }
  }
});
