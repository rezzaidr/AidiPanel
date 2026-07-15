#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"

ENSURE=$(sed -n '/^_ensure_site_user() {$/,/^_site_make_layout() {$/p' "$CLI")
LAYOUT=$(sed -n '/^_site_make_layout() {$/,/^# Let nginx /p' "$CLI")
SITE_ADD=$(sed -n '/^_site_add() {$/,/^_site_delete() {$/p' "$CLI")

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$1"; }

require_in() {
  local block="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" <<< "$block" || fail "$label"
  pass "$label"
}

forbid_in() {
  local block="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" <<< "$block"; then fail "$label"; fi
  pass "$label"
}

assert_before() {
  local block="$1" first="$2" second="$3" label="$4" first_line second_line
  first_line=$(grep -nF -- "$first" <<< "$block" | head -n1 | cut -d: -f1)
  second_line=$(grep -nF -- "$second" <<< "$block" | head -n1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] \
    || fail "$label"
  pass "$label"
}

[[ -n "$ENSURE" && -n "$LAYOUT" && -n "$SITE_ADD" ]] \
  || fail 'site home provisioning functions are missing'

require_in "$ENSURE" '[[ ! -d "$HOME_BASE" || -L "$HOME_BASE" ]]' \
  'home base must be a real directory'
require_in "$ENSURE" 'stat -c %u -- "$HOME_BASE"' \
  'home base ownership is inspected'
require_in "$ENSURE" '[[ "$base_uid" == "0" ]]' \
  'home base must be root-owned'
require_in "$ENSURE" '(( (8#${base_mode} & 0022) != 0 ))' \
  'home base must not be group/world-writable'
require_in "$ENSURE" 'exec {lock_fd}>"$lock"' \
  'user creation opens a dedicated lock descriptor'
require_in "$ENSURE" 'flock -w 10 "$lock_fd"' \
  'user creation lock has a bounded wait'
require_in "$ENSURE" '[[ -e "$home" || -L "$home" ]]' \
  'existing and dangling home paths are rejected'
require_in "$ENSURE" 'useradd --create-home --home-dir "$home"' \
  'useradd remains responsible for fresh home creation'
require_in "$ENSURE" 'user_uid=$(id -u "$user")' \
  'new account UID is resolved'
require_in "$ENSURE" 'user_gid=$(id -g "$user")' \
  'new account primary GID is resolved'
require_in "$ENSURE" 'home_uid=$(stat -c %u -- "$home")' \
  'new home UID is verified'
require_in "$ENSURE" 'home_gid=$(stat -c %g -- "$home")' \
  'new home GID is verified'
require_in "$ENSURE" '_remove_site_user "$user"' \
  'partial account state is cleaned up'
assert_before "$ENSURE" 'flock -w 10 "$lock_fd"' 'id "$user"' \
  'the race check runs after the user lock is acquired'
assert_before "$ENSURE" '[[ -e "$home" || -L "$home" ]]' \
  'useradd --create-home --home-dir "$home"' \
  'pre-existing homes are rejected before useradd'

require_in "$LAYOUT" '[[ "$webroot" != "${home}/htdocs/${domain}" ]]' \
  'layout rejects an unexpected webroot'
require_in "$LAYOUT" '[[ ! -d "$home" || -L "$home" ]]' \
  'layout requires a real home directory'
require_in "$LAYOUT" '[[ -e "$path" || -L "$path" ]]' \
  'layout inspects every managed path without losing dangling symlinks'
require_in "$LAYOUT" '[[ ! -d "$path" || -L "$path" ]]' \
  'layout rejects managed-path symlinks and non-directories'
require_in "$LAYOUT" 'install -d -o "$user" -g "$user" -m 0750 -- "$home"' \
  'home ownership is set non-recursively'
require_in "$LAYOUT" 'install -d -o "$user" -g "$user" -m 0700 -- "${home}/tmp"' \
  'tmp is created with the existing private mode'
require_in "$LAYOUT" 'install -d -o "$user" -g "$user" -m 0750 -- "${home}/logs" "${home}/backups"' \
  'logs and backups keep their existing modes'
require_in "$LAYOUT" 'install -d -o "$user" -g "$user" -m 2750 -- "${home}/htdocs" "$webroot"' \
  'htdocs and webroot keep their setgid modes'
forbid_in "$LAYOUT" 'chown -R' \
  'layout never recursively changes home ownership'
forbid_in "$LAYOUT" 'mkdir -p' \
  'layout does not root-create paths through unchecked parents'

require_in "$LAYOUT" 'staged=$(mktemp "${home}/.aidipanel-managed.XXXXXX")' \
  'managed marker uses unique same-directory staging'
require_in "$LAYOUT" 'chown root:root -- "$staged"' \
  'managed marker remains root-owned'
require_in "$LAYOUT" 'chmod 0644 -- "$staged"' \
  'managed marker keeps its public metadata mode'
require_in "$LAYOUT" 'ln -- "$staged" "$marker"' \
  'managed marker publication cannot overwrite an entry'
require_in "$LAYOUT" 'rm -f -- "$staged"' \
  'managed marker staging is cleaned up'

require_in "$SITE_ADD" '_site_make_layout "$site_user" "$domain" "$webroot" || {' \
  'site creation guards layout failure'
require_in "$SITE_ADD" '_site_add_rollback' \
  'site creation has a rollback path'
forbid_in "$SITE_ADD" 'created_home' \
  'site creation no longer supports reused-account cleanup'

printf 'site home layout safety contract passed\n'
