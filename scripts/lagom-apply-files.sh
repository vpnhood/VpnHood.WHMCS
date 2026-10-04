#!/bin/bash
# Copies a Lagom package (verified zip in the home's tmp) over a WHMCS install, as Lagom's update
# guide says: the contents of /php82+/ into the WHMCS root, overwriting, deleting nothing. Then
# drops the world-write bit the zip carries, and checks every file byte for byte.
# usage: ssh <user>@... 'bash -s -- <webroot> <zip> <zip sha256>' < lagom-apply-files.sh
set -euo pipefail
ROOT=$1 ZIP=$2 SHA=$3
cd ~/tmp
echo "$SHA  $ZIP" | sha256sum -c -
STAGE=~/tmp/lagom-stage
rm -rf "$STAGE" && mkdir -p "$STAGE" && unzip -q "$ZIP" -d "$STAGE"
SRC="$STAGE/php82+"
[ -f "$ROOT/configuration.php" ] || { echo "no configuration.php in $ROOT"; exit 1; }
cp -a "$SRC"/. "$ROOT"/
(cd "$ROOT" && find templates/lagom2 templates/orderforms/lagom2 modules/addons/RSThemes -perm -o+w ! -type l -exec chmod o-w {} +)

cd "$SRC"
bad=0 total=0
while IFS= read -r -d '' f; do
  total=$((total + 1))
  cmp -s "$f" "$ROOT/$f" || { bad=$((bad + 1)); [ $bad -le 5 ] && echo "DIFF $f"; }
done < <(find . -type f -print0)
echo "package files: $total, differing after copy: $bad"
echo "world-writable left under Lagom: $(cd "$ROOT" && find templates/lagom2 templates/orderforms/lagom2 modules/addons/RSThemes -perm -o+w ! -type l | wc -l)"
echo "not writable under core/styles: $(find "$ROOT/templates/lagom2/core/styles" ! -writable | wc -l)"
