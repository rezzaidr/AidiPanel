#!/usr/bin/env bash
# =============================================================================
#  AidiPanel — Deploy Panel Web App v1.2.1
#  Usage: bash deploy-panel.sh [--dir /opt/aidipanel]
#  Author: AidiPanel Team — by rezzaid
# =============================================================================

set -Eeuo pipefail

PANEL_DIR="/opt/aidipanel"
PANEL_USER="aidipanel"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PANEL_VERSION="1.2.1"
readonly DEPLOY_LOCK="/run/lock/aidipanel-deploy.lock"

# Resolve the default PHP version from the installed policy file, fallback 8.4.
PHP_DEFAULT_VERSION="8.4"
if [[ -r /etc/aidipanel/php.conf ]]; then
  # shellcheck source=/dev/null
  source /etc/aidipanel/php.conf
fi
PHP_BIN="php${PHP_DEFAULT_VERSION}"

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

# Heal v1.2.1 installations where the fresh installer failed to install `acl`.
# This is deliberately best-effort: panel updates must not fail because a package
# repository or a live cache entry is temporarily unavailable.
_repair_cache_acl() {
  local cache_dir repaired=0 failed=0

  if ! command -v setfacl >/dev/null 2>&1; then
    if ! DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends acl \
        >> /var/log/aidipanel-install.log 2>&1; then
      warn "Cache ACL repair skipped: the acl package could not be installed"
      return 0
    fi
  fi
  command -v setfacl >/dev/null 2>&1 || {
    warn "Cache ACL repair skipped: setfacl is unavailable"
    return 0
  }

  for cache_dir in /var/cache/nginx/fastcgi /var/cache/nginx/aidipanel/*/fastcgi; do
    [[ -d "$cache_dir" ]] || continue
    if setfacl -R -m "u:${PANEL_USER}:rX" -m "d:u:${PANEL_USER}:rX" "$cache_dir" 2>/dev/null; then
      repaired=1
    else
      failed=1
    fi
  done

  if [[ "$failed" -eq 1 ]]; then
    warn "Cache ACL repair was incomplete; the Data Cached tile may read as —"
  elif [[ "$repaired" -eq 1 ]]; then
    ok "FastCGI cache ACL repaired for Dashboard metrics"
  fi
  return 0
}

while [[ $# -gt 0 ]]; do
  case "$1" in --dir) shift; PANEL_DIR="$1" ;; *) ;; esac
  shift
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root: sudo bash deploy-panel.sh"
[[ -d "$SCRIPT_DIR/public" ]] || die "Run from panel-app directory."
[[ "$PANEL_DIR" == /* && "$PANEL_DIR" != "/" ]] || die "Panel directory must be a safe absolute path."
[[ -d "$PANEL_DIR" ]] || die "AidiPanel dir not found: ${PANEL_DIR}. Run install.sh first."
[[ -d "${PANEL_DIR}/config" && -d "${PANEL_DIR}/storage" ]] \
  || die "Panel directory is missing its config or storage directory: ${PANEL_DIR}"

mkdir -p "$(dirname "$DEPLOY_LOCK")"
exec 178>"$DEPLOY_LOCK"
flock -w 30 178 || die "Another AidiPanel install or update is currently running."

DEPLOY_STAGE=""
DEPLOY_ROLLBACK=""
DEPLOY_ACTIVATED=0
PANEL_FPM_WAS_ACTIVE=0
CLI_ROLLBACK=""
CLI_ACTIVATED=0
CLI_WAS_ABSENT=0

rollback_deploy() {
  local rc=$? part
  trap - EXIT INT TERM HUP

  if [[ "$rc" -ne 0 && "$DEPLOY_ACTIVATED" -eq 1 && -d "$DEPLOY_ROLLBACK" ]]; then
    warn "Deployment failed; restoring the previous application files."
    for part in app public bin; do
      if [[ -e "${DEPLOY_ROLLBACK}/${part}" || -L "${DEPLOY_ROLLBACK}/${part}" ]]; then
        rm -rf -- "${PANEL_DIR:?}/${part}"
        mv -- "${DEPLOY_ROLLBACK}/${part}" "${PANEL_DIR}/${part}" || true
      elif [[ -e "${DEPLOY_ROLLBACK}/.absent-${part}" ]]; then
        rm -rf -- "${PANEL_DIR:?}/${part}"
      fi
    done
  fi

  if [[ "$rc" -ne 0 && "$CLI_ACTIVATED" -eq 1 ]]; then
    if [[ -n "$CLI_ROLLBACK" && -f "$CLI_ROLLBACK" ]]; then
      mv -f -- "$CLI_ROLLBACK" /usr/local/bin/aidipanel || true
      CLI_ROLLBACK=""
    elif [[ "$CLI_WAS_ABSENT" -eq 1 ]]; then
      rm -f -- /usr/local/bin/aidipanel
    fi
  fi

  if [[ "$rc" -ne 0 && "$PANEL_FPM_WAS_ACTIVE" -eq 1 ]]; then
    systemctl restart aidipanel-fpm >/dev/null 2>&1 \
      || warn "The previous files were restored, but aidipanel-fpm could not be started."
  fi

  [[ -n "$DEPLOY_STAGE" && -d "$DEPLOY_STAGE" ]] && rm -rf -- "$DEPLOY_STAGE"
  [[ -n "$DEPLOY_ROLLBACK" && -d "$DEPLOY_ROLLBACK" ]] && rm -rf -- "$DEPLOY_ROLLBACK"
  [[ -n "$CLI_ROLLBACK" && -f "$CLI_ROLLBACK" ]] && rm -f -- "$CLI_ROLLBACK"
  exit "$rc"
}

trap rollback_deploy EXIT
trap 'exit 130' INT TERM HUP

log "Deploying AidiPanel v${PANEL_VERSION} to ${PANEL_DIR}..."

# Existing deployments must never rotate credentials as a side effect of an
# application update. A password is generated later only when no admin exists.
PANEL_ADMIN_PASS=""

# Migrate legacy installs away from the shared Nginx/PHP identity.
getent group "$PANEL_USER" >/dev/null 2>&1 || groupadd --system "$PANEL_USER"
if id "$PANEL_USER" >/dev/null 2>&1; then
    usermod --gid "$PANEL_USER" "$PANEL_USER"
else
    useradd --system --gid "$PANEL_USER" --no-create-home --shell /usr/sbin/nologin "$PANEL_USER"
fi
getent group adm >/dev/null 2>&1 && usermod --append --groups adm "$PANEL_USER"
_repair_cache_acl

PANEL_FPM_CONF="/etc/aidipanel/php-fpm/php-fpm.conf"
[[ -f "$PANEL_FPM_CONF" ]] || die "Panel FPM config not found: ${PANEL_FPM_CONF}"
sed -i -E \
    -e 's/^user = .*/user = aidipanel/' \
    -e 's/^group = .*/group = aidipanel/' \
    "$PANEL_FPM_CONF"
grep -Fq 'php_admin_flag[display_errors] = off' "$PANEL_FPM_CONF" \
    || printf '%s\n' 'php_admin_flag[display_errors] = off' >> "$PANEL_FPM_CONF"

# --------------------------------------------------------------------------
# 2. Stage and activate app files
# --------------------------------------------------------------------------
DEPLOY_STAGE=$(mktemp -d "${PANEL_DIR}/.deploy.XXXXXX")
DEPLOY_ROLLBACK=$(mktemp -d "${PANEL_DIR}/.rollback.XXXXXX")
for part in app public bin; do
  [[ -d "${SCRIPT_DIR}/${part}" ]] || die "Release is missing panel-app/${part}."
  cp -a -- "${SCRIPT_DIR}/${part}" "${DEPLOY_STAGE}/${part}"
done

chown -R root:"${PANEL_USER}" "${DEPLOY_STAGE}/app" "${DEPLOY_STAGE}/public" "${DEPLOY_STAGE}/bin"
find "${DEPLOY_STAGE}/app"    -type f -exec chmod 640 {} \;
find "${DEPLOY_STAGE}/app"    -type d -exec chmod 750 {} \;
find "${DEPLOY_STAGE}/public" -type f -exec chmod 644 {} \;
find "${DEPLOY_STAGE}/public" -type d -exec chmod 755 {} \;
find "${DEPLOY_STAGE}/bin"    -type f -exec chmod 640 {} \;
find "${DEPLOY_STAGE}/bin"    -type d -exec chmod 750 {} \;

if systemctl is-active --quiet aidipanel-fpm; then
  PANEL_FPM_WAS_ACTIVE=1
  systemctl stop aidipanel-fpm || die "Could not pause the panel runtime for a safe update."
fi

DEPLOY_ACTIVATED=1
for part in app public bin; do
  if [[ -e "${PANEL_DIR}/${part}" || -L "${PANEL_DIR}/${part}" ]]; then
    mv -- "${PANEL_DIR}/${part}" "${DEPLOY_ROLLBACK}/${part}"
  else
    : > "${DEPLOY_ROLLBACK}/.absent-${part}"
  fi
  mv -- "${DEPLOY_STAGE}/${part}" "${PANEL_DIR}/${part}"
done
ok "App files activated"

# --------------------------------------------------------------------------
# 3. Storage dirs + permissions
# --------------------------------------------------------------------------
mkdir -p "${PANEL_DIR}/storage/db" \
         "${PANEL_DIR}/storage/logs" \
         "${PANEL_DIR}/storage/cache" \
         "${PANEL_DIR}/storage/tmp/vhost" \
         "${PANEL_DIR}/storage/backups"

chown -R root:"${PANEL_USER}" "${PANEL_DIR}/app"
chown -R root:"${PANEL_USER}" "${PANEL_DIR}/public"
chown -R root:"${PANEL_USER}" "${PANEL_DIR}/bin"
chown -R "${PANEL_USER}":"${PANEL_USER}" "${PANEL_DIR}/storage"

find "${PANEL_DIR}/app"    -type f -exec chmod 640 {} \;
find "${PANEL_DIR}/app"    -type d -exec chmod 750 {} \;
find "${PANEL_DIR}/public" -type f -exec chmod 644 {} \;
find "${PANEL_DIR}/public" -type d -exec chmod 755 {} \;
find "${PANEL_DIR}/bin"    -type f -exec chmod 640 {} \;
find "${PANEL_DIR}/bin"    -type d -exec chmod 750 {} \;

chmod 750 "${PANEL_DIR}/storage"
chmod 770 "${PANEL_DIR}/storage/db"
chmod 770 "${PANEL_DIR}/storage/logs"
chmod 770 "${PANEL_DIR}/storage/cache"
chmod 770 "${PANEL_DIR}/storage/tmp"
chmod 770 "${PANEL_DIR}/storage/tmp/vhost"
chmod 770 "${PANEL_DIR}/storage/backups"
ok "Permissions set"

# Remove the legacy active-log gzip cron. Ubuntu's Nginx logrotate job safely
# renames and reopens *.log; gzipping a live inode can silently lose traffic.
if [[ -f /etc/cron.d/aidipanel ]]; then
    sed -i '\|find /var/log/nginx .* -exec gzip|d' /etc/cron.d/aidipanel
fi

# --------------------------------------------------------------------------
# 4. Write the password hash directly into SQLite (not read from a file at
#    runtime). This avoids permission issues between root and aidipanel.
# --------------------------------------------------------------------------
SQLITE_DB="${PANEL_DIR}/storage/db/aidipanel.sqlite"

# Detect an existing admin before migration. A missing users table is a clean install.
admin_exists="0"
if [[ -f "$SQLITE_DB" ]] \
   && [[ "$(sqlite3 "$SQLITE_DB" "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users';" 2>/dev/null || echo 0)" == "1" ]]; then
    admin_exists=$(sqlite3 "$SQLITE_DB" "SELECT COUNT(*) FROM users WHERE username='admin';" 2>/dev/null || echo 0)
fi

seed_hash=""
if [[ "$admin_exists" == "0" ]]; then
    PANEL_ADMIN_PASS=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 18)
    seed_hash=$(AIDIPANEL_PASS="$PANEL_ADMIN_PASS" "$PHP_BIN" -r 'echo password_hash(getenv("AIDIPANEL_PASS"), PASSWORD_BCRYPT, ["cost" => 12]);')
fi

# Initialize/migrate the schema as the dedicated panel identity.
sudo -u "${PANEL_USER}" env AIDIPANEL_ADMIN_HASH="$seed_hash" "$PHP_BIN" -r "
define('PANEL_DIR', '${PANEL_DIR}');
define('APP_ROOT', '${PANEL_DIR}/app');
define('PANEL_VERSION', '${PANEL_VERSION}');
// Trigger DB init
require '${PANEL_DIR}/app/Core/DB.php';
\Core\DB::instance();
echo 'DB initialized' . PHP_EOL;
" 2>/dev/null || die "Could not initialize or migrate the panel database."

if [[ -f "$SQLITE_DB" ]]; then
    if [[ "$admin_exists" == "0" ]]; then
        sqlite3 "$SQLITE_DB" "
        INSERT OR IGNORE INTO users (username, password_hash, role, active)
        VALUES ('admin', '${seed_hash}', 'admin', 1);
        "
        ok "Initial admin account created"
    else
        ok "Admin password unchanged"
    fi
    chown "${PANEL_USER}":"${PANEL_USER}" "$SQLITE_DB"
    chmod 660 "$SQLITE_DB"
else
    die "SQLite database was not created: ${SQLITE_DB}"
fi

# --------------------------------------------------------------------------
# 5. Save credentials
# --------------------------------------------------------------------------
CREDS="${PANEL_DIR}/credentials.conf"
if [[ -n "$PANEL_ADMIN_PASS" ]]; then
    touch "$CREDS"
    sed -i '/^PANEL_ADMIN_/d' "$CREDS"
    {
        credential_line PANEL_ADMIN_USER "admin"
        credential_line PANEL_ADMIN_PASSWORD "$PANEL_ADMIN_PASS"
    } >> "$CREDS"
    chmod 600 "$CREDS"
fi

# --------------------------------------------------------------------------
# 6. Sudoers wrapper for web-triggered CLI actions
# --------------------------------------------------------------------------
WRAPPER="/usr/local/sbin/aidipanel-web-run"
wrapper_staged=$(mktemp "$(dirname "$WRAPPER")/.aidipanel-web-run.XXXXXX")
cat > "$wrapper_staged" << 'WRAPPER_SCRIPT'
#!/usr/bin/env bash
set -Eeuo pipefail
export NO_COLOR=1
cmd="${1:-}"
case "$cmd" in
  system:cloud-metadata)
    [[ "$#" -eq 1 ]] || {
      echo "AidiPanel web command not allowed: system:cloud-metadata takes no arguments" >&2
      exit 126
    }
    ;;
  web-delivery:status)
    [[ "$#" -eq 1 ]] || {
      echo "AidiPanel web command not allowed: web-delivery:status takes no arguments" >&2
      exit 126
    }
    ;;
  security:status)
    if [[ "$#" -ne 3 || "${2:-}" != "--domain" \
          || ! "${3:-}" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
      echo "AidiPanel web command not allowed: security:status needs exactly --domain <domain>" >&2
      exit 126
    fi
    ;;
  cloudflare:realip)
    if [[ "$#" -ne 3 || "${2:-}" != "--action" || "${3:-}" != "status" ]]; then
      echo "AidiPanel web command not allowed: cloudflare:realip is status-only" >&2
      exit 126
    fi
    ;;
  security:ip-block)
    ipb_action=""; ipb_count=0; ipb_prev=""
    for ipb_arg in "$@"; do
      [[ "$ipb_prev" == "--action" ]] && { ipb_action="$ipb_arg"; ipb_count=$((ipb_count + 1)); }
      ipb_prev="$ipb_arg"
    done
    if [[ "$ipb_count" -ne 1 ]]; then
      echo "AidiPanel web command not allowed: security:ip-block needs exactly one --action" >&2
      exit 126
    fi
    case "$ipb_action" in
      status|get|set|disable) ;;
      *)
        echo "AidiPanel web command not allowed: security:ip-block action '${ipb_action}'" >&2
        exit 126
        ;;
    esac
    ;;
  security:cloudflare-only)
    cfo_action=""; cfo_count=0; cfo_prev=""
    for cfo_arg in "$@"; do
      [[ "$cfo_prev" == "--action" ]] && { cfo_action="$cfo_arg"; cfo_count=$((cfo_count + 1)); }
      cfo_prev="$cfo_arg"
    done
    if [[ "$cfo_count" -ne 1 ]]; then
      echo "AidiPanel web command not allowed: security:cloudflare-only needs exactly one --action" >&2
      exit 126
    fi
    case "$cfo_action" in
      status|enable|disable) ;;
      *)
        echo "AidiPanel web command not allowed: security:cloudflare-only action '${cfo_action}'" >&2
        exit 126
        ;;
    esac
    ;;
  panel:domain)
    if [[ "$#" -eq 1 ]]; then
      :
    elif [[ "$#" -eq 3 && "${2:-}" == "--action" && "${3:-}" =~ ^(status|clear)$ ]]; then
      :
    elif [[ "$#" -eq 3 && "${2:-}" == "--set" && "${3:-}" == *.* \
          && "${3:-}" =~ ^[a-z0-9][a-z0-9.-]*[a-z0-9]$ \
          && "${3:-}" != *..* ]]; then
      :
    else
      echo "AidiPanel web command not allowed: panel:domain needs status, clear, or --set <hostname>" >&2
      exit 126
    fi
    ;;
  panel:ssl)
    panel_ssl_count="$#"
    panel_ssl_args=("$@")
    if [[ "$panel_ssl_count" -gt 1 && "${panel_ssl_args[$((panel_ssl_count - 1))]}" == "--progress" ]]; then
      panel_ssl_count=$((panel_ssl_count - 1))
    fi
    if [[ "$panel_ssl_count" -eq 1 ]]; then
      :
    elif [[ "$panel_ssl_count" -eq 3 && "${panel_ssl_args[1]}" == "--action" && "${panel_ssl_args[2]}" == "status" ]]; then
      :
    elif [[ "$panel_ssl_count" -eq 3 && "${panel_ssl_args[1]}" == "--action" && "${panel_ssl_args[2]}" == "issue" ]]; then
      :
    elif [[ "$panel_ssl_count" -eq 5 && "${panel_ssl_args[1]}" == "--action" && "${panel_ssl_args[2]}" == "issue" \
          && "${panel_ssl_args[3]}" == "--email" && "${panel_ssl_args[4]}" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
      :
    else
      echo "AidiPanel web command not allowed: panel:ssl supports status or issue only" >&2
      exit 126
    fi
    ;;
  site:add|site:delete|site:list|vhost:save|\
  cache:page|cache:redis|cache:zone|cache:status|cache:purge|cache:enable|cache:disable|\
  cache:config|cache:redis-enable|cache:redis-disable|cache:redis-flush|cache:opcache-restart|\
  db:add|db:delete|db:list|db:users|db:user-add|db:user-edit|db:user-delete|db:pma-install|db:pma-credentials|db:backup|db:server-info|\
  php:list|php:version|php:restart|php:install|php:settings|\
  ssl:install|ssl:renew|ssl:status|ssl:import|\
  ssl:force-https|ssl:hsts|ssl:autorenew|ssl:check|ssl:use|\
  security:basic-auth|\
  service:status|service:start|service:stop|service:restart|service:reload|\
  cron:list|cron:add|cron:delete|cron:toggle|cron:wp|\
  files:list|files:read|files:write|files:mkdir|files:delete|files:download|\
  files:rename|files:copy|files:move|files:chmod|files:zip|files:unzip|\
  files:download-many|files:upload-chunk|files:upload-cancel|\
  backup:create|backup:list|backup:download|backup:delete|\
  remote-backup:status|remote-backup:test|remote-backup:save-destination|remote-backup:save-policy|remote-backup:run|\
  sftp:status|sftp:enable|sftp:disable|sftp:passwd|sftp:passwd-clear|sftp:key-add|sftp:key-delete|\
  system:info)
    ;;
  *)
    echo "AidiPanel web command not allowed: ${cmd:-<empty>}" >&2
    exit 126
    ;;
