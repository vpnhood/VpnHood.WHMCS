# Sourced by the scripts that deploy an older Hub release to dev (refund, connector-idempotency).
#
# A Hub release overlays includes/hooks with its own hook files, and deploy-dev.sh never deletes
# there (the folder is shared). A hook an older release shipped but the working tree no longer
# does — the site keeps its own copy now — would therefore stay behind, old, after the working
# tree is deployed again. These keep exactly those files as they were before the older release
# and put them back afterwards (or delete them, if they were not there).
#
# Needs SSH (the ssh command array) and REPO_ROOT from the caller.

WEBROOT="${WHMCS_DEV_WEBROOT:-/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html}"
OLD_ONLY_HOOKS=()

# <old release dir>: call after extracting the release, before deploying it
keep_old_only_hooks() {
  mapfile -t OLD_ONLY_HOOKS < <(comm -23 \
    <(ls "$1/includes/hooks" 2>/dev/null | LC_ALL=C sort) \
    <(ls "$REPO_ROOT/includes/hooks" | LC_ALL=C sort))
  [ ${#OLD_ONLY_HOOKS[@]} -eq 0 ] && return 0
  "${SSH[@]}" "rm -rf ~/tmp/vh-hooks-kept && mkdir -p ~/tmp/vh-hooks-kept && cd '$WEBROOT/includes/hooks' \
    && for f in ${OLD_ONLY_HOOKS[*]}; do if [ -f \"\$f\" ]; then cp -p \"\$f\" ~/tmp/vh-hooks-kept/; fi; done"
}

# call after the working tree is deployed again; does nothing when nothing was kept
put_back_old_only_hooks() {
  [ ${#OLD_ONLY_HOOKS[@]} -eq 0 ] && return 0
  "${SSH[@]}" "cd '$WEBROOT/includes/hooks' && for f in ${OLD_ONLY_HOOKS[*]}; do \
      if [ -f ~/tmp/vh-hooks-kept/\"\$f\" ]; then cp -p ~/tmp/vh-hooks-kept/\"\$f\" \"\$f\"; else rm -f \"\$f\"; fi; \
    done; rm -rf ~/tmp/vh-hooks-kept"
  OLD_ONLY_HOOKS=()
}
