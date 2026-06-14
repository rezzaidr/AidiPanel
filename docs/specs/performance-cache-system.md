# Performance & Cache System — Design Specification

**Version:** 1.0
**Status:** Approved for Phase 1

---

## Overview

AidiPanel manages site performance through a layered architecture. Each layer
operates at a different scope and must be presented separately to the user.
Keeping engine status and per-site feature status distinct ensures users always
understand what is ready at the infrastructure level versus what is configured
for their specific site.

---

## Layer Hierarchy

```
Infrastructure layer (server-wide)
├── Nginx + FastCGI engine         → Page Cache engine
├── Redis service                  → Object Cache engine
├── PHP OPcache                    → PHP Runtime cache
└── Protocol stack (HTTP/2, Gzip)  → Delivery optimisation

Per-site feature layer
├── Page Cache (FastCGI vhost)     → site-level ON / OFF
├── Object Cache (WP Redis plugin) → site-level ON / OFF (WordPress only)
└── WP helpers (Nginx Helper, etc) → site-level plugin state
```

Engine status and site-level feature status are always displayed separately.
"Redis service: Running" does not imply "Object Cache: Active" for a given site.

---

## 1. Page Cache

| Dimension            | Description                                             |
|----------------------|---------------------------------------------------------|
| Engine               | Nginx FastCGI cache zone (server-wide, shared)          |
| Per-site config      | Vhost FastCGI directives + per-site snippet             |
| Per-site toggle      | `cache:page --action enable/disable --domain`           |
| WP integration       | Nginx Helper plugin (auto-purge on publish)             |
| Flush scope          | Per-site cache key only, never global purge             |
| Supported site types | wordpress, php, laravel                                 |
| Unsupported types    | static, proxy                                           |

### Page Cache Status Values

| Field                | Values                                                   |
|----------------------|----------------------------------------------------------|
| `engine_ok`          | `0` or `1`                                              |
| `site_cache_status`  | `unsupported` / `disabled` / `active` / `broken`        |
| `wp_helper_status`   | `not_wordpress` / `not_installed` / `installed` / `inactive` / `active` / `unknown` |

### Page Cache Rules

- `site_cache_status=active` means FastCGI cache directives are uncommented in
  the site vhost and the per-site snippet exists.
- `site_cache_status=disabled` means directives are present but commented out,
  or the site has never had cache enabled.
- `site_cache_status=unsupported` for static/proxy site types.
- `can_purge=1` only when `site_cache_status=active`.
- `can_enable=1` when `engine_ok=1` and `site_cache_status=disabled`.
- `can_disable=1` when `site_cache_status=active`.

---

## 2. Object Cache

| Dimension            | Description                                             |
|----------------------|---------------------------------------------------------|
| Engine               | Redis service (server-wide)                             |
| Per-site config      | `WP_REDIS_*` constants in `wp-config.php`               |
| Per-site toggle      | `cache:redis --action enable/disable --domain`          |
| WP integration       | Redis Object Cache plugin + object-cache.php drop-in   |
| Key prefix           | `aidipanel:<site_user>:` (unique per site)              |
| Flush scope          | Per-site prefix only, via WP-CLI — never FLUSHALL       |
| Supported site types | wordpress only                                          |

### Object Cache Status Values

| Field                | Values                                                   |
|----------------------|----------------------------------------------------------|
| `service_ok`         | `0` or `1`                                              |
| `site_cache_status`  | `unsupported` / `service_down` / `not_connected` / `active` / `broken` |
| `plugin_status`      | `not_wordpress` / `not_installed` / `installed` / `inactive` / `active` / `unknown` |
| `dropin_status`      | `missing` / `present` / `unknown`                       |

### Object Cache Rules

- `service_ok=1` when Redis answers `PONG` on a connection test.
- `site_cache_status=active` when: Redis service is reachable AND the plugin
  directory exists in `wp-content/plugins/` AND `object-cache.php` drop-in is
  present in `wp-content/`. All three conditions must be true.
