#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
CLI="${ROOT}/aidipanel"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"
HELPERS="${ROOT}/panel-app/app/Core/helpers.php"

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

ok() {
  printf 'ok: %s\n' "$1"
}

require_file() {
  local file="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" "$file" || fail "$label"
  ok "$label"
}

require_text() {
  local text="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" <<< "$text" || fail "$label"
  ok "$label"
}

forbid_text() {
  local text="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" <<< "$text"; then
    fail "$label"
  fi
  ok "$label"
}

extract_motd() {
  awk '
    /<<.AIDIPANEL_MOTD./ { copy=1; next }
    copy && $0 == "AIDIPANEL_MOTD" { exit }
    copy { print }
  ' "$1"
}

TMP=$(mktemp -d)
trap 'rm -rf -- "$TMP"' EXIT

extract_motd "$INSTALLER" > "$TMP/install-motd"
extract_motd "$CLI" > "$TMP/cli-motd"
[[ -s "$TMP/install-motd" && -s "$TMP/cli-motd" ]] || fail 'MOTD template is present in installer and CLI'
cmp -s "$TMP/install-motd" "$TMP/cli-motd" || fail 'installer and CLI MOTD templates stay synchronized'
ok 'installer and CLI MOTD templates stay synchronized'

motd=$(<"$TMP/cli-motd")
require_text "$motd" 'public_ip_cache="/var/cache/aidipanel-public-ip"' \
  'MOTD reads the shared public IPv4 cache'
require_text "$motd" 'is_public_ipv4() {' \
  'MOTD validates public IPv4 candidates'
require_text "$motd" 'for candidate in $(hostname -I 2>/dev/null); do' \
  'MOTD evaluates every interface address'
forbid_text "$motd" 'curl ' 'MOTD never calls curl during SSH login'
forbid_text "$motd" 'wget ' 'MOTD never calls wget during SSH login'
forbid_text "$motd" '169.254.169.254' 'MOTD never probes cloud metadata during SSH login'
forbid_text "$motd" 'api.ipify.org' 'MOTD does not contact an IP echo during SSH login'
forbid_text "$motd" 'ifconfig.me' 'MOTD does not contact a second IP echo during SSH login'
forbid_text "$motd" 'icanhazip.com' 'MOTD does not contact a third IP echo during SSH login'

require_file "$CLI" '_public_ipv4_cache_refresh() {' \
  'CLI owns the shared public IPv4 cache refresh'
require_file "$CLI" 'local cache="${AIDIPANEL_PUBLIC_IP_CACHE:-/var/cache/aidipanel-public-ip}"' \
  'cache path has a testable production default'
require_file "$CLI" '[[ ! -L "$cache" ]]' \
  'cache writer rejects a symlink destination'
require_file "$CLI" 'mktemp "${cache_dir}/.aidipanel-public-ip.XXXXXX"' \
  'cache writer uses unique same-directory staging'
require_file "$CLI" 'chown aidipanel:aidipanel -- "$staged"' \
  'cache is writable by the isolated panel runtime'
require_file "$CLI" 'chmod 0644 -- "$staged"' \
  'cache contains public non-secret metadata only'
require_file "$CLI" 'mv -f -- "$staged" "$cache"' \
  'cache publication is atomic'
require_file "$CLI" 'system:motd-refresh) _system_motd_refresh "$@" ;;' \
  'CLI exposes a root-only MOTD refresh for trusted deployment paths'
require_file "$CLI" '_require_root "system:motd-refresh"' \
  'MOTD refresh requires root'
require_file "$CLI" 'system:motd-refresh does not accept arguments.' \
  'MOTD refresh rejects unexpected arguments'
require_file "$CLI" '[[ "$(id -u)" -eq 0 && -f "$marker" && ! -L "$marker" ]]' \
  'migration repair accepts only a root invocation and regular marker'
require_file "$CLI" '[[ "$(stat -c '\''%u'\'' -- "$marker" 2>/dev/null)" == 0 ]]' \
  'migration repair requires root marker ownership'
require_file "$DEPLOYER" '/run/aidipanel-motd-refresh-required' \
  'deployer arms the v1.3.3 update-order migration repair'
