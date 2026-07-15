#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"

function_body() {
  local file="$1" name="$2"
  awk -v sig="${name}() {" '
    $0 == sig { inside=1 }
    inside { print }
    inside && $0 == "}" { exit }
  ' "$file"
}

require() {
  local content="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" <<< "$content" \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

forbid() {
  local content="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" <<< "$content"; then
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  fi
  printf 'ok: %s\n' "$label"
}

assert_before() {
  local content="$1" first="$2" second="$3" label="$4"
  local first_line second_line
  first_line=$(grep -nF -- "$first" <<< "$content" | head -n1 | cut -d: -f1)
  second_line=$(grep -nF -- "$second" <<< "$content" | tail -n1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

install_cli=$(function_body "$INSTALLER" '_install_cli')

forbid "$install_cli" '/tmp/aidipanel-cli-$$' \
  'downloaded CLI no longer uses a predictable PID path'
require "$install_cli" 'local cli_src downloaded_cli=""' \
  'downloaded temporary state is separate from the bundled source path'
require "$install_cli" 'downloaded_cli=$(mktemp /tmp/aidipanel-cli.XXXXXX)' \
  'network installs allocate the CLI destination with mktemp'
require "$install_cli" 'cli_src="$downloaded_cli"' \
  'only the network branch installs from the allocated temporary path'
require "$install_cli" 'rm -f -- "$downloaded_cli"' \
  'download failures remove the allocated temporary path'
require "$install_cli" '[[ -z "$downloaded_cli" ]] || rm -f -- "$downloaded_cli"' \
  'install completion conditionally removes only downloaded CLI content'
forbid "$install_cli" 'rm -f -- "$cli_src"' \
  'cleanup can never delete the bundled CLI source'
assert_before "$install_cli" 'install -o root -g root -m 0755 "$cli_src" /usr/local/bin/aidipanel' \
  '[[ -z "$downloaded_cli" ]] || rm -f -- "$downloaded_cli"' \
  'successful cleanup happens after CLI installation'

cleanup_count=$(grep -Fc -- 'rm -f -- "$downloaded_cli"' <<< "$install_cli")
[[ "$cleanup_count" -eq 3 ]] \
  || { printf 'FAIL: download, install-failure, and success paths must each clean the temporary CLI\n' >&2; exit 1; }
printf 'ok: all downloaded-CLI exit paths clean the temporary file\n'

printf 'installer CLI temporary-file safety contract passed\n'
