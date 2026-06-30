#!/usr/bin/env bash
# =============================================================================
#  AidiPanel Installer v1.2.0-rc1
#  Stack: Nginx + FastCGI Cache + PHP-FPM (multi-version) + MySQL/MariaDB + Redis
#  Supported OS: Debian 11/12, Ubuntu 22.04/24.04 (x86_64 & arm64)
#
#  One-command install (installs stack + deploys panel app automatically):
#    bash <(curl -fsSL https://get.aidipanel.com)
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
#  Author : AidiPanel Team — by rezzaid
# =============================================================================

set -Eeuo pipefail
IFS=$'\n\t'

# ---------------------------------------------------------------------------
# 0. GLOBAL CONSTANTS & DEFAULTS
# ---------------------------------------------------------------------------
readonly PANEL_NAME="AidiPanel"
readonly PANEL_VERSION="1.2.0-rc1"
readonly PANEL_USER="aidipanel"
readonly PANEL_DIR="/opt/aidipanel"
readonly PANEL_LOG="/var/log/aidipanel-install.log"
readonly PANEL_LOCK="/var/run/aidipanel-install.lock"
readonly NGINX_CACHE_DIR="/var/cache/nginx/fastcgi"
readonly NGINX_CACHE_ZONE="aidipanel_fcgi"
readonly SITES_DIR="/var/www"
readonly DB_NAME="aidipanel"
PHP_DEFAULT_VERSION="8.4"

declare -A DB_ENGINE_LABELS=(
  [mysql80]="MySQL 8.0"
  [mysql84]="MySQL 8.4 (LTS)"
  [mariadb1011]="MariaDB 10.11 (LTS) — recommended for WordPress"
  [mariadb114]="MariaDB 11.4 (LTS)"
  [mariadb118]="MariaDB 11.8"
)

PANEL_PORT=8443
DB_ENGINE="mariadb1011"
INSTALL_REDIS=true
DRY_RUN=false
DB_ROOT_PASS=""
PANEL_ADMIN_PASS=""        # generated random, shown at end
SWAP_SIZE_MB=2048
UI_PLAIN=false        # --plain -> ASCII fallback (no unicode)
UI_VERBOSE=false      # --verbose -> inline command output (tee), for debugging
readonly RULE_WIDTH=47
DEBIAN_FRONTEND=noninteractive
export DEBIAN_FRONTEND
NEEDRESTART_MODE=a
NEEDRESTART_SUSPEND=1
export NEEDRESTART_MODE NEEDRESTART_SUSPEND

# Colors
RED='\033[0;31m'; RESET='\033[0m'

# ---------------------------------------------------------------------------
# 1. LOGGING HELPERS
# ---------------------------------------------------------------------------
_ts()   { date '+%Y-%m-%d %H:%M:%S'; }
log()   { printf '[%s] [INFO]  %s\n' "$(_ts)" "$*" >> "$PANEL_LOG"; }
warn()  { printf '[%s] [WARN]  %s\n' "$(_ts)" "$*" >> "$PANEL_LOG"; }
ok()    { printf '[%s] [OK]    %s\n' "$(_ts)" "$*" >> "$PANEL_LOG"; }
die()   {
  echo -e "${RED}[$(_ts)] [ERROR]${RESET} $*" | tee -a "$PANEL_LOG" >&2
  exit 1
}

run() {
  if [[ "$DRY_RUN" == "true" ]]; then
    warn "[dry-run] skipped: $1 …"
    return 0
  fi
  "$@" >> "$PANEL_LOG" 2>&1
}

credential_line() {
  local key="$1" value="$2"
  printf '%s=%q\n' "$key" "$value"
}

# ---------------------------------------------------------------------------
# 1b. PROVISIONING CONSOLE — presentation helpers (Phase 2; output/logging only)
# ---------------------------------------------------------------------------
# >>> CONSOLE_HELPERS_START (sourced by tests/unit/console_helpers_test.sh)
# unicode glyph, or ASCII fallback under --plain.  _u <unicode> <ascii>
_u() { if [[ "$UI_PLAIN" == "true" ]]; then printf '%s' "$2"; else printf '%s' "$1"; fi; }

# Color is enabled ONLY on an interactive TTY (and not --plain); piped/logged output
# stays clean (no ANSI codes in the install log or the test loop). Call once from main().
_color_init() {
  if [[ -t 1 && "$UI_PLAIN" != "true" ]]; then
    C_TITLE=$'\033[1;36m'; C_OK=$'\033[0;32m'; C_WARN=$'\033[1;33m'
    C_FAIL=$'\033[1;31m'; C_KEY=$'\033[1m'; C_RESET=$'\033[0m'
  else
    C_TITLE=''; C_OK=''; C_WARN=''; C_FAIL=''; C_KEY=''; C_RESET=''
  fi
}

# timestamped line to the FILE log only (no terminal)
_logf() { printf '[%s] %s\n' "$(_ts)" "$*" >> "$PANEL_LOG"; }

ui_rule() { local ch n="$RULE_WIDTH" out=''; ch=$(_u '━' '-'); while (( n-- > 0 )); do out+="$ch"; done; printf '%s\n' "$out"; }

ui_section() { printf '\n\n%s%s%s\n' "${C_TITLE:-}" "$1" "${C_RESET:-}"; ui_rule; _logf "=== $1 ==="; }

ui_kv()   { printf '%s%-12s%s %s\n' "${C_KEY:-}" "$1" "${C_RESET:-}" "$2"; }
ui_ok()   { printf '%s%s%s %s\n' "${C_OK:-}" "$(_u '✓' '[OK]')" "${C_RESET:-}" "$1"; _logf "[OK] $1"; }
ui_warn() { printf '%s%s %s%s\n' "${C_WARN:-}" "$(_u '⚠' '[!]')" "$1" "${C_RESET:-}"; _logf "[WARN] $1"; }
ui_fail() { printf '%s%s %s%s\n' "${C_FAIL:-}" "$(_u '✗' '[X]')" "$1" "${C_RESET:-}"; _logf "[FAIL] $1"; }
ui_note() { printf '  %s\n' "$1"; }

# Elapsed for a layer.  ui_elapsed <start_seconds>
ui_elapsed() { local d=$(( SECONDS - $1 )); printf '\nElapsed  %02d:%02d\n' $(( d/60 )) $(( d%60 )); }

# run_quiet <Layer> <step> <cmd...> — command output to the log only; on failure print a
# fail-loud line (layer/step/exit/log) and exit with the command's code. --verbose tees
# inline. Uses `if` (not `cmd; rc=$?`) so `set -e` doesn't abort before we read the code.
run_quiet() {
  local layer="$1" step="$2"; shift 2
  _logf ">>> [${layer}] ${step}"
  local rc=0
  if [[ "$UI_VERBOSE" == "true" ]]; then
    if "$@" 2>&1 | tee -a "$PANEL_LOG"; then rc=0; else rc=${PIPESTATUS[0]}; fi
  else
    if "$@" >> "$PANEL_LOG" 2>&1; then rc=0; else rc=$?; fi
  fi
  if (( rc != 0 )); then
    ui_fail "${layer} failed at: ${step} (exit ${rc})"
    ui_note "See ${PANEL_LOG}"
    exit "$rc"
  fi
  return 0
}
# <<< CONSOLE_HELPERS_END

_php_list() { echo "${PHP_DEFAULT_VERSION}"; }

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
      --plain)
        UI_PLAIN=true
        ;;
      --verbose)
        UI_VERBOSE=true
        ;;
      --help|-h)
        echo "Usage: bash $0 [OPTIONS]"
        echo "  --port PORT           Panel HTTPS port (default: 8443)"
        echo "  --db-engine ENGINE    Database engine (default: mariadb1011)"
        echo "                        Options: mysql80 | mysql84 | mariadb1011 | mariadb114 | mariadb118"
        echo "  --db-root-pass PASS   Set DB root password non-interactively"
        echo "  --no-redis            Skip Redis installation"
        echo "  --dry-run             Simulate install without making changes"
        echo "  --plain               ASCII output (no unicode); for basic terminals"
        echo "  --verbose             Show command output inline (debug)"
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
  if [[ -d "$PANEL_DIR" ]] && [[ -x /usr/local/bin/aidipanel ]]; then
    die "${PANEL_NAME} is already installed. " \
        "To reinstall, run the uninstaller first."
  fi
}

