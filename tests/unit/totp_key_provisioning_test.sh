#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"
TMP_ROOT=$(mktemp -d)
trap 'rm -rf -- "$TMP_ROOT"' EXIT

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$1"; }

extract_helper() {
  local source="$1"
  awk '
    $0 == "_provision_totp_key() {" { capture=1 }
    capture { print }
    capture && $0 == "}" { exit }
  ' "$source"
}

INSTALL_HELPER=$(extract_helper "$INSTALLER")
DEPLOY_HELPER=$(extract_helper "$DEPLOYER")
[[ -n "$INSTALL_HELPER" ]] || fail 'installer TOTP key helper is missing'
[[ -n "$DEPLOY_HELPER" ]] || fail 'deployer TOTP key helper is missing'

require_both() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" <<< "$INSTALL_HELPER" || fail "installer: ${label}"
  grep -Fq -- "$pattern" <<< "$DEPLOY_HELPER" || fail "deployer: ${label}"
  pass "$label"
}

require_both '[[ -d "$key_dir" && ! -L "$key_dir" ]]' 'key directory rejects symlinks'
require_both '[[ "$(stat -c %u -- "$key_dir")" == "0" ]]' 'key directory must be root-owned'
require_both '(( (8#${key_dir_mode} & 0022) == 0 ))' 'key directory must not be group/world-writable'
require_both '[[ -f "$key_path" && ! -L "$key_path" ]]' 'existing key must be a real regular file'
require_both '[[ "$(stat -c %s -- "$key_path")" == "32" ]]' 'existing key must contain exactly 32 bytes'
require_both 'chown root:"${PANEL_USER}" -- "$key_path"' 'key ownership is root:aidipanel'
require_both 'chmod 0640 -- "$key_path"' 'key mode is 0640'
require_both 'ln -- "$staged" "$key_path"' 'new key publication cannot overwrite an existing entry'
require_both 'sudo -u "${PANEL_USER}" test -r "$key_path"' 'panel identity readability is verified'
require_both "extension_loaded('sodium')" 'panel PHP Sodium availability is required'
require_both "totp_secret LIKE 'v1:%'" 'missing key checks for existing encrypted rows'

run_case() {
  local name="$1" setup="$2" encrypted="${3:-0}" sodium="${4:-1}" column="${5:-1}"
  local case_dir="${TMP_ROOT}/${name}"
  mkdir -p "$case_dir/etc/aidipanel" "$case_dir/panel/storage/db"
  chmod 0755 "$case_dir/etc/aidipanel"  # safe regardless of runner umask (cloud images default to 0002)
  : > "$case_dir/events"
  : > "$case_dir/error"

  case "$setup" in
    valid) command openssl rand 32 > "$case_dir/etc/aidipanel/totp.key" ;;
    short) command openssl rand 31 > "$case_dir/etc/aidipanel/totp.key" ;;
    db) : > "$case_dir/panel/storage/db/aidipanel.sqlite" ;;
    empty) ;;
    *) fail "unknown setup ${setup}" ;;
  esac

  local helper="${INSTALL_HELPER//\/etc\/aidipanel/$case_dir\/etc\/aidipanel}"
  (
    export DRY_RUN=false PANEL_USER=aidipanel PANEL_DIR="$case_dir/panel"
    export PHP_DEFAULT_VERSION=8.5 SIM_ENCRYPTED="$encrypted" SIM_SODIUM="$sodium"
    export SIM_COLUMN="$column"
    export PANEL_LOG="$case_dir/events"

    sudo() {
      [[ "${1:-}" == "-u" ]] || return 1
      shift 2
      case "${1:-}" in
        test) shift; builtin test "$@" ;;
        sqlite3)
          if [[ "$*" == *"sqlite_master"* ]]; then
            printf '1\n'
          elif [[ "$*" == *"pragma_table_info"* ]]; then
            printf '%s\n' "$SIM_COLUMN"
          else
            printf '%s\n' "$SIM_ENCRYPTED"
          fi
          ;;
        php8.5) [[ "$SIM_SODIUM" == "1" ]] ;;
        *) return 1 ;;
      esac
    }
    chown() { printf 'chown:%s\n' "$*" >> "$case_dir/events"; }
    chmod() {
      printf 'chmod:%s\n' "$*" >> "$case_dir/events"
      command chmod "$@"
    }
    stat() {
      if [[ "${1:-}" == "-c" && "${2:-}" == "%u" ]]; then printf '0\n'; else command stat "$@"; fi
    }
    openssl() {
      printf 'openssl:%s\n' "$*" >> "$case_dir/events"
      command openssl "$@"
    }
    log() { :; }
    warn() { :; }
    ok() { :; }
    die() { printf '%s\n' "$*" > "$case_dir/error"; exit 1; }

    eval "$helper"
    set +e
    ( _provision_totp_key )
    rc=$?
    set -e
    printf '%s\n' "$rc" > "$case_dir/rc"
  )

  printf '%s\n' "$case_dir"
}

created=$(run_case created empty)
[[ "$(<"$created/rc")" == "0" ]] || fail 'missing key should be created successfully'
[[ "$(stat -c %s -- "$created/etc/aidipanel/totp.key")" == "32" ]] || fail 'created key must be 32 bytes'
grep -Fq 'chown:root:aidipanel' "$created/events" || fail 'created key ownership was not enforced'
grep -Fq 'chmod:0640' "$created/events" || fail 'created key mode was not enforced'
pass 'missing key is securely created'

