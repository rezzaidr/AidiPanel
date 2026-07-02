#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"

require() {
  local pattern="$1" label="$2"
  grep -Fq -- "$pattern" "$INSTALLER" || {
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  }
  printf 'ok: %s\n' "$label"
}

reject() {
  local pattern="$1" label="$2"
  if grep -Fq -- "$pattern" "$INSTALLER"; then
    printf 'FAIL: %s\n' "$label" >&2
    exit 1
  fi
  printf 'ok: %s\n' "$label"
}

require '573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62' 'Nginx documented signing key is pinned'
require '8540A6F18833A80E9C1653A42FD21310B49F6B46' 'Nginx rotated signing key is pinned'
require '9E9BE90EACBCDE69FE9B204CBCDCD8A38D88A2B3' 'Nginx second rotated signing key is pinned'
require '15058500A0235D97F5D10063B188E2B695BD4743' 'PHP Sury signing key is pinned'
require 'B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6' 'Ubuntu PHP PPA signing key is pinned'
require '[[ "$actual_fingerprints" == "$expected_fingerprints" ]]' 'key bundles reject missing or additional primary keys'
require '_install_verified_apt_key "https://nginx.org/keys/nginx_signing.key"' 'Nginx uses verified key installation'
require '_install_verified_apt_key "https://packages.sury.org/php/apt.gpg"' 'PHP Sury uses verified key installation'
require 'ppa.launchpadcontent.net/ondrej/php/ubuntu' 'Ubuntu PHP repository uses the official HTTPS PPA endpoint'
reject 'add-apt-repository -y ppa:ondrej/php' 'Ubuntu PHP trust is not delegated to an implicit key import'
reject 'curl -fsSL https://nginx.org/keys/nginx_signing.key \' 'Nginx key is not trusted directly from the network'
reject 'curl -fsSL https://packages.sury.org/php/apt.gpg \' 'PHP Sury key is not trusted directly from the network'

printf 'repository key fingerprint contract passed\n'
