#!/usr/bin/env bash
# Local (no-root) guard for per-site PHP-FPM readiness after site:add.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="$ROOT/aidipanel"
[[ -f "$CLI" ]] || { echo "cannot find aidipanel at: $CLI"; exit 2; }

P=0; F=0
ok(){ echo "  [PASS] $1"; P=$((P+1)); }
no(){ echo "  [FAIL] $1"; F=$((F+1)); }

if grep -Eq 'test -S "\$sock"|\[\[ -S "\$sock" \]\]' "$CLI"; then
  ok "_write_fpm_pool waits for the per-site socket"
else
  no "_write_fpm_pool waits for the per-site socket"
fi

if grep -Fq 'PHP-FPM socket did not become ready' "$CLI"; then
  ok "_write_fpm_pool reports socket readiness timeout"
else
  no "_write_fpm_pool reports socket readiness timeout"
fi

echo "=== FPM-POOL-READINESS: ${P} PASS, ${F} FAIL ==="
[[ "$F" -eq 0 ]]
