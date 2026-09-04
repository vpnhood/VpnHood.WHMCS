#!/usr/bin/env bash
#
# Runner for its namesake .test.php — see that file for the contract.
#
# Uploads and runs verify-checkout.test.php on the dev box: fires the
# vpnhoodverify AfterShoppingCartCheckout hook through run_hook() for a fresh
# unconfirmed client (held) and again once confirmed (not held), and checks
# the gate-URL / pending-invoice helpers. Self-contained: creates one
# throwaway client and deletes it in its own cleanup.
#
# Requires the dev addon active with Enforce Verification = Yes.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${SITE_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Running verify-checkout test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/verify-checkout.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "php ~/tmp/verify-checkout.test.php; rc=\$?; rm -rf ~/tmp/verify-checkout.test.php ~/tmp/lib; exit \$rc"
