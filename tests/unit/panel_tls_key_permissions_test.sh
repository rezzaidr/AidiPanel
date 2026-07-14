#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"

function_body() {
  local file="$1" name="$2"
  awk -v sig="${name}() {" '
    $0 == sig { inside=1 }
    inside { print }
    inside && $0 == "}" { exit }
  ' "$file"
}

require() {
  local content="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" <<< "$content" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

installer_body=$(function_body "$INSTALLER" '_configure_panel_vhost')
deployer_body=$(function_body "$DEPLOYER" 'harden_panel_tls_permissions')

require "$installer_body" '( umask 077' \
  'fresh TLS material is created under a private umask'
require "$installer_body" 'install -d -o root -g root -m 0700 -- "$ssl_dir"' \
  'fresh panel TLS directory is root-only'
require "$installer_body" '[[ ! -L "$ssl_dir" ]]' \
  'fresh install rejects a symbolic-link TLS directory'
require "$installer_body" '[[ ! -L "$key_path" && ! -L "$cert_path" ]]' \
  'fresh install rejects symbolic-link TLS files before generation'
require "$installer_body" 'chown root:root -- "$ssl_dir" "$key_path" "$cert_path"' \
  'fresh panel TLS material is root-owned'
require "$installer_body" 'chmod 0600 -- "$key_path"' \
  'fresh panel private key is mode 0600'
require "$installer_body" 'chmod 0644 -- "$cert_path"' \
  'fresh panel certificate is mode 0644'

require "$deployer_body" 'harden_panel_tls_permissions()' \
  'release deployer defines the existing-install retrofit'
require "$deployer_body" '[[ -d "$ssl_dir" && ! -L "$ssl_dir" ]]' \
  'existing-install retrofit rejects a symbolic-link TLS directory'
require "$deployer_body" 'install -d -o root -g root -m 0700 -- "$ssl_dir"' \
  'existing panel TLS directory is repaired to root-only'
require "$deployer_body" '[[ -f "$key_path" && ! -L "$key_path" ]]' \
  'existing-install retrofit rejects an unsafe private-key entry'
require "$deployer_body" 'chown root:root -- "$key_path"' \
  'existing panel private key is repaired to root ownership'
require "$deployer_body" 'chmod 0600 -- "$key_path"' \
  'existing panel private key is repaired to mode 0600'
require "$deployer_body" '[[ -f "$cert_path" && ! -L "$cert_path" ]]' \
  'existing-install retrofit rejects an unsafe certificate entry'
require "$deployer_body" 'chown root:root -- "$cert_path"' \
  'existing panel certificate is repaired to root ownership'
require "$deployer_body" 'chmod 0644 -- "$cert_path"' \
  'existing panel certificate is repaired to mode 0644'

mapfile -t harden_refs < <(grep -n '^harden_panel_tls_permissions' "$DEPLOYER" | cut -d: -f1)
[[ "${#harden_refs[@]}" -eq 2 ]] \
  || { printf 'FAIL: deployer must define and invoke TLS hardening exactly once\n' >&2; exit 1; }
stage_line=$(grep -n '^DEPLOY_STAGE=$(mktemp ' "$DEPLOYER" | cut -d: -f1)
[[ "${harden_refs[1]}" -lt "$stage_line" ]] \
  || { printf 'FAIL: TLS hardening must run before app staging\n' >&2; exit 1; }
printf 'ok: existing TLS permissions are repaired before app activation\n'

printf 'panel TLS key permissions contract passed\n'