- `site_cache_status=service_down` when Redis is unreachable.
- `site_cache_status=not_connected` when Redis is up but the site is not
  configured to use it.
- `can_flush=1` only when `site_cache_status=active`.
- `can_enable=1` when `service_ok=1`, WordPress is detected, and the site is
  not already active.
- `can_disable=1` when `site_cache_status=active`.

### Key Prefix Convention

AidiPanel sets `WP_REDIS_PREFIX` to `aidipanel:<site_user>:` where `site_user`
is the dedicated Linux user for the site (e.g. `aidipanel:example-blog:`).

Using `site_user` rather than the domain ensures the prefix is unique,
shell-safe, and consistent with AidiPanel's per-site-user architecture.
The prefix is the single source of truth set during enable and read during
status checks.

**Unmanaged prefix:** If the prefix read from `wp-config.php` does not match
the expected `aidipanel:<site_user>:` pattern, the cache is considered
**active but unmanaged** (`prefix_managed=0`). This means the plugin was
configured manually or by another tool. AidiPanel displays the actual prefix
and labels the status accordingly. It will not overwrite the prefix unless
the user explicitly triggers an enable action in Phase 2.

---

## 3. PHP OPcache

| Dimension      | Description                                             |
|----------------|---------------------------------------------------------|
| Scope          | PHP runtime (server-wide, all sites share one OPcache) |
| Data source    | `opcache_get_status()` from within the panel process    |
| Site UI        | Read-only status display only                           |
| Management     | Admin Area → PHP                                        |

OPcache has no per-site enable/disable. The site Performance tab shows it as
informational context only.

---

## 4. Protocol & Compression

| Dimension      | Description                                                  |
|----------------|--------------------------------------------------------------|
| Scope          | Server-wide / global Nginx configuration                     |
| Site UI        | Read-only list (HTTP/2, HTTP/3, Brotli, Gzip, browser cache)|
| Management     | Admin Area → Server Tuning                                   |

Protocol settings are not per-site. The site Performance tab shows the
server-level status as a compact read-only reference.

Detection results are cached (5-minute file cache) to avoid repeated subprocess
calls on every page load. Non-blocking: if a check fails, the value is
`unknown` rather than an error.

---

## 5. CLI Contract

### 5.1 Page Cache: `cache:page`

```
aidipanel cache:page --action <action> --domain <domain> [options]
```

#### Action: `status`

Read-only. No writes. Output is `key=value`, one per line.

```
domain=example.com
site_user=example
webroot=/home/example/htdocs/example.com
site_type=wordpress
engine=fastcgi
engine_ok=1
site_cache_status=disabled
wp_helper_status=not_installed
cache_header=unknown
hit_rate=unknown
cache_path=/var/cache/nginx/fastcgi
can_enable=1
can_disable=0
can_purge=0
can_check=1
error=
```

#### Action: `enable` (Phase 2)

```
aidipanel cache:page --action enable --domain <domain>
```

Steps: resolve domain → validate vhost → backup vhost → create per-site snippet
→ uncomment FastCGI directives → `nginx -t` → reload → return status.

#### Action: `disable` (Phase 2)

```
aidipanel cache:page --action disable --domain <domain>
```

Steps: resolve domain → backup vhost → comment out FastCGI directives →
`nginx -t` → reload → purge site cache → return status.

#### Action: `purge` (Phase 3)

```
aidipanel cache:page --action purge --domain <domain>
aidipanel cache:page --action purge-url --domain <domain> --targets-file <path>
```

Purges cache for this site only.

#### Action: `check` (Phase 3)

```
aidipanel cache:page --action check --domain <domain> --url /
```

Output:
```
url=https://example.com/
http_status=200
cache_header=X-FastCGI-Cache
cache_status=HIT
ttfb_ms=86
error=
```

---

### 5.2 Object Cache: `cache:redis`

```
aidipanel cache:redis --action <action> --domain <domain>
```

#### Action: `status`

Read-only. No writes. Output is `key=value`, one per line.

