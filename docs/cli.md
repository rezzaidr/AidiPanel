# AidiPanel CLI Reference

> **by rezzaid** — AidiPanel v1.2.0

The `aidipanel` CLI lets you manage your entire server stack from SSH — no web UI needed.

## Installation

The CLI is installed automatically by `install.sh`. To install manually:

```bash
cp aidipanel /usr/local/bin/aidipanel
chmod +x /usr/local/bin/aidipanel
```

## Usage

```bash
aidipanel <command> [options]
```

---

## Site Management

### `site:add`

Add a new website/domain.

```bash
aidipanel site:add --domain example.com --type wordpress --php 8.3
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--domain` | *(required)* | Domain name |
| `--type` | `php` | Site type: `wordpress`, `laravel`, `php`, `static`, `proxy` |
| `--php` | `8.3` | PHP version: `8.1`, `8.2`, `8.3` |

**What it does:**
- Creates `/var/www/<domain>/htdocs/`
- Generates Nginx vhost with FastCGI cache
- Enables cache by default
- Registers site in the panel database

---

### `site:delete`

Delete a site and optionally its files.

```bash
aidipanel site:delete --domain example.com
```

---

### `site:list`

List all managed sites.

```bash
aidipanel site:list
```

---

### `site:info`

Show detailed info for a site.

```bash
aidipanel site:info --domain example.com
```

---

## Cache Management

AidiPanel uses **Nginx FastCGI Cache** — no Varnish, no Redis for page caching.

### `cache:status`

Show cache zone stats and per-domain status.

```bash
aidipanel cache:status
```

### `cache:purge`

Purge cache for all sites or a specific domain.

```bash
# Purge all
aidipanel cache:purge

# Purge one domain
aidipanel cache:purge --domain example.com
```

### `cache:enable` / `cache:disable`

Toggle FastCGI cache for a specific domain.

```bash
aidipanel cache:enable  --domain example.com
aidipanel cache:disable --domain example.com
```

---

## Database Management

### `db:add`

Create a new database and user.

```bash
aidipanel db:add --name mydb --user myuser
```

### `db:delete`

Delete a database and its user.

```bash
aidipanel db:delete --name mydb
```

### `db:list`

List all databases (excluding system DBs).

```bash
aidipanel db:list
```

### `db:backup`

Backup a database to a `.sql.gz` file.

```bash
aidipanel db:backup --name mydb
# Output: /opt/aidipanel/storage/backups/mydb-<timestamp>.sql.gz
```

---

## PHP Management

### `php:list`

List installed PHP versions and their FPM status.

```bash
aidipanel php:list
```

### `php:version`

Get or set PHP version for a domain.

```bash
# Get
aidipanel php:version --domain example.com

# Set
aidipanel php:version --domain example.com --php 8.2
```

### `php:restart`

Restart PHP-FPM for all or a specific version.

```bash
# All versions
aidipanel php:restart

# Specific version
aidipanel php:restart --version 8.3
```

### `php:info`

Show PHP info for a version.

```bash
aidipanel php:info --version 8.3
```

---

## SSL / TLS Management

See [ssl.md](ssl.md) for full SSL documentation.

### `ssl:install`

Install a Let's Encrypt certificate.

```bash
aidipanel ssl:install --domain example.com --email admin@example.com
```

### `ssl:renew`

Renew certificates.

```bash
aidipanel ssl:renew
```

### `ssl:status`

Show certificate expiry status for all domains.

```bash
aidipanel ssl:status
```

---

## Service Management

### `service:status`

Show status of all AidiPanel services.

```bash
aidipanel service:status
```

### `service:restart` / `service:start` / `service:stop`

Manage individual services.

```bash
aidipanel service:restart nginx
aidipanel service:restart php8.3-fpm
aidipanel service:restart redis
aidipanel service:restart mariadb
```

---

## User Management

### `user:add`

Add a system user for SSH/SFTP access.

```bash
aidipanel user:add --user john --domain example.com
```

### `user:delete`

Delete a system user.

```bash
aidipanel user:delete --user john
```

### `user:list`

List all site users.

```bash
aidipanel user:list
```

### `user:passwd`

Change or reset user password.

```bash
# Generate random password
aidipanel user:passwd --user john

# Set specific password
aidipanel user:passwd --user john --pass NewPass123
```

---

## System

### `system:info`

Show server and panel information.

```bash
aidipanel system:info
```

### `log:tail`

Tail Nginx or PHP logs.

```bash
aidipanel log:tail --domain example.com --type access
aidipanel log:tail --domain example.com --type error
aidipanel log:tail --domain example.com --type php
```

### `self:update`

Update the AidiPanel CLI to latest version.

```bash
aidipanel self:update
```

---

## Quick Reference

```
SITE     site:add  site:delete  site:list  site:info
CACHE    cache:status  cache:purge  cache:enable  cache:disable
DB       db:add  db:delete  db:list  db:backup
PHP      php:list  php:version  php:restart  php:info
SSL      ssl:install  ssl:renew  ssl:status
SERVICE  service:status  service:restart  service:start  service:stop
USER     user:add  user:delete  user:list  user:passwd
SYSTEM   system:info  log:tail  self:update
```