# ---------------------------------------------------------------------------
# FIX #1: Auto-detect OS including Ubuntu 24.04 Noble + proper codename
# ---------------------------------------------------------------------------
_detect_os() {
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

# ---------------------------------------------------------------------------
# FIX #3: RAM detection — use awk for proper MB→GB with 1 decimal place
# ---------------------------------------------------------------------------
_check_resources() {
  local mem_kb cpu_cores disk_kb
  mem_kb=$(awk '/MemTotal/{print $2}' /proc/meminfo)
  cpu_cores=$(nproc)
  disk_kb=$(df / --output=avail -k | tail -1)

  # Use awk for accurate floating-point MB→GB (fixes 0GB bug from integer division)
  local mem_gb disk_gb
  mem_gb=$(awk "BEGIN {printf \"%.1f\", ${mem_kb}/1024/1024}")
  disk_gb=$(awk "BEGIN {printf \"%.1f\", ${disk_kb}/1024/1024}")

  if (( cpu_cores > 1 )); then PROFILE_CPU="${cpu_cores} cores"; else PROFILE_CPU="${cpu_cores} core"; fi
  PROFILE_RAM="${mem_gb} GB"
  PROFILE_DISK="${disk_gb} GB"
  PROFILE_LOWRAM=false; (( mem_kb >= 2048000 )) || PROFILE_LOWRAM=true
  _logf "Resources: ${cpu_cores} CPU, ${mem_gb}GB RAM, ${disk_gb}GB free"

  (( mem_kb >= 512000 )) \
    || _logf "Low RAM: ~${mem_gb}GB (min 1GB recommended; swap will be created)."
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
  if [[ -z "$hostname" || "$hostname" == "localhost" || "$hostname" == "localhost.localdomain" ]]; then
    warn "Hostname is '${hostname}' — consider setting FQDN: hostnamectl set-hostname your-server.domain.com"
    return 0
  fi
  local resolved_ip
  resolved_ip=$(getent hosts "$hostname" 2>/dev/null | awk '{print $1}' | head -1)
  if [[ -z "$resolved_ip" ]]; then
    warn "Hostname '${hostname}' does not resolve — OK for now, configure DNS before SSL."
  else
    ok "Hostname '${hostname}' → ${resolved_ip}"
  fi
}

# ---------------------------------------------------------------------------
# 4b. PRE-FLIGHT PRESENTATION (Server Profile · Readiness · Plan)
# ---------------------------------------------------------------------------
print_server_profile() {
  ui_section "Server Profile"
  ui_kv "Host"       "$(hostname -s 2>/dev/null || hostname)"
  ui_kv "OS"         "${OS_ID^} ${OS_VERSION_ID} ${OS_CODENAME}"
  ui_kv "Kernel"     "$(uname -r)"
  ui_kv "CPU"        "${PROFILE_CPU:-?}"
  ui_kv "RAM"        "${PROFILE_RAM:-?}"
  ui_kv "Disk free"  "${PROFILE_DISK:-?}"
  ui_kv "Network"    "online"
  ui_kv "Panel port" "${PANEL_PORT}"
  if [[ "${PROFILE_LOWRAM:-false}" == "true" ]]; then
    printf '\n%s\n' "Resource note"
    ui_note "$(_u '⚠' '[!]') RAM is below recommended production size."
    ui_note "Swap will be created automatically to keep installation stable."
  fi
}

print_readiness_check() {
  ui_section "Readiness Check"
  ui_ok "Root access"
  ui_ok "Internet connectivity"
  ui_ok "Ubuntu version supported"
  ui_ok "Port ${PANEL_PORT} available"
  ui_ok "Hostname detected"
  ui_ok "No blocking pre-flight issue found"
}

print_provisioning_plan() {
  ui_section "Provisioning Plan"
  ui_kv "Foundation"    "System tools · Swap · Base services"
  ui_kv "Web Engine"    "Nginx mainline · FastCGI Cache"
  ui_kv "Runtime"       "PHP-FPM ${PHP_DEFAULT_VERSION} (default) · 8.2/8.3/8.5 on-demand"
  ui_kv "Data Layer"    "${DB_ENGINE_LABELS[$DB_ENGINE]} · Redis"
  ui_kv "Security"      "UFW · Fail2ban · Certbot · Sudo wrapper"
  ui_kv "Control Plane" "AidiPanel Web UI · CLI · Cron"
  ui_kv "Verification"  "Service status · Panel response · Config test"
}

# print_blueprint <web_state> <data_state> <panel_state> — the Runtime Blueprint tree.
print_blueprint() {
  ui_section "Runtime Blueprint"
  printf '%s\n' "Internet"
  printf '  %s\n' "$(_u '↓' 'v')"
  printf '%s\n' "Nginx ${NGINX_VERSION:-mainline}"
  local mid end
  mid="$(_u '├─' '|-')"; end="$(_u '└─' '\-')"
  printf '  %s %-16s %s\n' "$mid" "FastCGI Cache" "$1"
  printf '  %s %-16s %s\n' "$end" "PHP-FPM ${PHP_DEFAULT_VERSION}" "$1"
  printf '\n'
  printf '%-24s %s\n' "${DB_ENGINE_LABELS[$DB_ENGINE]%% (*}" "$2"
  printf '%-24s %s\n' "Redis" "$2"
  printf '%-24s %s\n' "AidiPanel UI" "$3"
}

# ---------------------------------------------------------------------------
# 5. BANNER — with "by rezzaid" branding
# ---------------------------------------------------------------------------
_banner() {
  printf '%s\n' "AidiPanel Provisioning Console"
  ui_rule
  printf '%s\n\n' "Fast LEMP Server Management Panel"
  ui_kv "Version"    "v${PANEL_VERSION}"
  ui_kv "Stack"      "Nginx · PHP-FPM · ${DB_ENGINE_LABELS[$DB_ENGINE]%% *} · Redis · SSL"
  ui_kv "Mode"       "Fresh VPS provisioning"
  ui_kv "By"         "rezzaid"
  printf '\n'
  ui_kv "Detail log" "${PANEL_LOG}"
  ui_kv "Verbose"    "bash install.sh --verbose"
  _logf "=== Provisioning Console start: ${PANEL_NAME} v${PANEL_VERSION} (port ${PANEL_PORT}, db ${DB_ENGINE}) ==="
}

# ---------------------------------------------------------------------------
# 6. APT HELPERS
# ---------------------------------------------------------------------------
_apt_update() {
  log "Updating apt package lists..."
  run apt-get update -qq
}

_apt_install() {
  log "Installing packages: $*"
  run apt-get install -y -qq --no-install-recommends "$@"
}

_pkg_installed() {
  dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"
}

# ---------------------------------------------------------------------------
# 7. BASE SYSTEM PACKAGES (minimal — reduced for lightweight VPS)
# ---------------------------------------------------------------------------
_install_base_packages() {
  log "Installing base system packages..."
  # Tolerate failure while apt still holds the lock at boot time (e.g. unattended-upgrades
  # right after boot): ask apt to wait for the lock up to 5 minutes instead of failing immediately.
  mkdir -p /etc/apt/apt.conf.d
  echo 'DPkg::Lock::Timeout "300";' > /etc/apt/apt.conf.d/99aidipanel-lock-timeout
  _apt_install \
    curl wget gnupg2 lsb-release ca-certificates apt-transport-https \
    software-properties-common unzip zip tar rclone \
    git cron ufw fail2ban \
    openssl certbot \
    sqlite3 python3 \
    net-tools jq
  ok "Base packages installed"
}

# ---------------------------------------------------------------------------
# 7b. SWAP FILE — create swap if none exists (critical for small VPS)
# ---------------------------------------------------------------------------
_create_swap() {
  log "Checking swap space..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping swap creation"; return 0; }

  local swap_total
  swap_total=$(free -m | awk '/^Swap:/ {print $2}')

  if (( swap_total >= 512 )); then
    SWAP_NOTE="present: ${swap_total} MB"
    ok "Swap already exists: ${swap_total}MB — skipping"
    return 0
  fi

  local swap_file="/swapfile"
  local swap_mb=$SWAP_SIZE_MB
  local mem_mb
  mem_mb=$(free -m | awk '/^Mem:/ {print $2}')
  (( mem_mb > 4096 )) && swap_mb=1024

  log "Creating ${swap_mb}MB swap file at ${swap_file}..."
  if command -v fallocate &>/dev/null; then
    fallocate -l "${swap_mb}M" "$swap_file" >> "$PANEL_LOG" 2>&1 \
      || dd if=/dev/zero of="$swap_file" bs=1M count="$swap_mb" >> "$PANEL_LOG" 2>&1
  else
    dd if=/dev/zero of="$swap_file" bs=1M count="$swap_mb" >> "$PANEL_LOG" 2>&1
  fi

  chmod 600 "$swap_file"
  mkswap "$swap_file"  >> "$PANEL_LOG" 2>&1
  swapon "$swap_file"  >> "$PANEL_LOG" 2>&1

  if ! grep -q "$swap_file" /etc/fstab; then
    echo "${swap_file} none swap sw 0 0" >> /etc/fstab
  fi

  # Performance kernel tuning
  local sysctl_conf="/etc/sysctl.d/99-aidipanel.conf"
  cat > "$sysctl_conf" <<'SYSCTL'
# AidiPanel — kernel tuning for performance
vm.swappiness = 10
vm.vfs_cache_pressure = 50
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.core.netdev_max_backlog = 65535
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_fin_timeout = 15
SYSCTL
  sysctl -p "$sysctl_conf" >> "$PANEL_LOG" 2>&1

  local actual_swap
  actual_swap=$(free -m | awk '/^Swap:/ {print $2}')
  SWAP_NOTE="created: ${actual_swap} MB"
  ok "Swap created: ${actual_swap}MB (swappiness=10)"
}

# ---------------------------------------------------------------------------
# 8. NGINX — FIX #2: Ubuntu 24.04 Noble GPG/repo fix
# ---------------------------------------------------------------------------
_apt_arch() {
  [[ "$ARCH" == "x86_64" ]] && echo "amd64" || echo "arm64"
}

_add_nginx_repo() {
  if [[ -f /etc/apt/sources.list.d/nginx.list ]]; then
    log "Nginx apt repo already configured — skipping"
    return 0
  fi
  log "Adding official Nginx apt repository..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping nginx repo add"; return 0; }

  local apt_arch; apt_arch=$(_apt_arch)

  # Download and dearmor GPG key properly
  curl -fsSL https://nginx.org/keys/nginx_signing.key \
    | gpg --dearmor -o /etc/apt/trusted.gpg.d/nginx.gpg 2>>"$PANEL_LOG"
  chmod 644 /etc/apt/trusted.gpg.d/nginx.gpg

  # Ubuntu 24.04 Noble: nginx.org mainline supports noble since 1.25.3+
  # Fall back to distro nginx if noble not yet in official repo
  local nginx_repo_os="$OS_ID"
  local nginx_repo_codename="$OS_CODENAME"

  if [[ "$OS_ID" == "ubuntu" && "$OS_VERSION_ID" == "24.04" ]]; then
    # Check if noble is in nginx.org mainline
    if curl -fsSL --max-time 10 \
        "http://nginx.org/packages/ubuntu/dists/noble/" >/dev/null 2>&1; then
      log "Nginx official repo supports Ubuntu 24.04 noble"
    else
      warn "Nginx official repo not yet available for Ubuntu 24.04 noble — using distro nginx"
      _apt_install nginx
      ok "Nginx installed from distro repo (Ubuntu 24.04 noble)"
      return 0
    fi
  fi

  cat > /etc/apt/sources.list.d/nginx.list <<NGINX_REPO
