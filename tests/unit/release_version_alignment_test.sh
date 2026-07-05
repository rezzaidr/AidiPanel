#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
CLI="${ROOT}/aidipanel"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"
PANEL="${ROOT}/panel-app/public/index.php"

installer_version="$(sed -n 's/^readonly PANEL_VERSION="\([^"]*\)"$/\1/p' "$INSTALLER")"
cli_version="$(sed -n 's/^readonly CLI_VERSION="\([^"]*\)"$/\1/p' "$CLI")"
deployer_version="$(sed -n 's/^readonly PANEL_VERSION="\([^"]*\)"$/\1/p' "$DEPLOYER")"
panel_version="$(sed -n "s/^define('PANEL_VERSION', '\([^']*\)');$/\1/p" "$PANEL")"
expected="${EXPECTED_RELEASE_VERSION:-$installer_version}"

expect_version() {
  local actual="$1" source="$2"
  [[ -n "$actual" ]] || { printf 'FAIL: could not read version from %s\n' "$source" >&2; exit 1; }
  [[ "$actual" == "$expected" ]] \
    || { printf 'FAIL: %s reports %s, expected %s\n' "$source" "$actual" "$expected" >&2; exit 1; }
  printf 'ok: %s reports %s\n' "$source" "$actual"
}

expect_version "$installer_version" 'install.sh'
expect_version "$cli_version" 'aidipanel'
expect_version "$deployer_version" 'panel-app/deploy-panel.sh'
expect_version "$panel_version" 'panel-app/public/index.php'

grep -Fq "AidiPanel Installer v${expected}" "$INSTALLER" \
  || { printf 'FAIL: installer header does not match %s\n' "$expected" >&2; exit 1; }
grep -Fq "Version : ${expected}" "$CLI" \
  || { printf 'FAIL: CLI header does not match %s\n' "$expected" >&2; exit 1; }
grep -Fq "Deploy Panel Web App v${expected}" "$DEPLOYER" \
  || { printf 'FAIL: deployer header does not match %s\n' "$expected" >&2; exit 1; }
grep -Fq "Entry Point v${expected}" "$PANEL" \
  || { printf 'FAIL: panel entry-point header does not match %s\n' "$expected" >&2; exit 1; }

printf 'release version alignment contract passed: %s\n' "$expected"
