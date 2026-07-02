#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$INSTALLER" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}
reject() {
  local pattern="$1" label="$2"
  if grep -Fq -- "$pattern" "$INSTALLER"; then printf 'FAIL: %s\n' "$label" >&2; exit 1; fi
  printf 'ok: %s\n' "$label"
}

reject 'ufw --force reset' 'installer preserves pre-existing firewall rules'
require 'sshd -T' 'installer discovers configured SSH ports'
require 'SSH_CONNECTION' 'installer preserves the current SSH session port as fallback'
require 'ufw allow "${ssh_port}/tcp"' 'installer opens every detected SSH port before enabling UFW'
require 'ufw allow "${PANEL_PORT}/tcp"' 'installer opens the selected panel port'

printf 'firewall preservation contract passed\n'
