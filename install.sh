#!/usr/bin/env bash
# =============================================================================
#  AidiPanel Installer v1.1.0
#  Stack: Nginx + FastCGI Cache + PHP-FPM (multi-version) + MySQL/MariaDB + Redis
#  Supported OS: Debian 11/12, Ubuntu 22.04/24.04 (x86_64 & arm64)
#  Usage  : bash install-aidipanel.sh [OPTIONS]
#
#  Options:
#    --port PORT           Panel HTTPS port (default: 8443)
#    --db-engine ENGINE    Database engine: mysql80 | mysql84 | mariadb1011 |
#                          mariadb114 | mariadb118  (default: mariadb1011)
#    --db-root-pass PASS   Set DB root password non-interactively
#    --no-redis            Skip Redis installation
#    --dry-run             Simulate install without making changes
#    --help                Show this help
#
#  Author : AidiPanel Team
# =============================================================================

set -Eeuo pipefail
IFS=$'\n\t'

# ---------------------------------------------------------------------------
# 0. GLOBAL CONSTANTS & DEFAULTS
# ---------------------------------------------------------------------------
readonly PANEL_NAME="AidiPanel"
readonly PANEL_VERSION="1.1.0"
readonly PANEL_USER="aidipanel"
readonly PANEL_DIR="/opt/aidipanel"
readonly PANEL_LOG="/var/log/aidipanel-install.log"
readonly PANEL_LOCK="/var/run/aidipanel-install.lock"
readonly NGINX_CACHE_DIR="/var/cache/nginx/fastcgi"
readonly NGINX_CACHE_ZONE="aidipanel_fcgi"
readonly NGINX_CACHE_SIZE="10g"
readonly NGINX_CACHE_KEYS_ZONE="200m"
readonly SITES_DIR="/var/www"
readonly DB_NAME="aidipanel"
readonly PHP_DEFAULT_VERSION="8.3"
# PHP versions that will be installed
readonly PHP_VERSIONS=("8.1" "8.2" "8.3")

# Supported DB engines: label → description
# mysql80 | mysql84 | mariadb1011 | mariadb114 | mariadb118
declare -A DB_ENGINE_LABELS=(
  [mysql80]="MySQL 8.0"
  [mysql84]="MySQL 8.4 (LTS)"
  [mariadb1011]="MariaDB 10.11 (LTS) — recommended for WordPress"
  [mariadb114]="MariaDB 11.4 (LTS)"
  [mariadb118]="MariaDB 11.8"
)

PANEL_PORT=8443
DB_ENGINE="mariadb1011"   # default: MariaDB 10.11 (best for WordPress)
INSTALL_REDIS=true
DRY_RUN=false
DB_ROOT_PASS=""
SWAP_SIZE_MB=2048          # 2GB swap — created if no swap exists
DEBIAN_FRONTEND=noninteractive
export DEBIAN_FRONTEND

# Colors
RED='\033[0;31m'; YELLOW='\033[1;33m'; GREEN='\033[0;32m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

# ---------------------------------------------------------------------------
# 1. LOGGING HELPERS
# ---------------------------------------------------------------------------
_ts()   { date '+%Y-%m-%d %H:%M:%S'; }
log()   { echo -e "${CYAN}[$(_ts)] [INFO]${RESET}  $*" | tee -a "$PANEL_LOG"; }
warn()  { echo -e "${YELLOW}[$(_ts)] [WARN]${RESET}  $*" | tee -a "$PANEL_LOG"; }
ok()    { echo -e "${GREEN}[$(_ts)] [OK]${RESET}    $*" | tee -a "$PANEL_LOG"; }
die()   {
  echo -e "${RED}[$(_ts)] [ERROR]${RESET} $*" | tee -a "$PANEL_LOG" >&2
  exit 1
}

run() {
  # Wrapper: log the command, skip if --dry-run
  log "RUN: $*"
  if [[ "$DRY_RUN" == "true" ]]; then
    warn "[dry-run] skipped: $*"
    return 0
  fi
  "$@" >> "$PANEL_LOG" 2>&1
}

# ---------------------------------------------------------------------------
# 2. TRAP — cleanup lockfile & print failure context on any error
# ---------------------------------------------------------------------------
_cleanup() {
  local exit_code=$?
  rm -f "$PANEL_LOCK"
  if [[ $exit_code -ne 0 ]]; then
    echo -e "\n${RED}══════════════════════════════════════════${RESET}"
    echo -e "${RED} ${PANEL_NAME} installation FAILED (exit $exit_code)${RESET}"
    echo -e "${RED} Check full log: ${PANEL_LOG}${RESET}"
    echo -e "${RED}══════════════════════════════════════════${RESET}\n"
  fi
}
trap _cleanup EXIT

# ---------------------------------------------------------------------------
# 3. ARGUMENT PARSING
# ---------------------------------------------------------------------------
_parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --port)
        shift
        [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1 && $1 <= 65535 )) \
          || die "--port must be a valid port number (1-65535)"
        PANEL_PORT="$1"
        ;;
      --db-engine)
        shift
        [[ -n "${DB_ENGINE_LABELS[$1]+_}" ]] \
          || die "--db-engine must be one of: mysql80 | mysql84 | mariadb1011 | mariadb114 | mariadb118"
        DB_ENGINE="$1"
        ;;
      --db-root-pass)
        shift
        DB_ROOT_PASS="$1"
        ;;
      # Keep legacy alias for backwards compat
      --mysql-root-pass)
        shift
        DB_ROOT_PASS="$1"
        warn "--mysql-root-pass is deprecated; use --db-root-pass"
        ;;
      --no-redis)
        INSTALL_REDIS=false
        ;;
      --dry-run)
        DRY_RUN=true
        warn "Dry-run mode enabled — no changes will be made to the system."
        ;;
      --help|-h)
        echo "Usage: bash $0 [OPTIONS]"
        echo "  --port PORT           Panel HTTPS port (default: 8443)"
        echo "  --db-engine ENGINE    Database engine (default: mariadb1011)"
        echo "                        Options: mysql80 | mysql84 | mariadb1011 | mariadb114 | mariadb118"
        echo "  --db-root-pass PASS   Set DB root password non-interactively"
        echo "  --no-redis            Skip Redis installation"
        echo "  --dry-run             Simulate install without making changes"
        exit 0
        ;;
      *)
        die "Unknown argument: '$1'. Run with --help for usage."
        ;;
    esac
    shift
  done
}

# ---------------------------------------------------------------------------
# 4. PRE-FLIGHT CHECKS
# ---------------------------------------------------------------------------
_check_root() {
  [[ "$(id -u)" -eq 0 ]] || die "This installer must be run as root (sudo bash $0)."
}