```
domain=example.com
site_user=example
webroot=/home/example/htdocs/example.com
site_type=wordpress
engine=redis
service_ok=1
redis_host=127.0.0.1
redis_port=6379
site_cache_status=not_connected
plugin_status=not_installed
dropin_status=missing
prefix=
prefix_managed=0
site_keys=unknown
site_memory=unknown
wp_cli_missing=0
can_enable=1
can_disable=0
can_flush=0
error=
```

If `site_type` is not `wordpress`:
```
domain=example.com
site_user=example
webroot=/home/example/htdocs/example.com
site_type=php
engine=redis
service_ok=1
redis_host=127.0.0.1
redis_port=6379
site_cache_status=unsupported
plugin_status=not_wordpress
dropin_status=unknown
prefix=
prefix_managed=0
site_keys=unknown
site_memory=unknown
wp_cli_missing=0
can_enable=0
can_disable=0
can_flush=0
error=
```

#### Action: `enable` (Phase 2)

```
aidipanel cache:redis --action enable --domain <domain>
```

Steps:
1. Resolve domain → webroot + site_user.
2. Validate WordPress detected (wp-config.php).
3. Validate WP-CLI available.
4. Validate Redis service running.
5. Backup `wp-config.php` with timestamp.
6. Install plugin if absent:
   `sudo -u <site_user> wp plugin install redis-cache --path=<webroot>`
7. Activate plugin:
   `sudo -u <site_user> wp plugin activate redis-cache --path=<webroot>`
8. Set constants via WP-CLI (not sed):
   ```
   sudo -u <site_user> wp config set WP_REDIS_HOST 127.0.0.1 --type=constant --path=<webroot>
   sudo -u <site_user> wp config set WP_REDIS_PORT 6379 --type=constant --raw --path=<webroot>
   sudo -u <site_user> wp config set WP_REDIS_PREFIX aidipanel:<site_user>: --type=constant --path=<webroot>
   sudo -u <site_user> wp config set WP_REDIS_TIMEOUT 1 --type=constant --raw --path=<webroot>
   sudo -u <site_user> wp config set WP_REDIS_READ_TIMEOUT 1 --type=constant --raw --path=<webroot>
   ```
9. Enable drop-in:
   `sudo -u <site_user> wp redis enable --path=<webroot>`
10. Validate: re-run status and assert `site_cache_status=active`.

On failure after step 5: restore `wp-config.php` backup, deactivate plugin if
AidiPanel activated it in this run, return error code.

#### Action: `disable` (Phase 2)

```
aidipanel cache:redis --action disable --domain <domain>
```

Steps:
1. Resolve domain.
2. Validate WordPress.
3. Disable drop-in: `sudo -u <site_user> wp redis disable --path=<webroot>`
4. Deactivate plugin: `sudo -u <site_user> wp plugin deactivate redis-cache --path=<webroot>`

**Does not:** stop Redis service, delete keys, touch any other site.

#### Action: `flush` (Phase 3)

```
aidipanel cache:redis --action flush --domain <domain>
```

Must use WP-CLI. Uses site prefix only.
`sudo -u <site_user> wp redis flush --path=<webroot>`

Never uses `redis-cli FLUSHALL` or `FLUSHDB`.

---

## 6. Controller Data Model

### `$pageCacheInfo`

```php
[
    'engine'           => 'fastcgi',
    'engine_ok'        => true,
    'site_cache_status'=> 'disabled',   // disabled|active|broken|unsupported
    'wp_helper_status' => 'not_installed', // not_wordpress|not_installed|installed|inactive|active|unknown
    'cache_header'     => 'unknown',
    'hit_rate'         => 'unknown',
    'cache_path'       => '/var/cache/nginx/fastcgi',
    'can_enable'       => true,
    'can_disable'      => false,
    'can_purge'        => false,
    'can_check'        => true,
    'error'            => '',
]
```

### `$objectCacheInfo`

