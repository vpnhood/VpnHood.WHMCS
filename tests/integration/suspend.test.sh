#!/usr/bin/env bash
#
# suspend.test.sh — suspend the buyer's active partner service.
#
# Requires an Active partner-type service for the buyer (either payment type —
# run purchase-order.test.sh first). Uploads and runs suspend.test.php on the
# dev box: calls localAPI('ModuleSuspend') on the buyer's service, which
# relays through the real vpnhoodpartner_SuspendAccount hook to the Hub's
# suspend action, and asserts both the buyer's and the reseller's service end
# up Suspended.
#
# This script does NOT clean up anything before or after running — cleanup
# only ever happens in purchase-order.test.sh.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Running suspend test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/suspend.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "php ~/tmp/suspend.test.php; rc=\$?; rm -rf ~/tmp/suspend.test.php ~/tmp/lib; exit \$rc"
