#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CI="${ROOT}/.github/workflows/ci.yml"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CI" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

require 'for test in tests/unit/*.sh' 'CI runs every tracked shell contract'
require 'npm ci' 'CI installs the exact frontend lockfile'
require 'npm run build' 'CI rebuilds generated frontend assets'
require 'git diff --exit-code -- panel-app/public/assets' 'CI rejects stale generated assets'
require 'npm audit --audit-level=high' 'CI checks build-time and runtime dependency advisories'
require 'name: Verify release version' 'release publication verifies the source version'
require 'source_version="${GITHUB_REF_NAME#v}"' 'release version derives from the immutable tag'
require 'grep -Fqx "readonly PANEL_VERSION=\"${source_version}\"" install.sh' 'release tag must match the installer version'
require 'grep -Fqx "readonly CLI_VERSION=\"${source_version}\"" aidipanel' 'release tag must match the CLI version'
require 'grep -Fqx "readonly PANEL_VERSION=\"${source_version}\"" panel-app/deploy-panel.sh' 'release tag must match the deployer version'
require 'sha256sum install-aidipanel.sh aidipanel aidipanel-panel-app.tar.gz' 'release artifacts include integrity hashes'
require 'SHA256SUMS' 'release upload includes the checksum manifest'

printf 'CI release contract passed\n'