deb [arch=${apt_arch} signed-by=/etc/apt/trusted.gpg.d/nginx.gpg] http://nginx.org/packages/${nginx_repo_os} ${nginx_repo_codename} nginx
NGINX_REPO

  _apt_update
  ok "Nginx repo added"
}

_install_nginx() {
  if _pkg_installed nginx; then
    log "Nginx already installed — skipping"
    return 0
  fi
  log "Installing Nginx..."
  _apt_install nginx
  ok "Nginx installed: $(nginx -v 2>&1 || true)"
}

_configure_nginx_main() {
  log "Writing Nginx main configuration (optimized)..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping nginx config write"; return 0; }

  if [[ -f /etc/nginx/nginx.conf ]]; then
    cp /etc/nginx/nginx.conf "/etc/nginx/nginx.conf.bak.$(date +%s)"
  fi

  # Calculate worker connections based on RAM
  local mem_mb; mem_mb=$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo)
  local worker_conn=2048
  (( mem_mb >= 2048 )) && worker_conn=4096
  (( mem_mb >= 4096 )) && worker_conn=8192

  cat > /etc/nginx/nginx.conf <<NGINX_MAIN
user www-data;
worker_processes auto;
worker_rlimit_nofile 65535;
pid /run/nginx.pid;
include /etc/nginx/modules-enabled/*.conf;

events {
    worker_connections ${worker_conn};
    multi_accept on;
    use epoll;
}

http {
    # --- Basic Settings ---
    sendfile            on;
    tcp_nopush          on;
    tcp_nodelay         on;
    keepalive_timeout   75;
    keepalive_requests  1000;
    types_hash_max_size 2048;
    server_tokens       off;
    client_max_body_size 256m;
    client_body_buffer_size 128k;

    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    # --- Logging (buffered for performance) ---
    log_format main '\$remote_addr - \$remote_user [\$time_local] "\$request" '
                    '\$status \$body_bytes_sent "\$http_referer" '
                    '"\$http_user_agent" cache:\$upstream_cache_status';
    access_log /var/log/nginx/access.log main buffer=32k flush=5s;
    error_log  /var/log/nginx/error.log  warn;

    # --- Gzip (optimized) ---
    gzip              on;
    gzip_vary         on;
    gzip_proxied      any;
    gzip_comp_level   5;
    gzip_min_length   256;
    gzip_buffers      16 8k;
    gzip_http_version 1.1;
    gzip_types text/plain text/css text/xml application/json application/javascript
               application/xml+rss application/atom+xml image/svg+xml
               text/javascript application/x-javascript application/xhtml+xml;

    # --- Open file cache (huge performance boost) ---
    open_file_cache          max=10000 inactive=30s;
    open_file_cache_valid    60s;
    open_file_cache_min_uses 2;
    open_file_cache_errors   on;

    # --- FastCGI Cache Zone ---
    fastcgi_cache_path /var/cache/nginx/fastcgi
        levels=1:2
        keys_zone=aidipanel_fcgi:200m
        max_size=10g
        inactive=60m
        use_temp_path=off;
    fastcgi_cache_key "\$scheme\$request_method\$host\$request_uri";
    fastcgi_cache_use_stale error timeout invalid_header updating
                            http_500 http_503;
    fastcgi_cache_lock        on;
    fastcgi_cache_lock_timeout 5s;
    # Never ignore Set-Cookie: a response that sets a cookie must not be cached
    # (it would serve one visitor's session cookie to everyone). Cache-Control and
    # Expires stay ignored so the page cache still works on apps that blanket-send
    # no-cache.
    fastcgi_ignore_headers    Cache-Control Expires;

    # --- Rate Limiting ---
    limit_req_zone \$binary_remote_addr zone=aidipanel_req:10m rate=30r/m;

    # --- Security Headers (applied globally) ---
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # --- Virtual Hosts ---
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
NGINX_MAIN

  mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/snippets
  rm -f /etc/nginx/conf.d/default.conf /etc/nginx/sites-enabled/default

  # Per-site dedicated cache zones (opt-in via `cache:zone`). Pre-create the include dir
  # and the http-context shim so an admin can enable a dedicated zone later with no
  # nginx.conf surgery (conf.d/*.conf is already included inside http{}). A wildcard
  # include over the empty dir is valid (nginx -t passes).
  mkdir -p /etc/nginx/aidipanel/cache-zones
  printf 'include /etc/nginx/aidipanel/cache-zones/*.conf;\n' > /etc/nginx/conf.d/aidipanel-cache-zones.conf

  ok "Nginx main config written (optimized, worker_conn=${worker_conn})"
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

  cat > /etc/nginx/snippets/fastcgi-cache.conf <<'FCGI_SNIP'
# AidiPanel — FastCGI Cache exclusion rules
# Include this snippet inside each server{} block.

set $skip_cache 0;

# Do not cache POST requests
if ($request_method = POST)          { set $skip_cache 1; }

# Do not cache URIs with query strings
if ($query_string != "")             { set $skip_cache 1; }

# WordPress: skip admin, login, cart, checkout
if ($request_uri ~* "(/wp-admin/|/wp-login\.php|/wp-json/|/cart|/checkout|/my-account|/xmlrpc\.php)") {
    set $skip_cache 1;
}

# WordPress logged-in users / WooCommerce sessions
if ($http_cookie ~* "(wordpress_logged_in|woocommerce_items_in_cart|woocommerce_session|comment_author)") {
    set $skip_cache 1;
}

# Laravel / generic session cookies
if ($http_cookie ~* "(laravel_session|PHPSESSID|auth_token)") {
    set $skip_cache 1;
}
FCGI_SNIP

  ok "FastCGI cache snippet written: /etc/nginx/snippets/fastcgi-cache.conf"
}

# ---------------------------------------------------------------------------
# 9. PHP-FPM (multi-version) — tuned for lightweight VPS
# ---------------------------------------------------------------------------
_add_php_repo() {
  if [[ -f /etc/apt/sources.list.d/php.list ]]; then
    log "PHP apt repo already configured — skipping"
    return 0
  fi
  log "Adding PHP repository (ondrej/sury)..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping PHP repo add"; return 0; }

  if [[ "$OS_ID" == "ubuntu" ]]; then
    # ondrej/php PPA works on all supported Ubuntu versions
    apt-get install -y -qq software-properties-common >> "$PANEL_LOG" 2>&1
    add-apt-repository -y ppa:ondrej/php >> "$PANEL_LOG" 2>&1
  else
    # Debian — use deb.sury.org
    curl -fsSL https://packages.sury.org/php/apt.gpg \
      | gpg --dearmor -o /etc/apt/trusted.gpg.d/php-sury.gpg 2>>"$PANEL_LOG"
    chmod 644 /etc/apt/trusted.gpg.d/php-sury.gpg
    cat > /etc/apt/sources.list.d/php.list <<PHP_REPO
deb [signed-by=/etc/apt/trusted.gpg.d/php-sury.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main
PHP_REPO
  fi
  _apt_update
  ok "PHP repository added"
}

_install_php_version() {
  local ver="$1"
  log "Installing PHP ${ver} and extensions..."
  [[ -z "${PHP_EXTENSIONS:-}" ]] && source /etc/aidipanel/php.conf
  # Skip suffixes the repo does not ship for this version (e.g. PHP 8.5 bundles
  # OPcache into core, so php8.5-opcache is absent). Keeps on-demand + upfront in
  # sync (both build from PHP_EXTENSIONS) and avoids apt failing the whole install.
  local pkgs=() skipped=() exts ext
  local IFS=' '
  read -ra exts <<< "$PHP_EXTENSIONS"
  for ext in "${exts[@]}"; do
    if apt-cache show "php${ver}-${ext}" >/dev/null 2>&1; then
      pkgs+=("php${ver}-${ext}")
    else
      skipped+=("php${ver}-${ext}")
    fi
  done
  [[ ${#skipped[@]} -gt 0 ]] && log "Skipping packages not in repo for PHP ${ver}: ${skipped[*]} (bundled into core or unavailable)."
  _apt_install "${pkgs[@]}"
  ok "PHP ${ver} installed"
}

_configure_php_fpm() {
  local ver="$1"
  local pool_conf="/etc/php/${ver}/fpm/pool.d/www.conf"
  [[ -f "$pool_conf" ]] || { warn "PHP ${ver} pool config not found; skipping FPM tune"; return 0; }
  [[ "$DRY_RUN" == "true" ]] && return 0

  log "Tuning PHP-FPM ${ver} pool (adaptive to RAM)..."
  cp "$pool_conf" "${pool_conf}.bak.$(date +%s)"

  # Calculate PM workers based on RAM
  local mem_mb; mem_mb=$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo)
  local max_ch=10 start_sv=2 min_sp=2 max_sp=4
  if (( mem_mb >= 2048 )); then max_ch=20; start_sv=4; min_sp=2; max_sp=6; fi
  if (( mem_mb >= 4096 )); then max_ch=40; start_sv=6; min_sp=4; max_sp=10; fi
  PHP_MAXCHILDREN="$max_ch"

  sed -i \
    -e "s|^listen = .*|listen = /run/php/php${ver}-fpm.sock|" \
    -e 's|^;listen.owner = .*|listen.owner = www-data|' \
    -e 's|^;listen.group = .*|listen.group = www-data|' \
    -e 's|^;listen.mode = .*|listen.mode = 0660|' \
    -e 's|^pm = .*|pm = dynamic|' \
    -e "s|^pm.max_children = .*|pm.max_children = ${max_ch}|" \
    -e "s|^pm.start_servers = .*|pm.start_servers = ${start_sv}|" \
    -e "s|^pm.min_spare_servers = .*|pm.min_spare_servers = ${min_sp}|" \
    -e "s|^pm.max_spare_servers = .*|pm.max_spare_servers = ${max_sp}|" \
    -e 's|^;pm.max_requests = .*|pm.max_requests = 500|' \
    "$pool_conf"

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
expose_php           = Off

; OPcache (important for performance)
opcache.enable                  = 1
opcache.enable_cli              = 0
opcache.memory_consumption      = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files   = 10000
opcache.revalidate_freq         = 2
opcache.fast_shutdown           = 1
opcache.validate_timestamps     = 1
opcache.save_comments           = 1
PHPINI

  run systemctl restart "php${ver}-fpm" || warn "Could not restart php${ver}-fpm"
  ok "PHP-FPM ${ver} configured (max_children=${max_ch})"
}

_install_all_php_versions() {
  log "Writing PHP policy..."
  mkdir -p /etc/aidipanel
  cat > /etc/aidipanel/php.conf <<'PHPCONF'
# /etc/aidipanel/php.conf — AidiPanel PHP version policy (single source of truth).
# Written by install.sh; sourced by the CLI; parsed by the panel.
PHP_DEFAULT_VERSION="8.4"
PHP_AVAILABLE_VERSIONS="8.2 8.3 8.4 8.5"
PHP_EXTENSIONS="fpm cli common mysql sqlite3 redis xml mbstring curl zip gd intl bcmath soap imagick opcache"
PHPCONF
  chmod 644 /etc/aidipanel/php.conf

  # shellcheck source=/dev/null
  source /etc/aidipanel/php.conf

  log "Installing PHP ${PHP_DEFAULT_VERSION} (default)..."
  _install_php_version "$PHP_DEFAULT_VERSION"
  ui_ok "PHP ${PHP_DEFAULT_VERSION} installed"
  _configure_php_fpm "$PHP_DEFAULT_VERSION"
  ui_ok "PHP ${PHP_DEFAULT_VERSION}-FPM tuned: max_children=${PHP_MAXCHILDREN:-?}"
  ok "Default PHP installed: ${PHP_DEFAULT_VERSION} (others available on-demand)"
}

# ---------------------------------------------------------------------------
# 10. DATABASE
# ---------------------------------------------------------------------------
_db_repo_component() {
  case "$DB_ENGINE" in
    mysql80) echo "mysql-8.0" ;;
    mysql84) echo "mysql-8.4-lts" ;;
    *)       echo "" ;;
  esac
}

_db_package_name() {
  case "$DB_ENGINE" in
    mysql80|mysql84) echo "mysql-community-server" ;;
    mariadb*)        echo "mariadb-server" ;;
  esac
}

_db_service_name() {
  case "$DB_ENGINE" in
    mysql80|mysql84)   echo "mysql" ;;
    mariadb*)          echo "mariadb" ;;
  esac
}

_db_client_binary() {
  case "$DB_ENGINE" in
    mysql80|mysql84) echo "mysql" ;;
    mariadb*)        echo "mariadb" ;;
  esac
}

_add_mysql_repo() {
  [[ "$DB_ENGINE" == mysql80 || "$DB_ENGINE" == mysql84 ]] || return 0
  if [[ -f /etc/apt/sources.list.d/mysql.list ]]; then
    log "MySQL apt repo already configured — skipping"
    return 0
  fi

  local mysql_version mysql_component
  [[ "$DB_ENGINE" == "mysql80" ]] && mysql_version="8.0" || mysql_version="8.4"
  mysql_component=$(_db_repo_component)
  [[ -n "$mysql_component" ]] || die "Unsupported MySQL engine: ${DB_ENGINE}"
  log "Adding MySQL ${mysql_version} apt repository (${mysql_component})..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local apt_arch; apt_arch=$(_apt_arch)
  # MySQL signing key. The standalone file at repo.mysql.com/RPM-GPG-KEY-mysql-2023 is
  # still served with its original 2025-10-22 expiry — Oracle renewed the key (to
  # 2027-10-23) but did not refresh that file — so a fresh fetch yields an EXPKEYSIG and
  # apt rejects the repo. Pull the renewed key from the Ubuntu keyserver first; fall back
  # to the published file if the keyserver is unreachable.
  local mysql_key="B7B3B788A8D3785C" tmp_gpg
  tmp_gpg=$(mktemp -d)
  if gpg --homedir "$tmp_gpg" --batch --keyserver keyserver.ubuntu.com \
       --recv-keys "$mysql_key" >>"$PANEL_LOG" 2>&1; then
    gpg --homedir "$tmp_gpg" --export "$mysql_key" > /etc/apt/trusted.gpg.d/mysql.gpg
  else
    warn "MySQL keyserver unreachable; falling back to repo.mysql.com key file."
    curl -fsSL "https://repo.mysql.com/RPM-GPG-KEY-mysql-2023" \
      | gpg --dearmor -o /etc/apt/trusted.gpg.d/mysql.gpg 2>>"$PANEL_LOG"
  fi
  rm -rf "$tmp_gpg"
  chmod 644 /etc/apt/trusted.gpg.d/mysql.gpg

  cat > /etc/apt/sources.list.d/mysql.list <<MYSQL_REPO
deb [arch=${apt_arch} signed-by=/etc/apt/trusted.gpg.d/mysql.gpg] http://repo.mysql.com/apt/${OS_ID} ${OS_CODENAME} ${mysql_component}
MYSQL_REPO

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

  curl -fsSL "https://downloads.mariadb.com/MariaDB/mariadb_repo_setup" \
    | bash -s -- --mariadb-server-version="mariadb-${mariadb_version}" >> "$PANEL_LOG" 2>&1

  _apt_update
  ok "MariaDB ${mariadb_version} repo added"
}

_ensure_db_root_pass() {
  if [[ -z "$DB_ROOT_PASS" ]]; then
    DB_ROOT_PASS=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)
    log "Generated DB root password"
  fi
  return 0
}

_preseed_mysql_root_password() {
  [[ "$DB_ENGINE" == mysql* ]] || return 0
  debconf-set-selections <<< "mysql-community-server mysql-community-server/root-pass password ${DB_ROOT_PASS}"
  debconf-set-selections <<< "mysql-community-server mysql-community-server/re-root-pass password ${DB_ROOT_PASS}"
  debconf-set-selections <<< "mysql-server mysql-server/root_password password ${DB_ROOT_PASS}"
  debconf-set-selections <<< "mysql-server mysql-server/root_password_again password ${DB_ROOT_PASS}"
  return 0
}

_assert_db_package_candidate() {
  [[ "$DB_ENGINE" == mysql* ]] || return 0

  local pkg="$1" expected_major candidate
  case "$DB_ENGINE" in
    mysql80) expected_major="8.0." ;;
    mysql84) expected_major="8.4." ;;
    *) die "Unsupported MySQL engine: ${DB_ENGINE}" ;;
  esac

  candidate=$(apt-cache policy "$pkg" | awk '/Candidate:/ {candidate=$2} END {print candidate}')
  [[ -n "$candidate" && "$candidate" != "(none)" ]] \
    || die "No install candidate found for ${pkg}. Check the MySQL APT repository component."

  [[ "$candidate" == ${expected_major}* ]] \
    || die "${DB_ENGINE_LABELS[$DB_ENGINE]} package candidate is ${candidate}, expected ${expected_major}x from Oracle."

  return 0
}

_install_database() {
  local pkg; pkg=$(_db_package_name)
  local svc; svc=$(_db_service_name)

  if _pkg_installed "$pkg"; then
    log "${DB_ENGINE_LABELS[$DB_ENGINE]} already installed — skipping"
    return 0
  fi

  _assert_db_package_candidate "$pkg"

  log "Installing ${DB_ENGINE_LABELS[$DB_ENGINE]}..."
  _preseed_mysql_root_password

  _apt_install "$pkg"
  run systemctl enable --now "$svc"
  ok "${DB_ENGINE_LABELS[$DB_ENGINE]} installed"
}

_configure_database() {
  log "Securing database installation..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  [[ -n "$DB_ROOT_PASS" ]] || die "Database root password was not initialized."

  # Use arrays so bash splits args correctly (string variable = "command not found" bug).
  # MariaDB starts with socket-auth root access; MySQL has already been preseeded
  # with DB_ROOT_PASS, so password auth must be used from the first SQL statement.
  local -a db_cmd_bootstrap db_cmd_auth
  if [[ "$DB_ENGINE" == mariadb* ]]; then
    db_cmd_bootstrap=(mariadb -u root)
    db_cmd_auth=(mariadb -u root "-p${DB_ROOT_PASS}")
  else
    db_cmd_bootstrap=(mysql -u root "-p${DB_ROOT_PASS}")
    db_cmd_auth=(mysql -u root "-p${DB_ROOT_PASS}")
  fi

  # Secure the installation
  "${db_cmd_bootstrap[@]}" 2>>"$PANEL_LOG" <<MYSQL_SECURE
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
MYSQL_SECURE

  local PANEL_DB_PASS
  PANEL_DB_PASS=$(openssl rand -base64 20 | tr -dc 'A-Za-z0-9' | head -c 20)

  "${db_cmd_auth[@]}" 2>>"$PANEL_LOG" <<PANEL_DB
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${PANEL_USER}'@'localhost' IDENTIFIED BY '${PANEL_DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${PANEL_USER}'@'localhost';
FLUSH PRIVILEGES;
PANEL_DB

  mkdir -p "$PANEL_DIR"
  chmod 700 "$PANEL_DIR"
  {
    echo "# AidiPanel Credentials — KEEP THIS FILE SECURE"
    echo "# Generated: $(date)"
    credential_line DB_ENGINE "$DB_ENGINE"
    credential_line DB_ENGINE_LABEL "${DB_ENGINE_LABELS[$DB_ENGINE]}"
    credential_line DB_ROOT_PASSWORD "$DB_ROOT_PASS"
    credential_line PANEL_DB_NAME "$DB_NAME"
    credential_line PANEL_DB_USER "$PANEL_USER"
    credential_line PANEL_DB_PASSWORD "$PANEL_DB_PASS"
    credential_line MYSQL_ROOT_PASSWORD "$DB_ROOT_PASS"
  } > "${PANEL_DIR}/credentials.conf"
  chmod 600 "${PANEL_DIR}/credentials.conf"

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
# 11. REDIS — lightweight config
# ---------------------------------------------------------------------------
_install_redis() {
  if [[ "$INSTALL_REDIS" == "false" ]]; then
    log "Skipping Redis (--no-redis)"
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
  log "Configuring Redis (lightweight)..."

  local redis_conf="/etc/redis/redis.conf"
  [[ -f "$redis_conf" ]] || { warn "Redis config not found; skipping"; return 0; }
  cp "$redis_conf" "${redis_conf}.bak.$(date +%s)"

  # Adaptive maxmemory based on RAM
  local mem_mb; mem_mb=$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo)
  local redis_maxmem="128mb"
  (( mem_mb >= 2048 )) && redis_maxmem="256mb"
  (( mem_mb >= 4096 )) && redis_maxmem="512mb"

  sed -i \
    -e 's/^bind .*/bind 127.0.0.1 ::1/' \
    -e "s/^# maxmemory .*/maxmemory ${redis_maxmem}/" \
    -e 's/^# maxmemory-policy .*/maxmemory-policy allkeys-lru/' \
    -e 's/^supervised no/supervised systemd/' \
    -e 's/^save 900 1/#save 900 1/' \
    -e 's/^save 300 10/#save 300 10/' \
    -e 's/^save 60 10000/#save 60 10000/' \
    "$redis_conf"

  # Per-site ACL isolation (backlog #6): point redis.conf at an aclfile so each
  # site can later get its own scoped Redis identity. The empty file here is a
  # no-op for behavior — `default` stays open; the admin user + `default off`
  # lock are applied by `cache:redis-acl`. Refuse if ACLs are externally managed.
  if ! grep -qE '^[[:space:]]*user[[:space:]]' "$redis_conf" 2>/dev/null; then
    local acl_file="/etc/redis/users.acl"
    if [[ ! -f "$acl_file" ]]; then
      : > "$acl_file"
      chown redis:redis "$acl_file" 2>/dev/null || true
      chmod 640 "$acl_file" 2>/dev/null || true
    fi
    grep -qE "^[[:space:]]*aclfile[[:space:]]+${acl_file}" "$redis_conf" 2>/dev/null \
      || printf '\n# AidiPanel — per-site Redis ACL isolation (backlog #6)\naclfile %s\n' "$acl_file" >> "$redis_conf"
  fi

  run systemctl restart redis-server
  ok "Redis configured (maxmemory: ${redis_maxmem}, policy: allkeys-lru, persistence: off, aclfile)"
}

