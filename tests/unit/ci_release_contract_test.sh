#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CI="${ROOT}/.github/workflows/ci.yml"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$CI" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

RELEASE_SIGNING_JOB=$(awk '
  $0 == "  release-signing:" { capture=1 }
  capture && $0 ~ /^  [A-Za-z0-9_-]+:$/ && $0 != "  release-signing:" { exit }
  capture { print }
' "$CI")

require_signing_job() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" <<< "$RELEASE_SIGNING_JOB" \
    || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

forbid_signing_job() {
  local pattern="$1" label="$2"
  if grep -Fq -- "$pattern" <<< "$RELEASE_SIGNING_JOB"; then
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  fi
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
require 'printf '\''# AIDIPANEL_RELEASE_VERSION=%s\n'\''' 'release manifest includes the signed version header'
require 'gh release create "$GITHUB_REF_NAME" "${flags[@]}" --draft' 'CI creates an unpublished draft'
require 'release-signing-public.pub' 'release publishes the public verification key'
require_signing_job 'name: Verify Release Signing' 'CI has a dedicated release-signing integration job'
require_signing_job 'runs-on: windows-latest' 'release-signing integration runs in its native Windows environment'
require_signing_job 'contents: read' 'release-signing integration has read-only repository permission'
require_signing_job 'actions/checkout@34e114876b0b11c390a56381ad16ebd13914f8d5' 'release-signing checkout is immutable'
require_signing_job 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests/integration/release_signing_test.ps1' \
  'CI executes the complete release-signing integration test'
forbid_signing_job 'GH_TOKEN' 'release-signing integration receives no GitHub publication token'
forbid_signing_job 'PRIVATE_KEY' 'release-signing integration receives no offline private key'
require 'needs: [lint-installer, lint-php, frontend, release-signing]' \
  'draft release creation depends on release-signing integration'

printf 'CI release contract passed\n'
