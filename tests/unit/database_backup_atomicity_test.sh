#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CLI" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

require 'backup_staged=$(mktemp "${output_dir}/.${db_name}.XXXXXX.sql.gz")' 'database dump uses a same-directory temporary file'
require "( set -o pipefail; mysqldump" 'database dump propagates mysqldump and gzip failures'
require 'rm -f -- "$backup_staged"' 'failed database dumps remove partial output'
require 'chmod 600 "$backup_staged"' 'database dumps are private before publication'
require 'mv -f -- "$backup_staged" "$backup_file"' 'completed database dump is atomically published'

printf 'database backup atomicity contract passed\n'
