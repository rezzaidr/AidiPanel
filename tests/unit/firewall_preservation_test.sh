#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
FIREWALL=$(awk '
  $0 == "_configure_firewall() {" { capture=1 }
  capture { print }
  capture && $0 == "}" { exit }
' "$INSTALLER")
[[ -n "$FIREWALL" ]] || { printf 'FAIL: firewall helper is missing\n' >&2; exit 1; }

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

require_firewall() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" <<< "$FIREWALL" \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

assert_before() {
  local first="$1" second="$2" label="$3" first_line second_line
  first_line=$(grep -nF -- "$first" <<< "$FIREWALL" | head -n1 | cut -d: -f1)
  second_line=$(grep -nF -- "$second" <<< "$FIREWALL" | head -n1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

reject 'ufw --force reset' 'installer preserves pre-existing firewall rules'
require 'sshd -T' 'installer discovers configured SSH ports'
require 'SSH_CONNECTION' 'installer preserves the current SSH session port as fallback'
require 'ufw allow "${ssh_port}/tcp"' 'installer opens every detected SSH port before enabling UFW'
require 'ufw allow "${PANEL_PORT}/tcp"' 'installer opens the selected panel port'
require_firewall 'ufw --force enable >> "$PANEL_LOG" 2>&1 \' \
  'UFW activation begins an explicit guarded command'
require_firewall '|| die "UFW could not be enabled. Check ${PANEL_LOG} before retrying the installer."' \
  'UFW activation failure aborts with a log-directed error'
assert_before 'ufw --force enable' 'ok "UFW enabled' \
  'UFW activation completes before its success log'

tmp_dir=$(mktemp -d)
trap 'rm -rf -- "$tmp_dir"' EXIT
events="$tmp_dir/events"
panel_log="$tmp_dir/panel.log"
: > "$events"
: > "$panel_log"

(
  export DRY_RUN=false PANEL_LOG="$panel_log" PANEL_PORT=8443
  export SSH_CONNECTION='198.51.100.10 12345 203.0.113.10 2222'

  log() { :; }
  sshd() { printf 'port 2222\n'; }
  ufw() {
    printf 'ufw:%s\n' "$*" >> "$events"
    [[ "$*" != '--force enable' ]]
  }
  ok() { printf 'success:%s\n' "$*" >> "$events"; }
  die() { printf 'die:%s\n' "$*" >> "$events"; exit 1; }

  eval "$FIREWALL"
  set +e
  ( _configure_firewall )
  rc=$?
  set -e
  printf '%s\n' "$rc" > "$tmp_dir/rc"
)

[[ "$(<"$tmp_dir/rc")" == "1" ]] \
  || { printf 'FAIL: UFW activation failure must return non-zero\n' >&2; exit 1; }
grep -Fq 'die:UFW could not be enabled.' "$events" \
  || { printf 'FAIL: UFW activation failure must call die\n' >&2; exit 1; }
if grep -Fq 'success:UFW enabled' "$events"; then
  printf 'FAIL: failed UFW activation emitted a success log\n' >&2
  exit 1
fi
printf 'ok: failed UFW activation aborts without a success log\n'

printf 'firewall preservation contract passed\n'
