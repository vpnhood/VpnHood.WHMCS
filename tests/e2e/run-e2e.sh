#!/usr/bin/env bash
#
# run-e2e.sh — browser (Playwright) tests for the hub store flows, against the
# DEV WHMCS only: the checkout warning's three voices and the reseller CSV
# delivery. Uploads e2e-state.php to the dev box (the specs drive scenarios
# through it over SSH), installs Playwright on first run, and cleans up after.
#
# Usage: tests/e2e/run-e2e.sh [playwright args, e.g. cart-notice.spec.mjs]
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, WHMCS_DEV_URL

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"
SECRETS="$VH_ROOT/.user/account-dev.vpnhood.com/secrets.json"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${SITE_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
[ -f "$SECRETS" ] || { echo "secrets file not found: $SECRETS" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

# a native Windows python cannot open an MSYS /c/... path — hand it the OS form
SECRETS_OS="$(cygpath -w "$SECRETS" 2>/dev/null || echo "$SECRETS")"
E2E_CLIENT_PASSWORD="$(python -c "import json,sys; print(json.load(open(sys.argv[1]))['testClientPassword'])" "$SECRETS_OS")"
[ -n "$E2E_CLIENT_PASSWORD" ] || { echo "testClientPassword missing from secrets.json" >&2; exit 1; }

echo "== Uploading the scenario driver to the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/e2e-state.php" "$SSH_HOST":tmp/

echo "== Installing Playwright (first run only)"
cd "$SCRIPT_DIR"
[ -d node_modules ] || npm install --no-fund --no-audit
npx playwright install chromium

echo "== Running the browser tests against $SITE_URL"
rc=0
E2E_SSH_KEY="$SSH_KEY" E2E_SSH_HOST="$SSH_HOST" E2E_CLIENT_PASSWORD="$E2E_CLIENT_PASSWORD" \
  WHMCS_DEV_URL="$SITE_URL" npx playwright test "$@" || rc=$?

echo "== Final cleanup"
"${SSH[@]}" "E2E_CLIENT_PASSWORD='$E2E_CLIENT_PASSWORD' php ~/tmp/e2e-state.php clean >/dev/null; rm -f ~/tmp/e2e-state.php" || true
exit $rc
