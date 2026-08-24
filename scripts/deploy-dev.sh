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
#   iap      the vpnhoodiap module (addon + gateway + hooks overlay) from the
#            sibling VpnHood.WHMCS.Iap repo
#   all      all of the above
#
# Each module directory is uploaded to a staging dir on the server and
# swapped into place, so the live site never serves a half-copied module.
# After deploy it verifies an md5 manifest (local vs remote), lints every
# deployed .php with the server's PHP, and smoke-checks the Hub endpoint.
#
# Config (env vars, all optional):
#   WHMCS_DEV_SSH_KEY   default <Vh root>/.user/account-dev.vpnhood.com/ssh.openssh
#   WHMCS_DEV_SSH_HOST  default whmcsdev@webhost-ftps.vpnhood.com
#   WHMCS_DEV_WEBROOT   default /home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html
#   WHMCS_DEV_URL       default https://whmcs-dev.vpnhood.com
#   PARTNER_REPO        default <Vh root>/VpnHood.WHMCS.Partner
#   IAP_REPO            default <Vh root>/VpnHood.WHMCS.Iap

set -euo pipefail

# macOS bsdtar writes AppleDouble ._* metadata entries into the stream; the
# server's GNU tar extracts them as real files, polluting the webroot and
# failing the md5 manifest. This env var tells bsdtar to omit them (no-op on
# Linux/Git Bash).
export COPYFILE_DISABLE=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VH_ROOT="$(cd "$REPO_ROOT/.." && pwd)"

TARGET="${1:-hub}"
case "$TARGET" in
  hub|partner|iap|all) ;;
  *) echo "Usage: $0 [hub|partner|iap|all]" >&2; exit 2 ;;
esac