_check_lock() {
  if [[ -f "$PANEL_LOCK" ]]; then
    die "Another installation is already running (lock: $PANEL_LOCK). " \
        "If this is stale, remove it: rm $PANEL_LOCK"
  fi
  touch "$PANEL_LOCK"
}

_check_already_installed() {
  if [[ -d "$PANEL_DIR" ]] && systemctl is-active --quiet aidipanel 2>/dev/null; then
    die "${PANEL_NAME} is already installed and running. " \
        "To reinstall, run the uninstaller first."
  fi
}

_detect_os() {
  # Sets OS_ID, OS_VERSION_ID, OS_CODENAME
  if [[ ! -f /etc/os-release ]]; then
    die "Cannot detect OS — /etc/os-release not found."
  fi
  # shellcheck source=/dev/null
  source /etc/os-release
  OS_ID="${ID:-}"
  OS_VERSION_ID="${VERSION_ID:-}"
  OS_CODENAME="${VERSION_CODENAME:-}"

  case "$OS_ID" in
    debian)
      case "$OS_VERSION_ID" in
        11) OS_CODENAME="bullseye" ;;
        12) OS_CODENAME="bookworm" ;;
        *) die "Unsupported Debian version: $OS_VERSION_ID. Supported: 11, 12." ;;
      esac
      ;;
    ubuntu)
      case "$OS_VERSION_ID" in
        22.04) OS_CODENAME="jammy" ;;
        24.04) OS_CODENAME="noble" ;;
        *) die "Unsupported Ubuntu version: $OS_VERSION_ID. Supported: 22.04, 24.04." ;;
      esac
      ;;
    *)
      die "Unsupported OS: '$OS_ID'. Supported: Debian 11/12, Ubuntu 22.04/24.04."
      ;;
  esac

  ARCH="$(uname -m)"
  [[ "$ARCH" == "x86_64" || "$ARCH" == "aarch64" ]] \
    || die "Unsupported architecture: $ARCH. Supported: x86_64, aarch64."

  ok "Detected OS: ${OS_ID} ${OS_VERSION_ID} (${OS_CODENAME}) on ${ARCH}"
}

_check_resources() {
  local mem_kb cpu_cores disk_kb
  mem_kb=$(grep MemTotal /proc/meminfo | awk '{print $2}')
  cpu_cores=$(nproc)
  disk_kb=$(df / --output=avail -k | tail -1)

  local mem_gb disk_gb
  mem_gb=$(( mem_kb / 1024 / 1024 ))
  disk_gb=$(( disk_kb / 1024 / 1024 ))

  log "Resources: ${cpu_cores} CPU cores, ${mem_gb}GB RAM, ${disk_gb}GB free disk"

  (( mem_kb >= 1536000 )) \
    || warn "Minimum 2GB RAM recommended. Current: ~${mem_gb}GB. Continuing anyway."
  (( disk_kb >= 5120000 )) \
    || die "Insufficient disk space. Minimum 5GB free required. Current: ~${disk_gb}GB."
}

_check_internet() {
  log "Checking internet connectivity..."
  if ! curl -fsSL --max-time 10 https://deb.nodesource.com/ >/dev/null 2>&1; then
    if ! curl -fsSL --max-time 10 https://packages.sury.org/ >/dev/null 2>&1; then
      die "No internet connection detected. AidiPanel requires internet to download packages."
    fi
  fi
  ok "Internet connectivity: OK"
}

_check_port_free() {
  if ss -tlnp 2>/dev/null | grep -q ":${PANEL_PORT} "; then
    die "Port ${PANEL_PORT} is already in use. Use --port to choose another port."
  fi
  ok "Port ${PANEL_PORT}: available"
}

_check_hostname_resolves() {
  local hostname
  hostname=$(hostname -f 2>/dev/null || hostname)

  log "Checking hostname: ${hostname}"

  # Hostname must not be localhost / empty
  if [[ -z "$hostname" || "$hostname" == "localhost" || "$hostname" == "localhost.localdomain" ]]; then
    warn "Hostname is set to '${hostname}' — this may cause issues with SSL and email."
    warn "Consider setting a proper FQDN: hostnamectl set-hostname your-server.domain.com"
    # Not fatal — just warn
    return 0
  fi

  # Try to resolve hostname to an IP
  local resolved_ip
  resolved_ip=$(getent hosts "$hostname" 2>/dev/null | awk '{print $1}' | head -1)

  if [[ -z "$resolved_ip" ]]; then
    warn "Hostname '${hostname}' does not resolve to an IP address."
    warn "This is OK for now — make sure DNS is configured before installing SSL."
  else
    ok "Hostname '${hostname}' resolves to: ${resolved_ip}"
  fi
}

# ---------------------------------------------------------------------------
# 5. BANNER
# ---------------------------------------------------------------------------
_banner() {
  echo -e "\n${BOLD}${CYAN}"
  echo "  ╔═══════════════════════════════════════════╗"
  echo "  ║                                           ║"
  echo "  ║        AidiPanel Installer v${PANEL_VERSION}         ║"
  echo "  ║   Nginx + FastCGI Cache + PHP-FPM + Redis ║"
  echo "  ║                                           ║"
  echo "  ╚═══════════════════════════════════════════╝"
  echo -e "${RESET}\n"
  log "Starting ${PANEL_NAME} v${PANEL_VERSION} installation"
  log "DB engine    : ${DB_ENGINE_LABELS[$DB_ENGINE]}"
  log "Panel port   : ${PANEL_PORT}"
  log "Redis        : ${INSTALL_REDIS}"
  log "Log file     : ${PANEL_LOG}"
}

# ---------------------------------------------------------------------------
# 6. APT HELPERS
# ---------------------------------------------------------------------------
_apt_update() {
  log "Updating apt package lists..."
  run apt-get update -qq
}

_apt_install() {
  # Usage: _apt_install pkg1 pkg2 ...
  log "Installing packages: $*"
  run apt-get install -y -qq --no-install-recommends "$@"
}

_pkg_installed() {
  dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"
}

# ---------------------------------------------------------------------------
# 7. BASE SYSTEM PACKAGES
# ---------------------------------------------------------------------------
_install_base_packages() {
  log "Installing base system packages..."
  _apt_install \
    curl wget gnupg2 lsb-release ca-certificates apt-transport-https \
    software-properties-common unzip zip tar \
    git cron ufw fail2ban \
    openssl certbot \
    sqlite3 \
    net-tools jq
  ok "Base packages installed"
}

