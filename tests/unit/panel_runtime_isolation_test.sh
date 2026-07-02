#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"
CLI="${ROOT}/aidipanel"
ARCHITECTURE="${ROOT}/docs/architecture.md"

require() {
  local file="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" "$file" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}
reject() {
  local file="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" "$file"; then printf 'FAIL: %s\n' "$label" >&2; exit 1; fi
  printf 'ok: %s\n' "$label"
}

require "$INSTALLER" 'user = aidipanel' 'panel FPM runs as the dedicated runtime user'
require "$INSTALLER" 'group = aidipanel' 'panel FPM does not inherit the web-server identity'
require "$INSTALLER" 'usermod --append --groups adm "$PANEL_USER"' 'panel collector receives read-only system log access'
require "$INSTALLER" 'listen.group = www-data' 'Nginx can connect to the isolated panel socket'
require "$INSTALLER" 'chown -R root:"${PANEL_USER}" "${PANEL_DIR}/app"' 'installed application source is root-owned'
require "$INSTALLER" 'chown -R "${PANEL_USER}":"${PANEL_USER}" "${PANEL_DIR}/storage"' 'runtime storage belongs only to the panel runtime'
require "$INSTALLER" 'aidipanel ALL=(root) NOPASSWD: /usr/local/sbin/aidipanel-web-run *' 'only the panel runtime crosses the sudo boundary'
reject "$INSTALLER" 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/aidipanel-web-run *' 'the Nginx worker cannot invoke the root wrapper'
require "$INSTALLER" '* * * * * aidipanel /usr/bin/php /opt/aidipanel/bin/collect-metrics.php' 'metrics run under the panel runtime'
require "$DEPLOYER" 'chown -R root:"${PANEL_USER}" "${PANEL_DIR}/app"' 'deploy preserves root-owned application source'
require "$DEPLOYER" 'sudo -u "${PANEL_USER}" env AIDIPANEL_ADMIN_HASH=' 'deploy initializes SQLite as the panel runtime'
require "$DEPLOYER" 'systemctl restart aidipanel-fpm' 'deploy activates the isolated FPM runtime'
reject "$DEPLOYER" 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/aidipanel-web-run *' 'deploy removes the legacy web-server sudo grant'
require "$CLI" 'local fpm_sock="$PMA_FPM_SOCKET"' 'phpMyAdmin remains isolated from panel FPM'
require "$ARCHITECTURE" 'dedicated `aidipanel` system user' 'architecture documents the isolated runtime'
reject "$ARCHITECTURE" 'www-data ALL=(root)' 'architecture omits the retired sudo boundary'

printf 'panel runtime isolation contract passed\n'
