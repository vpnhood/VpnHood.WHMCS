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
#   HUB_RUN_PROVISION=1  Place real orders. WARNING: this SPENDS PARTNER CREDIT and provisions
#                        REAL keys on the access server. Covers:
#                        - a keyed order (idempotencyKey), placed as two concurrent identical
#                          calls and then repeated: one order, charged once, the others replays;
#                          a different request under the same key is refused;
#                        - keyless orders (the contract of every connector before
#                          idempotency-v1): a repeat buys again; a keyed order under the same
#                          customerReference is refused with 409 reconcile; linkOrder binds the
#                          right one (and answers again when repeated); confirmNewPurchase buys.
#                        The keyless orders are terminated at the end; the keyed one is left
#                        ACTIVE unless HUB_RUN_TERMINATE=1.
#   HUB_RUN_SUSPEND=1    Also exercise suspend -> unsuspend on the keyed order (opt-in).
#   HUB_RUN_RENEW=1      Also exercise renew on the keyed order (opt-in).
#   HUB_RUN_TERMINATE=1  Also terminate the keyed order at the end (opt-in), then prove its key
#                        is spent (409) while a new key buys a new order, terminated too.
#
# Renewal, suspension, and termination are separate, opt-in jobs — none of them
# run unless you explicitly ask for them via the flags above.
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

PASS=0; FAIL=0; BODY=""; CODE=""; HEADERS=""

