#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"
CONTROLLER="${ROOT}/panel-app/app/Controllers/SiteDatabaseController.php"

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

require "$CLI" 'readonly PMA_RUNTIME_USER="aidipanel-pma"' 'phpMyAdmin has a dedicated Unix identity'
require "$CLI" 'readonly PMA_FPM_SOCKET="/run/php/aidipanel-pma.sock"' 'phpMyAdmin has a dedicated FPM socket'
require "$CLI" 'user = ${PMA_RUNTIME_USER}' 'phpMyAdmin pool never runs as panel or Nginx user'
require "$CLI" 'listen = ${PMA_FPM_SOCKET}' 'phpMyAdmin traffic reaches only its pool'
require "$CLI" 'php_admin_value[session.gc_maxlifetime] = 1800' 'database signon credentials have a bounded lifetime'
require "$CLI" 'usermod --append --groups "$PMA_RUNTIME_USER" aidipanel' 'panel can write only the shared signon-session group'
require "$CLI" 'chown root:"$PMA_RUNTIME_USER" "${PMA_DIR}/config.inc.php"' 'phpMyAdmin config is readable only by its runtime'
# PHP's files session handler only trusts session files owned by the reading
# uid or by root — the panel and phpMyAdmin are separate users, so the signon
# session must be minted root-owned by the CLI, never written by the panel.
require "$CLI" 'chown root:"$PMA_RUNTIME_USER" "$tmpf"' 'signon session file is root-owned so the pma runtime accepts it'
require "$CLI" 'chmod 0660 "$tmpf"' 'signon session file is shared only with the pma group'
reject "$CLI" 'echo "pma_password=' 'database password never leaves the credential broker'
require "$CONTROLLER" "preg_match('/^[a-f0-9]{32}\$/'" 'panel forwards only well-formed signon session ids'
require "$CONTROLLER" "'httponly' => true," 'signon cookie is unreadable from JavaScript'
reject "$CONTROLLER" 'PMA_single_signon_password' 'panel code never handles the raw database password'

printf 'phpMyAdmin runtime isolation contract passed\n'
