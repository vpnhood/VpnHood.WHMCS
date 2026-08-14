#!/usr/bin/env bash
#
# sync-products.test.sh — the connector addon's "create missing products" sync.
#
# Uploads and runs sync-products.test.php on the dev box: it offers one extra
# product to the test partner (temporary Hub mapping), reads it back over the
# live Hub API exactly as the addon page does, runs the real
# vpnhoodpartnerconfig sync, and asserts the created product is wired to the
# connector, hidden, unpriced, and carries exactly the upstream's billing cycles.
#
# Safe to run anytime: it never places an order, so it spends no credit and
# provisions no access token. It cleans up both the product it creates and the
# temporary mapping, pass or fail, and touches no other state.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${SITE_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Running product sync test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/sync-products.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "php ~/tmp/sync-products.test.php; rc=\$?; rm -rf ~/tmp/sync-products.test.php ~/tmp/lib; exit \$rc"
