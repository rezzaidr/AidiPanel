#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
CLI="${ROOT}/aidipanel"
CI="${ROOT}/.github/workflows/ci.yml"
PUBLIC_KEY="${ROOT}/config/release-signing-public.pub"

require() {
  local file="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" "$file" || { printf 'FAIL: %s\n' "$label" >&2; exit 1; }
  printf 'ok: %s\n' "$label"
}

reject() {
  local file="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" "$file"; then
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  fi
  printf 'ok: %s\n' "$label"
}

[[ -s "$PUBLIC_KEY" ]] \
  || { printf 'FAIL: canonical release public key exists\n' >&2; exit 1; }
openssl pkey -pubin -in "$PUBLIC_KEY" -noout >/dev/null 2>&1 \
  || { printf 'FAIL: canonical release public key is valid PEM\n' >&2; exit 1; }

require "$INSTALLER" 'readonly AIDIPANEL_RELEASE_PUBLIC_KEY_B64=' 'installer pins the release public key'
require "$CLI" 'readonly AIDIPANEL_RELEASE_PUBLIC_KEY_B64=' 'CLI pins the release public key'
reject "$INSTALLER" '__AIDIPANEL_RELEASE_PUBLIC_KEY_B64__' 'installer key initialization is complete'
reject "$CLI" '__AIDIPANEL_RELEASE_PUBLIC_KEY_B64__' 'CLI key initialization is complete'
require "$INSTALLER" 'openssl dgst -sha256 -verify' 'installer verifies the signed manifest'
require "$CLI" 'openssl dgst -sha256 -verify' 'self-update verifies the signed manifest'
require "$INSTALLER" 'SHA256SUMS.sig' 'installer requires the detached signature'
require "$CLI" 'SHA256SUMS.sig' 'self-update requires the detached signature'
require "$CI" '# AIDIPANEL_RELEASE_VERSION=' 'release manifest binds the version'
require "$CI" '--draft' 'CI creates draft releases only'
reject "$CI" 'RELEASE_SIGNING_PRIVATE_KEY' 'CI has no private signing key'
reject "$CI" 'openssl dgst -sha256 -sign' 'CI cannot sign releases'

key_b64=$(base64 -w0 "$PUBLIC_KEY")
installer_b64=$(sed -n 's/^readonly AIDIPANEL_RELEASE_PUBLIC_KEY_B64="\([^"]*\)"$/\1/p' "$INSTALLER")
cli_b64=$(sed -n 's/^readonly AIDIPANEL_RELEASE_PUBLIC_KEY_B64="\([^"]*\)"$/\1/p' "$CLI")
[[ "$installer_b64" == "$key_b64" && "$cli_b64" == "$key_b64" ]] \
  || { printf 'FAIL: embedded keys match canonical public key\n' >&2; exit 1; }

printf 'release signing contract passed\n'
