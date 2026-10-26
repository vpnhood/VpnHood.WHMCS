#!/usr/bin/env bash
#
# low-credit-alert.test.sh — the low-credit email (low-credit-alert.test.php) on the dev box, over the
# real Hub API as the test partner (.env). Installs a dev-only hook (hooks/vhtest-slow-mail.php) into
# includes/hooks for the slow-mail step and removes it again.
#
# ⚠ Spends reseller (test) credit, tops it up once and provisions real tokens; every key is
#   terminated at the end.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, WHMCS_DEV_WEBROOT

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
WEBROOT="${WHMCS_DEV_WEBROOT:-/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html}"
if [ -f "$SCRIPT_DIR/.env" ]; then set -a; . "$SCRIPT_DIR/.env"; set +a; fi
: "${HUB_URL:?run tests/bootstrap/init-skeleton.sh first (it writes tests/integration/.env)}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${HUB_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")
ENV="RUN=$(date +%s) HUB_URL=$(printf %q "$HUB_URL") HUB_KEY=$(printf %q "$HUB_KEY") HUB_SECRET=$(printf %q "$HUB_SECRET")"
HOOK="$WEBROOT/includes/hooks/vhtest-slow-mail.php"

cleanup() {
  "${SSH[@]}" "rm -f '$HOOK' ~/tmp/vhtest-slow-mail; rm -rf ~/tmp/low-credit-alert.test.php ~/tmp/lib" || true
}
trap cleanup EXIT

echo "== Running low-credit-alert test on the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/low-credit-alert.test.php" "$SSH_HOST":tmp/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/hooks/vhtest-slow-mail.php" "$SSH_HOST":"$HOOK"
"${SSH[@]}" "$ENV php ~/tmp/low-credit-alert.test.php"
