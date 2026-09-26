#!/usr/bin/env bash
#
# run.sh — the Hub's unit tests: pure PHP, no WHMCS and no database. There is no PHP here, so
# they run with the dev box's PHP CLI in ~/tmp/vhunit, which is removed afterwards; nothing else
# on the server is touched.
#
# Usage: tests/unit/run.sh
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Running the unit tests with the dev box's PHP"
"${SSH[@]}" 'rm -rf ~/tmp/vhunit && mkdir -p ~/tmp/vhunit'
scp -i "$SSH_KEY" -q "$REPO_ROOT/modules/addons/vpnhoodpartnerhub/lib/RefundPolicy.php" "$SCRIPT_DIR/refund-policy.test.php" "$SSH_HOST":tmp/vhunit/
"${SSH[@]}" 'php ~/tmp/vhunit/refund-policy.test.php ~/tmp/vhunit/RefundPolicy.php; rc=$?; rm -rf ~/tmp/vhunit; exit $rc'
