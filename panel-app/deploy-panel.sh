#!/usr/bin/env bash
# =============================================================================
#  AidiPanel — Deploy Panel Web App v1.2.0-rc1
#  Usage: bash deploy-panel.sh [--dir /opt/aidipanel]
#  Author: AidiPanel Team — by rezzaid
# =============================================================================

set -Eeuo pipefail

PANEL_DIR="/opt/aidipanel"
PANEL_USER="aidipanel"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'
YELLOW='\033[1;33m'; BOLD='\033[1m'; RESET='\033[0m'

log() { echo -e "${CYAN}[INFO]${RESET}  $*"; }
ok()  { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn(){ echo -e "${YELLOW}[WARN]${RESET}  $*"; }
die() { echo -e "${RED}[ERROR]${RESET} $*" >&2; exit 1; }
credential_line() {
  local key="$1" value="$2"
  printf '%s=%q\n' "$key" "$value"
}

while [[ $# -gt 0 ]]; do
  case "$1" in --dir) shift; PANEL_DIR="$1" ;; *) ;; esac
  shift
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root: sudo bash deploy-panel.sh"
[[ -d "$SCRIPT_DIR/public" ]] || die "Run from panel-app directory."
[[ -d "$PANEL_DIR" ]] || die "AidiPanel dir not found: ${PANEL_DIR}. Run install.sh first."

log "Deploying AidiPanel v1.2.0-rc1 to ${PANEL_DIR}..."

# --------------------------------------------------------------------------
# 1. Generate random admin password
# --------------------------------------------------------------------------
PANEL_ADMIN_PASS=$(openssl rand -base64 16 | tr -dc 'A-Za-z0-9' | head -c 18)
log "Generated admin password"

# --------------------------------------------------------------------------
# 2. Copy app files
# --------------------------------------------------------------------------
cp -r "${SCRIPT_DIR}/public/"* "${PANEL_DIR}/public/"
cp -r "${SCRIPT_DIR}/app"       "${PANEL_DIR}/"
ok "App files copied"

# --------------------------------------------------------------------------
# 3. Storage dirs + permissions
# --------------------------------------------------------------------------
mkdir -p "${PANEL_DIR}/storage/db" \
         "${PANEL_DIR}/storage/logs" \
         "${PANEL_DIR}/storage/cache" \
         "${PANEL_DIR}/storage/tmp/vhost" \
         "${PANEL_DIR}/storage/backups"

chown -R "${PANEL_USER}":www-data "${PANEL_DIR}/app"
chown -R "${PANEL_USER}":www-data "${PANEL_DIR}/public"
chown -R www-data:www-data         "${PANEL_DIR}/storage"

find "${PANEL_DIR}/app"    -type f -exec chmod 640 {} \;
find "${PANEL_DIR}/app"    -type d -exec chmod 750 {} \;
find "${PANEL_DIR}/public" -type f -exec chmod 644 {} \;
find "${PANEL_DIR}/public" -type d -exec chmod 755 {} \;

chmod 750 "${PANEL_DIR}/storage"
chmod 770 "${PANEL_DIR}/storage/db"
chmod 770 "${PANEL_DIR}/storage/logs"
chmod 770 "${PANEL_DIR}/storage/cache"
chmod 770 "${PANEL_DIR}/storage/tmp"
chmod 770 "${PANEL_DIR}/storage/tmp/vhost"
chmod 770 "${PANEL_DIR}/storage/backups"
ok "Permissions set"

# --------------------------------------------------------------------------
# 4. Tulis password hash LANGSUNG ke SQLite (tidak baca file saat runtime)
#    Ini menghindari permission issues antara root vs www-data
# --------------------------------------------------------------------------
SQLITE_DB="${PANEL_DIR}/storage/db/aidipanel.sqlite"

# Buat SQLite DB schema dulu jika belum ada (jalankan sebagai www-data)
sudo -u www-data php8.3 -r "
define('PANEL_DIR', '${PANEL_DIR}');
define('APP_ROOT', '${PANEL_DIR}/app');
define('PANEL_VERSION', '1.2.0-rc1');
// Trigger DB init
require '${PANEL_DIR}/app/Core/DB.php';
\Core\DB::instance();
echo 'DB initialized' . PHP_EOL;
" 2>/dev/null || true

# Sekarang tulis password hash langsung via sqlite3 (kita masih root)
HASH=$(php8.3 -r "echo password_hash('${PANEL_ADMIN_PASS}', PASSWORD_BCRYPT, ['cost'=>12]);")

if [[ -f "$SQLITE_DB" ]]; then
    sqlite3 "$SQLITE_DB" "
        INSERT OR IGNORE INTO users (username, password_hash, role, active)
        VALUES ('admin', '${HASH}', 'admin', 1);
        UPDATE users SET password_hash='${HASH}' WHERE username='admin';
    "
    chown www-data:www-data "$SQLITE_DB"
    chmod 660 "$SQLITE_DB"
    ok "Admin password written to database"
else
    warn "SQLite DB not created yet — will be initialized on first page load"
    warn "After first load, run: sqlite3 ${SQLITE_DB} \"UPDATE users SET password_hash='\$(php8.3 -r \\\"echo password_hash('${PANEL_ADMIN_PASS}', PASSWORD_BCRYPT);\\\")' WHERE username='admin';\""
fi

# --------------------------------------------------------------------------
# 5. Save credentials
# --------------------------------------------------------------------------
CREDS="${PANEL_DIR}/credentials.conf"
sed -i '/^PANEL_ADMIN_/d' "$CREDS" 2>/dev/null || true
{
    credential_line PANEL_ADMIN_USER "admin"
    credential_line PANEL_ADMIN_PASSWORD "$PANEL_ADMIN_PASS"
} >> "$CREDS"
chmod 600 "$CREDS"

# --------------------------------------------------------------------------
# 6. Sudoers wrapper for web-triggered CLI actions
# --------------------------------------------------------------------------
WRAPPER="/usr/local/sbin/aidipanel-web-run"
cat > "$WRAPPER" << 'WRAPPER_SCRIPT'
#!/usr/bin/env bash
set -Eeuo pipefail
export NO_COLOR=1
cmd="${1:-}"
case "$cmd" in
  site:add|site:delete|site:list|vhost:save|\
  cache:status|cache:purge|cache:enable|cache:disable|\
  db:add|db:delete|db:list|db:backup|\
  php:list|php:version|php:restart|\
  ssl:install|ssl:renew|ssl:status|\
  service:status|service:start|service:stop|service:restart|\
  system:info)
    ;;
  *)
    echo "AidiPanel web command not allowed: ${cmd:-<empty>}" >&2
    exit 126
    ;;
