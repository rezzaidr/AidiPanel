#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
CLI="${ROOT}/aidipanel"
DEPLOYER="${ROOT}/panel-app/deploy-panel.sh"
COLLECTOR="${ROOT}/panel-app/bin/collect-metrics.php"
CONTROLLER="${ROOT}/panel-app/app/Controllers/DashboardController.php"
VIEW="${ROOT}/panel-app/app/Views/dashboard/index.php"
LANG="${ROOT}/panel-app/app/Lang/en.php"
CSS="${ROOT}/panel-app/public/assets/app.css"

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

function_body() {
  local file="$1" name="$2"
  awk -v signature="${name}() {" '
    $0 == signature { capture=1 }
    capture { print }
    capture && /^}/ { exit }
  ' "$file"
}

reject "$INSTALLER" 'setfacl' 'fresh install no longer grants filesystem cache ACLs'
reject "$INSTALLER" '_grant_cache_acl' 'fresh install no longer defines or calls the cache ACL helper'
if function_body "$INSTALLER" _install_base_packages | grep -Eq '(^|[[:space:]\\])acl([[:space:]\\]|$)'; then
  printf 'FAIL: fresh install no longer installs acl solely for the removed metric\n' >&2
  exit 1
fi
printf 'ok: fresh install no longer installs acl solely for the removed metric\n'

reject "$DEPLOYER" 'setfacl' 'panel deploy no longer mutates cache filesystem ACLs'
reject "$DEPLOYER" '_repair_cache_acl' 'panel deploy no longer repairs the removed metric'
reject "$CLI" 'setfacl' 'CLI cache and self-update workflows no longer mutate cache filesystem ACLs'
reject "$CLI" 'Data Cached' 'CLI no longer carries the removed metric compatibility path'

reject "$COLLECTOR" 'tc_measure_cache_bytes' 'collector no longer scans FastCGI cache directories'
reject "$COLLECTOR" 'cache_last_attempt_at' 'collector no longer schedules disk-size scans'
reject "$COLLECTOR" 'cache_checked_at' 'collector no longer stores disk-size freshness state'
reject "$CONTROLLER" 'cache_checked_at' 'dashboard controller no longer reads disk-size state'
reject "$CONTROLLER" "'cache_bytes' => \$cacheBytes" 'dashboard analytics no longer exposes disk usage'
reject "$VIEW" 'dash.kpi.data_cached' 'dashboard no longer renders the Data Cached tile'
reject "$LANG" 'dash.kpi.data_cached' 'translations no longer advertise the removed tile'
reject "$CSS" 'repeat(4, minmax(0, 1fr))' 'KPI strip no longer reserves a fourth column'
require "$CSS" 'repeat(3, minmax(0, 1fr))' 'KPI strip uses three columns'

require "$INSTALLER" 'fastcgi_cache_path /var/cache/nginx/fastcgi' 'shared FastCGI cache remains configured'
require "$CLI" 'fastcgi_cache_path ${cdir}' 'dedicated FastCGI cache zones remain configured'
require "$CLI" 'cache:redis-acl --action reconcile' 'Redis ACL self-healing remains intact'
require "$COLLECTOR" 'cache_bytes = cache_bytes + excluded.cache_bytes' 'served-from-cache byte accounting remains intact'
require "$CONTROLLER" "'served_bytes' => \$status === 'ready' ? \$summary['cache_bytes'] : null" 'Served from Cache dashboard data remains intact'

printf 'Data Cached removal contract passed\n'
