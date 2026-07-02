#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CLI" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

reject() {
  local pattern="$1" label="$2"
  if grep -Fq -- "$pattern" "$CLI"; then
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  fi
  printf 'ok: %s\n' "$label"
}

require 'readonly SFTP_LOCK="/run/lock/aidipanel-sftp.lock"' 'SFTP mutations share a dedicated lock'
require 'flock -w 10 176' 'SFTP lock wait is bounded'
require '[[ ! -L "$sshdir" && ! -L "$ak" ]]' 'SFTP key paths reject symbolic links'
require 'runuser -u "$FILES_USER" -- mkdir -p -- "$sshdir"' 'SSH directory creation uses site privileges'
require 'runuser -u "$FILES_USER" -- tee -a -- "$ak"' 'key append uses site privileges'
require 'runuser -u "$FILES_USER" -- python3 - "$ak" "$fp"' 'key deletion uses site privileges'
reject 'chown "$FILES_USER":"$FILES_USER" "$ak"' 'root never follows a user-controlled authorized_keys path'
require 'tempfile.NamedTemporaryFile(' 'key deletion uses an unpredictable temporary file'

printf 'SFTP authorized_keys safety contract passed\n'
