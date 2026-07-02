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
  if grep -Fq -- "$pattern" "$CLI"; then printf 'FAIL: %s\n' "$label" >&2; exit 1; fi
  printf 'ok: %s\n' "$label"
}

require 'releases/latest/download/aidipanel' 'self-update uses a release asset'
require 'releases/latest/download/SHA256SUMS' 'self-update downloads checksums'
require 'aidipanel-panel-app.tar.gz' 'self-update downloads matching panel release'
require 'tar -tzf "$panel_archive"' 'self-update validates archive paths'
require 'bash "${tmp_dir}/panel-app/deploy-panel.sh"' 'self-update deploys panel and CLI together'
require 'sha256sum -c -' 'self-update verifies checksums'
require 'install -o root -g root -m 0755' 'self-update stages trusted executable permissions'
require 'mv -f -- "$staged" "$current_bin"' 'self-update activates CLI atomically'
require "trap 'rm -rf -- \"\$tmp_dir\"' EXIT" 'self-update cleans temporary files'
require 'self:update     Update AidiPanel CLI and web panel' 'CLI help describes complete update scope'
reject 'raw.githubusercontent.com/rezzaidr/AidiPanel/master/aidipanel' 'self-update never executes master code'
reject 'source /etc/aidipanel/update.conf' 'self-update does not execute configuration'

printf 'self-update integrity contract passed\n'
