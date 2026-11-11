#!/usr/bin/env bash
#
# Runner for its namesake .test.php — see that file for the contract.
#
# The refund memory and its warning (includes/hooks/vpnhood-refund-memory.php) on the
# dev WHMCS. Needs the fixtures (tests/bootstrap/init-skeleton.sh) and the working tree
# deployed (scripts/deploy-dev.sh hub). Places no order and spends no credit; removes
# what it creates.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

. "$REPO_ROOT/scripts/dev-server.sh"  # the dev WHMCS's names, from <Vh root>/.user/whmcs/dev.env
SSH_KEY="$WHMCS_DEV_SSH_KEY"
SSH_HOST="$WHMCS_DEV_SSH_HOST"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${SITE_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *"$WHMCS_DEV_SSH_USER@"*) ;; "") ;; *) echo "!! REFUSED: only $WHMCS_DEV_SSH_USER@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Running refund-memory test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/refund-memory.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "php ~/tmp/refund-memory.test.php; rc=\$?; rm -rf ~/tmp/refund-memory.test.php ~/tmp/lib; exit \$rc"