```php
[
    'engine'           => 'redis',
    'service_ok'       => true,
    'redis_host'       => '127.0.0.1',
    'redis_port'       => '6379',
    'site_cache_status'=> 'not_connected', // unsupported|service_down|not_connected|active|broken
    'plugin_status'    => 'not_installed', // not_wordpress|not_installed|installed|inactive|active|unknown
    'dropin_status'    => 'missing',       // missing|present|unknown
    'prefix'           => 'aidipanel:example:',
    'prefix_managed'   => true,     // false if prefix doesn't match aidipanel:<site_user>:
    'site_keys'        => 'unknown',
    'site_memory'      => 'unknown',
    'wp_cli_missing'   => false,
    'can_enable'       => true,
    'can_disable'      => false,
    'can_flush'        => false,
    'error'            => '',
]
```

### `$opcacheInfo`

```php
[
    'enabled'         => true,
    'scope'           => 'php_runtime',
    'php_version'     => '8.4',
    'hit_rate'        => '98.5',
    'memory_used'     => '17.3 MB',
    'memory_limit'    => '128 MB',
    'scripts_cached'  => 1400,
    'managed_at'      => 'Admin > PHP',
]
```

### `$protocolInfo`

```php
[
    'http2'                 => 'on',             // on|off|unknown
    'http3'                 => 'not_configured', // on|not_configured|unsupported|unknown
    'brotli'                => 'not_installed',  // on|off|not_installed|unknown
    'gzip'                  => 'on',             // on|off|unknown
    'browser_cache_headers' => 'on',             // on|off|unknown
    'scope'                 => 'server_level',
]
```

---

## 7. View States

### Performance Tab Structure

```
Summary row (4 compact cards):
  Page Cache  |  Object Cache  |  OPcache  |  Protocol

Full-width Page Cache card
Full-width Object Cache card
OPcache card (compact)
Protocol & Compression (simple list)
```

Page Cache and Object Cache cards are full-width (not side by side) because
they contain the primary actions for the site.

### Page Cache Card States

**Disabled / Setup:**
```
Page Cache [FastCGI]               Setup needed
──────────────────────────────────────────────
  Enable Page Cache for this site.
  FastCGI cache is available. Enabling will activate site cache.

  [Enable Page Cache]
```

**Active:**
```
Page Cache [FastCGI]               Active  [toggle on]
──────────────────────────────────────────────────────
  Status     Active  |  TTL  1 hour  |  Last purge  —  |  Zone  —

  [Advanced Cache Rules ▼]
    Bypass rules (default chips)
    Custom bypass URLs (textarea)
    Custom purge targets (textarea)

  [Purge]  saved by [Save]
```

**Unsupported (static/proxy):**
```
Page Cache [FastCGI]               Not applicable
──────────────────────────────────────────────────
  ℹ  Page Cache is available for PHP and WordPress sites.
     Not applicable for this site type.
```

### Object Cache Card States

**Not WordPress:**
```
Object Cache [Redis]               WordPress only
──────────────────────────────────────────────────
  Engine        Ready
  Site cache    Unsupported
  Integration   WordPress only
  Cache data    —

  Redis is available. Object cache integration is supported for WordPress sites.
```

**Service down:**
```
Object Cache [Redis]               Engine down
──────────────────────────────────────────────────
  ⚠  Redis service is not running.
     Start Redis in Admin Area → Services before enabling object cache.
  [Go to Services →]
```

**Setup / Not connected:**
```
Object Cache [Redis]               Setup needed
──────────────────────────────────────────────────
  Engine        Ready
  Site cache    Not connected
  WP plugin     Not installed
  Cache data    —

  [Enable Object Cache]
```

If plugin is installed but not active:
```
  [Enable Object Cache]  [Activate Plugin]
```

**Active:**
```
Object Cache [Redis]               Active  [toggle on]
──────────────────────────────────────────────────────
  Engine        Ready
  Site cache    Active
  WP plugin     Active
  Cache data    Keys: —   Memory: —

  [Flush Object Cache]
```

Cache data shows real values in Phase 3. Phase 1 shows `—`.

### OPcache Card

