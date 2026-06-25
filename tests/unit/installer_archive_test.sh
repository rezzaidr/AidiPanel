#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Load installer functions without running the full installer.
# shellcheck disable=SC1090
source <(sed '/^main "\$@"$/d' "${repo_root}/install.sh")

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

test_archive_root_is_discovered_without_case_assumption() {
  local tmp_dir result
  tmp_dir="$(mktemp -d)"
  trap 'rm -rf "$tmp_dir"' RETURN

  mkdir -p "${tmp_dir}/AidiPanel-master/panel-app/public"
  mkdir -p "${tmp_dir}/AidiPanel-master/panel-app/app"

  result="$(_find_downloaded_panel_app "$tmp_dir")"
  [[ "$result" == "${tmp_dir}/AidiPanel-master/panel-app" ]] \
    || fail "expected discovered panel app path, got: ${result}"
}

test_missing_panel_app_returns_failure() {
  local tmp_dir
  tmp_dir="$(mktemp -d)"
  trap 'rm -rf "$tmp_dir"' RETURN

  mkdir -p "${tmp_dir}/AidiPanel-master"

  if _find_downloaded_panel_app "$tmp_dir" >/dev/null; then
    fail "expected missing panel app lookup to fail"
  fi
}

test_archive_root_is_discovered_without_case_assumption
test_missing_panel_app_returns_failure

printf 'installer archive tests passed\n'
