// The dev WHMCS the browser tests run against. This repo is public and never names the server:
// its address, SSH login and admin-login file come from the private <Vh root>/.user/whmcs/dev.env
// (KEY=VALUE lines), and an environment variable of the same name wins over its line. The guards
// compare against the file's own values, so an override cannot vouch for itself.
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, isAbsolute, join } from 'node:path';

const VH_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const ENV_FILE = join(VH_ROOT, '.user', 'whmcs', 'dev.env');
if (!existsSync(ENV_FILE)) {
  throw new Error(`${ENV_FILE} not found: it names the dev WHMCS server`);
}

const file = {};
for (const line of readFileSync(ENV_FILE, 'utf8').split(/\r?\n/)) {
  const match = line.match(/^(WHMCS_DEV_[A-Z_]+)=(.*)$/);
  if (match) file[match[1]] = match[2];
}
if (!file.WHMCS_DEV_URL || !file.WHMCS_DEV_SSH_HOST) {
  throw new Error(`${ENV_FILE} must name WHMCS_DEV_URL and WHMCS_DEV_SSH_HOST`);
}

/** What the guards allow: the dev site's host and the dev box's SSH user, from the file only. */
export const DEV_HOST = new URL(file.WHMCS_DEV_URL).host;
export const DEV_SSH_USER = file.WHMCS_DEV_SSH_HOST.split('@')[0];

export const DEV_URL = (process.env.WHMCS_DEV_URL || file.WHMCS_DEV_URL).replace(/\/+$/, '');
export const DEV_SSH_HOST = process.env.WHMCS_DEV_SSH_HOST || file.WHMCS_DEV_SSH_HOST;
const secrets = process.env.WHMCS_DEV_SECRETS || file.WHMCS_DEV_SECRETS || '';
/** The admin logins and test passwords (secrets.json). */
export const DEV_SECRETS = secrets && !isAbsolute(secrets) ? join(VH_ROOT, '.user', secrets) : secrets;