Read-only. No toggle. No action buttons in the site Performance tab.
OPcache restart is available in Admin Area → PHP only.

```
PHP OPcache [PHP]                  On
──────────────────────────────────────────────────
  Hit rate: 98.5% · Memory: 17.3 MB / 128 MB · Scripts: 1,400
  PHP 8.4  ·  Managed in Admin Area → PHP
```

### Protocol & Compression

Simple read-only list. No per-site controls.

```
Protocol & Compression   [server level]           Admin Area →
─────────────────────────────────────────────────────────────
  HTTP/2                 On
  HTTP/3 / QUIC          Not configured
  Brotli                 On
  Gzip                   On
  Browser cache headers  On
```

---

## 8. Safety Rules

The following constraints are non-negotiable across all phases:

- Never run `redis-cli FLUSHALL` or `FLUSHDB` from any site-level action.
- Never flush Redis keys belonging to other sites.
- Object Cache flush must use WP-CLI (`wp redis flush`) which respects the
  site prefix. No direct key deletion.
- Never use `redis-cli KEYS` for metrics — use `SCAN` with a cap and timeout.
- Never hardcode webroot paths like `/var/www`. All paths resolve from AidiPanel
  site data (vhost `root` directive).
- Per-site commands run as the site's Linux user via `sudo -u <site_user>`.
  Not as `www-data`, not with `--allow-root`.
- Never edit `wp-config.php` with raw `sed`. Use `wp config set --type=constant`.
- Back up `wp-config.php` with a timestamp before any write operation.
- Back up the Nginx vhost config before any write operation.
- Always run `nginx -t` before reloading Nginx. If it fails, restore the backup.
- Never stop the Redis service when disabling Object Cache for a single site.
- Never touch another site's cache, vhost, or Redis keys.
- Do not implement all phases simultaneously. Each phase is gated on the
  previous phase being stable and verified on production.

---

## 9. Rollback Rules

### Page Cache Enable Rollback

If any step fails after the vhost backup is taken:
1. Restore the backup: `cp <backup> <vhost>`
2. Run `nginx -t` to confirm the restored config is valid.
3. If `nginx -t` fails on the restored config, alert and do not reload.
4. Return `error=<step_name>_failed`.

### Object Cache Enable Rollback

If any step fails after `wp-config.php` is modified:
1. Restore `wp-config.php` from the timestamped backup.
2. If AidiPanel installed the plugin in this run, deactivate it.
3. If AidiPanel activated the plugin in this run, deactivate it.
4. Return `error=<step_name>_failed`.

### wp-config Safety Check (before write)

1. Resolve `realpath` — reject if the resolved path is outside the site webroot.
2. Confirm `wp-config.php` exists and is a regular file (not a symlink to an
   unexpected location).
3. Confirm the file is writable by the site user.
4. If any check fails: return `error=wp_config_not_safe` without modifying.

---

## 10. Error Codes

```
domain_not_found
site_not_managed
webroot_missing
site_user_missing
wp_not_detected
wp_cli_missing
redis_service_down
plugin_not_installed
plugin_install_failed_network
plugin_activate_failed
wp_config_not_safe
wp_config_not_writable
dropin_enable_failed
object_cache_not_connected
nginx_config_missing
nginx_test_failed
nginx_reload_failed
page_cache_not_enabled
unsupported_site_type
permission_denied
unknown_error
```

The panel maps these codes to user-friendly messages via the language file.

---

## 11. WP-CLI Dependency

WP-CLI is an official dependency for Object Cache Phase 2 and later.

Installation: `install.sh` downloads the phar to `/usr/local/bin/wp` and
verifies with `wp --info`.

Per-site commands run as:
```
sudo -u <site_user> wp --path=<webroot> <command>
```

No `--allow-root` flag. Site users do not need a login shell; `sudo -u`
does not require one.

Phase 1 (read-only status) does not require WP-CLI. Plugin and drop-in status
are detected via filesystem checks. If WP-CLI is missing, the status output
includes `wp_cli_missing=1` as an informational flag.