esac

# Defense-in-depth: reject dangerous argument content (the CLI validates too,
# but this wrapper is the privilege boundary).
for arg in "$@"; do
  case "$arg" in
    *';'*|*'`'*|*'$('*|*$'\n'*)
      echo "AidiPanel: rejected argument with unsafe characters" >&2
      exit 125
      ;;
  esac
done

# vhost:save may only read from the panel's managed temp dir.
if [[ "$cmd" == "vhost:save" ]]; then
  prev=""
  for arg in "$@"; do
    if [[ "$prev" == "--file" ]]; then
      case "$arg" in
        *..*)
          echo "AidiPanel: vhost:save --file must not contain '..'" >&2
          exit 125
          ;;
        /opt/aidipanel/storage/tmp/vhost/*) ;;
        *)
          echo "AidiPanel: vhost:save --file must be under /opt/aidipanel/storage/tmp/vhost/" >&2
          exit 125
          ;;
      esac
    fi
    prev="$arg"
  done
fi

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
chown root:root "$wrapper_staged"
chmod 750 "$wrapper_staged"
mv -f -- "$wrapper_staged" "$WRAPPER"

SUDOERS_FILE="/etc/sudoers.d/aidipanel"
sudoers_staged=$(mktemp "/etc/sudoers.d/.aidipanel.XXXXXX")
cat > "$sudoers_staged" << 'SUDOERS'
# AidiPanel - allow the web panel to run the controlled CLI wrapper as root
aidipanel ALL=(root) NOPASSWD: /usr/local/sbin/aidipanel-web-run *
SUDOERS
chown root:root "$sudoers_staged"
chmod 440 "$sudoers_staged"
if visudo -c -f "$sudoers_staged" >> /var/log/aidipanel-install.log 2>&1; then
  mv -f -- "$sudoers_staged" "$SUDOERS_FILE"
  ok "Sudoers configured"
else
  rm -f -- "$sudoers_staged"
  die "Sudoers validation failed; the previous rule was preserved."
fi

# --------------------------------------------------------------------------
# 7. Nginx test & reload
# --------------------------------------------------------------------------
# --------------------------------------------------------------------------
# Update the CLI binary too (not just the panel app)
# --------------------------------------------------------------------------
SCRIPT_PARENT="$(dirname "$SCRIPT_DIR")"
if [[ -f "${SCRIPT_PARENT}/aidipanel" ]]; then
  cli_staged="/usr/local/bin/.aidipanel.new.$$"
  install -o root -g root -m 0755 "${SCRIPT_PARENT}/aidipanel" "$cli_staged"
  if [[ -e /usr/local/bin/aidipanel ]]; then
    CLI_ROLLBACK=$(mktemp "/usr/local/bin/.aidipanel.rollback.XXXXXX")
    cp -a -- /usr/local/bin/aidipanel "$CLI_ROLLBACK"
  else
    CLI_WAS_ABSENT=1
  fi
  mv -f -- "$cli_staged" /usr/local/bin/aidipanel
  CLI_ACTIVATED=1
  ok "CLI updated: /usr/local/bin/aidipanel"
else
  warn "CLI binary not found at ${SCRIPT_PARENT}/aidipanel — skipping CLI update"
fi

nginx -t >> /var/log/aidipanel-install.log 2>&1 || die "Nginx config test failed."
if [[ -f /etc/cron.d/aidipanel ]]; then
    sed -i -E 's#^\* \* \* \* \* www-data /usr/bin/php /opt/aidipanel/bin/collect-metrics\.php#* * * * * aidipanel /usr/bin/php /opt/aidipanel/bin/collect-metrics.php#' /etc/cron.d/aidipanel
fi
systemctl restart aidipanel-fpm
systemctl reload nginx
PANEL_FPM_WAS_ACTIVE=0
ok "Nginx reloaded"

# --------------------------------------------------------------------------
# 8. Summary
# --------------------------------------------------------------------------
SERVER_IP=$(ip route get 8.8.8.8 2>/dev/null \
    | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1);exit}}' \
    || echo "<server-ip>")
PANEL_PORT=$(grep '^PANEL_PORT=' "${PANEL_DIR}/config/panel.conf" 2>/dev/null \
    | cut -d= -f2 || echo "8443")
PANEL_HOSTNAME=$(grep '^PANEL_HOSTNAME=' "${PANEL_DIR}/config/panel.conf" 2>/dev/null \
    | tail -1 | cut -d= -f2- || true)

echo ""
echo -e "  ${BOLD}${GREEN}AidiPanel v${PANEL_VERSION} deployed!${RESET}"
echo ""
if [[ -n "$PANEL_HOSTNAME" ]]; then
  echo -e "  Panel URL  : https://${PANEL_HOSTNAME}"
  echo -e "  Recovery   : https://${SERVER_IP}:${PANEL_PORT}"
else
  echo -e "  Panel URL  : https://${SERVER_IP}:${PANEL_PORT}"
fi
echo -e "  Login      : admin"
if [[ -n "$PANEL_ADMIN_PASS" ]]; then
  echo -e "  ${BOLD}${RED}Password   : ${PANEL_ADMIN_PASS}${RESET}  ← SAVE THIS NOW"
else
  echo -e "  Password   : unchanged"
fi
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
