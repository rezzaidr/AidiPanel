#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
INSTALL_DOC="${ROOT}/docs/installation.md"
UNINSTALL_DOC="${ROOT}/docs/uninstall.md"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"

require() {
  local file="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" "$file" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}
reject() {
  local file="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" "$file"; then printf 'FAIL: %s\n' "$label" >&2; exit 1; fi
  printf 'ok: %s\n' "$label"
}

reject "$INSTALLER" 'run the uninstaller first' 'installer does not promise a nonexistent uninstaller'
require "$INSTALLER" 'readonly PANEL_LOCK="/run/lock/aidipanel-deploy.lock"' 'install and update share one lifecycle lock'
require "$INSTALLER" 'flock -n 179' 'installer lock acquisition is atomic'
reject "$INSTALLER" 'rm -f "$PANEL_LOCK"' 'installer never unlinks a potentially held flock inode'
reject "$INSTALL_DOC" 'ufw --force reset' 'manual removal never resets unrelated firewall rules'
require "$INSTALL_DOC" '[manual uninstall guide](uninstall.md)' 'installation guide points to the complete removal guide'
require "$UNINSTALL_DOC" 'Removal is manual and deliberate' 'uninstall boundary is explicit'
require "$WORKFLOW" "tags: ['v*']" 'version tags trigger the release workflow'
require "$WORKFLOW" 'contents: write' 'release job has explicit publication permission'
require "$WORKFLOW" 'gh release create' 'version tag publishes a GitHub Release'
require "$WORKFLOW" 'gh release upload' 'verified distribution assets are attached to the release'
reject "$WORKFLOW" '--clobber' 'published release assets are immutable'

printf 'install lifecycle contract passed\n'
