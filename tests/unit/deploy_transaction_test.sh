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
  if grep -Fq -- "$pattern" "$DEPLOYER"; then
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  fi
  printf 'ok: %s\n' "$label"
}

require 'readonly DEPLOY_LOCK="/run/lock/aidipanel-deploy.lock"' 'deploys share a dedicated lock'
require 'flock -w 30 178' 'deploy lock has a bounded wait'
require '[[ -d "${PANEL_DIR}/config" && -d "${PANEL_DIR}/storage" ]]' 'destructive replacement requires a recognizable panel directory'
require 'DEPLOY_STAGE=$(mktemp -d "${PANEL_DIR}/.deploy.XXXXXX")' 'application is staged on the panel filesystem'
require 'rollback_deploy()' 'failed activation has a rollback path'
require 'trap rollback_deploy EXIT' 'rollback runs for errors and interruptions'
require 'systemctl stop aidipanel-fpm' 'panel workers stop before application activation'
require 'systemctl restart aidipanel-fpm >/dev/null 2>&1' 'rollback reloads the restored application files'
require 'mv -- "${DEPLOY_STAGE}/${part}" "${PANEL_DIR}/${part}"' 'staged trees replace live trees'
reject 'cp -r "${SCRIPT_DIR}/public/"* "${PANEL_DIR}/public/"' 'deploy never copies into the live public tree'
require 'CLI_ROLLBACK=$(mktemp "/usr/local/bin/.aidipanel.rollback.XXXXXX")' 'current CLI is retained for rollback'
require 'mv -f -- "$CLI_ROLLBACK" /usr/local/bin/aidipanel' 'failed deploy restores the previous CLI'
require 'visudo -c -f "$sudoers_staged"' 'sudoers is validated before activation'
require 'mv -f -- "$sudoers_staged" "$SUDOERS_FILE"' 'validated sudoers is atomically activated'

printf 'deploy transaction contract passed\n'