# ---------------------------------------------------------------------------
# 7b. SWAP FILE — create 2GB swap if no swap exists (critical for small VPS)
# ---------------------------------------------------------------------------
_create_swap() {
  log "Checking swap space..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping swap creation"; return 0; }

  local swap_total
  swap_total=$(free -m | awk '/^Swap:/ {print $2}')

  if (( swap_total >= 512 )); then
    ok "Swap already exists: ${swap_total}MB — skipping"
    return 0
  fi

  local swap_file="/swapfile"
  local swap_mb=$SWAP_SIZE_MB

  # Adjust swap size based on available RAM:
  # RAM < 1GB  → 2GB swap
  # RAM 1-4GB  → 2GB swap
  # RAM > 4GB  → 1GB swap (enough)
  local mem_mb
  mem_mb=$(free -m | awk '/^Mem:/ {print $2}')
  (( mem_mb > 4096 )) && swap_mb=1024

  log "Creating ${swap_mb}MB swap file at ${swap_file}..."

  # Use fallocate (fast), fallback to dd (compatible)
  if command -v fallocate &>/dev/null; then
    fallocate -l "${swap_mb}M" "$swap_file" >> "$PANEL_LOG" 2>&1 \
      || dd if=/dev/zero of="$swap_file" bs=1M count="$swap_mb" >> "$PANEL_LOG" 2>&1
  else
    dd if=/dev/zero of="$swap_file" bs=1M count="$swap_mb" >> "$PANEL_LOG" 2>&1
  fi

  chmod 600 "$swap_file"
  mkswap "$swap_file"  >> "$PANEL_LOG" 2>&1
  swapon "$swap_file"  >> "$PANEL_LOG" 2>&1

  # Persist across reboots
  if ! grep -q "$swap_file" /etc/fstab; then
    echo "${swap_file} none swap sw 0 0" >> /etc/fstab
  fi

  # Tune swappiness for server use (default 60 is for desktop)
  local sysctl_conf="/etc/sysctl.d/99-aidipanel.conf"
  cat > "$sysctl_conf" <<'SYSCTL'
# AidiPanel — kernel tuning
vm.swappiness = 10
vm.vfs_cache_pressure = 50
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
SYSCTL
  sysctl -p "$sysctl_conf" >> "$PANEL_LOG" 2>&1

  local actual_swap
  actual_swap=$(free -m | awk '/^Swap:/ {print $2}')
  ok "Swap created: ${actual_swap}MB (swappiness=10)"
}

# ---------------------------------------------------------------------------
# 8. NGINX
# ---------------------------------------------------------------------------
_add_nginx_repo() {
  if [[ -f /etc/apt/sources.list.d/nginx.list ]]; then
    log "Nginx apt repo already configured — skipping"
    return 0
  fi
  log "Adding official Nginx apt repository..."
  run curl -fsSL https://nginx.org/keys/nginx_signing.key \
    | gpg --dearmor -o /etc/apt/trusted.gpg.d/nginx.gpg
  echo "deb [arch=${ARCH/x86_64/amd64}${ARCH/aarch64/arm64} signed-by=/etc/apt/trusted.gpg.d/nginx.gpg] \
http://nginx.org/packages/${OS_ID} ${OS_CODENAME} nginx" \
    > /etc/apt/sources.list.d/nginx.list
  _apt_update
  ok "Nginx repo added"
}

# Fix arch string for apt
_apt_arch() {
  [[ "$ARCH" == "x86_64" ]] && echo "amd64" || echo "arm64"
}

_install_nginx() {
  if _pkg_installed nginx; then
    log "Nginx already installed — skipping"
  else
    log "Installing Nginx..."
    if [[ -f /etc/apt/sources.list.d/nginx.list ]]; then
      _apt_install nginx
    else
      # Fallback to distro's nginx if repo addition was skipped (dry-run)
      _apt_install nginx
    fi
    ok "Nginx installed: $(nginx -v 2>&1 || true)"
  fi
}