# _req BODY KEY SECRET [METHOD]  — sets CODE, BODY and HEADERS
_req() {
  local body="$1" k="$2" s="$3" method="${4:-POST}"
  local tmp hdr; tmp="$(mktemp)"; hdr="$(mktemp)"
  local args=(-o "$tmp" -D "$hdr" -w '%{http_code}' -H 'Content-Type: application/json' -X "$method")
  [ -n "$k" ] && args+=(-H "X-Vpnhood-Key: $k")
  [ -n "$s" ] && args+=(-H "X-Vpnhood-Secret: $s")
  [ "$method" = "POST" ] && args+=(-d "$body")
  CODE="$("${CURL[@]}" "${args[@]}" "$ENDPOINT")"
  BODY="$(cat "$tmp")"; HEADERS="$(tr -d '\r' < "$hdr")"; rm -f "$tmp" "$hdr"
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

# check DESC COMMAND... — passes when COMMAND succeeds
check() {
  local desc="$1"; shift
  if "$@"; then
    PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$desc"
  else
    FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$desc"
  fi
}

json_num() { printf '%s' "$BODY" | grep -o "\"$1\":[0-9]\+" | head -n1 | sed "s/\"$1\"://"; }
json_dec() { printf '%s' "$BODY" | grep -o "\"$1\":[0-9.]\+" | head -n1 | sed "s/\"$1\"://"; }
balance() { call '{"action":"getBalance"}'; json_dec balance; }

# order_body REF KEY [EXTRA_JSON]
order_body() {
  local key="" extra="${3:-}"
  [ -n "$2" ] && key=",\"idempotencyKey\":\"$2\""
  printf '{"action":"order","downstreamRef":"%s","customerReference":"%s"%s%s}' "$HUB_DOWNSTREAM_REF" "$1" "$key" "$extra"
}
# link_body REF KEY ORDER_ID
link_body() {
  printf '{"action":"linkOrder","downstreamRef":"%s","customerReference":"%s","idempotencyKey":"%s","upstreamOrderId":%s}' \
    "$HUB_DOWNSTREAM_REF" "$1" "$2" "$3"
}
terminate() { call "{\"action\":\"terminate\",\"upstreamOrderId\":$1}"; assert "terminate #$1 -> 200" 200 'terminated'; }

echo "Endpoint: $ENDPOINT"
echo "== Auth & read-only =="
_req '{"action":"getBalance"}' '' '' POST;                         assert "missing credentials -> 401" 401 "Missing API credentials"
check "an error response advertises idempotency-v1" grep -qi '^x-vpnhood-hub-features: idempotency-v1' <<<"$HEADERS"
_req '{"action":"getBalance"}' "$HUB_KEY" "wrong-secret" POST;     assert "bad secret -> 401"          401 "Invalid API credentials"
_req '{"action":"getBalance"}' "$HUB_KEY" "$HUB_SECRET" GET;       assert "GET method -> 405"          405 "Only POST"
call '{"action":"getBalance"}';                                   assert "getBalance -> 200"          200 '"balance"'
check "a success response advertises idempotency-v1" grep -qi '^x-vpnhood-hub-features: idempotency-v1' <<<"$HEADERS"
call '{"action":"getProducts"}';                                  assert "getProducts -> 200"         200 '"products"'
call '{"action":"order","downstreamRef":"__definitely_not_mapped__"}'; assert "unknown product -> 403" 403 "not available"
call '{"action":"order","downstreamRef":"x","idempotencyKey":"has space"}'; assert "malformed idempotencyKey -> 422" 422 "idempotencyKey must be"
call '{"action":"order","downstreamRef":"x","idempotencyKey":"k1","quantity":2}'; assert "keyed quantity 2 -> 422" 422 "exactly one unit"
call '{"action":"linkOrder","downstreamRef":"x","upstreamOrderId":1}'; assert "linkOrder without a key -> 422" 422 "idempotencyKey is required"

if [ "${HUB_RUN_PROVISION:-0}" = "1" ]; then
  : "${HUB_DOWNSTREAM_REF:?set HUB_DOWNSTREAM_REF for the provisioning run}"
  RUN="$(date +%s)"
  call "$(link_body "nope-$RUN" "nope-$RUN" 999999999)";          assert "linkOrder of a foreign order -> 404" 404 '"code":"link_rejected"'

  echo "== Keyed order (SPENDS CREDIT, provisions a real key) =="
  # A fresh reference and key per run: a reused key would replay the previous run's order.
  REF="integration-test-$RUN"; KEY="it-$RUN-keyed"
  ORDER_BODY="$(order_body "$REF" "$KEY")"

  # Two identical calls at once: one places the order, the other waits on the key lock and
  # replays it.
  PAIR="$(mktemp -d)"
  for n in 1 2; do
    ( call "$ORDER_BODY"; printf '%s\n%s' "$CODE" "$BODY" > "$PAIR/$n" ) &
  done
  wait
  OIDS=(); REPLAYS=0
  for n in 1 2; do
    CODE="$(head -n1 "$PAIR/$n")"; BODY="$(tail -n +2 "$PAIR/$n")"
    assert "concurrent keyed order #$n -> 200" 200 '"upstreamOrderId"'
    OIDS+=("$(json_num upstreamOrderId)")
    case "$BODY" in *'"replayed":true'*) REPLAYS=$((REPLAYS+1));; esac
  done
  rm -rf "$PAIR"
  OID="${OIDS[0]}"
  echo "        idempotencyKey=$KEY upstreamOrderId=$OID"
  check "concurrent calls return one order (#${OIDS[0]} / #${OIDS[1]})" test -n "$OID" -a "$OID" = "${OIDS[1]}"
  check "exactly one of them was a replay ($REPLAYS)" test "$REPLAYS" = 1

  BALANCE="$(balance)"
  call "$ORDER_BODY";             assert "repeat keyed order -> 200, replayed" 200 '"replayed":true'
  check "repeat returns the same order (#$(json_num upstreamOrderId))" test "$(json_num upstreamOrderId)" = "$OID"
  AFTER="$(balance)"
  check "repeat charged nothing (balance $BALANCE -> $AFTER)" test "$AFTER" = "$BALANCE"
  call "$(order_body "other-$REF" "$KEY")"; assert "same key, other customerReference -> 409 key_mismatch" 409 '"code":"key_mismatch"'

  if [ -n "$OID" ]; then
    call "{\"action\":\"getOrder\",\"upstreamOrderId\":$OID}";      assert "getOrder -> 200"      200 '"status"'
    call "{\"action\":\"getAccessCode\",\"upstreamOrderId\":$OID}"; assert "getAccessCode -> 200, normal delivery" 200 '"deliveryType":"normal"'

    if [ "${HUB_RUN_SUSPEND:-0}" = "1" ]; then
      echo "== Suspend/unsuspend (order #$OID, opt-in) =="
      call "{\"action\":\"suspend\",\"upstreamOrderId\":$OID}";     assert "suspend -> 200"       200 'suspended'
      call "$ORDER_BODY";                                           assert "a suspended keyed order still replays" 200 '"replayed":true'
      call "{\"action\":\"unsuspend\",\"upstreamOrderId\":$OID}";   assert "unsuspend -> 200"     200 'active'
    else
      echo "(suspend/unsuspend skipped — set HUB_RUN_SUSPEND=1 to include)"
    fi

    if [ "${HUB_RUN_RENEW:-0}" = "1" ]; then
      echo "== Renew (order #$OID, opt-in) =="
      # Manual renewal: 409 is the expected result when no renewal invoice is outstanding yet.
      call "{\"action\":\"renew\",\"upstreamOrderId\":$OID}"
      if [ "$CODE" = "409" ]; then
        assert "renew -> 409 (nothing due yet)" 409 'No renewal invoice'
      else
        assert "renew -> 200" 200 'renewed'
      fi
    else
      echo "(renew skipped — set HUB_RUN_RENEW=1 to include)"
    fi
  fi

  echo "== Keyless orders, reconcile and linkOrder (SPENDS CREDIT; cleaned up) =="
  LREF="legacy-$RUN"
  call "$(order_body "$LREF" "")";       assert "keyless order -> 200" 200 '"replayed":false'
  L1="$(json_num upstreamOrderId)"
  call "$(order_body "$LREF" "")";       assert "the same keyless call again -> 200" 200 '"replayed":false'
  L2="$(json_num upstreamOrderId)"
  check "a keyless repeat buys a second order (#$L1, #$L2)" test -n "$L1" -a -n "$L2" -a "$L1" != "$L2"

  K2="it-$RUN-link"
  B0="$(balance)"
  call "$(order_body "$LREF" "$K2")";    assert "keyed order over keyless orders of its reference -> 409 reconcile" 409 '"code":"reconcile"'
  check "reconcile lists both keyless orders" grep -q "\"upstreamOrderId\":$L1.*\"upstreamOrderId\":$L2" <<<"$BODY"
  call "$(link_body "$LREF" "$K2" "$L1")"; assert "linkOrder -> 200, linked" 200 '"linked":true'
  check "linkOrder returns the linked order (#$(json_num upstreamOrderId))" test "$(json_num upstreamOrderId)" = "$L1"
  call "$(link_body "$LREF" "$K2" "$L1")"; assert "linkOrder repeated (lost response) -> 200 again" 200 '"linked":true'
  call "$(link_body "$LREF" "$K2" "$L2")"; assert "the same key linking another order -> 409 key_mismatch" 409 '"code":"key_mismatch"'
  call "$(link_body "$LREF" "it-$RUN-other" "$L1")"; assert "another key claiming the linked order -> 409 already_claimed" 409 '"code":"already_claimed"'
  call "$(order_body "$LREF" "$K2")";    assert "the linked key's order call replays the linked order" 200 '"replayed":true'
  check "... which is #$L1" test "$(json_num upstreamOrderId)" = "$L1"
  check "reconcile, link and replay charged nothing ($B0 -> $(balance))" test "$(balance)" = "$B0"
  call "$(order_body "$LREF" "it-$RUN-new" ',"confirmNewPurchase":true')"; assert "confirmNewPurchase buys despite the keyless orders" 200 '"replayed":false'
  L3="$(json_num upstreamOrderId)"

  for o in "$L1" "$L2" "$L3"; do [ -n "$o" ] && terminate "$o"; done
  call "$(order_body "$LREF" "$K2")";    assert "the key of a terminated order is spent -> 409 key_spent" 409 '"code":"key_spent"'

  if [ "${HUB_RUN_TERMINATE:-0}" = "1" ] && [ -n "$OID" ]; then
    echo "== Terminate (order #$OID, opt-in) =="
    terminate "$OID"
    call "$ORDER_BODY";                  assert "its key is spent -> 409 key_spent" 409 '"code":"key_spent"'
    call "$(order_body "$REF" "it-$RUN-replacement")"; assert "a new key buys a replacement -> 200" 200 '"replayed":false'
    NEW_OID="$(json_num upstreamOrderId)"
    check "the replacement is a new order (#$NEW_OID)" test -n "$NEW_OID" -a "$NEW_OID" != "$OID"
    [ -n "$NEW_OID" ] && terminate "$NEW_OID"
  else
    echo "(terminate skipped — set HUB_RUN_TERMINATE=1 to include; order #$OID left Active)"
  fi
else
  echo "(provisioning tests skipped — set HUB_RUN_PROVISION=1 to include them)"
fi

echo
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" = "0" ]
