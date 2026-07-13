#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"
INSTALLER="${ROOT}/install.sh"

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
require 'SHA256SUMS.sig' 'self-update downloads the detached signature'
require '_release_manifest_verify' 'self-update authenticates the checksum manifest'
require '_release_version_is_downgrade' 'self-update rejects signed rollback attempts'
require 'UPDATE_SIGNATURE_URL' 'custom mirrors provide the matching signature URL'
require "IFS=' ' read -r cli_checksum cli_checksum_asset extra" 'self-update parses the CLI checksum under strict global IFS'
require "IFS=' ' read -r panel_checksum panel_checksum_asset extra" 'self-update parses the panel checksum under strict global IFS'
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

function_body() {
  local name="$1" file="$2"
  sed -n "/^${name}() {$/,/^}$/p" "$file"
}

for helper in _release_public_key_write _release_manifest_verify _release_manifest_version; do
  installer_body=$(function_body "$helper" "$INSTALLER")
  cli_body=$(function_body "$helper" "$CLI")
  [[ -n "$installer_body" && "$installer_body" == "$cli_body" ]] \
    || { printf 'FAIL: installer and CLI differ for %s\n' "$helper" >&2; exit 1; }
  printf 'ok: installer and CLI share %s\n' "$helper"
done

eval "$(function_body _release_version_parts "$CLI")"
eval "$(function_body _release_version_is_downgrade "$CLI")"

# Match the CLI's process-wide strict IFS; version parsing must not depend on
# the test shell's default space-delimited IFS.
original_ifs="$IFS"
IFS=$'\n\t'

expect_version_status() {
  local expected="$1" candidate="$2" current="$3" status
  if _release_version_is_downgrade "$candidate" "$current"; then
    status=0
  else
    status=$?
  fi
  [[ "$status" -eq "$expected" ]] \
    || { printf 'FAIL: compare %s against %s returned %s, expected %s\n' \
      "$candidate" "$current" "$status" "$expected" >&2; exit 1; }
  printf 'ok: compare %s against %s\n' "$candidate" "$current"
}

expect_version_status 0 1.3.1 1.3.2
expect_version_status 1 1.3.2 1.3.2
expect_version_status 1 1.4.0 1.3.9
expect_version_status 0 1.3.3-rc1 1.3.3
expect_version_status 1 1.3.3 1.3.3-rc9
expect_version_status 0 1.3.3-rc1 1.3.3-rc2
expect_version_status 1 1.3.3-rc2 1.3.3-rc1
expect_version_status 2 1.3.3-beta1 1.3.3
IFS="$original_ifs"

printf 'self-update integrity contract passed\n'