_configure_nginx_main() {
  log "Writing Nginx main configuration..."
  local apt_arch; apt_arch=$(_apt_arch)

  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping nginx config write"; return 0; }

  # Backup existing config
  if [[ -f /etc/nginx/nginx.conf ]]; then
    cp /etc/nginx/nginx.conf "/etc/nginx/nginx.conf.bak.$(date +%s)"
  fi

  cat > /etc/nginx/nginx.conf <<'NGINX_MAIN'
user www-data;
worker_processes auto;
worker_rlimit_nofile 65535;
pid /run/nginx.pid;
include /etc/nginx/modules-enabled/*.conf;

events {
    worker_connections 4096;
    multi_accept on;
    use epoll;
}

http {
    # --- Basic Settings ---
    sendfile            on;
    tcp_nopush          on;
    tcp_nodelay         on;
    keepalive_timeout   65;
    types_hash_max_size 2048;
    server_tokens       off;
    client_max_body_size 256m;

    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    # --- Logging ---
    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent" cache:$upstream_cache_status';
    access_log /var/log/nginx/access.log main buffer=16k;
    error_log  /var/log/nginx/error.log  warn;

    # --- Gzip ---
    gzip              on;
    gzip_vary         on;
    gzip_proxied      any;
    gzip_comp_level   6;
    gzip_buffers      16 8k;
    gzip_http_version 1.1;
    gzip_types text/plain text/css text/xml application/json application/javascript
               application/xml+rss application/atom+xml image/svg+xml;

    # --- FastCGI Cache Zone ---
    fastcgi_cache_path /var/cache/nginx/fastcgi
        levels=1:2
        keys_zone=aidipanel_fcgi:200m
        max_size=10g
        inactive=60m
        use_temp_path=off;
    fastcgi_cache_key "$scheme$request_method$host$request_uri";
    fastcgi_cache_use_stale error timeout invalid_header updating
                            http_500 http_503;
    fastcgi_cache_lock        on;
    fastcgi_cache_lock_timeout 5s;
    fastcgi_ignore_headers    Cache-Control Expires Set-Cookie;

    # --- Rate Limiting ---
    limit_req_zone $binary_remote_addr zone=aidipanel_req:10m rate=30r/m;

    # --- Security Headers (applied globally, sites may override) ---
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # --- Virtual Hosts ---
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
NGINX_MAIN

  # Ensure sites directories exist
  mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled

  # Remove default site if present
  rm -f /etc/nginx/conf.d/default.conf
  rm -f /etc/nginx/sites-enabled/default

  ok "Nginx main config written"
}

_create_fastcgi_cache_dir() {
  log "Creating FastCGI cache directory..."
  [[ "$DRY_RUN" == "true" ]] && return 0
  install -d -o www-data -g www-data -m 0750 "$NGINX_CACHE_DIR"
  ok "FastCGI cache dir: $NGINX_CACHE_DIR"
}

_create_fastcgi_params_snippet() {
  log "Writing FastCGI params snippet..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  # Create a reusable FastCGI cache exclude snippet
  cat > /etc/nginx/snippets/fastcgi-cache.conf <<'FCGI_SNIP'
# AidiPanel — FastCGI Cache exclusion rules
# Include this snippet inside each server{} block that uses FastCGI cache.

set $skip_cache 0;

# Do not cache POST requests
if ($request_method = POST)          { set $skip_cache 1; }

# Do not cache URIs with query strings
if ($query_string != "")             { set $skip_cache 1; }

# Do not cache the following URIs (WordPress admin, login, cart, checkout)
if ($request_uri ~* "(/wp-admin/|/wp-login.php|/cart|/checkout|/my-account|/xmlrpc.php)") {
    set $skip_cache 1;
}

# Do not cache for logged-in WordPress users or WooCommerce sessions
if ($http_cookie ~* "(wordpress_logged_in|woocommerce_items_in_cart|woocommerce_session|comment_author)") {
    set $skip_cache 1;
}

# Do not cache for Laravel / general session cookies
if ($http_cookie ~* "(laravel_session|PHPSESSID|auth_token)") {
    set $skip_cache 1;
}
FCGI_SNIP

  ok "FastCGI cache snippet written: /etc/nginx/snippets/fastcgi-cache.conf"
}

# ---------------------------------------------------------------------------
# 9. PHP-FPM (multi-version via ondrej/php PPA or sury.org)
# ---------------------------------------------------------------------------
_add_php_repo() {
  if [[ -f /etc/apt/sources.list.d/php.list ]]; then
    log "PHP apt repo already configured — skipping"
    return 0
  fi
  log "Adding PHP repository (ondrej/sury)..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping PHP repo add"; return 0; }

  if [[ "$OS_ID" == "ubuntu" ]]; then
    run add-apt-repository -y ppa:ondrej/php
  else
    # Debian — use deb.sury.org
    run curl -fsSL https://packages.sury.org/php/apt.gpg \
      | gpg --dearmor -o /etc/apt/trusted.gpg.d/php-sury.gpg
    echo "deb [signed-by=/etc/apt/trusted.gpg.d/php-sury.gpg] \
https://packages.sury.org/php/ ${OS_CODENAME} main" \
      > /etc/apt/sources.list.d/php.list
  fi
  _apt_update
  ok "PHP repository added"
}

_install_php_version() {
  local ver="$1"
  log "Installing PHP ${ver} and extensions..."
  _apt_install \
    "php${ver}-fpm" \
    "php${ver}-cli" \
    "php${ver}-common" \
    "php${ver}-mysql" \
    "php${ver}-redis" \
    "php${ver}-xml" \
    "php${ver}-mbstring" \
    "php${ver}-curl" \
    "php${ver}-zip" \
    "php${ver}-gd" \
    "php${ver}-intl" \
    "php${ver}-bcmath" \
    "php${ver}-soap" \
    "php${ver}-imagick" \
    "php${ver}-opcache"
  ok "PHP ${ver} installed"
}

_configure_php_fpm() {
  local ver="$1"
  local pool_conf="/etc/php/${ver}/fpm/pool.d/www.conf"
  [[ -f "$pool_conf" ]] || { warn "PHP ${ver} pool config not found; skipping FPM tune"; return 0; }
  [[ "$DRY_RUN" == "true" ]] && return 0

  log "Tuning PHP-FPM ${ver} pool..."
  # Backup
  cp "$pool_conf" "${pool_conf}.bak.$(date +%s)"

  # Switch to Unix socket (faster than TCP) and tune workers
  sed -i \
    -e "s|^listen = .*|listen = /run/php/php${ver}-fpm.sock|" \
    -e 's|^;listen.owner = .*|listen.owner = www-data|' \
    -e 's|^;listen.group = .*|listen.group = www-data|' \
    -e 's|^;listen.mode = .*|listen.mode = 0660|' \
    -e 's|^pm = .*|pm = dynamic|' \
    -e 's|^pm.max_children = .*|pm.max_children = 20|' \
    -e 's|^pm.start_servers = .*|pm.start_servers = 4|' \
    -e 's|^pm.min_spare_servers = .*|pm.min_spare_servers = 2|' \
    -e 's|^pm.max_spare_servers = .*|pm.max_spare_servers = 6|' \
    -e 's|^;pm.max_requests = .*|pm.max_requests = 500|' \
    "$pool_conf"

  # PHP ini tuning
  local ini_dir="/etc/php/${ver}/fpm/conf.d"
  mkdir -p "$ini_dir"
  cat > "${ini_dir}/99-aidipanel.ini" <<PHPINI
; AidiPanel PHP-FPM tuning (PHP ${ver})
memory_limit         = 256M
max_execution_time   = 300
max_input_time       = 300
post_max_size        = 256M
upload_max_filesize  = 256M
date.timezone        = Asia/Jakarta
opcache.enable       = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
PHPINI

  run systemctl restart "php${ver}-fpm" || warn "Could not restart php${ver}-fpm (may not be started yet)"
  ok "PHP-FPM ${ver} configured"
}

_install_all_php_versions() {
  for ver in "${PHP_VERSIONS[@]}"; do
    _install_php_version "$ver"
    _configure_php_fpm "$ver"
  done
  ok "All PHP versions installed: ${PHP_VERSIONS[*]}"
}

# ---------------------------------------------------------------------------
# 10. DATABASE — MySQL 8.0 / 8.4  OR  MariaDB 10.11 / 11.4 / 11.8
# ---------------------------------------------------------------------------

# Resolve which apt package name to use based on DB_ENGINE
_db_package_name() {
  case "$DB_ENGINE" in
    mysql80|mysql84)   echo "mysql-server" ;;
    mariadb*)          echo "mariadb-server" ;;
  esac
}

_db_service_name() {
  case "$DB_ENGINE" in
    mysql80|mysql84)   echo "mysql" ;;
    mariadb*)          echo "mariadb" ;;
  esac
}

_add_mysql_repo() {
  # Only needed for MySQL — MariaDB uses its own repo
  [[ "$DB_ENGINE" == mysql80 || "$DB_ENGINE" == mysql84 ]] || return 0

  if [[ -f /etc/apt/sources.list.d/mysql.list ]]; then
    log "MySQL apt repo already configured — skipping"
    return 0
  fi

  local mysql_version
  [[ "$DB_ENGINE" == "mysql80" ]] && mysql_version="8.0" || mysql_version="8.4"

  log "Adding MySQL ${mysql_version} apt repository..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local apt_arch; apt_arch=$(_apt_arch)
  run curl -fsSL "https://repo.mysql.com/RPM-GPG-KEY-mysql-2023" \
    | gpg --dearmor -o /etc/apt/trusted.gpg.d/mysql.gpg

  echo "deb [arch=${apt_arch} signed-by=/etc/apt/trusted.gpg.d/mysql.gpg] \
http://repo.mysql.com/apt/${OS_ID} ${OS_CODENAME} mysql-${mysql_version}" \
    > /etc/apt/sources.list.d/mysql.list

  _apt_update
  ok "MySQL ${mysql_version} repo added"
}

_add_mariadb_repo() {
  [[ "$DB_ENGINE" == mariadb* ]] || return 0

  if [[ -f /etc/apt/sources.list.d/mariadb.list ]]; then
    log "MariaDB apt repo already configured — skipping"
    return 0
  fi

  local mariadb_version
  case "$DB_ENGINE" in
    mariadb1011) mariadb_version="10.11" ;;
    mariadb114)  mariadb_version="11.4"  ;;
    mariadb118)  mariadb_version="11.8"  ;;
  esac

  log "Adding MariaDB ${mariadb_version} apt repository..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  run curl -fsSL "https://downloads.mariadb.com/MariaDB/mariadb_repo_setup" \
    | bash -s -- --mariadb-server-version="mariadb-${mariadb_version}" >> "$PANEL_LOG" 2>&1

  _apt_update
  ok "MariaDB ${mariadb_version} repo added"
}

_install_database() {
  local pkg; pkg=$(_db_package_name)
  local svc; svc=$(_db_service_name)

  if _pkg_installed "$pkg"; then
    log "${DB_ENGINE_LABELS[$DB_ENGINE]} already installed — skipping"
    return 0
  fi

  log "Installing ${DB_ENGINE_LABELS[$DB_ENGINE]}..."

  # Pre-seed root password for MySQL (MariaDB uses unix_socket by default)
  if [[ "$DB_ENGINE" == mysql* && -n "$DB_ROOT_PASS" ]]; then
    debconf-set-selections <<< "mysql-server mysql-server/root_password password ${DB_ROOT_PASS}"
    debconf-set-selections <<< "mysql-server mysql-server/root_password_again password ${DB_ROOT_PASS}"
  fi

  _apt_install "$pkg"
  run systemctl enable --now "$svc"
  ok "${DB_ENGINE_LABELS[$DB_ENGINE]} installed"
}

_configure_database() {
  log "Securing database installation..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local svc; svc=$(_db_service_name)

  # Generate root password if not provided
  if [[ -z "$DB_ROOT_PASS" ]]; then
    DB_ROOT_PASS=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)
    log "Generated DB root password (saved to ${PANEL_DIR}/credentials.conf)"
  fi

  # MariaDB: use unix_socket auth (no password needed for root initially)
  # MySQL 8.x: needs explicit password set
  local mysql_cmd
  if [[ "$DB_ENGINE" == mariadb* ]]; then
    mysql_cmd="mariadb -u root"
  else
    mysql_cmd="mysql -u root"
  fi

  # Secure installation queries
  $mysql_cmd <<MYSQL_SECURE 2>>"$PANEL_LOG"
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
MYSQL_SECURE

  # From here on, use password auth
  local db_cli_auth
  if [[ "$DB_ENGINE" == mariadb* ]]; then
    db_cli_auth="mariadb -u root -p${DB_ROOT_PASS}"
  else
    db_cli_auth="mysql -u root -p${DB_ROOT_PASS}"
  fi

  # Create AidiPanel database and user
  local PANEL_DB_PASS
  PANEL_DB_PASS=$(openssl rand -base64 20 | tr -dc 'A-Za-z0-9' | head -c 20)

  $db_cli_auth <<PANEL_DB 2>>"$PANEL_LOG"
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${PANEL_USER}'@'localhost' IDENTIFIED BY '${PANEL_DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${PANEL_USER}'@'localhost';
FLUSH PRIVILEGES;
PANEL_DB

  # Save credentials
  mkdir -p "$PANEL_DIR"
  chmod 700 "$PANEL_DIR"
  cat > "${PANEL_DIR}/credentials.conf" <<CREDS
# AidiPanel Credentials — KEEP THIS FILE SECURE
# Generated: $(date)
DB_ENGINE=${DB_ENGINE}
DB_ENGINE_LABEL=${DB_ENGINE_LABELS[$DB_ENGINE]}
DB_ROOT_PASSWORD=${DB_ROOT_PASS}
PANEL_DB_NAME=${DB_NAME}
PANEL_DB_USER=${PANEL_USER}
PANEL_DB_PASSWORD=${PANEL_DB_PASS}
MYSQL_ROOT_PASSWORD=${DB_ROOT_PASS}
CREDS
  chmod 600 "${PANEL_DIR}/credentials.conf"

  # Write a DB client config for panel user
  cat > "${PANEL_DIR}/.my.cnf" <<MYCNF
[client]
user=${PANEL_USER}
password=${PANEL_DB_PASS}
database=${DB_NAME}
MYCNF
  chmod 600 "${PANEL_DIR}/.my.cnf"

  ok "${DB_ENGINE_LABELS[$DB_ENGINE]} secured and AidiPanel database created"
}

# ---------------------------------------------------------------------------
# 11. REDIS
# ---------------------------------------------------------------------------
_install_redis() {
  if [[ "$INSTALL_REDIS" == "false" ]]; then
    log "Skipping Redis installation (--no-redis)"
    return 0
  fi
  if _pkg_installed redis-server; then
    log "Redis already installed — skipping"
    return 0
  fi
  log "Installing Redis..."
  _apt_install redis-server
  run systemctl enable --now redis-server
  ok "Redis installed"
}

_configure_redis() {
  [[ "$INSTALL_REDIS" == "false" ]] && return 0
  [[ "$DRY_RUN" == "true" ]] && return 0
  log "Configuring Redis for AidiPanel..."

  local redis_conf="/etc/redis/redis.conf"
  [[ -f "$redis_conf" ]] || { warn "Redis config not found; skipping"; return 0; }

  cp "$redis_conf" "${redis_conf}.bak.$(date +%s)"

  # Bind to localhost only, enable maxmemory policy suitable for caching
  sed -i \
    -e 's/^bind .*/bind 127.0.0.1 ::1/' \
    -e 's/^# maxmemory .*/maxmemory 256mb/' \
    -e 's/^# maxmemory-policy .*/maxmemory-policy allkeys-lru/' \
    -e 's/^supervised no/supervised systemd/' \
    "$redis_conf"

  run systemctl restart redis-server
  ok "Redis configured (maxmemory: 256mb, policy: allkeys-lru, bind: 127.0.0.1)"
}

