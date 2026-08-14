#!/usr/bin/env bash
#
# Runner for its namesake .test.php — see that file for the contract.
#
# The merchant bulk purchase end to end, no partner in the chain: a real qty>1
# order on the hub CSV product, the batch provisioned at the access manager,
# the stock marks, and the CSV export the client area's Download button uses.
#
# Standalone: creates and cleans up its own order; safe to run in any sequence.
#
# ⚠ Creates BULK_QTY (default 2) real tokens at the access manager; cleanup
#   expires + disables them when the CSV exposes their ids.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, BULK_QTY

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

echo "== Running bulk-order test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/bulk-order.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "BULK_QTY=${BULK_QTY:-2} php ~/tmp/bulk-order.test.php; rc=\$?; rm -rf ~/tmp/bulk-order.test.php ~/tmp/lib; exit \$rc"