require_file "$DEPLOYER" 'mktemp "/run/.aidipanel-motd-refresh-required.XXXXXX"' \
  'migration marker uses unique root-directory staging'
require_file "$DEPLOYER" 'chown root:root -- "$motd_marker_staged"' \
  'migration marker remains root-owned'
require_file "$DEPLOYER" 'chmod 0600 -- "$motd_marker_staged"' \
  'migration marker is not writable by local users'
require_file "$INSTALLER" 'cached_ip=$(tr -d '\''[:space:]'\'' < /var/cache/aidipanel-public-ip)' \
  'installer summary reuses the public IPv4 cache'
require_file "$DEPLOYER" 'SERVER_IP=$(tr -d '\''[:space:]'\'' < /var/cache/aidipanel-public-ip)' \
  'deployer summary reuses the public IPv4 cache'
require_file "$HELPERS" "'/var/cache/aidipanel-public-ip'" \
  'dashboard and header read the shared cache'
require_file "$HELPERS" 'FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE' \
  'PHP validates shared cache content as public IPv4'
if grep -Fq -- "'system:motd-refresh'" "$HELPERS"; then
  fail 'web CLI allowlist must not expose the root-only MOTD refresh command'
fi
ok 'web CLI allowlist does not expose MOTD refresh'

mkdir -p "$TMP/bin"
cat > "$TMP/bin/hostname" <<'STUB'
#!/bin/sh
if [ "${1:-}" = "-I" ]; then
  printf '%s\n' "${MOTD_TEST_HOSTNAME_I:-}"
  exit 0
fi
exec /bin/hostname "$@"
STUB
chmod 0755 "$TMP/bin/hostname"

render_case() {
  local name="$1" nic="$2" cache_value="${3:-}" panel_hostname="${4:-}"
  local case_dir="$TMP/$name" script="$TMP/$name.sh"
  mkdir -p "$case_dir"
  if [[ -n "$cache_value" ]]; then
    printf '%s\n' "$cache_value" > "$case_dir/public-ip"
  fi
  if [[ -n "$panel_hostname" ]]; then
    printf 'PANEL_HOSTNAME=%s\nPANEL_PORT=8443\n' "$panel_hostname" > "$case_dir/panel.conf"
  else
    printf 'PANEL_HOSTNAME=\nPANEL_PORT=8443\n' > "$case_dir/panel.conf"
  fi
  printf 'readonly CLI_VERSION="1.3.3"\n' > "$case_dir/aidipanel"

  sed \
    -e "s#^panel_conf=.*#panel_conf=\"$case_dir/panel.conf\"#" \
    -e "s#^public_ip_cache=.*#public_ip_cache=\"$case_dir/public-ip\"#" \
    -e "s#^cli_bin=.*#cli_bin=\"$case_dir/aidipanel\"#" \
    "$TMP/cli-motd" > "$script"

  MOTD_TEST_HOSTNAME_I="$nic" PATH="$TMP/bin:$PATH" /bin/sh "$script"
}

out=$(render_case hostname '2a01:db8::1 10.3.0.10' '' 'panel.example.test')
require_text "$out" 'https://panel.example.test' 'configured panel hostname wins'

out=$(render_case cached '2a01:db8::1 10.3.0.10' '1.1.1.1')
require_text "$out" 'https://1.1.1.1:8443' 'cached public IPv4 wins over private IPv4 and IPv6'

out=$(render_case nic-public '2a01:db8::1 203.0.113.10 8.8.8.8')
require_text "$out" 'https://8.8.8.8:8443' 'NIC scan skips reserved IPv4 and selects public IPv4'

out=$(render_case private-only '2a01:db8::1 10.3.0.10')
require_text "$out" 'https://10.3.0.10:8443' 'private-only server keeps a local IPv4 fallback'

out=$(render_case ipv6-only '2a01:db8::1')
require_text "$out" 'run: aidipanel panel:hostname <fqdn>' 'IPv6-only input does not produce a malformed panel URL'
forbid_text "$out" 'https://2a01:db8::1' 'IPv6 is never rendered as the MOTD URL host'

printf 'MOTD public IPv4 regression contract passed\n'