_install_wpcli() {
  if command -v wp &>/dev/null; then
    log "WP-CLI already installed — skipping"
    return 0
  fi
  log "Installing WP-CLI..."
  if curl -fsSL -o /usr/local/bin/wp \
      "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" \
      2>/dev/null; then
    chmod +x /usr/local/bin/wp
    ok "WP-CLI installed: /usr/local/bin/wp"
  else
    warn "WP-CLI download failed (non-fatal) — object cache management will be limited"
  fi
  return 0
}

# ---------------------------------------------------------------------------
# 12. UFW FIREWALL
# ---------------------------------------------------------------------------
_configure_firewall() {
  log "Configuring UFW firewall..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  run_quiet "Security" "configure UFW rules" bash -c "
    set -e
    ufw --force reset >/dev/null 2>&1 || true
    ufw default deny incoming
    ufw default allow outgoing
    ufw allow ssh comment 'SSH'
    ufw allow 80/tcp comment 'HTTP'
    ufw allow 443/tcp comment 'HTTPS'
    ufw allow ${PANEL_PORT}/tcp comment 'AidiPanel'
    ufw --force enable"
  ok "UFW enabled — allowed ports: 22, 80, 443, ${PANEL_PORT}"
}

# ---------------------------------------------------------------------------
# 13. CERTBOT / SSL
# ---------------------------------------------------------------------------
_install_certbot() {
  log "Installing Certbot (Let's Encrypt)..."
  if _pkg_installed certbot && _pkg_installed python3-certbot-nginx; then
    log "Certbot + nginx plugin already installed — skipping"
    return 0
  fi
  _apt_install certbot python3-certbot-nginx
  ok "Certbot installed"
}

