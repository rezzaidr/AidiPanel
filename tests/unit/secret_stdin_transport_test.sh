#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE="${ROOT}/panel-app/app/Controllers/SiteController.php"
DB="${ROOT}/panel-app/app/Controllers/SiteDatabaseController.php"
HELPERS="${ROOT}/panel-app/app/Core/helpers.php"
CLI="${ROOT}/aidipanel"
INSTALLER="${ROOT}/install.sh"

reject() {
  local file="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" "$file"; then printf 'FAIL: %s\n' "$label" >&2; exit 1; fi
  printf 'ok: %s\n' "$label"
}
require() {
  local file="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" "$file" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

reject "$SITE" "'--wp-admin-pass'," 'WordPress admin password never enters argv'
require "$SITE" "'--wp-admin-pass-stdin'" 'WordPress site creation declares stdin password transport'
require "$HELPERS" 'function run_cli_stream(string $command, array $args, callable $onProgress, string $stdin = ' 'stream runner supports stdin secrets'
reject "$DB" "\$args[] = '--pass'" 'database passwords never enter argv'
require "$DB" "'--password-stdin'" 'database commands declare stdin password transport'
require "$DB" "run_cli_stdin('db:" 'database controllers send passwords through stdin'
require "$CLI" 'wp-admin-pass-stdin' 'CLI reads WordPress password from stdin'
require "$CLI" 'password-stdin' 'CLI reads database passwords from stdin'
reject "$CLI" '-e "$1"' 'SQL containing passwords never enters database client argv'
require "$CLI" "printf '%s\\n' \"\$1\" | \"\$bin\"" 'SQL reaches database client through stdin'
reject "$INSTALLER" '"-p${DB_ROOT_PASS}"' 'installer never exposes the database root password in process arguments'
require "$INSTALLER" '"--defaults-extra-file=${DB_DEFAULTS_TMP}"' 'installer uses a private database client option file'
require "$INSTALLER" 'rm -f -- "$DB_DEFAULTS_TMP"' 'installer always removes its temporary database client option file'

printf 'secret stdin transport contract passed\n'
