#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"
INSTALL=$(sed -n '/^_ssl_install() {$/,/^_ssl_renew() {$/p' "$CLI" | sed '$d')

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
ok() { printf 'ok: %s\n' "$1"; }
extract_function() {
  awk "/^$1\\(\\) \\{/{found=1} found{print} found&&/^\\}$/{exit}" "$CLI"
}

HELPER=$(extract_function _ssl_domain_within_site)
[[ -n "$HELPER" ]] || fail 'CLI defines the SSL relationship helper'
eval "$HELPER"

_ssl_domain_within_site example.com example.com || fail 'primary domain is accepted'
ok 'primary domain is accepted'
_ssl_domain_within_site www.example.com example.com || fail 'www subdomain is accepted'
ok 'www subdomain is accepted'
_ssl_domain_within_site api.shop.example.com example.com || fail 'nested subdomain is accepted'
ok 'nested subdomain is accepted'
! _ssl_domain_within_site evilexample.com example.com || fail 'suffix confusion is rejected'
ok 'suffix confusion is rejected'
! _ssl_domain_within_site example.com.evil.test example.com || fail 'parent-prefix confusion is rejected'
ok 'parent-prefix confusion is rejected'
! _ssl_domain_within_site example.net example.com || fail 'unrelated domain is rejected'
ok 'unrelated domain is rejected'

require_install() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" <<< "$INSTALL" || fail "$label"
  ok "$label"
}
assert_before() {
  local first="$1" second="$2" label="$3" first_line second_line
  first_line=$(grep -nF -- "$first" <<< "$INSTALL" | head -1 | cut -d: -f1)
  second_line=$(grep -nF -- "$second" <<< "$INSTALL" | head -1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] || fail "$label"
  ok "$label"
}

require_install '_ssl_domain_within_site "$d" "$primary"' 'SSL install enforces the relationship'
require_install '_domain_exists "$d"' 'SSL install rejects another managed site'
assert_before '_ssl_domain_within_site "$d" "$primary"' '_progress 10 dns "Checking DNS"' \
  'relationship validation runs before DNS'
assert_before '_domain_exists "$d"' 'local certbot_args=' \
  'managed-site validation runs before Certbot'

run_case() {
  local domains="$1" work="$2" input_mode="${3:-domains}"
  mkdir -p "$work"
  (
    set -Eeuo pipefail
    TEST_DOMAINS="$domains"
    TEST_INPUT_MODE="$input_mode"
    CLI_LOG="${work}/cli.log"
    CERTBOT_LOG="${work}/certbot.log"
    NGINX_CONF_DIR="${work}/nginx"
    mkdir -p "$NGINX_CONF_DIR"
    : > "$CLI_LOG"
    eval "$HELPER"
    eval "$INSTALL"
    _require_root() { :; }
    _parse_opts() { :; }
    _opt() {
      case "$1" in
        domains) [[ "$TEST_INPUT_MODE" == domains ]] && printf '%s' "$TEST_DOMAINS" ;;
        domain) [[ "$TEST_INPUT_MODE" == domain ]] && printf '%s' "$TEST_DOMAINS" ;;
        email|staging) printf '' ;;
        *) printf '%s' "${2:-}" ;;
      esac
    }
    _require_arg() { [[ -n "$2" ]] || die "missing $1"; }
    _domain_valid() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; }
    _domain_exists() { [[ "$1" == example.com || "$1" == tenant.example.com ]]; }
    getent() { return 0; }
    certbot() { printf '%s\n' "$*" >> "$CERTBOT_LOG"; }
    chmod() { :; }
    _progress() { :; }
    log() { :; }
    ok() { :; }
    info() { :; }
    _ssl_point_vhost() { :; }
    _vhost_marker_set() { :; }
    die() { printf 'DIE: %s\n' "$*" >&2; exit 64; }
    _ssl_install
  )
}

TMP=$(mktemp -d)
trap 'rm -rf -- "$TMP"' EXIT

if run_case 'example.com,victim.example.net' "${TMP}/unrelated" >"${TMP}/unrelated.out" 2>&1; then
  fail 'unrelated SAN reaches a successful install'
fi
grep -Fq 'must be example.com or one of its subdomains' "${TMP}/unrelated.out" \
  || fail 'unrelated SAN returns the boundary error'
[[ ! -s "${TMP}/unrelated/certbot.log" ]] || fail 'unrelated SAN reached Certbot'
ok 'unrelated SAN stops before Certbot'

if run_case 'example.com,tenant.example.com' "${TMP}/managed" >"${TMP}/managed.out" 2>&1; then
  fail 'managed subdomain reaches a successful install'
fi
grep -Fq 'managed as a separate AidiPanel site' "${TMP}/managed.out" \
  || fail 'managed subdomain returns the managed-site error'
[[ ! -s "${TMP}/managed/certbot.log" ]] || fail 'managed subdomain reached Certbot'
ok 'managed subdomain stops before Certbot'

run_case 'example.com,www.example.com,api.shop.example.com' "${TMP}/accepted" \
  >"${TMP}/accepted.out" 2>&1 || fail 'valid primary/subdomain request failed'
grep -Fq -- '--nginx --non-interactive --agree-tos --cert-name example.com -d example.com -d www.example.com -d api.shop.example.com' \
  "${TMP}/accepted/certbot.log" || fail 'accepted SAN order was not preserved'
ok 'valid request reaches Certbot with stable argument order'

run_case 'example.com' "${TMP}/legacy" domain \
  >"${TMP}/legacy.out" 2>&1 || fail 'legacy single --domain request failed'
grep -Fq -- '--cert-name example.com -d example.com' "${TMP}/legacy/certbot.log" \
  || fail 'legacy single --domain request did not reach Certbot'
ok 'legacy single --domain workflow remains compatible'

printf '%s\n' 'SSL SAN CLI tenant-boundary contract passed'
