#!/usr/bin/env bash
#
# Integration smoke test for the VpnHood! Partner Hub API.
#
# Credentials are read from the environment (or a gitignored .env next to this
# script) and are NEVER hard-coded. See .env.example.
#
# Required:
#   HUB_URL              Base URL of the WHMCS running the Hub addon.
#                        (the /modules/addons/vpnhoodpartnerhub/api.php path is appended)
#   HUB_KEY              Partner API key   (X-Vpnhood-Key)
#   HUB_SECRET           Partner API secret (X-Vpnhood-Secret)
#
# Required only for the provisioning run:
#   HUB_DOWNSTREAM_REF   A downstream ref mapped + enabled for this partner (e.g. vpn-monthly)
#
# Optional:
#   HUB_INSECURE=1       Pass -k to curl (self-signed dev certificate)
#   HUB_RUN_PROVISION=1  Also run order + lifecycle. WARNING: this SPENDS PARTNER CREDIT
#                        and provisions a REAL key on the access server.
#   HUB_TERMINATE=1      Terminate the provisioned service at the end (default: 1)
#
# Usage:
#   cp .env.example .env && edit .env
#   ./hub-api.test.sh
#
# Exit code is non-zero if any assertion fails.
#
set -u

DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -f "$DIR/.env" ]; then set -a; . "$DIR/.env"; set +a; fi

: "${HUB_URL:?set HUB_URL (see .env.example)}"
: "${HUB_KEY:?set HUB_KEY}"
: "${HUB_SECRET:?set HUB_SECRET}"

ENDPOINT="${HUB_URL%/}/modules/addons/vpnhoodpartnerhub/api.php"

# The WinLibs mingw curl that shadows PATH in Git Bash fails with exit 43 on
# -w '%{http_code}' — prefer the Windows system curl when present.
CURL_BIN="${CURL_BIN:-curl}"
[ -x "/c/Windows/System32/curl.exe" ] && CURL_BIN="/c/Windows/System32/curl.exe"
CURL=("$CURL_BIN" -s -m 45)
[ "${HUB_INSECURE:-0}" = "1" ] && CURL+=(-k)

PASS=0; FAIL=0; BODY=""; CODE=""

# request POST_BODY [header-pairs as KEY:VALUE ...]
# Default headers are the valid partner key/secret unless overridden by passing
# explicit -H pairs is awkward, so we expose two modes via globals below.
_req() { # $1 body  $2 keyHeaderValue  $3 secretHeaderValue  $4 method(optional, default POST)
  local body="$1" k="$2" s="$3" method="${4:-POST}"
  local tmp; tmp="$(mktemp)"
  local args=(-o "$tmp" -w '%{http_code}' -H 'Content-Type: application/json' -X "$method")
  [ -n "$k" ] && args+=(-H "X-Vpnhood-Key: $k")
  [ -n "$s" ] && args+=(-H "X-Vpnhood-Secret: $s")
  [ "$method" = "POST" ] && args+=(-d "$body")
  CODE="$("${CURL[@]}" "${args[@]}" "$ENDPOINT")"
  BODY="$(cat "$tmp")"; rm -f "$tmp"
}

# authed POST with the real partner credentials
call() { _req "$1" "$HUB_KEY" "$HUB_SECRET" POST; }

# assert DESC EXPECTED_CODE [SUBSTRING]
assert() {
  local desc="$1" exp="$2" sub="${3:-}" ok=1
  [ "$CODE" = "$exp" ] || ok=0
  if [ -n "$sub" ]; then case "$BODY" in *"$sub"*) ;; *) ok=0;; esac; fi
  if [ "$ok" = "1" ]; then
    PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s (HTTP %s)\n' "$desc" "$CODE"
  else
    FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s (got %s want %s)\n        %s\n' "$desc" "$CODE" "$exp" "$BODY"
  fi
}

json_num() { printf '%s' "$BODY" | grep -o "\"$1\":[0-9]\+" | head -n1 | sed "s/\"$1\"://"; }

echo "Endpoint: $ENDPOINT"
echo "== Auth & read-only =="
_req '{"action":"getBalance"}' '' '' POST;                         assert "missing credentials -> 401" 401 "Missing API credentials"
_req '{"action":"getBalance"}' "$HUB_KEY" "wrong-secret" POST;     assert "bad secret -> 401"          401 "Invalid API credentials"
_req '{"action":"getBalance"}' "$HUB_KEY" "$HUB_SECRET" GET;       assert "GET method -> 405"          405 "Only POST"
call '{"action":"getBalance"}';                                   assert "getBalance -> 200"          200 '"balance"'
call '{"action":"getProducts"}';                                  assert "getProducts -> 200"         200 '"products"'
call '{"action":"order","downstreamRef":"__definitely_not_mapped__"}'; assert "unknown product -> 403" 403 "not available"

if [ "${HUB_RUN_PROVISION:-0}" = "1" ]; then
  : "${HUB_DOWNSTREAM_REF:?set HUB_DOWNSTREAM_REF for the provisioning run}"
  echo "== Provisioning + lifecycle (SPENDS CREDIT, provisions a real key) =="
  call "{\"action\":\"order\",\"downstreamRef\":\"$HUB_DOWNSTREAM_REF\",\"customerReference\":\"integration-test\"}"
  assert "order -> 200" 200 '"upstreamServiceId"'
  SID="$(json_num upstreamServiceId)"
  echo "        upstreamServiceId=$SID"
  if [ -n "$SID" ]; then
    call "{\"action\":\"getOrder\",\"upstreamServiceId\":$SID}";   assert "getOrder -> 200"   200 '"status"'
    call "{\"action\":\"suspend\",\"upstreamServiceId\":$SID}";    assert "suspend -> 200"    200 'suspended'
    call "{\"action\":\"unsuspend\",\"upstreamServiceId\":$SID}";  assert "unsuspend -> 200"  200 'active'
    call "{\"action\":\"renew\",\"upstreamServiceId\":$SID}";      assert "renew -> 200"      200 'renewed'
    if [ "${HUB_TERMINATE:-1}" = "1" ]; then
      call "{\"action\":\"terminate\",\"upstreamServiceId\":$SID}"; assert "terminate -> 200" 200 'terminated'
    fi
  fi
else
  echo "(provisioning tests skipped — set HUB_RUN_PROVISION=1 to include them)"
fi

echo
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" = "0" ]
