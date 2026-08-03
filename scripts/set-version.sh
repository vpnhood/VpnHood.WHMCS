#!/usr/bin/env bash
#
# set-version.sh — propagate the repo version into every module that ships one.
#
# The root VERSION file is the single source of truth. Every module in this repo
# carries the same number (they are built, deployed and supported together), so
# the repo tag and the version an admin sees in WHMCS always agree.
#
# Usage:
#   scripts/set-version.sh            # apply VERSION to all modules
#   scripts/set-version.sh 1.4.2      # set VERSION to 1.4.2, then apply
#   scripts/set-version.sh --check    # verify modules match VERSION (exit 1 if not)
#
# Where the version lives, and why:
#   - Addon modules  -> the 'version' key of <module>_config(). WHMCS reads this
#     natively and shows it in System Settings -> Addon Modules.
#   - Server modules -> the "version" key of whmcs.json. WHMCS has NO native
#     version display for provisioning modules, so vpnhoodconfig's admin page
#     reads this file back and shows it (see vpnhoodconfig_output).
#
# Run by .github/workflows/release.yml on every push to main, but it is an
# ordinary script — run it locally any time to re-sync.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION_FILE="$REPO_ROOT/VERSION"

CHECK_ONLY=0
[ "${1:-}" = "--check" ] && { CHECK_ONLY=1; shift || true; }

# An explicit version argument rewrites VERSION first.
if [ $# -gt 0 ] && [ -n "$1" ]; then
  printf '%s\n' "$1" > "$VERSION_FILE"
fi

[ -f "$VERSION_FILE" ] || { echo "!! missing $VERSION_FILE" >&2; exit 1; }
VERSION="$(tr -d ' \t\r\n' < "$VERSION_FILE")"

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "!! VERSION must be MAJOR.MINOR.PATCH, got '$VERSION'" >&2
  exit 1
fi

# Addon modules: the 'version' key inside <module>_config().
PHP_MODULES=(
  "modules/addons/vpnhoodconfig/vpnhoodconfig.php"
  "modules/addons/vpnhoodpartnerhub/vpnhoodpartnerhub.php"
)

# Server modules: the "version" key of the module's whmcs.json manifest.
JSON_MODULES=(
  "modules/servers/vpnhoodstore/whmcs.json"
)

FAIL=0

# Rewrite  'version' => '...'  in a module's _config(). Exactly one such key is
# expected; bailing out otherwise stops us silently editing the wrong line.
apply_php() {
  local rel="$1" path="$REPO_ROOT/$1" count current
  [ -f "$path" ] || { echo "!! missing $rel" >&2; FAIL=1; return; }

  count="$(grep -cE "'version'[[:space:]]*=>" "$path" || true)"
  if [ "$count" != "1" ]; then
    echo "!! $rel: expected exactly one 'version' key, found $count" >&2
    FAIL=1
    return
  fi

  current="$(sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']*)'.*/\1/p" "$path")"
  if [ "$CHECK_ONLY" = "1" ]; then
    [ "$current" = "$VERSION" ] \
      && echo "   ok      $rel ($current)" \
      || { echo "!! stale   $rel ($current, want $VERSION)" >&2; FAIL=1; }
    return
  fi

  # -i.bak keeps this portable between GNU sed and the BSD sed on macOS.
  sed -i.bak -E "s/('version'[[:space:]]*=>[[:space:]]*')[^']*(')/\1$VERSION\2/" "$path"
  rm -f "$path.bak"
  echo "   set     $rel  $current -> $VERSION"
}

# Set/insert the top-level "version" key of a whmcs.json manifest. Uses node so
# the file is parsed rather than pattern-matched, preserving it as valid JSON.
apply_json() {
  local rel="$1" path="$REPO_ROOT/$1"
  [ -f "$path" ] || { echo "!! missing $rel" >&2; FAIL=1; return; }

  local node_path="$path"
  command -v cygpath >/dev/null 2>&1 && node_path="$(cygpath -m "$path")"

  MODULE_PATH="$node_path" MODULE_REL="$rel" TARGET_VERSION="$VERSION" \
  CHECK_ONLY="$CHECK_ONLY" node <<'EOF' || FAIL=1
const fs = require('fs');
const path = process.env.MODULE_PATH;
const rel = process.env.MODULE_REL;
const want = process.env.TARGET_VERSION;
const checkOnly = process.env.CHECK_ONLY === '1';

// whmcs.json is written by WHMCS' own tooling and can carry a UTF-8 BOM.
const raw = fs.readFileSync(path, 'utf8');
const bom = raw.charCodeAt(0) === 0xfeff ? '﻿' : '';
const json = JSON.parse(bom ? raw.slice(1) : raw);
const current = json.version ?? '(none)';

if (checkOnly) {
  if (current === want) { console.log(`   ok      ${rel} (${current})`); process.exit(0); }
  console.error(`!! stale   ${rel} (${current}, want ${want})`);
  process.exit(1);
}

// Keep "version" next to "name" rather than appended at the end, so the
// manifest still reads the way WHMCS' own examples do. The existing version key
// must be skipped as we copy: it sorts after "name", so copying it would
// overwrite the value we just inserted and silently no-op every re-run.
const out = {};
for (const [k, v] of Object.entries(json)) {
  if (k === 'version') continue;
  out[k] = v;
  if (k === 'name') out.version = want;
}
if (!('version' in out)) out.version = want;

fs.writeFileSync(path, bom + JSON.stringify(out, null, 2) + '\n');
console.log(`   set     ${rel}  ${current} -> ${want}`);
EOF
}

if [ "$CHECK_ONLY" = "1" ]; then
  echo "Checking modules against VERSION $VERSION"
else
  echo "Applying VERSION $VERSION to all modules"
fi

for m in "${PHP_MODULES[@]}";  do apply_php  "$m"; done
for m in "${JSON_MODULES[@]}"; do apply_json "$m"; done

if [ "$FAIL" -ne 0 ]; then
  if [ "$CHECK_ONLY" = "1" ]; then
    echo "VERSION MISMATCH — run scripts/set-version.sh to re-sync" >&2
  else
    echo "FAILED to apply version to every module" >&2
  fi
  exit 1
fi

echo "All modules at $VERSION"
