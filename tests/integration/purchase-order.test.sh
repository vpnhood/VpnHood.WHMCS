#!/usr/bin/env bash
#
# purchase-order.test.sh — buyer places a real order through the connector.
#
# Ensures the environment (init-skeleton), asks whether to buy a One-time or
# Recurring product (always a one-month billing cycle), then uploads and runs
# purchase-order.test.php on the dev box: the buyer's order is placed and paid
# via localAPI + the buyer's own credit (never a raw DB write), the connector
# provisions through the Hub from the reseller's credit, and the access code
# lands in service properties.
#
# This is the ONLY script in the lifecycle suite that cleans up stale state —
# it wipes any pre-existing orders/services/invoices for BOTH the buyer and
# the reseller before placing its own new order. renew/suspend/unsuspend/
# terminate never do this; they act on whatever this script leaves behind.
#
# The order is left ACTIVE on both sides. Renewal, suspension, and termination
# are separate scripts (renew.test.sh, suspend.test.sh, unsuspend.test.sh,
# terminate.test.sh).
#
# ⚠ Spends real reseller AND buyer (test) credit, and provisions a real
#   access token.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, WHMCS_DEV_URL, SKIP_INIT=1
#                PRODUCT_TYPE=onetime|recurring (skips the interactive prompt)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${SITE_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac
SECRETS="$VH_ROOT/.user/account-dev.vpnhood.com/secrets.json"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

if [ "${SKIP_INIT:-0}" != "1" ]; then
  "$REPO_ROOT/tests/bootstrap/init-skeleton.sh"
fi

PRODUCT_TYPE="${PRODUCT_TYPE:-}"
while [ "$PRODUCT_TYPE" != "onetime" ] && [ "$PRODUCT_TYPE" != "recurring" ]; do
  read -r -p "Purchase which product type? [1] One-time  [2] Recurring: " choice
  case "$choice" in
    1) PRODUCT_TYPE=onetime ;;
    2) PRODUCT_TYPE=recurring ;;
    *) echo "Please enter 1 or 2." ;;
  esac
done
echo "== Product type: $PRODUCT_TYPE =="

echo "== Running purchase-order test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/purchase-order.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "PRODUCT_TYPE=$(printf %q "$PRODUCT_TYPE") \
  php ~/tmp/purchase-order.test.php; rc=\$?; rm -rf ~/tmp/purchase-order.test.php ~/tmp/lib; exit \$rc"
