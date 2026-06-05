# FastCGI Cache Guide

> **by rezzaid** — AidiPanel v1.2.0

AidiPanel uses **Nginx FastCGI Cache** — a built-in Nginx feature that caches PHP responses as files on disk. This eliminates the need for Varnish while achieving equivalent (often better) performance.

---

## How It Works

```
Browser request
     │
     ▼
Nginx FastCGI Cache ──── HIT ────► Return cached response (microseconds)
     │
    MISS
     │
     ▼
PHP-FPM processes request
     │
     ▼
Response cached to disk → sent to browser
```

**Cache hit** = PHP never runs. Response served from disk in ~1ms.  
**Cache miss** = PHP runs normally, response cached for next visitor.

---

## Cache Configuration

The cache zone is defined in `/etc/nginx/nginx.conf`:

```nginx
fastcgi_cache_path /var/cache/nginx/fastcgi
    levels=1:2
    keys_zone=aidipanel_fcgi:200m
    max_size=10g
    inactive=60m
    use_temp_path=off;

fastcgi_cache_key "$scheme$request_method$host$request_uri";
fastcgi_cache_use_stale error timeout invalid_header updating http_500 http_503;
fastcgi_cache_lock on;
```

**Key settings:**

| Setting | Value | Meaning |
|---------|-------|---------|
| `keys_zone` | `200m` | 200MB RAM for cache index (~1.6M entries) |
| `max_size` | `10g` | Max 10GB disk cache |
| `inactive` | `60m` | Remove uncached entries after 60 min |
| `use_temp_path=off` | — | Write directly to cache dir (faster) |

---

## Cache Exclusion Rules

Located in `/etc/nginx/snippets/fastcgi-cache.conf`, included in every vhost:

```nginx
set $skip_cache 0;

# Skip POST requests (form submissions)
if ($request_method = POST) { set $skip_cache 1; }

# Skip URLs with query strings (?page=2)
if ($query_string != "") { set $skip_cache 1; }

# Skip WordPress admin/login/cart/checkout
if ($request_uri ~* "(/wp-admin/|/wp-login.php|/cart|/checkout|/my-account)") {
    set $skip_cache 1;
}

# Skip for logged-in WordPress users
if ($http_cookie ~* "(wordpress_logged_in|woocommerce_items_in_cart)") {
    set $skip_cache 1;
}
```

---

## Per-Site Cache Config

Each site's PHP block includes these directives:

```nginx
fastcgi_cache        aidipanel_fcgi;
fastcgi_cache_valid  200 301 302 1h;   # Cache 200/301/302 for 1 hour
fastcgi_cache_valid  404 1m;           # Cache 404s for 1 minute
fastcgi_cache_bypass  $skip_cache;     # Skip cache when $skip_cache=1
fastcgi_no_cache      $skip_cache;     # Don't store when $skip_cache=1
```

The response header `X-FastCGI-Cache: HIT|MISS|BYPASS|EXPIRED` tells you cache status.

---

## Managing Cache via CLI

```bash
# Check cache status and hit rate
aidipanel cache:status

# Purge entire cache
aidipanel cache:purge

# Purge cache for one domain
aidipanel cache:purge --domain example.com

# Disable cache for a domain
aidipanel cache:disable --domain example.com

# Re-enable cache
aidipanel cache:enable --domain example.com
```

---

## Managing Cache via Web Panel

1. Log into the panel at `https://<server-ip>:8443`
2. Go to **Cache** in the sidebar
3. View hit rate, total cached files, and per-domain status
4. Use **Purge** to clear cache for a domain or all domains

---

## Checking Cache Status with curl

```bash
# First request — MISS (PHP runs)
curl -I https://example.com/
# X-FastCGI-Cache: MISS

# Second request — HIT (served from disk)
curl -I https://example.com/
# X-FastCGI-Cache: HIT
```

---

## WordPress-Specific: Nginx Cache Purge Plugin

For WordPress sites, install **Nginx Cache** plugin or **Nginx Helper** so WordPress automatically purges cache when posts are published/updated.

In `wp-config.php`:

```php
define('RT_WP_NGINX_HELPER_CACHE_PATH', '/var/cache/nginx/fastcgi');
```

---

## Adjusting Cache Duration

Edit the site's Nginx config in `/etc/nginx/sites-available/<domain>.conf`:

```nginx
# Cache for 4 hours instead of 1
fastcgi_cache_valid  200 301 302 4h;
```

Then reload: `systemctl reload nginx`

---

## Cache Directory

```
/var/cache/nginx/fastcgi/
├── a/
│   └── 3f/
│       └── a3f8c2e1b4...  (cached PHP response)
├── b/
│   └── ...
```

The cache uses a 2-level directory structure (`levels=1:2`) for efficient filesystem lookups.

**Manual purge:**

```bash
# Purge all
find /var/cache/nginx/fastcgi -type f -delete

# Purge one domain's cache (less precise, purge all and let it rebuild)
aidipanel cache:purge --domain example.com
```

---

## Performance Tips

1. **Keep cache TTL high** for static content sites (1h–24h)
2. **Keep cache TTL low** for frequently updated sites (5–15min)
3. **Use Redis Object Cache** for WordPress alongside FastCGI Cache — they complement each other:
   - FastCGI Cache: page-level caching (full HTML response)
   - Redis Object Cache: database query caching inside PHP
4. **Exclude WooCommerce cart/checkout** — already done by default snippet
5. **Monitor** with `aidipanel cache:status` to ensure high hit rate (>80% is good)
