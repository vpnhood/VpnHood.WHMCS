#!/usr/bin/env bash
#
# purchase-recovery.test.sh — the Hub's purchase records when things go wrong.
#
# Runs the scenarios of purchase-recovery.test.php on the dev box: insufficient credit, a
# failure after payment finished by the support procedure (keyed and keyless), a rollback that
# cannot be verified, a request that died after each step, a token whose record was lost, CSV
# delivery, the client-area and admin views, two keys racing to link one order, a renewal and
# an order competing for credit enough for one, and initialization (held, then resumed).
#
# The concurrent scenarios run as parallel processes started from this SSH session (PHP-CLI on
# the box cannot start any). The failed-rollback scenario installs a dev-only hook
# (hooks/vhtest-rollback-interference.php) into includes/hooks and removes it again.
#
# Usage: ./purchase-recovery.test.sh [scenario ...]   (default: every scenario, in order)
#
# ⚠ Spends reseller (test) credit and provisions real tokens; every order placed is terminated
#   (normal delivery) or neutralized (CSV). shared-credit runs WHMCS's Generate Invoices task,
#   which sweeps every due service on the dev install (all test accounts).
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, WHMCS_DEV_WEBROOT; HUB_URL, HUB_KEY and
# HUB_SECRET come from tests/integration/.env (written by tests/bootstrap/init-skeleton.sh).

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
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 "$SSH_HOST")

RUN="$(date +%s)"
ENV="RUN=$RUN HUB_URL=$(printf %q "$HUB_URL") HUB_KEY=$(printf %q "$HUB_KEY") HUB_SECRET=$(printf %q "$HUB_SECRET")"
T='~/tmp/purchase-recovery.test.php'
HOOK="$WEBROOT/includes/hooks/vhtest-rollback-interference.php"

cleanup() {
  "${SSH[@]}" "rm -f '$HOOK' ~/tmp/vhtest-rollback-interference; rm -rf ~/tmp/purchase-recovery.test.php ~/tmp/lib ~/tmp/vhrt" || true
}
trap cleanup EXIT

echo "== Uploading the recovery scenarios to the dev box"
"${SSH[@]}" 'mkdir -p ~/tmp/lib'
scp -i "$SSH_KEY" -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
scp -i "$SSH_KEY" -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -q "$SCRIPT_DIR/purchase-recovery.test.php" "$SSH_HOST":tmp/

FAILED=0
remote() { "${SSH[@]}" "$1" || FAILED=1; }
scenario() { echo "== $*"; remote "$ENV php $T $*"; }

SCENARIOS=("$@")
[ ${#SCENARIOS[@]} -eq 0 ] && SCENARIOS=(insufficient-credit failure-after-payment keyless-failure-after-payment
  failed-rollback crash-resume token-record-lost csv views link-race shared-credit init)

for s in "${SCENARIOS[@]}"; do
  case "$s" in
    failed-rollback)
      scp -i "$SSH_KEY" -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -q "$SCRIPT_DIR/hooks/vhtest-rollback-interference.php" "$SSH_HOST":"$HOOK"
      scenario failed-rollback
      "${SSH[@]}" "rm -f '$HOOK'"
      ;;
    link-race)
      scenario link-race-setup
      echo "== link-race: two keys link the same order at once"
      remote "$ENV php $T call-link rt-$RUN-race-a & $ENV php $T call-link rt-$RUN-race-b & wait"
      scenario link-race-check "rt-$RUN-race-a" "rt-$RUN-race-b"
      ;;
    shared-credit)
      scenario shared-credit-order
      echo "== shared-credit: WHMCS Generate Invoices"
      remote "cd '$WEBROOT/crons' && php cron.php do --CreateInvoices >/dev/null"
      scenario shared-credit-arm
      echo "== shared-credit: a renewal and an order at once"
      remote "$ENV php $T call-renew & $ENV php $T call-order rt-$RUN-compete & wait"
      scenario shared-credit-check "rt-$RUN-compete"
      ;;
    init)
      echo "== init: a keyed order while initialization is held elsewhere"
      remote "$ENV php $T hold-init 30 & sleep 2; $ENV php $T init-wait; rc=\$?; wait; exit \$rc"
      scenario init-resume
      ;;
    *)
      scenario "$s"
      ;;
  esac
done

[ "$FAILED" = "0" ] && echo "== all scenarios passed" || echo "== SOME SCENARIOS FAILED" >&2
exit "$FAILED"
