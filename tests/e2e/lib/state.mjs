// Scenario driver: runs e2e-state.php on the dev box over SSH (uploaded there
// by run-e2e.sh) and returns its JSON. The guard mirrors the shell runners:
// only the dev box, ever.
import { execFileSync } from 'node:child_process';

const sshKey = process.env.E2E_SSH_KEY;
const sshHost = process.env.E2E_SSH_HOST ?? 'whmcsdev@webhost-ftps.vpnhood.com';

export function setState(scenario) {
  if (!sshHost.includes('whmcsdev@') || sshHost.includes('account.vpnhood.com')) {
    throw new Error(`REFUSED: state changes only on the dev box, got: ${sshHost}`);
  }
  if (!sshKey) {
    throw new Error('E2E_SSH_KEY is not set — run through run-e2e.sh');
  }

  const password = process.env.E2E_CLIENT_PASSWORD ?? '';
  const output = execFileSync('ssh',
    ['-i', sshKey, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', sshHost,
      `E2E_CLIENT_PASSWORD='${password}' php ~/tmp/e2e-state.php ${scenario}`],
    { encoding: 'utf8' });
  const lastLine = output.trim().split('\n').pop();
  return JSON.parse(lastLine);
}
