#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
CLI="${ROOT}/aidipanel"

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

require "$INSTALLER" 'releases/latest/download' 'standalone installer downloads stable release assets'
require "$INSTALLER" '_download_release_asset "aidipanel"' 'CLI fallback uses the verified release downloader'
require "$INSTALLER" '_download_release_asset "aidipanel-panel-app.tar.gz"' 'panel fallback uses the verified release downloader'
require "$INSTALLER" 'sha256sum -c -' 'release assets are checksum-verified'
require "$INSTALLER" 'install -o root -g root -m 0755 "$cli_src" /usr/local/bin/aidipanel' 'installer applies trusted CLI ownership and mode'
reject "$INSTALLER" 'raw.githubusercontent.com/rezzaidr/AidiPanel/master/aidipanel' 'installer never downloads the CLI from master'
reject "$INSTALLER" 'archive/refs/heads/master.tar.gz' 'installer never deploys a mutable branch archive'
reject "$INSTALLER" 'http://nginx.org/packages' 'Nginx packages use HTTPS transport'
reject "$INSTALLER" 'http://repo.mysql.com/apt' 'MySQL packages use HTTPS transport'
require "$INSTALLER" '177F4010FE56CA3336300305F1656F24C74CD1D8' 'MariaDB repository key uses the full official fingerprint'
reject "$INSTALLER" 'r.mariadb.com/downloads/mariadb_repo_setup' 'MariaDB trust does not depend on the mutable upstream bootstrap script'
require "$INSTALLER" 'BCA43417C3B485DD128EC6D4B7B3B788A8D3785C' 'MySQL repository key uses the full official fingerprint'
reject "$INSTALLER" '--keyserver keyserver.ubuntu.com' 'MySQL trust does not depend on an unverified keyserver result'
require "$INSTALLER" 'wp-cli.phar.sha512' 'WP-CLI download is verified with its published SHA-512'
require "$CLI" '"${url}.sha256"' 'phpMyAdmin archive is verified with its published SHA-256'
require "$CLI" 'sha256sum -c' 'phpMyAdmin checksum is enforced before extraction'

printf 'installer supply-chain contract passed\n'
