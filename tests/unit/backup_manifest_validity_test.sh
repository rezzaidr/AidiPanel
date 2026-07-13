#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="${ROOT}/aidipanel"
CREATE=$(sed -n '/^_backup_create() {$/,/^# files:rename --domain X/p' "$CLI")
PHP_ASSIGN=$(grep -F 'php_ver=$(grep -oP' <<< "$CREATE" | head -1)

if ! printf 'php8.5-fpm\n' | grep -qP 'php\K[0-9.]+(?=-fpm)' 2>/dev/null; then
  printf 'SKIP: host grep does not support the PCRE lookup used by AidiPanel\n'
  exit 0
fi

extract_function() {
  awk "/^$1\\(\\) \\{/{found=1} found{print} found&&/^\\}$/{exit}" "$CLI"
}

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

assert_equal() {
  local actual="$1" expected="$2" label="$3"
  [[ "$actual" == "$expected" ]] \
    || fail "${label}: expected $(printf '%q' "$expected"), got $(printf '%q' "$actual")"
  printf 'ok: %s\n' "$label"
}

manifest_php_version() {
  python3 -c 'import json, sys; print(json.load(sys.stdin)["php_version"])'
}

eval "$(extract_function _backup_manifest)"

TMP=$(mktemp -d)
trap 'rm -rf -- "$TMP"' EXIT
mkdir -p "$TMP/files"
printf 'webroot' > "$TMP/files/webroot.tar.gz"

cat > "$TMP/php.conf" <<'VHOST'
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.5-fpm-backupsmoke.sock;
}
location = /index.php {
    fastcgi_pass unix:/run/php/php8.5-fpm-backupsmoke.sock;
}
VHOST

vconf="$TMP/php.conf"
[[ -r "$vconf" ]] || fail 'synthetic PHP vhost is readable'
php_ver=""
eval "$PHP_ASSIGN"
assert_equal "$php_ver" "8.5" \
  'duplicate PHP socket references resolve to one version'

php_manifest=$(_backup_manifest \
  backup-smoke.test backupsmoke php "$php_ver" '' "$TMP/files" 1.3.2)
php_manifest_version=$(printf '%s\n' "$php_manifest" | manifest_php_version) \
  || fail 'PHP-site manifest is valid JSON'
assert_equal "$php_manifest_version" "8.5" \
  'PHP-site manifest is valid JSON with one PHP version'

cat > "$TMP/static.conf" <<'VHOST'
server {
    root /home/staticsmoke/htdocs/static-smoke.test;
}
VHOST

vconf="$TMP/static.conf"
[[ -r "$vconf" ]] || fail 'synthetic static vhost is readable'
eval "$PHP_ASSIGN"
assert_equal "$php_ver" "" 'a vhost without PHP keeps an empty version'

static_manifest=$(_backup_manifest \
  static-smoke.test staticsmoke static "$php_ver" '' "$TMP/files" 1.3.2)
static_manifest_version=$(printf '%s\n' "$static_manifest" | manifest_php_version) \
  || fail 'static-site manifest is valid JSON'
assert_equal "$static_manifest_version" "" \
  'static-site manifest is valid JSON with an empty PHP version'

printf 'backup manifest validity contract passed\n'
