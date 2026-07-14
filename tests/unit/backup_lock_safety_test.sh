#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"
CREATE=$(sed -n '/^_backup_create() {$/,/^# files:rename --domain X/p' "$CLI")

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

require_create 'local lock_dir="/run/lock/aidipanel-backup"' \
  'backup locks use a dedicated runtime directory'
require_create '[[ ! -L "$lock_dir" ]]' \
  'symbolic-link lock directories are rejected'
require_create 'install -d -o root -g root -m 0700 -- "$lock_dir"' \
  'backup lock directory is explicitly root-only'
require_create 'local lock="${lock_dir}/${domain//[!A-Za-z0-9.-]/_}.lock"' \
  'per-domain lock stays below the protected directory'
require_create '[[ ! -L "$lock" ]]' \
  'symbolic-link lock entries are rejected'
require_create 'exec 9>"$lock" || die "Could not open the backup lock."' \
  'backup lock open fails closed'
require_create 'chmod 0600 -- "$lock" || die "Could not secure the backup lock."' \
  'backup lock file is root-only'
require_create 'flock -n 9 || die "Backup is already running for this site."' \
  'existing non-blocking per-domain serialization is preserved'

assert_before '[[ ! -L "$lock_dir" ]]' 'install -d -o root -g root -m 0700 -- "$lock_dir"' \
  'directory symlinks are rejected before directory creation'
assert_before '[[ ! -L "$lock" ]]' 'exec 9>"$lock"' \
  'lock symlinks are rejected before privileged open'

forbid_create '/tmp/aidipanel-backup-' \
  'backup lock no longer uses a world-writable directory'
forbid_create 'rm -f -- "$lock"' \
  'backup completion never unlinks the flock inode'

printf 'backup lock safety contract passed\n'
