#!/usr/bin/env bash
#
# Runner for its namesake .test.php — see that file for the contract.
#
# The purchase email carries the code (includes/hooks/vpnhoodstore-welcome-code.php) on the dev WHMCS.
# Needs the fixtures (tests/bootstrap/init-skeleton.sh), the working tree deployed
# (scripts/deploy-dev.sh hub) and scripts/welcome-code-template.php run on the install. Places one
# real order as a client of its own; ends its token and deletes the client.
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

echo "== Running welcome-code test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/welcome-code.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "php ~/tmp/welcome-code.test.php; rc=\$?; rm -rf ~/tmp/welcome-code.test.php ~/tmp/lib; exit \$rc"
