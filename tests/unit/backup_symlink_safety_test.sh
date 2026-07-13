#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"
CREATE=$(sed -n '/^_backup_create() {$/,/^# files:rename --domain X/p' "$CLI")

require_cli() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CLI" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

require_create() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" <<< "$CREATE" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

forbid_create() {
  local pattern="$1" label="$2"
  if grep -Fq -- "$pattern" <<< "$CREATE"; then
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  fi
  printf 'ok: %s\n' "$label"
}

assert_before() {
  local first="$1" second="$2" label="$3" first_line second_line
  first_line=$(grep -nF -- "$first" <<< "$CREATE" | head -n1 | cut -d: -f1)
  second_line=$(grep -nF -- "$second" <<< "$CREATE" | head -n1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

require_cli 'readonly BACKUP_STAGING_ROOT="/var/lib/aidipanel/backup-staging"' \
  'backup staging has a dedicated root-owned base'
require_create '[[ ! -L "$backups" ]] || die "Unsafe backup path:' \
  'backup directory symlinks fail closed'
require_create 'runuser -u "$user" -- mkdir -p -- "$backups"' \
  'missing backup directory is created with site-user privileges'
require_create 'runuser -u "$user" -- chmod 750 -- "$backups"' \
  'backup directory mode is applied with site-user privileges'
require_create 'install -d -o root -g root -m 0700 -- "$BACKUP_STAGING_ROOT"' \
  'root staging base is explicitly secured'
require_create 'BACKUP_STAGING=$(mktemp -d "${BACKUP_STAGING_ROOT}/' \
  'unique staging is created below the root-owned base'
require_create 'runuser -u "$user" -- tar czf -' \
  'webroot is read with site-user privileges and streamed to root staging'
require_create 'tar czf "${BACKUP_STAGING}/bundle.tar.gz"' \
  'bundle is assembled inside root staging'
require_create '--owner="$user" --group="$user"' \
  'bundle preserves the previous site-user member ownership metadata'
require_create 'BACKUP_PUBLISH_TMP=$(runuser -u "$user" -- mktemp "${backups}/.aidipanel-backup.XXXXXXXXXX")' \
  'publication starts with a same-directory site-user temporary file'
require_create 'runuser -u "$user" -- tee -- "$BACKUP_PUBLISH_TMP"' \
  'bundle publication writes with site-user privileges'
require_create 'runuser -u "$user" -- chmod 600 -- "$BACKUP_PUBLISH_TMP"' \
  'published archive mode is applied with site-user privileges'
require_create 'runuser -u "$user" -- mv -f -- "$BACKUP_PUBLISH_TMP" "$final"' \
  'publication uses a same-directory atomic rename as the site user'
require_create 'runuser -u "$user" -- rm -f -- "$pf"' \
  'retention pruning runs with site-user privileges'
require_create '( cd / && runuser -u "$user" -- find "$backups"' \
  'retention pruning starts from a site-user-accessible working directory'
require_create '"$BACKUP_STAGING" == "${BACKUP_STAGING_ROOT}/"*' \
  'root cleanup is bounded to the staging base'
require_create '"$BACKUP_PUBLISH_TMP" == "${BACKUP_HOME}/backups/.aidipanel-backup."*' \
  'failed publication cleanup is bounded to the site backup directory'
assert_before '[[ ! -L "$backups" ]]' 'BACKUP_STAGING=$(mktemp' \
  'symlinks are rejected before staging starts'

forbid_create 'mkdir -p "$backups" "${BACKUP_HOME}/tmp"' \
  'root no longer creates tenant backup or tmp paths'
forbid_create 'chown ' \
  'backup creation performs no chown on tenant-controlled paths'
forbid_create 'mktemp -d -p "${BACKUP_HOME}/tmp"' \
  'privileged staging no longer uses the tenant tmp directory'
forbid_create 'chmod 600 "$final"' \
  'root no longer chmods the final tenant-controlled path'
forbid_create 'rm -f "$pf" 2>/dev/null ||' \
  'root no longer prunes tenant backup paths'

printf 'backup symlink safety contract passed\n'