# ---------------------------------------------------------------------------
# 14. PROFTPD (opt-in; NOT installed by default — see the call site in main())
# ---------------------------------------------------------------------------
_install_proftpd() {
  log "Installing ProFTPD..."
  if _pkg_installed proftpd-basic && _pkg_installed proftpd-mod-crypto; then
    log "ProFTPD already installed — reusing package"
  else
    _apt_install proftpd-basic proftpd-mod-crypto
  fi
  [[ "$DRY_RUN" == "true" ]] && return 0

  if [[ -f /etc/proftpd/proftpd.conf ]]; then
    # Prevent the stock FTP listener on port 21; AidiPanel exposes ProFTPD as SFTP on 2022.
    sed -i -E 's/^[[:space:]]*Port[[:space:]]+[0-9]+[[:space:]]*$/Port 2022/' /etc/proftpd/proftpd.conf
    sed -i -E 's/^[[:space:]]*ListOptions[[:space:]].*$/# &/' /etc/proftpd/proftpd.conf
  fi

  cat > /etc/proftpd/conf.d/aidipanel.conf <<'PROFTPD_CONF'
# AidiPanel ProFTPD - SFTP only
LoadModule mod_sftp.c
Port 2022
SFTPEngine on
SFTPLog /var/log/proftpd/sftp.log
SFTPHostKey /etc/proftpd/ssh_host_rsa_key
SFTPCompression delayed
AuthOrder mod_auth_pam.c mod_auth_unix.c
TransferLog /var/log/proftpd/xferlog
SystemLog /var/log/proftpd/proftpd.log
PROFTPD_CONF

  if [[ ! -f /etc/proftpd/ssh_host_rsa_key ]]; then
    ssh-keygen -q -t rsa -b 4096 -N '' -f /etc/proftpd/ssh_host_rsa_key
  fi

  run systemctl enable proftpd
  run systemctl restart proftpd
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
    "${PANEL_DIR}/storage/db" \
    "${PANEL_DIR}/config" \
    "${SITES_DIR}"

  cat > "${PANEL_DIR}/config/panel.conf" <<PANELCONF
# AidiPanel Configuration — Generated by installer v${PANEL_VERSION}
# $(date)

PANEL_VERSION=${PANEL_VERSION}
PANEL_PORT=${PANEL_PORT}
PANEL_HOSTNAME=
PANEL_DIR=${PANEL_DIR}
SITES_DIR=${SITES_DIR}
NGINX_CACHE_DIR=${NGINX_CACHE_DIR}
NGINX_CACHE_ZONE=${NGINX_CACHE_ZONE}
PHP_DEFAULT_VERSION=${PHP_DEFAULT_VERSION}
INSTALL_REDIS=${INSTALL_REDIS}
OS_ID=${OS_ID}
OS_VERSION_ID=${OS_VERSION_ID}
OS_CODENAME=${OS_CODENAME}
INSTALLED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
PANELCONF

  # WordPress vhost template
  cat > "${PANEL_DIR}/config/vhost-template-wordpress.conf" <<'VHOST_WP'
# AidiPanel Vhost Template — WordPress with FastCGI Cache
# Variables replaced at site creation: %%DOMAIN%%, %%PHP_VERSION%%, %%WEBROOT%%

server {
    listen 80;
    listen [::]:80;
    server_name %%DOMAIN%% www.%%DOMAIN%%;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name %%DOMAIN%% www.%%DOMAIN%%;

    root %%WEBROOT%%;
    index index.php index.html;

    ssl_certificate     /etc/ssl/%%DOMAIN%%/fullchain.pem;
    ssl_certificate_key /etc/ssl/%%DOMAIN%%/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_stapling        on;
    ssl_stapling_verify on;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-FastCGI-Cache $upstream_cache_status always;

    include /etc/nginx/snippets/fastcgi-cache.conf;

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|webp|avif|mp4|webm)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
        log_not_found off;
    }

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ /\. { deny all; }
    location ~ ^/(wp-config\.php|xmlrpc\.php|wp-cron\.php)$ { deny all; }
    location ~* /(?:uploads|files)/.*\.php$ { deny all; }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php%%PHP_VERSION%%-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        #fastcgi_cache        aidipanel_fcgi;
        # AidiPanel caches public 404 responses for 60 seconds when the FastCGI
        # page cache is enabled. This short TTL absorbs repeated bot/scanner probes
        # without keeping stale 404s for long. Server errors are never cached —
        # only the codes listed below are.
        #fastcgi_cache_valid  200 301 302 1h;
        #fastcgi_cache_valid  404 1m;
        #fastcgi_cache_bypass  $skip_cache;
        #fastcgi_no_cache      $skip_cache;

        fastcgi_connect_timeout 60s;
        fastcgi_send_timeout    180s;
        fastcgi_read_timeout    180s;
        fastcgi_buffer_size     64k;
        fastcgi_buffers         8 128k;
    }

    access_log /var/log/nginx/%%DOMAIN%%-access.log main buffer=8k;
    error_log  /var/log/nginx/%%DOMAIN%%-error.log warn;
}
VHOST_WP

  chown -R "$PANEL_USER":www-data "$PANEL_DIR"
  chmod 750 "$PANEL_DIR"
  ok "Panel directory structure created: ${PANEL_DIR}"
}