---

## 12. Testing

### Phase 1 Acceptance Criteria

**CLI output is correct:**
- All expected `key=value` fields are present.
- No write action occurs for any `status` call.
- Correct status for all 4 site/service scenarios (see section 13).

**Panel displays correct states:**
- Redis ready does not show Object Cache as active.
- FastCGI ready does not show Page Cache as active.
- Non-WordPress site does not show plugin install actions for Object Cache.
- OPcache remains read-only.
- Protocol list is accurate (not hardcoded).

**PHP lint clean:** `php -l` passes on all changed panel files.
**Bash syntax clean:** `bash -n aidipanel` passes.

### Phase 2 Acceptance Criteria

- Enable/disable triggers correct CLI actions and returns meaningful status.
- Backup files are created before any write.
- Rollback is triggered on failure and leaves the system clean.
- `nginx -t` is always run before reload.
- `wp-config.php` is never modified with sed.
- Commands run as the site user — confirmed via process listing.
- Redis global service is not stopped by site-level disable.
- Other sites are unaffected.

### Phase 3 Acceptance Criteria

- Object Cache flush is per-site only. Verified by confirming Redis keys for
  other sites are unchanged.
- No `FLUSHALL` call in any code path triggered from a site action.
- Redis metrics use `SCAN` with cap and timeout.
- Custom Page Cache purge accepts targets as one-per-line textarea input.
- Cache check returns real HTTP status and cache header value.

---

## 13. Phase Plan

### Phase 0 — Specification

Write and approve this specification before any implementation.

### Phase 1 — Read-Only Status

**Scope:** CLI status commands only. No write actions. Panel reads status and
displays correct state.

**Page Cache actions** (enable, disable, purge) are **fully functional** in
Phase 1, using the existing CLI backend (`cache:enable`, `cache:disable`,
`cache:purge`).

**Object Cache actions** (enable, disable, flush) are **visible but disabled**
pending Phase 2 implementation.

**Deliverables:**
- `aidipanel cache:page --action status --domain` (new command)
- `aidipanel cache:redis --action status --domain` (updated per-site, replaces
  global `cache:redis-status`)
- `SiteController`: replace `$redisInfo` with `$pageCacheInfo` + `$objectCacheInfo`
  + structured `$opcacheInfo` + `$protocolInfo`
- Performance tab: full redesign using the 4-state cards defined in section 7
- `en.php`: all new language keys for Phase 1 UI states
- `install.sh`: add WP-CLI install function (non-fatal in Phase 1)
- `tests/smoke/smoke.sh`: add smoke checks for `cache:page status` and
  `cache:redis status`

**Risk:** Low. All changes are read-only at the CLI level. Page load performance
may add ~100–200ms per CLI call on WP sites (Redis ping + file checks).

### Phase 2 — Enable / Disable

**Scope:** Implement enable and disable for Page Cache and Object Cache. Wire
up the action buttons. Full rollback on failure.

**Gated on:** Phase 1 verified stable on production.

**Risk:** Medium. Writes to vhost config and `wp-config.php`. WP-CLI is
required on the server. Backup and rollback logic is critical.

### Phase 3 — Flush, Purge, Check, Metrics

**Scope:** Object Cache flush (per-site WP-CLI), Page Cache purge with custom
targets, cache check (HTTP probe), real Redis metrics via SCAN.

**Gated on:** Phase 2 verified stable on production.

**Risk:** Medium. SCAN performance depends on Redis key count. HTTP probe adds
external network call. Custom purge requires target validation.

---

## 14. Out of Scope

The following are explicitly deferred and not part of this specification:

- Non-WordPress Object Cache (Memcached, APCu, Laravel cache)
- Redis ACL / password per site
- Redis cluster or Sentinel
- Custom Redis host/port per site (MVP assumes 127.0.0.1:6379)
- SFTP/SSH key management
- Database tab
- Automatic key prefix migration when `site_user` changes
- Admin Area global Redis management (existing, unchanged by this spec)
