#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"

function_body() {
  local name="$1"
  awk -v signature="${name}() {" '
    $0 == signature { capture=1 }
    capture { print }
    capture && /^}$/ { exit }
  ' "$CLI"
}

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

require_cli() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CLI" || fail "$label"
  printf 'ok: %s\n' "$label"
}

forbid_cli() {
  local pattern="$1" label="$2"
  if grep -Fq -- "$pattern" "$CLI"; then fail "$label"; fi
  printf 'ok: %s\n' "$label"
}

require_body() {
  local body="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" <<< "$body" || fail "$label"
  printf 'ok: %s\n' "$label"
}

forbid_cli '/tmp/aidipanel-wp-cli-cache' \
  'the world-writable legacy cache path is absent'

HELPER=$(function_body _wp_cli_cache_dir)
SITE_INSTALL=$(function_body _site_install_wordpress)
CRON_WP=$(function_body _cron_wp)
REDIS_RESOLVE=$(function_body _cache_redis_resolve)
PLUGIN_INSTALL=$(function_body _cache_install_wp_plugins)

require_cli '_wp_cli_cache_dir() {' \
  'WP-CLI cache setup has one shared helper'
require_body "$HELPER" 'cache_dir="${HOME_BASE}/${1}/tmp/wp-cli-cache"' \
  'cache path is isolated below the site-user tmp directory'
require_body "$HELPER" '_validate_site_user "$user"' \
  'cache helper validates the managed site identity'
require_body "$HELPER" 'runuser -u "$user" -- mkdir -p -- "$cache_dir"' \
  'cache directory is created with site-user privileges'
require_body "$HELPER" 'runuser -u "$user" -- chmod 700 -- "$cache_dir"' \
  'cache permissions are applied with site-user privileges'

require_body "$SITE_INSTALL" 'cache_dir=$(_wp_cli_cache_dir "$site_user")' \
  'WordPress provisioning uses the per-user cache helper'
require_body "$CRON_WP" 'cache_dir=$(_wp_cli_cache_dir "$CRON_USER")' \
  'WordPress cron uses the per-user cache helper'
require_body "$REDIS_RESOLVE" 'cache_dir=$(_wp_cli_cache_dir "$OC_SITE_USER")' \
  'Redis object-cache commands use the per-user cache helper'
require_body "$PLUGIN_INSTALL" 'cache_dir=$(_wp_cli_cache_dir "$site_user")' \
  'cache plugin installation uses the per-user cache helper'
require_body "$PLUGIN_INSTALL" 'cache_dir="/root/.cache/aidipanel/wp-cli"' \
  'unmanaged compatibility uses a root-private cache'
require_body "$PLUGIN_INSTALL" 'install -d -o root -g root -m 0700 -- "$cache_dir"' \
  'root-private fallback cache is explicitly secured'

if grep -Eq 'chown.*wp-cli-cache|wp-cli-cache.*chown' "$CLI"; then
  fail 'WP-CLI cache paths must never be passed to chown'
fi
printf 'ok: WP-CLI cache paths are never passed to chown\n'

cache_refs=$(grep -c 'WP_CLI_CACHE_DIR=' "$CLI" || true)
[[ "$cache_refs" -eq 4 ]] \
  || fail "expected four WP-CLI cache consumers, found ${cache_refs}"
printf 'ok: all four WP-CLI cache consumers remain covered\n'

printf 'WP-CLI cache isolation contract passed\n'