# ---------------------------------------------------------------------------
# 15b. DEDICATED PANEL PHP-FPM  (isolates the panel from per-site reloads)
# ---------------------------------------------------------------------------
# The panel must keep answering while sites are created/deleted. site:add /
# site:delete run `systemctl reload php<ver>-fpm`; if the panel shared that
# service, the reload would cycle its worker mid-request -> 502. A dedicated
# service (own master + socket) is never touched by those reloads.
# Runtime user = www-data (TRANSITIONAL; a dedicated aidipanel runtime user is a
# later hardening slice — see the installer-runtime-maturity spec).
_setup_panel_fpm() {
  log "Setting up dedicated panel PHP-FPM service (aidipanel-fpm)..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping aidipanel-fpm setup"; return 0; }

  local conf_dir="/etc/aidipanel/php-fpm"
  local conf="${conf_dir}/php-fpm.conf"
  local fpm_bin="/usr/sbin/php-fpm${PHP_DEFAULT_VERSION}"
  [[ -x "$fpm_bin" ]] || die "PHP-FPM binary not found: ${fpm_bin} (is php${PHP_DEFAULT_VERSION}-fpm installed?)"

  mkdir -p "$conf_dir"

  # Quoted heredoc: nothing here should be expanded by the installer shell.
  cat > "$conf" <<'PANELFPM'
; AidiPanel dedicated PHP-FPM — serves ONLY the panel UI.
; Isolated from php<ver>-fpm so site:add/site:delete reloads never cycle the
; panel worker (the create/delete 502 fix). Runtime user = www-data
; (TRANSITIONAL — a dedicated aidipanel runtime user is a later hardening slice).
[global]
error_log = /var/log/aidipanel-fpm.log
log_level = warning
daemonize = no

[aidipanel]
user = www-data
group = www-data
listen = /run/aidipanel-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
pm.max_requests = 200
php_admin_value[error_log] = /var/log/aidipanel-fpm.log
php_admin_flag[log_errors] = on
PANELFPM

  # Unquoted heredoc: ${fpm_bin}/${conf} expand; \$MAINPID is a systemd runtime
  # variable and MUST stay literal (escaped) so it is not eaten by the shell.
  cat > /etc/systemd/system/aidipanel-fpm.service <<UNIT
[Unit]
Description=AidiPanel dedicated PHP-FPM (panel UI runtime, isolated from site pools)
After=network.target

[Service]
Type=notify
ExecStart=${fpm_bin} --nodaemonize --fpm-config ${conf}
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT

  # Fail loud on a bad global/pool config BEFORE trying to start the service.
  "$fpm_bin" --fpm-config "$conf" -t >> "$PANEL_LOG" 2>&1 \
    || die "aidipanel-fpm config test failed — check: ${PANEL_LOG}"

  systemctl daemon-reload >> "$PANEL_LOG" 2>&1
  systemctl enable --now aidipanel-fpm >> "$PANEL_LOG" 2>&1 \
    || die "Could not start aidipanel-fpm — check: ${PANEL_LOG} and 'journalctl -u aidipanel-fpm'"

  ok "Dedicated panel PHP-FPM active: aidipanel-fpm → /run/aidipanel-fpm.sock (www-data, transitional)"
}

# ---------------------------------------------------------------------------
# 16. PANEL NGINX VHOST
# ---------------------------------------------------------------------------
_configure_panel_vhost() {
  log "Creating AidiPanel web UI Nginx vhost (port ${PANEL_PORT})..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local ssl_dir="/etc/ssl/aidipanel"
  mkdir -p "$ssl_dir"
  if [[ ! -f "${ssl_dir}/aidipanel.crt" ]]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
      -keyout "${ssl_dir}/aidipanel.key" \
      -out "${ssl_dir}/aidipanel.crt" \
      -subj "/C=ID/ST=Jakarta/L=Jakarta/O=AidiPanel/OU=Panel/CN=localhost" \
      >> "$PANEL_LOG" 2>&1
    ok "Self-signed SSL certificate generated"
  fi

  cat > /etc/nginx/sites-available/aidipanel-ui.conf <<PANEL_VHOST
# AidiPanel Web UI — port ${PANEL_PORT}
server {
    listen ${PANEL_PORT} ssl;
    listen [::]:${PANEL_PORT} ssl;
    http2 on;
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
        fastcgi_pass unix:/run/aidipanel-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 8 32k;
    }

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
# 17. INSTALL CLI TOOL
# ---------------------------------------------------------------------------
_install_cli() {
  log "Installing AidiPanel CLI tool..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping CLI install"; return 0; }

  # The CLI is bundled inside this installer's directory or fetched from GitHub
  local cli_src
  # Check if aidipanel CLI exists beside this script
  local script_dir; script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  if [[ -f "${script_dir}/aidipanel" ]]; then
    cli_src="${script_dir}/aidipanel"
  else
    # Download from GitHub
    log "Downloading AidiPanel CLI from GitHub..."
    cli_src="/tmp/aidipanel-cli-$$"
    curl -fsSL "https://raw.githubusercontent.com/rezzaidr/AidiPanel/master/aidipanel" \
      -o "$cli_src" 2>>"$PANEL_LOG" || { warn "Could not download CLI — install manually"; return 0; }
  fi

  cp "$cli_src" /usr/local/bin/aidipanel
  chmod +x /usr/local/bin/aidipanel
  ok "AidiPanel CLI installed: /usr/local/bin/aidipanel"
}

# ---------------------------------------------------------------------------
# FIX #5: DEPLOY PANEL APP AUTOMATICALLY (one-command install)
# ---------------------------------------------------------------------------
_find_downloaded_panel_app() {
  local search_root="$1"
  local public_dir

  public_dir="$(find "$search_root" -mindepth 2 -maxdepth 4 -type d -path '*/panel-app/public' -print -quit 2>/dev/null || true)"
  [[ -n "$public_dir" ]] || return 1

  dirname "$public_dir"
}

_deploy_panel_app() {
  log "Deploying AidiPanel web application..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping panel app deploy"; return 0; }

  local script_dir; script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

  # Try local panel-app directory first (when running from extracted ZIP)
  if [[ -d "${script_dir}/panel-app/public" ]]; then
    log "Deploying panel app from local directory..."
    _do_deploy_app "${script_dir}/panel-app"
    return 0
  fi

  # Try aidipanel-app directory (legacy name)
  if [[ -d "${script_dir}/aidipanel-app/public" ]]; then
    log "Deploying panel app from aidipanel-app directory..."
    _do_deploy_app "${script_dir}/aidipanel-app"
    return 0
  fi

  log "Panel app directory not found locally; downloading from GitHub..."
  local tmp_dir downloaded_app
  tmp_dir="$(mktemp -d)"
  curl -fsSL --retry 3 --connect-timeout 15 \
    "https://github.com/rezzaidr/AidiPanel/archive/refs/heads/master.tar.gz" \
    -o "${tmp_dir}/aidipanel.tar.gz" \
    || { rm -rf "$tmp_dir"; die "Failed to download AidiPanel repository archive."; }
  tar -xzf "${tmp_dir}/aidipanel.tar.gz" -C "$tmp_dir" \
    || { rm -rf "$tmp_dir"; die "Failed to extract AidiPanel repository archive."; }
  downloaded_app="$(_find_downloaded_panel_app "$tmp_dir")" \
    || { rm -rf "$tmp_dir"; die "Downloaded archive does not contain panel-app/public."; }
  _do_deploy_app "$downloaded_app"
  rm -rf "$tmp_dir"
  return 0
}

