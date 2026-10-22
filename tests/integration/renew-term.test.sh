#!/usr/bin/env bash
#
# renew-term.test.sh — a partner key's term and its renewals (renew-term.test.php) on the dev box,
# over the real Hub API as the test partner (.env).
#
# ⚠ Spends reseller (test) credit and provisions one real token; the key is terminated at the end.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
if [ -f "$SCRIPT_DIR/.env" ]; then set -a; . "$SCRIPT_DIR/.env"; set +a; fi
: "${HUB_URL:?run tests/bootstrap/init-skeleton.sh first (it writes tests/integration/.env)}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${HUB_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")
ENV="RUN=$(date +%s) HUB_URL=$(printf %q "$HUB_URL") HUB_KEY=$(printf %q "$HUB_KEY") HUB_SECRET=$(printf %q "$HUB_SECRET")"

echo "== Running renew-term test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/renew-term.test.php" "$SSH_HOST":tmp/
"${SSH[@]}" "$ENV php ~/tmp/renew-term.test.php; rc=\$?; rm -rf ~/tmp/renew-term.test.php ~/tmp/lib; exit \$rc"
