#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"

require() {
  local file="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" "$file" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

function_body() {
  local file="$1" name="$2"
  awk -v signature="${name}() {" '
    $0 == signature { capture=1 }
    capture { print }
    capture && /^}/ { exit }
  ' "$file"
}

tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/cache"

eval "$(function_body "$INSTALLER" _grant_cache_acl)"

APT_PACKAGE=""
ACL_ARGS_FILE="$tmp/setfacl.args"
export ACL_ARGS_FILE
_apt_install() {
  APT_PACKAGE="$1"
  printf '%s\n' \
    '#!/bin/sh' \
    'printf '\''%s\n'\'' "$*" > "$ACL_ARGS_FILE"' \
    > "$tmp/bin/setfacl"
  /usr/bin/chmod +x "$tmp/bin/setfacl"
}

DRY_RUN=false
NGINX_CACHE_DIR="$tmp/cache"
PANEL_USER=aidipanel
export DRY_RUN NGINX_CACHE_DIR PANEL_USER
ORIGINAL_PATH="$PATH"
PATH="$tmp/bin"
if ! _grant_cache_acl; then
  PATH="$ORIGINAL_PATH"
  printf 'FAIL: installer cache ACL helper must install acl and then apply the grant\n' >&2
  exit 1
fi
PATH="$ORIGINAL_PATH"

[[ "$APT_PACKAGE" == "acl" ]] \
  || { printf 'FAIL: installer cache ACL helper must request the acl package\n' >&2; exit 1; }
grep -Fq 'u:aidipanel:rX' "$ACL_ARGS_FILE" \
  || { printf 'FAIL: panel cache ACL must be read-only\n' >&2; exit 1; }
grep -Fq 'd:u:aidipanel:rX' "$ACL_ARGS_FILE" \
  || { printf 'FAIL: panel cache ACL must be inherited by new cache entries\n' >&2; exit 1; }
printf 'ok: installer cache ACL helper installs acl and applies inherited read-only access\n'

if grep -Eq '(^|[[:space:]])apt_install[[:space:]]+acl' "$INSTALLER"; then
  printf 'FAIL: installer must not call the undefined bare apt_install helper\n' >&2
  exit 1
fi
printf 'ok: installer uses only the defined _apt_install helper\n'

function_body "$INSTALLER" _install_base_packages | grep -Eq '(^|[[:space:]])acl([[:space:]\\]|$)' \
  || { printf 'FAIL: fresh installs must include acl in the base package bundle\n' >&2; exit 1; }
printf 'ok: fresh installs include the acl package\n'

require "$DEPLOYER" '_repair_cache_acl()' 'deploy defines first-update cache ACL repair'
require "$DEPLOYER" 'apt-get install -y -qq --no-install-recommends acl' 'deploy can install acl on affected servers'
require "$DEPLOYER" 'for cache_dir in /var/cache/nginx/fastcgi /var/cache/nginx/aidipanel/*/fastcgi; do' 'deploy repairs shared and dedicated cache directories'
require "$DEPLOYER" 'setfacl -R -m "u:${PANEL_USER}:rX" -m "d:u:${PANEL_USER}:rX" "$cache_dir"' 'deploy repair remains inherited and read-only'

user_ready_line="$(grep -nF 'getent group adm' "$DEPLOYER" | head -1 | cut -d: -f1)"
repair_call_line="$(grep -nE '^_repair_cache_acl$' "$DEPLOYER" | head -1 | cut -d: -f1 || true)"
[[ -n "$repair_call_line" && "$repair_call_line" -gt "$user_ready_line" ]] \
  || { printf 'FAIL: deploy must invoke cache ACL repair after the panel user is ready\n' >&2; exit 1; }
printf 'ok: deploy invokes cache ACL repair after the panel user is ready\n'

printf 'cache ACL repair contract passed\n'