SSH_KEY="${WHMCS_DEV_SSH_KEY:-$VH_ROOT/.user/account-dev.vpnhood.com/ssh.openssh}"
SSH_HOST="${WHMCS_DEV_SSH_HOST:-whmcsdev@webhost-ftps.vpnhood.com}"
WEBROOT="${WHMCS_DEV_WEBROOT:-/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html}"
SITE_URL="${WHMCS_DEV_URL:-https://whmcs-dev.vpnhood.com}"
# tests and dev deploys run ONLY against the dev box — never production (account.vpnhood.com)
case "${SSH_HOST:-}${SITE_URL:-}${WHMCS_DEV_URL:-}" in *account.vpnhood.com*) echo "!! REFUSED: production host detected" >&2; exit 1;; esac
case "${SSH_HOST:-}" in *whmcsdev@*) ;; "") ;; *) echo "!! REFUSED: only whmcsdev@… (the dev box) is allowed, got: $SSH_HOST" >&2; exit 1;; esac
PARTNER_REPO="${PARTNER_REPO:-$VH_ROOT/VpnHood.WHMCS.Partner}"
IAP_REPO="${IAP_REPO:-$VH_ROOT/VpnHood.WHMCS.Iap}"

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
#
# Because nothing is ever deleted, a hook file RENAMED in the repo leaves its old
# copy behind on the server. WHMCS includes every file in includes/hooks, so two
# files declaring the same function is an instant site-wide fatal ("Cannot
# redeclare ..."), which is exactly how this dev box went down. We can't just
# delete unknown files — the directory is shared with hooks this repo doesn't own
# — so detect the actual hazard instead: the same function name declared twice.
overlay_dir() {
  local src="$1" rel="$2" dupes
  [ -d "$src/$rel" ] || { echo "!! source missing: $src/$rel" >&2; exit 1; }
  echo "-> $rel (overlay)"
  tar -C "$src" -cf - "$rel" | "${SSH[@]}" "tar -C '$WEBROOT' -xf -"

  dupes="$("${SSH[@]}" "cd '$WEBROOT/$rel' && \
    grep -hoE '^[[:space:]]*function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' *.php 2>/dev/null \
    | sed 's/.*function[[:space:]]*//' | LC_ALL=C sort | uniq -d")"
  if [ -n "$dupes" ]; then
    echo "!! DUPLICATE FUNCTION DECLARATIONS in $rel — this fatals the whole site:" >&2
    printf '     %s\n' $dupes >&2
    echo "   Usually a renamed hook whose old copy is still on the server. Remove the stale file." >&2
    FAIL=1
  else
    echo "   no duplicate hook functions: $rel"
  fi
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
  local dirs=(modules/servers/vpnhoodstore modules/addons/vpnhoodconfig modules/addons/vpnhoodpartnerhub modules/addons/vpnhoodverify)
  local d
  for d in "${dirs[@]}"; do deploy_dir "$REPO_ROOT" "$d"; done
  overlay_dir "$REPO_ROOT" includes/hooks
  for d in "${dirs[@]}"; do verify_dir "$REPO_ROOT" "$d"; lint_dir "$d"; done
  lint_dir includes/hooks

  # Smoke check: the Hub API must answer with its OWN JSON envelope (an auth error
  # proves it boots). Matching a bare 'error' substring is not enough — a PHP fatal
  # renders an HTML page that also contains "error", which is how a site-wide 500
  # once passed this check and printed "Deploy OK". Require the JSON shape *and* a
  # sane status code.
  local resp code body
  resp="$("${SSH[@]}" "curl -sk -m 30 -w '\n%{http_code}' -X POST '$SITE_URL/modules/addons/vpnhoodpartnerhub/api.php' -d 'action=getBalance'")"
  code="$(printf '%s' "$resp" | tail -n1)"
  body="$(printf '%s' "$resp" | sed '$d')"
  if [ "$code" -ge 500 ] 2>/dev/null; then
    echo "!! HUB API SMOKE CHECK FAILED (HTTP $code) — the site is erroring:" >&2
    echo "$body" | head -c 400 >&2; echo >&2
    FAIL=1
  elif printf '%s' "$body" | grep -q '"success"[[:space:]]*:[[:space:]]*false'; then
    echo "   hub api answers (HTTP $code): $body"
  else
    echo "!! HUB API SMOKE CHECK FAILED — not the Hub's JSON envelope (HTTP $code):" >&2
    echo "$body" | head -c 400 >&2; echo >&2
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

deploy_iap() {
  [ -d "$IAP_REPO" ] || { echo "IAP repo not found: $IAP_REPO" >&2; exit 1; }
  deploy_dir "$IAP_REPO" modules/addons/vpnhoodiap
  # hooks and gateways dirs are shared with other modules on the server: overlay, never replace
  overlay_dir "$IAP_REPO" includes/hooks
  overlay_dir "$IAP_REPO" modules/gateways
  verify_dir "$IAP_REPO" modules/addons/vpnhoodiap
  lint_dir modules/addons/vpnhoodiap
  lint_dir includes/hooks
  lint_dir modules/gateways

  # IAP API smoke check: an active addon answers GET /v1/system/status; an inactive one
  # answers its fail-closed 404 problem+json. Anything else (HTML error page, 5xx)
  # is a failure. The path also proves PATH_INFO routing survives the web server.
  # Versioned on purpose — the unversioned path is a real 404 now, which would let a
  # dead addon and a live one look identical here.
  local resp code body
  resp="$("${SSH[@]}" "curl -sk -m 30 -w '\n%{http_code}' '$SITE_URL/modules/addons/vpnhoodiap/api.php/v1/system/status'")"
  code="$(printf '%s' "$resp" | tail -n1)"
  body="$(printf '%s' "$resp" | sed '$d')"
  if { [ "$code" = "200" ] && printf '%s' "$body" | grep -q '"status":"ok"'; } ||
     { [ "$code" = "404" ] && printf '%s' "$body" | grep -q '"code":"not_found"'; }; then
    echo "   iap api answers (HTTP $code): $body"
  else
    echo "!! IAP API SMOKE CHECK FAILED (HTTP $code):" >&2
    echo "$body" | head -c 400 >&2; echo >&2
    FAIL=1
  fi
}

echo "Deploying '$TARGET' to $SSH_HOST:$WEBROOT"
case "$TARGET" in
  hub)     deploy_hub ;;
  partner) deploy_partner ;;
  iap)     deploy_iap ;;
  all)     deploy_hub; deploy_partner; deploy_iap ;;
esac

if [ "$FAIL" -ne 0 ]; then
  echo "DEPLOY FINISHED WITH ERRORS" >&2
  exit 1
fi
echo "Deploy OK"
