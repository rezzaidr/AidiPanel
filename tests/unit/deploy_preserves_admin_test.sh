#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$DEPLOYER" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}
reject() {
  local pattern="$1" label="$2"
  if grep -Fq -- "$pattern" "$DEPLOYER"; then printf 'FAIL: %s\n' "$label" >&2; exit 1; fi
  printf 'ok: %s\n' "$label"
}

require 'PANEL_ADMIN_PASS=""' 'deploy starts without rotating credentials'
require "SELECT COUNT(*) FROM users WHERE username='admin'" 'deploy detects whether initial admin seeding is needed'
require 'if [[ "$admin_exists" == "0" ]]; then' 'password generation is first-install only'
reject 'UPDATE users SET password_hash=' 'deploy never resets an existing admin password'
require 'Admin password unchanged' 'deploy explicitly reports preserved credentials'
require 'install -o root -g root -m 0755' 'deploy stages the CLI with trusted ownership and mode'
require 'mv -f -- "$cli_staged" /usr/local/bin/aidipanel' 'deploy activates the CLI atomically'

printf 'deploy admin preservation contract passed\n'
