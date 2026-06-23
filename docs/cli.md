# AidiPanel CLI Reference

The `aidipanel` CLI manages the entire server stack from SSH.

## Usage

```bash
aidipanel <command> [options]
```

The CLI is installed automatically by `install.sh` at `/usr/local/bin/aidipanel`.

---

## Site Management

### `site:add`

Add a new site with a dedicated Linux user and per-site PHP-FPM pool.

```bash
aidipanel site:add --domain example.com --user example --type wordpress
```

| Option | Default | Description |
|--------|---------|-------------|
| `--domain` | *(required)* | Domain name |
| `--user` | *(derived from domain)* | Dedicated no-login Linux user for the site |
| `--type` | `php` | `wordpress`, `laravel`, `php`, `static`, `proxy` |
| `--php` | `8.4` | PHP version (must be installed) |

Creates `/home/<user>/htdocs/<domain>`, a PHP-FPM pool running as `<user>`, and the Nginx vhost. The default PHP version (8.4) is used when `--php` is omitted.

### `site:delete`

```bash
aidipanel site:delete --domain example.com
```

CLI default keeps the site's home directory. Add `--purge --yes --user <user>` to also remove the Linux user and home (guarded by the `.aidipanel-managed` marker).

### `site:list` / `site:info`

```bash
aidipanel site:list
aidipanel site:info --domain example.com
```

---

## Cache Management

AidiPanel uses **Nginx FastCGI Cache**. Caching is off by default per site and enabled explicitly.

```bash
aidipanel cache:status
aidipanel cache:purge                      # purge all
aidipanel cache:purge  --domain example.com
aidipanel cache:enable --domain example.com
aidipanel cache:disable --domain example.com

# Optional WordPress helper plugins when enabling cache
aidipanel cache:enable --domain example.com --install-nginx-helper --install-redis-plugin
```

---

## Database Management

```bash
aidipanel db:add    --name mydb --user myuser
aidipanel db:delete --name mydb
aidipanel db:list
aidipanel db:backup --name mydb
# Output: /opt/aidipanel/storage/backups/mydb-<timestamp>.sql.gz
```

---

## PHP Management

PHP 8.4 is installed by default. 8.2, 8.3, and 8.5 are available on-demand.

### `php:list`

```bash
aidipanel php:list
```

Shows each available version with its status (installed / default / available).

### `php:install`

Install an available version on-demand.

```bash
aidipanel php:install --version 8.5
```

### `php:version`

Get or set the PHP version for a domain (the version must already be installed).

```bash
aidipanel php:version --domain example.com
aidipanel php:version --domain example.com --set 8.3
```

### `php:restart` / `php:info`

```bash
aidipanel php:restart                 # all installed versions
aidipanel php:restart --version 8.4   # one version
aidipanel php:info    --version 8.4
```

---

## SSL / TLS Management

See [ssl.md](ssl.md) for full documentation.

```bash
aidipanel ssl:install --domain example.com --email admin@example.com
aidipanel ssl:renew
aidipanel ssl:status
aidipanel ssl:import  --domain example.com   # import an existing cert
```

---

## Service Management

```bash
aidipanel service:status
aidipanel service:restart nginx
aidipanel service:restart php8.4-fpm
aidipanel service:restart redis
aidipanel service:restart mariadb
aidipanel service:reload  nginx
aidipanel service:start   <service>
aidipanel service:stop    <service>
```

The `php` alias resolves to the default PHP version's FPM service.

---

## User Management

> Site users are created and managed automatically by `site:add` / `site:delete` as no-login accounts. The `user:*` commands are low-level helpers for managing those system accounts directly; most workflows do not need them.

```bash
aidipanel user:list                       # list managed site users
aidipanel user:delete --user example
aidipanel user:2fa-reset --user admin     # break-glass: clear a panel account's 2FA
```

> `user:2fa-reset` is the recovery path for a panel **login account** that has lost
> both its authenticator app and its recovery codes. Run on the server as root; it
> clears the account's two-factor secret and recovery codes in the panel database so
> the user can sign in with their password alone, then re-enable 2FA.

---

## System

```bash
aidipanel system:info
aidipanel log:tail --domain example.com --type access   # access | error | php
aidipanel self:update
```

---

## Quick Reference

```
SITE     site:add  site:delete  site:list  site:info  vhost:save
CACHE    cache:status  cache:purge  cache:enable  cache:disable
DB       db:add  db:delete  db:list  db:backup
PHP      php:install  php:list  php:version  php:restart  php:info
SSL      ssl:install  ssl:renew  ssl:status  ssl:import
SERVICE  service:status  service:restart  service:reload  service:start  service:stop
USER     user:list  user:delete  user:2fa-reset
SYSTEM   system:info  log:tail  self:update
```