_do_deploy_app() {
  local app_src="$1"

  # FIX #4: Generate random admin password before seeding DB
  PANEL_ADMIN_PASS=$(openssl rand -base64 16 | tr -dc 'A-Za-z0-9!@#$%' | head -c 16)

  # Copy app files
  cp -r "${app_src}/public/"* "${PANEL_DIR}/public/"
  cp -r "${app_src}/app"       "${PANEL_DIR}/"
  [[ -d "${app_src}/bin" ]] && cp -r "${app_src}/bin" "${PANEL_DIR}/"   # CLI helpers (metrics collector)

  # Storage dirs with correct permissions for www-data write access
  mkdir -p "${PANEL_DIR}/storage/db" \
           "${PANEL_DIR}/storage/logs" \
           "${PANEL_DIR}/storage/cache" \
           "${PANEL_DIR}/storage/tmp/vhost" \
           "${PANEL_DIR}/storage/backups"

  # Permissions
  chown -R "${PANEL_USER}":www-data "${PANEL_DIR}/app"
  chown -R "${PANEL_USER}":www-data "${PANEL_DIR}/public"
  if [[ -d "${PANEL_DIR}/bin" ]]; then
    chown -R "${PANEL_USER}":www-data "${PANEL_DIR}/bin"
    chmod 750 "${PANEL_DIR}/bin"
    find "${PANEL_DIR}/bin" -type f -exec chmod 640 {} \;
  fi
  chown -R www-data:www-data "${PANEL_DIR}/storage"
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

  ok "Panel app deployed to ${PANEL_DIR}"

  # Initialize SQLite and write the admin hash directly into the database.
  local php_bin="php${PHP_DEFAULT_VERSION}"
  command -v "$php_bin" >/dev/null 2>&1 || php_bin="php"
  command -v "$php_bin" >/dev/null 2>&1 || die "PHP CLI not found; cannot seed panel admin password."
  command -v sqlite3 >/dev/null 2>&1 || die "sqlite3 not found; cannot seed panel admin password."

  "$php_bin" -r "
define('PANEL_DIR', '${PANEL_DIR}');
define('APP_ROOT', '${PANEL_DIR}/app');
define('PANEL_VERSION', '${PANEL_VERSION}');
require '${PANEL_DIR}/app/Core/DB.php';
\Core\DB::instance();
" >> "$PANEL_LOG" 2>&1 || die "Failed to initialize panel SQLite database."

  local sqlite_db="${PANEL_DIR}/storage/db/aidipanel.sqlite"
  [[ -f "$sqlite_db" ]] || die "Panel SQLite DB was not created: ${sqlite_db}"

  local hashed_pass
  hashed_pass=$(AIDIPANEL_PASS="$PANEL_ADMIN_PASS" "$php_bin" -r 'echo password_hash(getenv("AIDIPANEL_PASS"), PASSWORD_BCRYPT, ["cost" => 12]);')
  sqlite3 "$sqlite_db" <<SQLITE
INSERT OR IGNORE INTO users (username, password_hash, role, active)
VALUES ('admin', '${hashed_pass}', 'admin', 1);
UPDATE users SET password_hash='${hashed_pass}', role='admin', active=1 WHERE username='admin';
SQLITE
  chown www-data:www-data "$sqlite_db" "$sqlite_db-shm" "$sqlite_db-wal" 2>/dev/null || true
  chmod 660 "$sqlite_db" 2>/dev/null || true
  ok "Admin password hash written to panel SQLite database"
}

# ---------------------------------------------------------------------------
# 18. FAIL2BAN
# ---------------------------------------------------------------------------
_configure_sudoers() {
  log "Configuring sudoers for AidiPanel web panel..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local wrapper="/usr/local/sbin/aidipanel-web-run"
  cat > "$wrapper" <<'WRAPPER'
#!/usr/bin/env bash
set -Eeuo pipefail
export NO_COLOR=1
cmd="${1:-}"
case "$cmd" in
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
WRAPPER
  chown root:root "$wrapper"
  chmod 750 "$wrapper"

  local sudoers_file="/etc/sudoers.d/aidipanel"
  cat > "$sudoers_file" <<'SUDOERS'
# AidiPanel - allow the web panel to run the controlled CLI wrapper as root
www-data ALL=(root) NOPASSWD: /usr/local/sbin/aidipanel-web-run *
SUDOERS
  chmod 440 "$sudoers_file"

  # Validate sudoers file
  visudo -c -f "$sudoers_file" >> "$PANEL_LOG" 2>&1 \
    || { warn "sudoers validation failed - removing"; rm -f "$sudoers_file"; return 1; }

  ok "Sudoers configured: www-data can run AidiPanel wrapper as root"
}

_configure_fail2ban() {
  log "Configuring Fail2ban..."
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
# 19. CRON JOBS
# ---------------------------------------------------------------------------
_setup_cron() {
  log "Setting up AidiPanel maintenance cron jobs..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  cat > /etc/cron.d/aidipanel <<'CRONFILE'
# AidiPanel maintenance jobs
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# Renew Let's Encrypt certificates daily at 2:30 AM
30 2 * * * root certbot renew --quiet >> /var/log/aidipanel-certbot.log 2>&1

# Purge FastCGI cache older than 1 day
0 4 * * * root find /var/cache/nginx/fastcgi -type f -atime +1 -delete >> /var/log/aidipanel-install.log 2>&1

# Rotate AidiPanel logs weekly
0 0 * * 0 root find /var/log/nginx -name "*.log" -size +100M -exec gzip {} \; 2>/dev/null

# Collect system metrics every minute (dashboard Monitoring history charts)
* * * * * www-data /usr/bin/php /opt/aidipanel/bin/collect-metrics.php >/dev/null 2>&1
CRONFILE

  chmod 644 /etc/cron.d/aidipanel
  ok "Cron jobs configured"
}

_provision_cloudflare_realip() {
  log "Provisioning the global Cloudflare real-IP foundation..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping Cloudflare real-IP provision"; return 0; }
  /usr/local/bin/aidipanel cloudflare:realip --action provision >> "$PANEL_LOG" 2>&1
  ok "Cloudflare real-IP foundation provisioned"
}

# ---------------------------------------------------------------------------
# 20. TEST & START SERVICES
# ---------------------------------------------------------------------------
_test_and_start_services() {
  log "Testing Nginx configuration..."
  [[ "$DRY_RUN" == "true" ]] && { warn "[dry-run] skipping nginx test & service start"; return 0; }

  nginx -t >> "$PANEL_LOG" 2>&1 \
    || die "Nginx configuration test FAILED. Check: nginx -t"
  ok "Nginx config: OK"

  local services=("nginx" "php${PHP_DEFAULT_VERSION}-fpm")
  services+=("$(_db_service_name)")
  [[ "$INSTALL_REDIS" == "true" ]] && services+=("redis-server")

  for svc in "${services[@]}"; do
    log "Enabling and starting: ${svc}..."
    systemctl enable --now "$svc" >> "$PANEL_LOG" 2>&1 \
      || warn "Could not start ${svc} — check manually"
  done

  ok "All services started"
}

# ---------------------------------------------------------------------------
# 21. HEALTH CHECK
# ---------------------------------------------------------------------------
_health_check() {
  log "Running post-install health check..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local failed=0
  _matrix()  { printf '%-22s %s\n' "$1" "$2"; }
  _svc_row() { if systemctl is-active --quiet "$1"; then _matrix "$1" "running"; else _matrix "$1" "NOT running"; failed=$((failed+1)); fi; }

  ui_section "Verification Matrix"
  _svc_row nginx
  _svc_row "php${PHP_DEFAULT_VERSION}-fpm"
  _svc_row aidipanel-fpm
  _svc_row "$(_db_service_name)"
  [[ "$INSTALL_REDIS" == "true" ]] && _svc_row redis-server
  if [[ -d "$NGINX_CACHE_DIR" ]]; then _matrix "FastCGI cache" "ready"; else _matrix "FastCGI cache" "missing"; failed=$((failed+1)); fi
  if curl -ksfS --max-time 5 "https://127.0.0.1:${PANEL_PORT}/" -o /dev/null; then _matrix "AidiPanel port ${PANEL_PORT}" "responding"; else _matrix "AidiPanel port ${PANEL_PORT}" "no response"; fi
  printf '\n'
  if (( failed > 0 )); then ui_kv "Result" "${failed} issue(s) — see ${PANEL_LOG}"; else ui_kv "Result" "all checks passed"; fi
}

# ---------------------------------------------------------------------------
# 22. CLEANUP
# ---------------------------------------------------------------------------
_cleanup_system() {
  log "Cleaning up..."
  [[ "$DRY_RUN" == "true" ]] && return 0
  apt-get autoremove -y -qq >> "$PANEL_LOG" 2>&1 || true
  apt-get clean >> "$PANEL_LOG" 2>&1 || true
  rm -f /tmp/aidipanel-* 2>/dev/null || true
  ok "Cleanup done"
}

# ---------------------------------------------------------------------------
# 23. SUMMARY — FIX #4: Show random panel password
# ---------------------------------------------------------------------------

# True when $1 is a private / link-local / loopback address — i.e. NOT a routable
# public IP. NAT clouds (Tencent, AWS, GCP, Oracle, Alibaba) only put a private IP
# on the interface; the real public IP is mapped at the gateway.
_is_private_ip() {
  case "$1" in
    10.*|192.168.*|127.*|169.254.*)         return 0 ;;
    172.1[6-9].*|172.2[0-9].*|172.3[0-1].*) return 0 ;;
    *)                                      return 1 ;;
  esac
}

# Best-effort public IP via an HTTPS echo. Called ONLY when the interface IP is
# private/empty, so installs where the public IP is on the NIC (DigitalOcean,
# Hetzner, Vultr, Linode) make zero extra calls. Prints nothing if all probes fail.
_detect_public_ip() {
  local url ip
  for url in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
    ip=$(curl -fsS --max-time 4 "$url" 2>/dev/null | tr -dc '0-9.')
    [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] && { printf '%s\n' "$ip"; return 0; }
  done
  return 1
}