# ---------------------------------------------------------------------------
# 12. UFW FIREWALL
# ---------------------------------------------------------------------------
_configure_firewall() {
  log "Configuring UFW firewall..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  # Reset to defaults but don't disable
  ufw --force reset > /dev/null 2>&1 || true

  ufw default deny incoming
  ufw default allow outgoing
  ufw allow ssh          comment 'SSH'
  ufw allow 80/tcp       comment 'HTTP'
  ufw allow 443/tcp      comment 'HTTPS'
  ufw allow "${PANEL_PORT}/tcp" comment 'AidiPanel'

  ufw --force enable
  ok "UFW enabled — allowed ports: 22, 80, 443, ${PANEL_PORT}"
}

# ---------------------------------------------------------------------------
# 13. CERTBOT / SSL
# ---------------------------------------------------------------------------
_install_certbot() {
  log "Installing Certbot (Let's Encrypt)..."
  if _pkg_installed certbot; then
    log "Certbot already installed — skipping"
    return 0
  fi
  _apt_install certbot python3-certbot-nginx
  ok "Certbot installed"
}

# ---------------------------------------------------------------------------
# 14. PROFTPD (SFTP/FTP)
# ---------------------------------------------------------------------------
_install_proftpd() {
  log "Installing ProFTPD..."
  if _pkg_installed proftpd-basic; then
    log "ProFTPD already installed — skipping"
    return 0
  fi
  _apt_install proftpd-basic
  [[ "$DRY_RUN" == "true" ]] && return 0

  # Minimal secure config — only SFTP (no plain FTP)
  cat > /etc/proftpd/conf.d/aidipanel.conf <<'PROFTPD_CONF'
# AidiPanel ProFTPD — SFTP only
LoadModule mod_sftp.c
<VirtualHost 0.0.0.0>
    SFTPEngine on
    Port 2022
    SFTPLog /var/log/proftpd/sftp.log
    SFTPHostKey /etc/proftpd/ssh_host_rsa_key
    SFTPCompression delayed
    AuthOrder mod_auth_pam.c mod_auth_unix.c
    TransferLog /var/log/proftpd/xferlog
    SystemLog /var/log/proftpd/proftpd.log
</VirtualHost>
PROFTPD_CONF

  # Generate SFTP host key if it doesn't exist
  if [[ ! -f /etc/proftpd/ssh_host_rsa_key ]]; then
    ssh-keygen -q -t rsa -b 4096 -N '' -f /etc/proftpd/ssh_host_rsa_key
  fi

  run systemctl enable --now proftpd
  # Allow SFTP port in firewall
  ufw allow 2022/tcp comment 'ProFTPD SFTP' > /dev/null 2>&1 || true
  ok "ProFTPD installed (SFTP on port 2022)"
}

