#!/usr/bin/env bash
#
# connector-order.test.sh — end-to-end buyer order through the connector.
#
# Ensures the environment (init-skeleton), then uploads and runs
# connector-order.test.php on the dev box: buyer orders the connector product,
# the connector provisions through the Hub from the reseller's credit, the
# access code lands in service properties, and the service is terminated again.
#
# ⚠ Spends ~2 USD of the reseller's (test) credit and provisions a real access
#   token, which the final terminate releases. CONNECTOR_TERMINATE=0 keeps it.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, WHMCS_DEV_URL, SKIP_INIT=1

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/whmcs/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"
SECRETS="$VH_ROOT/.user/whmcs/secrets-dev.json"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

if [ "${SKIP_INIT:-0}" != "1" ]; then
  "$REPO_ROOT/tests/bootstrap/init-skeleton.sh"
fi

SECRETS_NODE="$SECRETS"
command -v cygpath >/dev/null 2>&1 && SECRETS_NODE="$(cygpath -m "$SECRETS")"
secret() { SECRETS_PATH="$SECRETS_NODE" KEY_NAME="$1" node -p 'require(process.env.SECRETS_PATH)[process.env.KEY_NAME]'; }
ADMIN_USER="$(secret adminUser)"
ADMIN_PASS="$(secret adminPassword)"

echo "== Running connector order test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/connector-order.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "ADMIN_USER=$(printf %q "$ADMIN_USER") \
  ADMIN_PASS=$(printf %q "$ADMIN_PASS") \
  SITE_URL=$(printf %q "$SITE_URL") \
  CONNECTOR_TERMINATE=$(printf %q "${CONNECTOR_TERMINATE:-1}") \
  php ~/tmp/connector-order.test.php; rc=\$?; rm -f ~/tmp/connector-order.test.php; exit \$rc"
