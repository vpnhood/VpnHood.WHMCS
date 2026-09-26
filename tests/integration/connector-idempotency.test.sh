#!/usr/bin/env bash
#
# connector-idempotency.test.sh — the connector's side of idempotent ordering, and both
# directions of compatibility, on the dev WHMCS (Hub and connector co-installed).
#
# Phases (default: all, in this order):
#   current        this connector + this Hub: a lost response recovered by Create again, two
#                  concurrent Creates of one service, Terminate then Create.
#   old-connector  the connector release partners run today (OLD_CONNECTOR, default v1.2.1,
#                  an older release) against this Hub: the whole buyer lifecycle
#                  (purchase-order, suspend, unsuspend, terminate, renew) and a repeated
#                  Create that buys again.
#   legacy         orders placed by the old connector whose responses were "lost", then this
#                  connector: Create stops with reconcile, Link returns the original order, a
#                  wrong link is refused, "Order a new key" buys.
#   old-hub        this connector against a Hub without idempotency-v1 (OLD_HUB, default v1.2.8):
#                  the cached idempotency-v1 is dropped, no Link is offered, the admin is told
#                  not to press Create again — then back on this Hub, it is learned again.
#
# Older releases are deployed from their git tags; the working tree of both repos is deployed
# again when the script exits, whatever happened.
#
# Usage: ./connector-idempotency.test.sh [phase ...]
#
# ⚠ Spends buyer and reseller (test) credit and provisions real tokens. The lifecycle scripts
#   it runs wipe the buyer's and reseller's earlier orders (purchase-order.test.sh), and
#   renew.test.sh runs WHMCS's Generate Invoices task on the dev install.
#
# Env overrides: WHMCS_DEV_SSH_KEY, WHMCS_DEV_SSH_HOST, PARTNER_REPO, OLD_CONNECTOR, OLD_HUB.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/ssh/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
PARTNER_REPO="${PARTNER_REPO:-$VH_ROOT/VpnHood.WHMCS.Partner}"
OLD_CONNECTOR="${OLD_CONNECTOR:-v1.2.1}"
OLD_HUB="${OLD_HUB:-v1.2.8}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 "$SSH_HOST")
export WHMCS_DEV_SSH_KEY="$SSH_KEY" WHMCS_DEV_SSH_HOST="$SSH_HOST"

TMP="$(mktemp -d)"
T='~/tmp/connector-idempotency.test.php'
FAILED=0

deployed() { "$@" > "$TMP/deploy.log" 2>&1 || { cat "$TMP/deploy.log" >&2; echo "!! deploy failed" >&2; exit 1; }; }
deploy_current() {
  echo "-- deploying the working tree ($1)"
  if [ "$1" = hub ]; then deployed "$REPO_ROOT/scripts/deploy-dev.sh" hub; else deployed env PARTNER_REPO="$PARTNER_REPO" "$REPO_ROOT/scripts/deploy-dev.sh" partner; fi
}
deploy_old_connector() {
  echo "-- deploying the connector release $OLD_CONNECTOR"
  mkdir -p "$TMP/partner-$OLD_CONNECTOR"
  git -C "$PARTNER_REPO" archive "$OLD_CONNECTOR" modules | tar -x -C "$TMP/partner-$OLD_CONNECTOR"
  deployed env PARTNER_REPO="$TMP/partner-$OLD_CONNECTOR" "$REPO_ROOT/scripts/deploy-dev.sh" partner
}
deploy_old_hub() {
  echo "-- deploying the Hub release $OLD_HUB"
  mkdir -p "$TMP/hub-$OLD_HUB"
  git -C "$REPO_ROOT" archive "$OLD_HUB" | tar -x -C "$TMP/hub-$OLD_HUB"
  deployed "$TMP/hub-$OLD_HUB/scripts/deploy-dev.sh" hub
}

restore() {
  echo "== restoring the working tree of both repos on dev"
  "$REPO_ROOT/scripts/deploy-dev.sh" hub > "$TMP/restore.log" 2>&1 || { cat "$TMP/restore.log" >&2; FAILED=1; }
  PARTNER_REPO="$PARTNER_REPO" "$REPO_ROOT/scripts/deploy-dev.sh" partner >> "$TMP/restore.log" 2>&1 || { cat "$TMP/restore.log" >&2; FAILED=1; }
  "${SSH[@]}" "rm -rf ~/tmp/connector-idempotency.test.php ~/tmp/vhci" || true
  rm -rf "$TMP"
}
trap restore EXIT

upload() {
  "${SSH[@]}" 'mkdir -p ~/tmp/lib'
  scp -i "$SSH_KEY" -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -q "$SCRIPT_DIR/lib/common.php" "$SSH_HOST":tmp/lib/
  scp -i "$SSH_KEY" -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -q "$SCRIPT_DIR/connector-idempotency.test.php" "$SSH_HOST":tmp/
}
scenario() { echo "== $*"; upload; "${SSH[@]}" "php $T $*" || FAILED=1; }
lifecycle() { echo "== $*"; env SKIP_INIT=1 "$@" || FAILED=1; }

PHASES=("$@")
[ ${#PHASES[@]} -eq 0 ] && PHASES=(current old-connector legacy old-hub)

for phase in "${PHASES[@]}"; do
  echo "######## $phase"
  case "$phase" in
    current)
      deploy_current hub; deploy_current partner
      scenario lost-response
      scenario concurrent-setup
      echo "== two Creates of one service at once"
      "${SSH[@]}" "php $T module-create a & php $T module-create b & wait" || FAILED=1
      scenario concurrent-check
      scenario terminate-create
      ;;
    old-connector)
      deploy_current hub; deploy_old_connector
      scenario old-connector-repeat
      lifecycle env PRODUCT_TYPE=onetime "$SCRIPT_DIR/purchase-order.test.sh"
      lifecycle "$SCRIPT_DIR/suspend.test.sh"
      lifecycle "$SCRIPT_DIR/unsuspend.test.sh"
      lifecycle "$SCRIPT_DIR/terminate.test.sh"
      lifecycle env PRODUCT_TYPE=recurring "$SCRIPT_DIR/purchase-order.test.sh"
      lifecycle "$SCRIPT_DIR/renew.test.sh"
      lifecycle "$SCRIPT_DIR/terminate.test.sh"
      ;;
    legacy)
      deploy_current hub; deploy_old_connector
      scenario legacy-setup
      deploy_current partner
      scenario legacy-reconcile
      ;;
    old-hub)
      deploy_current partner; deploy_current hub
      scenario new-hub-again
      deploy_old_hub
      scenario old-hub
      deploy_current hub
      scenario new-hub-again
      ;;
    *)
      echo "unknown phase '$phase'" >&2; FAILED=1
      ;;
  esac
done

[ "$FAILED" = "0" ] && echo "== all phases passed" || echo "== SOME PHASES FAILED" >&2
exit "$FAILED"