# ---------------------------------------------------------------------------
# 15. PANEL APPLICATION SCAFFOLD
# ---------------------------------------------------------------------------
_create_panel_user() {
  log "Creating system user: ${PANEL_USER}..."
  [[ "$DRY_RUN" == "true" ]] && return 0
  if id "$PANEL_USER" &>/dev/null; then
    log "User ${PANEL_USER} already exists"
    return 0
  fi
  useradd --system --no-create-home --shell /usr/sbin/nologin "$PANEL_USER"
  ok "System user '${PANEL_USER}' created"
}

_create_panel_scaffold() {
  log "Creating AidiPanel directory structure..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  mkdir -p \
    "${PANEL_DIR}" \
    "${PANEL_DIR}/public" \
    "${PANEL_DIR}/storage" \
    "${PANEL_DIR}/storage/logs" \
    "${PANEL_DIR}/storage/cache" \
    "${PANEL_DIR}/config" \
    "${SITES_DIR}"

  # Main panel config file
  cat > "${PANEL_DIR}/config/panel.conf" <<PANELCONF
# AidiPanel Configuration
# Generated by installer on $(date)

PANEL_VERSION=${PANEL_VERSION}
PANEL_PORT=${PANEL_PORT}
PANEL_DIR=${PANEL_DIR}
SITES_DIR=${SITES_DIR}
NGINX_CACHE_DIR=${NGINX_CACHE_DIR}
NGINX_CACHE_ZONE=${NGINX_CACHE_ZONE}
PHP_DEFAULT_VERSION=${PHP_DEFAULT_VERSION}
INSTALL_REDIS=${INSTALL_REDIS}
OS_ID=${OS_ID}
OS_VERSION_ID=${OS_VERSION_ID}
INSTALLED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
PANELCONF

  # Panel vhost template (used when adding new sites)
  cat > "${PANEL_DIR}/config/vhost-template-wordpress.conf" <<'VHOST_WP'
# AidiPanel Vhost Template — WordPress with FastCGI Cache
# Variables replaced at site creation: %%DOMAIN%%, %%PHP_VERSION%%, %%WEBROOT%%

server {
    listen 80;
    listen [::]:80;
    server_name %%DOMAIN%% www.%%DOMAIN%%;

    # Redirect HTTP → HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name %%DOMAIN%% www.%%DOMAIN%%;

    root %%WEBROOT%%;
    index index.php index.html;

    # SSL — managed by Certbot / AidiPanel
    ssl_certificate     /etc/ssl/%%DOMAIN%%/fullchain.pem;
    ssl_certificate_key /etc/ssl/%%DOMAIN%%/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    # Security headers
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-FastCGI-Cache $upstream_cache_status always;

    # FastCGI cache exclusion rules
    include /etc/nginx/snippets/fastcgi-cache.conf;

    # Static files — served directly, no PHP
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|webp|avif|mp4|webm)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
        log_not_found off;
    }

    # WordPress permalinks
    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    # Deny access to hidden files and sensitive WordPress files
    location ~ /\. { deny all; }
    location ~ ^/(wp-config\.php|xmlrpc\.php|wp-cron\.php)$ { deny all; }
    location ~* /(?:uploads|files)/.*\.php$ { deny all; }

    # PHP handler with FastCGI cache
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php%%PHP_VERSION%%-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # FastCGI Cache directives
        fastcgi_cache        aidipanel_fcgi;
        fastcgi_cache_valid  200 301 302 1h;
        fastcgi_cache_valid  404 1m;
        fastcgi_cache_bypass  $skip_cache;
        fastcgi_no_cache      $skip_cache;

        # Timeouts
        fastcgi_connect_timeout 60s;
        fastcgi_send_timeout    180s;
        fastcgi_read_timeout    180s;
        fastcgi_buffer_size     64k;
        fastcgi_buffers         8 128k;
    }

    # Logging
    access_log /var/log/nginx/%%DOMAIN%%-access.log main;
    error_log  /var/log/nginx/%%DOMAIN%%-error.log warn;
}
VHOST_WP

  chown -R "$PANEL_USER":www-data "$PANEL_DIR"
  chmod 750 "$PANEL_DIR"

  ok "Panel directory structure created: ${PANEL_DIR}"
}