_print_summary() {
  local server_ip
  # Interface src is correct where the public IP is bound to the NIC; on NAT clouds
  # it returns a private IP, so resolve the real public one (the panel listens on
  # all interfaces, so it's reachable there once the cloud firewall allows the port).
  server_ip=$(ip route get 8.8.8.8 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") {print $(i+1); exit}}')
  if [[ -z "$server_ip" ]] || _is_private_ip "$server_ip"; then
    local public_ip; public_ip=$(_detect_public_ip || true)
    [[ -n "$public_ip" ]] && server_ip="$public_ip"
  fi
  server_ip="${server_ip:-<server-ip>}"

  [[ "$DRY_RUN" == "true" ]] && return 0

  local dur; dur=$(printf '%02dm %02ds' $(( SECONDS/60 )) $(( SECONDS%60 )))

  ui_section "Transformation Summary"
  printf '%-30s %s\n' "Before" "After"
  printf '%-30s %s %s\n' "Plain Ubuntu VPS" "$(_u '→' '->')" "Managed LEMP server"
  printf '%-30s %s %s\n' "No web engine"     "$(_u '→' '->')" "Nginx ${NGINX_VERSION:-installed}"
  printf '%-30s %s %s\n' "No PHP runtime"    "$(_u '→' '->')" "PHP-FPM $(_php_list)"
  printf '%-30s %s %s\n' "No database layer" "$(_u '→' '->')" "${DB_ENGINE_LABELS[$DB_ENGINE]%% (*}"
  printf '%-30s %s %s\n' "No cache layer"    "$(_u '→' '->')" "Redis + FastCGI Cache"
  printf '%-30s %s %s\n' "No control panel"  "$(_u '→' '->')" "AidiPanel on port ${PANEL_PORT}"

  ui_section "Server Passport"
  ui_kv "Panel"       "AidiPanel v${PANEL_VERSION}"
  ui_kv "URL"         "https://${server_ip}:${PANEL_PORT}"
  ui_kv "Login"       "admin"
  ui_kv "Credentials" "${PANEL_DIR}/credentials.conf"
  printf '\n'
  ui_kv "Web Engine"  "Nginx ${NGINX_VERSION:-installed}"
  ui_kv "Runtime"     "PHP-FPM $(_php_list)"
  ui_kv "Database"    "${DB_ENGINE_LABELS[$DB_ENGINE]}"
  ui_kv "Cache"       "Redis + FastCGI Cache"
  ui_kv "Firewall"    "UFW enabled (22, 80, 443, ${PANEL_PORT})"
  ui_kv "Protection"  "Fail2ban enabled"
  printf '\n'
  printf '%s\n' "Panel runtime aidipanel-fpm · www-data (transitional)"
  ui_kv "Site Model"  "/home/<site-user>/htdocs/<domain>"
  ui_kv "Cache Dir"   "${NGINX_CACHE_DIR}"
  ui_kv "Install Log" "${PANEL_LOG}"
  ui_kv "Duration"    "${dur}"

  ui_section "Important"
  ui_note "The panel currently uses a self-signed SSL certificate."
  ui_note "Install trusted SSL later from the SSL/TLS tab or CLI."
  printf '\n'
  ui_note "On a cloud VM (AWS, GCP, Tencent, Oracle, Alibaba), the server's UFW"
  ui_note "  can't open the provider firewall — also allow TCP 80, 443, and"
  ui_note "  ${PANEL_PORT} in its security group, or the panel/sites won't load."
  printf '\n'
  ui_note "Credentials are stored in:"
  ui_note "  ${PANEL_DIR}/credentials.conf"
  printf '\n'
  ui_note "Keep this file secure."
  if [[ -n "$PANEL_ADMIN_PASS" ]]; then
    printf '\n'
    ui_warn "SAVE THIS — panel admin password (shown only once):"
    printf '\n      %s%s%s\n' "${C_FAIL:-}${C_KEY:-}" "${PANEL_ADMIN_PASS}" "${C_RESET:-}"
  fi

  ui_section "Next Steps"
  printf '1. %s\n' "Cloud VM? First open TCP 80, 443, and ${PANEL_PORT} in your provider's firewall"
  printf '2. %s\n' "Open the Panel URL"
  printf '3. %s\n' "Save/change the admin password"
  printf '4. %s\n' "Add your first isolated site"
  printf '5. %s\n' "Point your domain DNS to this server"
  printf '6. %s\n' "Install trusted SSL for the panel"
  printf '\n'

  # Also save admin pass to credentials file
  if [[ -n "$PANEL_ADMIN_PASS" ]]; then
    credential_line PANEL_ADMIN_USER "admin" >> "${PANEL_DIR}/credentials.conf"
    credential_line PANEL_ADMIN_PASSWORD "$PANEL_ADMIN_PASS" >> "${PANEL_DIR}/credentials.conf"
  fi
}

# ---------------------------------------------------------------------------
# 24. AUTO-CLEANUP - remove the installer directory after completion
# ---------------------------------------------------------------------------
_cleanup_installer() {
  log "Cleaning up installer files..."
  [[ "$DRY_RUN" == "true" ]] && return 0

  local script_path; script_path="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

  # Only remove if the folder is named aidipanel-* under /root or /home
  if [[ "$script_path" =~ ^(/root|/home/[^/]+)/aidipanel-v[0-9] ]]; then
    # Also remove the ZIP if present in the parent dir
    local parent; parent="$(dirname "$script_path")"
    rm -f "${parent}/aidipanel-v"*.zip 2>/dev/null || true
    # Remove the installer folder
    rm -rf "$script_path"
    ok "Installer directory removed: ${script_path}"
  else
    warn "Installer not in standard location (${script_path}) — skipping auto-delete"
    warn "To delete manually: rm -rf ${script_path}"
  fi
}
main() {
  mkdir -p "$(dirname "$PANEL_LOG")"
  touch "$PANEL_LOG"
  chmod 640 "$PANEL_LOG"

  _parse_args "$@"
  _color_init
  _banner
  _check_root
  _check_lock
  _check_already_installed
  _detect_os
  _check_resources
  _check_internet
  _check_port_free
  _check_hostname_resolves
  print_server_profile
  print_readiness_check
  print_provisioning_plan

  local t

  ui_section "Foundation Layer"; ui_note "Preparing the base server environment"; printf '\n'
  t=$SECONDS
  _apt_update;            ui_ok "Package index updated"
  _install_base_packages; ui_ok "Base tools installed"
  _create_swap;           ui_ok "Swap ${SWAP_NOTE:-ready}"
  ui_ok "Foundation ready"
  ui_elapsed "$t"

  ui_section "Web Engine Layer"; ui_note "Building the Nginx + FastCGI Cache layer"; printf '\n'
  t=$SECONDS
  _add_nginx_repo;                ui_ok "Official Nginx repository added"
  _install_nginx
  NGINX_VERSION="$(nginx -v 2>&1 | grep -oP 'nginx/\K[0-9.]+' || echo '?')"
  ui_ok "Nginx installed: ${NGINX_VERSION}"
  _configure_nginx_main;          ui_ok "Optimized nginx.conf written"
  _create_fastcgi_cache_dir;      ui_ok "FastCGI cache directory created"
  _create_fastcgi_params_snippet; ui_ok "FastCGI cache snippet installed"
  ui_elapsed "$t"

  ui_section "Runtime Layer"; ui_note "Installing the default PHP-FPM runtime"; printf '\n'
  printf '%s\n' "Runtime bundle (PHP ${PHP_DEFAULT_VERSION})"
  ui_note "PHP-FPM · CLI · MySQL · Redis · SQLite · XML · mbstring"
  ui_note "curl · zip · GD · intl · bcmath · SOAP · imagick · OPcache"
  ui_note "Versions 8.2 / 8.3 / 8.5 install on-demand (aidipanel php:install)"
  printf '\n'
  t=$SECONDS
  _add_php_repo
  _install_all_php_versions
  ui_elapsed "$t"
  print_blueprint ready pending pending

  ui_section "Data Layer"; ui_note "Installing database and cache services"; printf '\n'
  t=$SECONDS
  _ensure_db_root_pass
  _add_mysql_repo
  _add_mariadb_repo
  ui_ok "${DB_ENGINE_LABELS[$DB_ENGINE]%% (*} repository added"
  _install_database;   ui_ok "${DB_ENGINE_LABELS[$DB_ENGINE]%% (*} installed"
  _configure_database; ui_ok "Database secured"; ui_ok "AidiPanel database created"
  _install_redis;      ui_ok "Redis installed"
  _configure_redis;    ui_ok "Redis configured: 128mb, allkeys-lru, persistence off"
  _install_wpcli;      ui_ok "WP-CLI ready"
  ui_elapsed "$t"

  ui_section "Security Layer"; ui_note "Applying base protection"; printf '\n'
  t=$SECONDS
  _configure_firewall; ui_ok "UFW enabled: 22, 80, 443, ${PANEL_PORT}"
  _configure_sudoers;  ui_ok "AidiPanel sudo wrapper configured"
  _configure_fail2ban; ui_ok "Fail2ban configured"
  _install_certbot;    ui_ok "Certbot installed"
  printf '\n%s\n' "Security note"
  ui_note "The web panel can only run approved privileged actions"
  ui_note "through the AidiPanel wrapper."
  ui_elapsed "$t"

  ui_section "Control Plane"; ui_note "Deploying AidiPanel web UI and CLI"; printf '\n'
  t=$SECONDS
  # SFTP is disabled by default (per-site users are no-login). ProFTPD setup
  # is retained as _install_proftpd() for a future opt-in SFTP feature.
  _create_panel_user;     ui_ok "System user created: aidipanel"
  _create_panel_scaffold; ui_ok "Panel directory prepared: ${PANEL_DIR}"
  _setup_panel_fpm;       ui_ok "Panel PHP-FPM service: aidipanel-fpm (www-data, transitional)"
  _configure_panel_vhost; ui_ok "Self-signed panel SSL generated"
  _install_cli;           ui_ok "CLI installed: /usr/local/bin/aidipanel"
  # Born locked (backlog #6): now that the CLI exists and Redis is up with an
  # aclfile, provision the admin identity and lock the `default` user. No sites
  # exist yet, so this just closes the multi-tenant hole from the first boot.
  if [[ "$INSTALL_REDIS" != "false" ]]; then
    if /usr/local/bin/aidipanel cache:redis-acl --action enable >/dev/null 2>&1; then
      ui_ok "Redis ACL isolation enabled (default user locked)"
    else
      ui_warn "Redis ACL isolation not enabled — run: aidipanel cache:redis-acl --action enable"
    fi
  fi
  _deploy_panel_app;      ui_ok "Web UI deployed"
  _setup_cron;            ui_ok "Maintenance cron configured"
  _test_and_start_services
  _provision_cloudflare_realip; ui_ok "Cloudflare real-IP foundation configured"
  ui_elapsed "$t"

  _health_check

  _cleanup_system
  _cleanup_installer

  _print_summary

  ok "${PANEL_NAME} v${PANEL_VERSION} installation finished in ${SECONDS}s"
}

main "$@"
