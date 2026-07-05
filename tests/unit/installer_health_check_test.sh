#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"

health_check_body="$({
  awk '
    $0 == "_health_check() {" { capture=1 }
    capture { print }
    capture && /^}/ { exit }
  ' "$INSTALLER"
})"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" <<< "$health_check_body" \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

require '--http1.1' 'panel readiness probe uses deterministic HTTP/1.1'
require '--retry 5' 'panel readiness probe tolerates the post-reload startup window'
require '--retry-all-errors' 'panel readiness probe retries transient send failures'
require '>> "$PANEL_LOG" 2>&1' 'transient curl errors stay in the detailed install log'
require '_matrix "AidiPanel port ${PANEL_PORT}" "no response"; failed=$((failed+1))' \
  'a failed panel response increments the health-check issue count'

printf 'installer health-check contract passed\n'