# ---------------------------------------------------------------------------
# 16. PANEL NGINX VHOST (the web UI itself)
# ---------------------------------------------------------------------------
_configure_panel_vhost() {
  log "Creating AidiPanel web UI Nginx vhost (port ${PANEL_PORT})..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  # Self-signed SSL cert for initial setup
  local ssl_dir="/etc/ssl/aidipanel"
  mkdir -p "$ssl_dir"
  if [[ ! -f "${ssl_dir}/aidipanel.crt" ]]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
      -keyout "${ssl_dir}/aidipanel.key" \
      -out "${ssl_dir}/aidipanel.crt" \
      -subj "/C=ID/ST=Jakarta/L=Jakarta/O=AidiPanel/OU=Panel/CN=localhost" \
      >> "$PANEL_LOG" 2>&1
    ok "Self-signed SSL certificate generated for panel UI"
  fi

  cat > /etc/nginx/sites-available/aidipanel-ui.conf <<PANEL_VHOST
# AidiPanel Web UI — port ${PANEL_PORT}
server {
    listen ${PANEL_PORT} ssl http2;
    listen [::]:${PANEL_PORT} ssl http2;
    server_name _;

    root ${PANEL_DIR}/public;
    index index.php index.html;

    ssl_certificate     ${ssl_dir}/aidipanel.crt;
    ssl_certificate_key ${ssl_dir}/aidipanel.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:PanelSSL:10m;
    ssl_session_timeout 10m;

    # Rate limit login attempts
    location ~* ^/(login|auth) {
        limit_req zone=aidipanel_req burst=10 nodelay;
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:/run/php/php${PHP_DEFAULT_VERSION}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Deny access to config and credential files
    location ~* \.(conf|sh|env|bak|sql)$ { deny all; }
    location ~ /\. { deny all; }

    access_log /var/log/nginx/aidipanel-access.log;
    error_log  /var/log/nginx/aidipanel-error.log;
}
PANEL_VHOST

  ln -sf /etc/nginx/sites-available/aidipanel-ui.conf \
         /etc/nginx/sites-enabled/aidipanel-ui.conf

  ok "AidiPanel web UI vhost created (port ${PANEL_PORT})"
}

# ---------------------------------------------------------------------------
# 17. PLACEHOLDER INDEX PAGE
# ---------------------------------------------------------------------------
_create_placeholder_index() {
  [[ "$DRY_RUN" == "true" ]] && return 0
  mkdir -p "${PANEL_DIR}/public"
  cat > "${PANEL_DIR}/public/index.php" <<'INDEX'
<?php
// AidiPanel — placeholder until the full panel app is deployed
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AidiPanel — Installation Successful</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
       background:#0f0f11;color:#e8e8e8;display:flex;align-items:center;
       justify-content:center;min-height:100vh}
  .card{background:#1a1a1f;border:1px solid #2a2a35;border-radius:16px;
        padding:48px 40px;max-width:480px;width:100%;text-align:center}
  .logo{display:inline-flex;align-items:center;gap:10px;margin-bottom:24px}
  .logo-icon{width:44px;height:44px;background:#534AB7;border-radius:10px;
             display:flex;align-items:center;justify-content:center;font-size:22px}
  h1{font-size:22px;font-weight:600;margin-bottom:8px;color:#fff}
  p{font-size:14px;color:#9ca3af;line-height:1.6;margin-bottom:20px}
  .badge{display:inline-block;background:#1e3a2f;color:#4ade80;
         border:1px solid #166534;border-radius:20px;
         padding:4px 14px;font-size:13px;font-weight:500;margin-bottom:24px}
  .info{background:#0f0f13;border:1px solid #2a2a35;border-radius:10px;
        padding:16px;text-align:left;font-size:13px;color:#9ca3af}
  .info span{color:#fff;font-weight:500}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <span style="font-size:20px;font-weight:700;color:#fff">AidiPanel</span>
  </div>
  <div class="badge">✓ Installation Successful</div>
  <h1>Server siap digunakan</h1>
  <p>AidiPanel berhasil diinstall. Deploy panel application untuk mulai mengelola server Anda.</p>
  <div class="info">
    <div style="margin-bottom:8px">PHP: <span><?php echo PHP_VERSION; ?></span></div>
    <div style="margin-bottom:8px">Nginx: <span><?php echo shell_exec('nginx -v 2>&1') ?: 'OK'; ?></span></div>
    <div>Waktu server: <span><?php echo date('Y-m-d H:i:s T'); ?></span></div>
  </div>
</div>
</body>
</html>
INDEX
  ok "Placeholder index page created"
}

# ---------------------------------------------------------------------------
# 18. FAIL2BAN (basic protection)
# ---------------------------------------------------------------------------
_configure_fail2ban() {
  log "Configuring Fail2ban for SSH and Nginx..."
  [[ "$DRY_RUN" == "true" ]] && return 0
  _pkg_installed fail2ban || { warn "fail2ban not installed; skipping"; return 0; }

  cat > /etc/fail2ban/jail.d/aidipanel.conf <<'F2B'
[sshd]
enabled  = true
port     = ssh
maxretry = 5
bantime  = 3600
findtime = 600

[nginx-http-auth]
enabled  = true
port     = http,https
maxretry = 5
bantime  = 3600
findtime = 600

[nginx-botsearch]
enabled  = true
port     = http,https
maxretry = 10
bantime  = 86400
findtime = 3600
F2B

  run systemctl enable --now fail2ban
  ok "Fail2ban configured"
}

# ---------------------------------------------------------------------------
# 19. SYSTEMD CRON / SERVICES
# ---------------------------------------------------------------------------
_setup_cron() {
  log "Setting up AidiPanel maintenance cron jobs..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local cron_file="/etc/cron.d/aidipanel"
  cat > "$cron_file" <<'CRONFILE'
# AidiPanel maintenance jobs
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# Renew Let's Encrypt certificates daily at 2:30 AM
30 2 * * * root certbot renew --quiet --nginx >> /var/log/aidipanel-certbot.log 2>&1

# Purge FastCGI cache older than 1 day (keeps cache fresh)
0 4 * * * root find /var/cache/nginx/fastcgi -type f -atime +1 -delete >> /var/log/aidipanel-install.log 2>&1

# Rotate AidiPanel logs weekly
0 0 * * 0 root find /var/log/nginx -name "*.log" -size +100M -exec gzip {} \; 2>/dev/null
CRONFILE

  chmod 644 "$cron_file"
  ok "Cron jobs configured: /etc/cron.d/aidipanel"
}

# ---------------------------------------------------------------------------
# 20. NGINX CONFIG TEST & RESTART ALL SERVICES
# ---------------------------------------------------------------------------
_test_and_start_services() {
  log "Testing Nginx configuration..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping nginx test & service start"; return 0; }

  nginx -t >> "$PANEL_LOG" 2>&1 \
    || die "Nginx configuration test FAILED. Check: nginx -t"
  ok "Nginx config: OK"

  local services=("nginx")
  for ver in "${PHP_VERSIONS[@]}"; do
    services+=("php${ver}-fpm")
  done
  services+=("$(_db_service_name)")
  [[ "$INSTALL_REDIS" == "true" ]] && services+=("redis-server")

  for svc in "${services[@]}"; do
    log "Enabling and starting: ${svc}..."
    systemctl enable --now "$svc" >> "$PANEL_LOG" 2>&1 \
      || warn "Could not start ${svc} — it may need manual start"
  done

  ok "All services started"
}

# ---------------------------------------------------------------------------
# 21. FINAL HEALTH CHECK
# ---------------------------------------------------------------------------
_health_check() {
  log "Running post-install health check..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local failed=0

  _svc_check() {
    if systemctl is-active --quiet "$1"; then
      ok "  ✓ $1 is running"
    else
      warn "  ✗ $1 is NOT running"
      (( failed++ )) || true
    fi
  }

  _svc_check nginx
  for ver in "${PHP_VERSIONS[@]}"; do
    _svc_check "php${ver}-fpm"
  done
  _svc_check "$(_db_service_name)"
  [[ "$INSTALL_REDIS" == "true" ]] && _svc_check redis-server

  # Check cache directory writable by nginx
  if [[ -d "$NGINX_CACHE_DIR" ]] && [[ -w "$NGINX_CACHE_DIR" || "$(stat -c %U "$NGINX_CACHE_DIR")" == "www-data" ]]; then
    ok "  ✓ FastCGI cache dir is OK: ${NGINX_CACHE_DIR}"
  else
    warn "  ✗ FastCGI cache dir may have permission issues: ${NGINX_CACHE_DIR}"
    (( failed++ )) || true
  fi

  # Quick HTTP check on panel port
  if curl -ksfS --max-time 5 "https://127.0.0.1:${PANEL_PORT}/" -o /dev/null; then
    ok "  ✓ AidiPanel responding on port ${PANEL_PORT}"
  else
    warn "  ✗ AidiPanel not responding on port ${PANEL_PORT} yet"
  fi

  if (( failed > 0 )); then
    warn "Health check: ${failed} issue(s) detected — review log: ${PANEL_LOG}"
  else
    ok "Health check: ALL PASSED"
  fi
}

# ---------------------------------------------------------------------------
# 22. CLEANUP — remove install artifacts, clear history
# ---------------------------------------------------------------------------
_cleanup_system() {
  log "Cleaning up installation artifacts..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping cleanup"; return 0; }

  # Clean apt cache
  apt-get autoremove -y -qq >> "$PANEL_LOG" 2>&1 || true
  apt-get clean >> "$PANEL_LOG" 2>&1 || true

  # Clear bash history (security — credentials may have been typed)
  history -c 2>/dev/null || true
  cat /dev/null > ~/.bash_history 2>/dev/null || true

  # Remove any temp files left in /tmp by this installer
  rm -f /tmp/aidipanel-* 2>/dev/null || true

  ok "Cleanup done"
}

# ---------------------------------------------------------------------------
# 23. SUMMARY
# ---------------------------------------------------------------------------
_print_summary() {
  local server_ip
  server_ip=$(ip route get 8.8.8.8 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") {print $(i+1); exit}}')
  server_ip="${server_ip:-<server-ip>}"

  [[ "$DRY_RUN" == "true" ]] && return 0

  echo -e "\n${BOLD}${GREEN}"
  echo "  ╔══════════════════════════════════════════════════════╗"
  echo "  ║       AidiPanel v${PANEL_VERSION} — Installation Complete!        ║"
  echo "  ╚══════════════════════════════════════════════════════╝"
  echo -e "${RESET}"
  echo -e "  ${BOLD}Panel URL       :${RESET} https://${server_ip}:${PANEL_PORT}"
  echo -e "  ${BOLD}Database        :${RESET} ${DB_ENGINE_LABELS[$DB_ENGINE]}"
  echo -e "  ${BOLD}Credentials     :${RESET} ${PANEL_DIR}/credentials.conf"
  echo -e "  ${BOLD}Config dir      :${RESET} ${PANEL_DIR}/config/"
  echo -e "  ${BOLD}Sites dir       :${RESET} ${SITES_DIR}/"
  echo -e "  ${BOLD}Nginx cache dir :${RESET} ${NGINX_CACHE_DIR}"
  echo -e "  ${BOLD}Log             :${RESET} ${PANEL_LOG}"
  echo -e "  ${BOLD}PHP versions    :${RESET} ${PHP_VERSIONS[*]}"
  echo -e "  ${BOLD}Redis           :${RESET} ${INSTALL_REDIS}"
  echo -e "  ${BOLD}Duration        :${RESET} ${SECONDS}s"
  echo ""
  echo -e "  ${YELLOW}NOTE: The panel uses a self-signed SSL certificate for now.${RESET}"
  echo -e "  ${YELLOW}      Point your domain to this server and run certbot to get${RESET}"
  echo -e "  ${YELLOW}      a free Let's Encrypt certificate.${RESET}"
  echo ""
  echo -e "  ${CYAN}Next step: Deploy the AidiPanel web application to:${RESET}"
  echo -e "  ${CYAN}  ${PANEL_DIR}/public/${RESET}"
  echo ""
}

# ---------------------------------------------------------------------------
# MAIN
# ---------------------------------------------------------------------------
main() {
  # Init log file
  mkdir -p "$(dirname "$PANEL_LOG")"
  touch "$PANEL_LOG"
  chmod 640 "$PANEL_LOG"

  _parse_args "$@"
  _banner
  _check_root
  _check_lock
  _check_already_installed
  _detect_os
  _check_resources
  _check_internet
  _check_port_free
  _check_hostname_resolves

  log "=== Phase 1: Base system packages ==="
  _apt_update
  _install_base_packages

  log "=== Phase 1b: Swap file ==="
  _create_swap

  log "=== Phase 2: Nginx + FastCGI Cache ==="
  _add_nginx_repo
  _install_nginx
  _configure_nginx_main
  _create_fastcgi_cache_dir
  _create_fastcgi_params_snippet

  log "=== Phase 3: PHP-FPM (multi-version) ==="
  _add_php_repo
  _install_all_php_versions

  log "=== Phase 4: Database (${DB_ENGINE_LABELS[$DB_ENGINE]}) ==="
  _add_mysql_repo
  _add_mariadb_repo
  _install_database
  _configure_database

  log "=== Phase 5: Redis ==="
  _install_redis
  _configure_redis

  log "=== Phase 6: Security ==="
  _configure_firewall
  _configure_fail2ban
  _install_certbot

  log "=== Phase 7: ProFTPD ==="
  _install_proftpd

  log "=== Phase 8: Panel Scaffold ==="
  _create_panel_user
  _create_panel_scaffold
  _configure_panel_vhost
  _create_placeholder_index

  log "=== Phase 9: Cron & Services ==="
  _setup_cron
  _test_and_start_services

  log "=== Phase 10: Health Check ==="
  _health_check

  log "=== Phase 11: Cleanup ==="
  _cleanup_system

  _print_summary

  ok "${PANEL_NAME} v${PANEL_VERSION} installation finished in ${SECONDS}s"
}

main "$@"
