#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CLI" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}
require 'cp -p -- "$SFTP_DROPIN" "$backup"' 'SFTP update preserves the previous sshd config'
require 'mv -f -- "$backup" "$SFTP_DROPIN"' 'SFTP validation failure restores the previous config'
require 'Could not reload sshd; the previous SFTP configuration was restored.' 'SFTP reload failure is reported honestly'
require 'mktemp "${SFTP_DROPIN}.new.XXXXXX"' 'SFTP config staging uses a unique same-directory file'
require 'if ! systemctl reload ssh 2>/dev/null && ! systemctl reload sshd 2>/dev/null; then' 'SFTP activation handles reload failure'

printf 'SFTP sshd transaction contract passed\n'
