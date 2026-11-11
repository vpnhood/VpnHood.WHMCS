# dev-server.sh — sourced by the dev scripts and test runners, after they set VH_ROOT.
#
# This repo is public, so it never names the dev WHMCS. The server's SSH login and key, web root,
# site and admin-login file come from the private <Vh root>/.user/whmcs/dev.env, as KEY=VALUE lines:
#   WHMCS_DEV_SSH_HOST  WHMCS_DEV_SSH_KEY  WHMCS_DEV_WEBROOT  WHMCS_DEV_URL  WHMCS_DEV_SECRETS
# (the key and the secrets file relative to .user).
# A variable already set in the environment wins over its line. WHMCS_DEV_SSH_USER is the user of
# the file's own SSH login: the scripts' guard allows only that user, so an override cannot vouch
# for itself.

DEV_ENV_FILE="$VH_ROOT/.user/whmcs/dev.env"
[ -f "$DEV_ENV_FILE" ] || { echo "!! $DEV_ENV_FILE not found: it names the dev WHMCS server" >&2; exit 1; }
WHMCS_DEV_SSH_USER=""
while IFS='=' read -r _dev_key _dev_value || [ -n "$_dev_key" ]; do
  _dev_value="${_dev_value%$'\r'}"
  case "$_dev_key" in
    WHMCS_DEV_SSH_HOST|WHMCS_DEV_SSH_KEY|WHMCS_DEV_WEBROOT|WHMCS_DEV_URL|WHMCS_DEV_SECRETS)
      if [ "$_dev_key" = WHMCS_DEV_SSH_HOST ]; then WHMCS_DEV_SSH_USER="${_dev_value%%@*}"; fi
      if [ -z "${!_dev_key:-}" ]; then export "$_dev_key=$_dev_value"; fi
      ;;
  esac
done < "$DEV_ENV_FILE"
[ -n "$WHMCS_DEV_SSH_USER" ] || { echo "!! $DEV_ENV_FILE names no WHMCS_DEV_SSH_HOST" >&2; exit 1; }
# an empty web root would point the deploy's rm -rf at the server's root folder
for _dev_key in WHMCS_DEV_SSH_HOST WHMCS_DEV_SSH_KEY WHMCS_DEV_WEBROOT WHMCS_DEV_URL; do
  [ -n "${!_dev_key:-}" ] || { echo "!! no $_dev_key: set it in $DEV_ENV_FILE" >&2; exit 1; }
done
unset _dev_key _dev_value
case "${WHMCS_DEV_SECRETS:-}" in
  ""|/*|[A-Za-z]:*) ;;
  *) WHMCS_DEV_SECRETS="$VH_ROOT/.user/$WHMCS_DEV_SECRETS" ;;
esac
case "$WHMCS_DEV_SSH_KEY" in
  /*|[A-Za-z]:*) ;;
  *) WHMCS_DEV_SSH_KEY="$VH_ROOT/.user/$WHMCS_DEV_SSH_KEY" ;;
esac