esac
if [[ ! -x /usr/local/bin/aidipanel ]]; then
  echo "AidiPanel CLI not found: /usr/local/bin/aidipanel" >&2
  exit 127
fi
if [[ -x /usr/bin/systemd-run && -d /run/systemd/system ]]; then
  exec /usr/bin/systemd-run --quiet --wait --pipe --collect /usr/local/bin/aidipanel "$@"
fi
if [[ -x /usr/bin/nsenter && -e /proc/1/ns/mnt ]]; then
  exec /usr/bin/nsenter --mount=/proc/1/ns/mnt /usr/local/bin/aidipanel "$@"
fi
exec /usr/local/bin/aidipanel "$@"
WRAPPER_SCRIPT
chown root:root "$WRAPPER"
chmod 750 "$WRAPPER"

SUDOERS_FILE="/etc/sudoers.d/aidipanel"
cat > "$SUDOERS_FILE" << 'SUDOERS'
# AidiPanel - allow the web panel to run the controlled CLI wrapper as root
www-data ALL=(root) NOPASSWD: /usr/local/sbin/aidipanel-web-run *
SUDOERS
chmod 440 "$SUDOERS_FILE"
visudo -c -f "$SUDOERS_FILE" >> /var/log/aidipanel-install.log 2>&1 \
    && ok "Sudoers configured" \
    || { warn "Sudoers validation failed - removing"; rm -f "$SUDOERS_FILE"; }

# --------------------------------------------------------------------------
# 7. Nginx test & reload
# --------------------------------------------------------------------------
# --------------------------------------------------------------------------
# Update CLI binary juga (bukan hanya panel app)
# --------------------------------------------------------------------------
SCRIPT_PARENT="$(dirname "$SCRIPT_DIR")"
if [[ -f "${SCRIPT_PARENT}/aidipanel" ]]; then
  cp "${SCRIPT_PARENT}/aidipanel" /usr/local/bin/aidipanel
  chmod +x /usr/local/bin/aidipanel
  ok "CLI updated: /usr/local/bin/aidipanel"
else
  warn "CLI binary not found at ${SCRIPT_PARENT}/aidipanel — skipping CLI update"
fi

nginx -t >> /var/log/aidipanel-install.log 2>&1 || die "Nginx config test failed."
systemctl reload nginx
ok "Nginx reloaded"

# --------------------------------------------------------------------------
# 8. Summary
# --------------------------------------------------------------------------
SERVER_IP=$(ip route get 8.8.8.8 2>/dev/null \
    | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1);exit}}' \
    || echo "<server-ip>")
PANEL_PORT=$(grep '^PANEL_PORT=' "${PANEL_DIR}/config/panel.conf" 2>/dev/null \
    | cut -d= -f2 || echo "8443")

echo ""
echo -e "  ${BOLD}${GREEN}AidiPanel v1.2.0-rc1 deployed!${RESET}"
echo ""
echo -e "  Panel URL  : https://${SERVER_IP}:${PANEL_PORT}"
echo -e "  Login      : admin"
echo -e "  ${BOLD}${RED}Password   : ${PANEL_ADMIN_PASS}${RESET}  ← SAVE THIS NOW"
echo ""
echo -e "  Credentials: ${CREDS}"
echo ""

# Auto-cleanup installer directory
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="$(dirname "$DEPLOY_DIR")"
if [[ "$INSTALL_DIR" =~ ^(/root|/home/[^/]+)/aidipanel-v[0-9] ]]; then
  PARENT="$(dirname "$INSTALL_DIR")"
  rm -f "${PARENT}/aidipanel-v"*.zip 2>/dev/null || true
  rm -rf "$INSTALL_DIR"
  echo -e "  ${CYAN}[INFO]${RESET}  Installer directory removed: ${INSTALL_DIR}"
fi
