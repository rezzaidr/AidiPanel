#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CLI" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

require 'readonly DB_SECRET_LOCK="/run/lock/aidipanel-db-secrets.lock"' 'database secret store has a dedicated lock'
require 'flock -w 10 177' 'database secret lock has a bounded wait'
require '_db_secret_lock || return 1' 'credential writes lock before encryption and key initialization'
require '_db_secret_lock || return 0' 'credential cleanup locks before read-modify-write'
require '_db_secret_lock || return 1' 'credential reads lock before key initialization'

printf 'database secret locking contract passed\n'
