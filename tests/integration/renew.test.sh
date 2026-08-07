#!/usr/bin/env bash
#
# renew.test.sh — renew the buyer's active recurring partner service.
#
# Requires an Active, recurring, partner-type service for the buyer — run
# purchase-order.test.sh with the Recurring option first.
#
# Runs renew.test.php on the dev box in three steps:
#   1. RENEW_STAGE=force  — finds the buyer's + reseller's service and forces
#      both due today (UpdateClientProduct)
#   2. runs WHMCS's own daily "Generate Invoices" automation task directly
#      over SSH (crons/cron.php do --CreateInvoices) — PHP-CLI on this box has
#      exec()/shell_exec()/proc_open() disabled, so this step can't run from
#      inside the PHP script itself
#   3. RENEW_STAGE=finish (default) — confirms both renewal invoices were
#      generated, pays the buyer's from the buyer's own credit (which is what
#      makes WHMCS advance nextduedate and relay the renewal through the Hub,
#      settling the reseller's invoice from the reseller's credit), and
#      asserts both sides
#
# This script does NOT clean up anything before or after running — cleanup
# only ever happens in purchase-order.test.sh.
#
# ⚠ Spends real buyer AND reseller (test) credit. Step 2 runs WHMCS's real
#   "Generate Invoices" cron task, which sweeps every due service on this dev
#   WHMCS (fine here — every client on it is a test account).
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, WHMCS_DEV_URL

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
WEBROOT="${WHMCS_DEV_WEBROOT:-/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html}"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

echo "== Uploading renew test to the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -q "$SCRIPT_DIR/renew.test.php" "$SSH_HOST":tmp/

echo "== Step 1/3: forcing buyer + reseller service due today"
"${SSH[@]}" "RENEW_STAGE=force php ~/tmp/renew.test.php"

echo "== Step 2/3: running WHMCS's Generate Invoices automation task"
"${SSH[@]}" "cd '$WEBROOT/crons' && php cron.php do --CreateInvoices"

echo "== Step 3/3: paying the buyer's renewal invoice and asserting both sides"
"${SSH[@]}" "php ~/tmp/renew.test.php; rc=\$?; rm -rf ~/tmp/renew.test.php ~/tmp/lib; exit \$rc"
