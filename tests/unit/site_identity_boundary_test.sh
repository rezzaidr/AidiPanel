#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"

cron_resolve_body="$({
  awk '
    $0 == "_cron_resolve() {" { capture=1 }
    capture { print }
    capture && /^}/ { exit }
  ' "$CLI"
})"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" <<< "$cron_resolve_body" \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

require '_validate_site_user "$CRON_USER"' \
  'cron validates the resolved user against the site-user policy'
require '_site_purge_guard "$domain" "$CRON_USER" "$CRON_USER"' \
  'cron requires the managed-site identity marker'

printf 'site identity boundary contract passed\n'
