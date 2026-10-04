#!/bin/bash
# Copies the official WHMCS release files (verified zip in ~/tmp) over a WHMCS install, as WHMCS's
# manual upgrade says: every file of the package, configuration.php untouched (the package has
# only configuration.sample.php). A renamed admin folder receives the package's admin/ files.
# usage: ssh <user>@... 'bash -s -- <webroot> <zip> <zip sha256> [admin folder name]' < whmcs-apply-files.sh
set -euo pipefail
ROOT=$1 ZIP=$2 SHA=$3 ADMIN=${4:-admin}
cd ~/tmp
echo "$SHA  $ZIP" | sha256sum -c -
STAGE=~/tmp/whmcs-stage-$(basename "$ZIP" .zip)
rm -rf "$STAGE" && mkdir -p "$STAGE" && unzip -q "$ZIP" -d "$STAGE"
if [ "$ADMIN" != admin ]; then
  [ -d "$ROOT/$ADMIN" ] || { echo "no $ROOT/$ADMIN"; exit 1; }
  mv "$STAGE/admin" "$STAGE/$ADMIN"
fi
[ -f "$ROOT/configuration.php" ] || { echo "no configuration.php in $ROOT"; exit 1; }
cp -a "$STAGE"/. "$ROOT"/

# every package file is now in place, byte for byte
cd "$STAGE"
bad=0 total=0
while IFS= read -r -d '' f; do
  total=$((total + 1))
  cmp -s "$f" "$ROOT/$f" || { bad=$((bad + 1)); [ $bad -le 5 ] && echo "DIFF $f"; }
done < <(find . -type f -print0)
echo "package files: $total, differing after copy: $bad"
echo "world-writable in package paths: $(cd "$ROOT" && find "$ADMIN" includes vendor modules templates -perm -o+w -type f 2>/dev/null | wc -l)"
