import { defineConfig } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DEV_HOST, DEV_URL } from './lib/dev-server.mjs';

const baseURL = DEV_URL;

// browser tests run ONLY against the dev install — never production
if (baseURL.includes('account.vpnhood.com') || !baseURL.includes(DEV_HOST)) {
  throw new Error(`REFUSED: e2e tests run only against the dev WHMCS, got: ${baseURL}`);
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