reused=$(run_case reused valid)
before=$(sha256sum "$reused/etc/aidipanel/totp.key" | awk '{print $1}')
events_before=$(grep -c '^openssl:' "$reused/events" || true)
helper="${INSTALL_HELPER//\/etc\/aidipanel/$reused\/etc\/aidipanel}"
(
  export DRY_RUN=false PANEL_USER=aidipanel PANEL_DIR="$reused/panel" PHP_DEFAULT_VERSION=8.5
  export PANEL_LOG="$reused/events"
  sudo() { [[ "$1" == "-u" ]]; shift 2; case "$1" in test) shift; builtin test "$@" ;; php8.5) return 0 ;; sqlite3) printf '0\n' ;; esac; }
  chown() { :; }
  chmod() { command chmod "$@"; }
  stat() { if [[ "${1:-}" == "-c" && "${2:-}" == "%u" ]]; then printf '0\n'; else command stat "$@"; fi; }
  log() { :; }
  ok() { :; }
  die() { exit 1; }
  eval "$helper"
  _provision_totp_key
)
after=$(sha256sum "$reused/etc/aidipanel/totp.key" | awk '{print $1}')
[[ "$before" == "$after" && "$events_before" == "0" ]] || fail 'existing key must be reused byte-for-byte'
pass 'existing key is reused byte-for-byte'

short=$(run_case short short)
[[ "$(<"$short/rc")" == "1" ]] || fail 'wrong-sized key must abort'
grep -Fq 'exactly 32 bytes' "$short/error" || fail 'wrong-sized key error must be explicit'
pass 'wrong-sized key aborts'

encrypted=$(run_case encrypted db 1)
[[ "$(<"$encrypted/rc")" == "1" ]] || fail 'missing key with encrypted DB rows must abort'
[[ ! -e "$encrypted/etc/aidipanel/totp.key" ]] || fail 'encrypted DB refusal must not create a replacement key'
grep -Fq 'Restore the original key' "$encrypted/error" || fail 'encrypted DB refusal must direct key recovery'
pass 'encrypted rows prevent replacement-key generation'

legacy_schema=$(run_case legacy_schema db 0 1 0)
[[ "$(<"$legacy_schema/rc")" == "0" ]] || fail 'database without a TOTP column should accept first key creation'
[[ "$(stat -c %s -- "$legacy_schema/etc/aidipanel/totp.key")" == "32" ]] \
  || fail 'legacy-schema upgrade did not create a 32-byte key'
pass 'pre-2FA database schema accepts first key creation'

no_sodium=$(run_case no_sodium empty 0 0)
[[ "$(<"$no_sodium/rc")" == "1" ]] || fail 'missing Sodium must abort'
[[ ! -e "$no_sodium/etc/aidipanel/totp.key" ]] || fail 'Sodium failure must occur before key creation'
pass 'missing Sodium aborts before key creation'

install_user=$(grep -nF '  _create_panel_user;' "$INSTALLER" | cut -d: -f1)
install_scaffold=$(grep -nF '  _create_panel_scaffold;' "$INSTALLER" | cut -d: -f1)
install_key=$(grep -nF '  _provision_totp_key;' "$INSTALLER" | cut -d: -f1)
install_fpm=$(grep -nF '  _setup_panel_fpm;' "$INSTALLER" | cut -d: -f1)
[[ -n "$install_user" && -n "$install_scaffold" && -n "$install_key" && -n "$install_fpm" \
   && "$install_user" -lt "$install_scaffold" && "$install_scaffold" -lt "$install_key" \
   && "$install_key" -lt "$install_fpm" ]] || fail 'fresh-install key call order is unsafe'
pass 'fresh-install key call order is safe'

deploy_key=$(grep -nF '_provision_totp_key' "$DEPLOYER" | tail -n1 | cut -d: -f1)
deploy_stage=$(grep -nF 'DEPLOY_STAGE=$(mktemp' "$DEPLOYER" | cut -d: -f1)
[[ -n "$deploy_key" && -n "$deploy_stage" && "$deploy_key" -lt "$deploy_stage" ]] \
  || fail 'deployer must provision key before staging activation'
pass 'deployer provisions key before activation'

fpm_restart=$(grep -nF 'systemctl restart aidipanel-fpm' "$DEPLOYER" | tail -n1 | cut -d: -f1)
nginx_reload=$(grep -nF 'systemctl reload nginx' "$DEPLOYER" | tail -n1 | cut -d: -f1)
app_commit=$(grep -nF 'DEPLOY_ACTIVATED=0' "$DEPLOYER" | tail -n1 | cut -d: -f1)
cli_commit=$(grep -nF 'CLI_ACTIVATED=0' "$DEPLOYER" | tail -n1 | cut -d: -f1)
migration=$(grep -nF 'migrate-totp-secrets.php' "$DEPLOYER" | tail -n1 | cut -d: -f1)
[[ -n "$fpm_restart" && -n "$nginx_reload" && -n "$app_commit" && -n "$cli_commit" && -n "$migration" \
   && "$fpm_restart" -lt "$app_commit" && "$nginx_reload" -lt "$app_commit" \
   && "$app_commit" -lt "$migration" && "$cli_commit" -lt "$migration" ]] \
  || fail 'TOTP migration must run after the updater commit point'
pass 'TOTP migration runs after the updater commit point'

printf 'TOTP key provisioning tests passed\n'
