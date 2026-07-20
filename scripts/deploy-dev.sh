#!/usr/bin/env bash
#
# deploy-dev.sh — publish the VpnHood WHMCS modules to the dev WHMCS
# (https://whmcs-dev.vpnhood.com), so tests always run against the
# current working tree.
#
# Usage:
#   scripts/deploy-dev.sh [hub|partner|all]
#
#   hub      (default) modules from THIS repo: vpnhoodstore, vpnhoodconfig,
#            vpnhoodpartnerhub, plus the includes/hooks overlay
#   partner  the connector module (vpnhoodpartner) from the sibling
#            VpnHood.WHMCS.Partner repo
#   all      both
#
# Each module directory is uploaded to a staging dir on the server and
# swapped into place, so the live site never serves a half-copied module.
# After deploy it verifies an md5 manifest (local vs remote), lints every
# deployed .php with the server's PHP, and smoke-checks the Hub endpoint.
#
# Config (env vars, all optional):
#   WHMCS_DEV_SSH_KEY   default <Vh root>/.user/whmcs/ssh.openssh
#   WHMCS_DEV_SSH_HOST  default whmcsdev@webhost-ftps.vpnhood.com
#   WHMCS_DEV_WEBROOT   default /home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html
#   WHMCS_DEV_URL       default https://whmcs-dev.vpnhood.com
#   PARTNER_REPO        default <Vh root>/VpnHood.WHMCS.Partner

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

TARGET="${1:-hub}"
case "$TARGET" in
  hub|partner|all) ;;
  *) echo "Usage: $0 [hub|partner|all]" >&2; exit 2 ;;
esac

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/whmcs/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
WEBROOT="${WHMCS_DEV_WEBROOT:-/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"
PARTNER_REPO="${PARTNER_REPO:-$VH_ROOT/VpnHood.WHMCS.Partner}"

[ -f "$SSH_KEY" ] || { echo "SSH key not found: $SSH_KEY" >&2; exit 1; }
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST")

FAIL=0

# Replace $WEBROOT/$rel with $src/$rel (exact sync, staged then swapped).
deploy_dir() {
  local src="$1" rel="$2" stage
  stage="$rel.deploying"
  [ -d "$src/$rel" ] || { echo "!! source missing: $src/$rel" >&2; exit 1; }
  echo "-> $rel"
  "${SSH[@]}" "rm -rf '$WEBROOT/$stage' && mkdir -p '$WEBROOT/$stage'"
  tar -C "$src/$rel" -cf - . \
    | "${SSH[@]}" "tar -C '$WEBROOT/$stage' -xf - \
        && rm -rf '$WEBROOT/$rel' && mv '$WEBROOT/$stage' '$WEBROOT/$rel'"
}

# Copy files under $src/$rel into $WEBROOT/$rel without deleting anything
# already there (used for includes/hooks, which is shared with other modules).
overlay_dir() {
  local src="$1" rel="$2"
  [ -d "$src/$rel" ] || { echo "!! source missing: $src/$rel" >&2; exit 1; }
  echo "-> $rel (overlay)"
  tar -C "$src" -cf - "$rel" | "${SSH[@]}" "tar -C '$WEBROOT' -xf -"
}

# Compare an md5 manifest of local vs deployed files. (`sed 's/ \*/  /'`
# normalizes the binary-mode marker Git Bash's md5sum emits but Linux's doesn't.)
verify_dir() {
  local src="$1" rel="$2" local_sum remote_sum
  local_sum="$(cd "$src/$rel" && find . -type f | LC_ALL=C sort | xargs md5sum | sed 's/ \*/  /' | md5sum | cut -d' ' -f1)"
  remote_sum="$("${SSH[@]}" "cd '$WEBROOT/$rel' && find . -type f | LC_ALL=C sort | xargs md5sum | sed 's/ \*/  /' | md5sum" | cut -d' ' -f1)"
  if [ "$local_sum" = "$remote_sum" ]; then
    echo "   verified: $rel"
  else
    echo "!! MANIFEST MISMATCH: $rel" >&2
    FAIL=1
  fi
}

# php -l every .php in a deployed directory, using the server's PHP.
lint_dir() {
  local rel="$1" out
  out="$("${SSH[@]}" "cd '$WEBROOT/$rel' && find . -name '*.php' -print0 \
        | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors' || true")"
  if [ -n "$out" ]; then
    echo "!! PHP LINT ERRORS in $rel:" >&2
    echo "$out" >&2
    FAIL=1
  else
    echo "   php -l ok: $rel"
  fi
}

deploy_hub() {
  local dirs=(modules/servers/vpnhoodstore modules/addons/vpnhoodconfig modules/addons/vpnhoodpartnerhub)
  local d
  for d in "${dirs[@]}"; do deploy_dir "$REPO_ROOT" "$d"; done
  overlay_dir "$REPO_ROOT" includes/hooks
  for d in "${dirs[@]}"; do verify_dir "$REPO_ROOT" "$d"; lint_dir "$d"; done
  lint_dir includes/hooks

  # Smoke check: the Hub API must answer (an auth error proves it boots).
  local body
  body="$("${SSH[@]}" "curl -sk -m 30 -o - -X POST '$SITE_URL/modules/addons/vpnhoodpartnerhub/api.php' -d 'action=getBalance'")"
  if echo "$body" | grep -qi '"success" *: *false\|error'; then
    echo "   hub api answers: $body"
  else
    echo "!! HUB API SMOKE CHECK FAILED, response: $body" >&2
    FAIL=1
  fi
}

deploy_partner() {
  [ -d "$PARTNER_REPO" ] || { echo "Partner repo not found: $PARTNER_REPO" >&2; exit 1; }
  local dirs=(modules/servers/vpnhoodpartner modules/addons/vpnhoodpartnerconfig)
  local d
  for d in "${dirs[@]}"; do
    deploy_dir "$PARTNER_REPO" "$d"
    verify_dir "$PARTNER_REPO" "$d"
    lint_dir "$d"
  done
}

echo "Deploying '$TARGET' to $SSH_HOST:$WEBROOT"
case "$TARGET" in
  hub)     deploy_hub ;;
  partner) deploy_partner ;;
  all)     deploy_hub; deploy_partner ;;
esac

if [ "$FAIL" -ne 0 ]; then
  echo "DEPLOY FINISHED WITH ERRORS" >&2
  exit 1
fi
echo "Deploy OK"
