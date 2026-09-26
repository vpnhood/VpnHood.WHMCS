#!/usr/bin/env bash
#
# refund.test.sh — partner refunds on the dev WHMCS (Hub and connector co-installed).
#
# Runs the scenarios of refund.test.php:
#   refund            a new key refunded: key ended, price back once, a repeat pays nothing, and
#                     suspend/unsuspend/renew refused afterwards (terminate still runs)
#   terminate-first   terminate, then refund: the module runs again and the price comes back
#   suspended         a suspended key refunds; unsuspend is refused afterwards
#   window            the default 3-day window, PartnerRefundDays = 5, and 0 (refunds off)
#   later-invoice     a key with a renewal invoice is refused
#   records           no purchase record, an unfinished purchase, an extra invoice line, a
#                     refund booked by hand: each refused, then the restored order refunds
#   concurrent        two refunds of one order at once: one credit
#   lock              an unsuspend racing a refund while the test holds the credit lock: the
#                     key ends whichever runs first
#   timeout           a suspend that cannot get the lock -> 409 in_progress (takes ~20 s)
#   connector         the connector's Refund button on a buyer service, then against the
#                     previous Hub release (OLD_HUB, deployed from its git tag and replaced by
#                     the working tree again on exit)
#
# Usage: ./refund.test.sh [scenario ...]   (default: all, in the order above)
#
# ⚠ Spends reseller and buyer (test) credit and provisions real tokens; every order ends
#   refunded or terminated. later-invoice runs WHMCS's GenInvoices for its one service.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, PARTNER_REPO, OLD_HUB; HUB_URL, HUB_KEY and
# HUB_SECRET come from tests/integration/.env (written by tests/bootstrap/init-skeleton.sh).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
PARTNER_REPO="${PARTNER_REPO:-$VH_ROOT/VpnHood.WHMCS.Partner}"
OLD_HUB="${OLD_HUB:-v1.2.9}"
if [ -f "$SCRIPT_DIR/.env" ]; then set -a; . "$SCRIPT_DIR/.env"; set +a; fi
: "${HUB_URL:?run tests/bootstrap/init-skeleton.sh first (it writes tests/integration/.env)}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${HUB_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 "$SSH_HOST")
export WHMCS_DEV_SSH_KEY="$SSH_KEY" WHMCS_DEV_SSH_HOST="$SSH_HOST"

RUN="$(date +%s)"
ENV="RUN=$RUN HUB_URL=$(printf %q "$HUB_URL") HUB_KEY=$(printf %q "$HUB_KEY") HUB_SECRET=$(printf %q "$HUB_SECRET")"
T='~/tmp/refund.test.php'
TMP="$(mktemp -d)"
OLD_HUB_DEPLOYED=0
FAILED=0

cleanup() {
  if [ "$OLD_HUB_DEPLOYED" = "1" ]; then
    echo "== restoring the working tree of the Hub on dev"
    "$REPO_ROOT/scripts/deploy-dev.sh" hub > "$TMP/restore.log" 2>&1 || { cat "$TMP/restore.log" >&2; FAILED=1; }
  fi
  "${SSH[@]}" "rm -rf ~/tmp/refund.test.php ~/tmp/lib ~/tmp/vhrf" || true
  rm -rf "$TMP"
}
trap cleanup EXIT

deployed() { "$@" > "$TMP/deploy.log" 2>&1 || { cat "$TMP/deploy.log" >&2; echo "!! deploy failed" >&2; exit 1; }; }
upload() {
  "${SSH[@]}" 'mkdir -p ~/tmp/lib'
  scp -i "$SSH_KEY" -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
  scp -i "$SSH_KEY" -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -q "$SCRIPT_DIR/refund.test.php" "$SSH_HOST":tmp/
}
remote() { "${SSH[@]}" "$1" || FAILED=1; }
scenario() { echo "== $*"; remote "$ENV php $T $*"; }

upload

SCENARIOS=("$@")
[ ${#SCENARIOS[@]} -eq 0 ] && SCENARIOS=(refund terminate-first suspended window later-invoice records concurrent lock timeout connector)

for s in "${SCENARIOS[@]}"; do
  case "$s" in
    concurrent)
      scenario setup concurrent
      echo "== concurrent: two refunds of one order at once"
      remote "$ENV php $T call refund concurrent a & $ENV php $T call refund concurrent b & wait"
      scenario concurrent-check
      ;;
    lock)
      scenario setup lock suspend
      echo "== lock: an unsuspend and a refund while the test holds the credit lock for 6 s"
      remote "$ENV php $T hold-lock 6 & sleep 1; $ENV php $T call unsuspend lock x & $ENV php $T call refund lock x & wait"
      scenario lock-check
      ;;
    timeout)
      scenario setup timeout
      echo "== timeout: a suspend while the test holds the credit lock for 22 s"
      remote "$ENV php $T hold-lock 22 & sleep 2; $ENV php $T call suspend timeout x; wait"
      scenario timeout-check
      ;;
    connector)
      scenario connector-refund
      scenario connector-old-hub-setup
      echo "-- deploying the Hub release $OLD_HUB"
      mkdir -p "$TMP/hub-$OLD_HUB"
      git -C "$REPO_ROOT" archive "$OLD_HUB" | tar -x -C "$TMP/hub-$OLD_HUB"
      OLD_HUB_DEPLOYED=1
      deployed "$TMP/hub-$OLD_HUB/scripts/deploy-dev.sh" hub
      scenario connector-old-hub
      echo "-- deploying the working tree (hub)"
      deployed "$REPO_ROOT/scripts/deploy-dev.sh" hub
      OLD_HUB_DEPLOYED=0
      upload
      scenario connector-old-hub-cleanup
      ;;
    *)
      scenario "$s"
      ;;
  esac
done

[ "$FAILED" = "0" ] && echo "== all scenarios passed" || echo "== SOME SCENARIOS FAILED" >&2
exit "$FAILED"
