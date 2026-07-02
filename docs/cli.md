# AidiPanel CLI Reference

The `aidipanel` CLI manages the entire server stack from SSH.

## Usage

```bash
aidipanel <command> [options]
```

The CLI is installed automatically by `install.sh` at `/usr/local/bin/aidipanel`.
Commands that change system state require root; run them from a root shell or
prefix them with `sudo`. Examples below omit repeated `sudo` except where a
pipeline makes the privilege boundary important.

---

## Site Management

### `site:add`

Add a new site with a dedicated Linux user and per-site PHP-FPM pool.

```bash
sudo aidipanel site:add --domain example.com --user example --type php
```

| Option | Default | Description |
|--------|---------|-------------|
| `--domain` | *(required)* | Domain name |
| `--user` | *(derived from domain)* | Dedicated no-login Linux user for the site |
| `--type` | `php` | `wordpress`, `laravel`, `php`, `static`, `proxy` |
| `--php` | `8.4` | PHP version (must be installed) |
| `--proxy-pass` | `http://127.0.0.1:3000` | Upstream URL for a proxy site |

Creates `/home/<user>/htdocs/<domain>`, a PHP-FPM pool running as `<user>`, and the Nginx vhost. The default PHP version (8.4) is used when `--php` is omitted.

WordPress creation performs the database and WordPress installation atomically.
Supply its required setup fields and pass the password over stdin:

```bash
printf '%s\n' 'StrongPassword123!' |
sudo aidipanel site:add \
  --domain blog.example.com \
  --type wordpress \
  --wp-title 'Example Blog' \
  --wp-admin-user admin \
  --wp-admin-pass-stdin \
  --wp-admin-email admin@example.com
```

Use `--wp-multisite subdir` for subdirectory multisite. Use `--type php` for a
bare document root; a WordPress type without the setup fields is rejected.

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
aidipanel cache:config --domain example.com
aidipanel cache:zone --action status --domain example.com

# Optional WordPress helper plugins when enabling cache
aidipanel cache:enable --domain example.com --install-nginx-helper --install-redis-plugin

# Redis and OPcache operations
aidipanel cache:redis-status
aidipanel cache:redis-acl --action status
aidipanel cache:opcache-restart --php 8.4
```

`cache:config` manages per-site TTL and bypass rules. `cache:zone` gives a noisy
site an optional dedicated FastCGI cache budget; see [fastcgi-cache.md](fastcgi-cache.md).

---

## Site Security and Cron

```bash
aidipanel security:status --domain example.com
aidipanel security:basic-auth --domain example.com --action status
aidipanel security:ip-block --domain example.com --action status
aidipanel security:cloudflare-only --domain example.com --action status
aidipanel cloudflare:realip --action status

aidipanel cron:list --domain example.com
aidipanel cron:wp --domain example.com --action enable
```

The Security tab manages Basic Auth, IP deny rules, and direct-origin blocking
for Cloudflare-proxied sites. Mutating cron and security commands validate their
stdin payloads and update Nginx or crontab state transactionally.

---

## File Manager and SFTP

The web panel provides text editing, chunked uploads, download, rename, copy,
move, permissions, compression, and extraction within a site's contained web
root. The matching `files:*` CLI commands are primarily the panel's privileged
backend and take paths or content on stdin rather than interpolating shell text.

SFTP is disabled by default. Enable jailed SFTP-only access per site and then
set a password or add a public key:

```bash
aidipanel sftp:status  --domain example.com
aidipanel sftp:enable  --domain example.com
printf '%s\n' 'UniqueSftpPassword!' | sudo aidipanel sftp:passwd --domain example.com
printf '%s\n' 'ssh-ed25519 AAAA...' | sudo aidipanel sftp:key-add --domain example.com
aidipanel sftp:disable --domain example.com
```

Interactive SSH shells remain disabled.

---

## Backups

```bash
aidipanel backup:create --domain example.com --keep 5
aidipanel backup:list   --domain example.com

aidipanel remote-backup:status
aidipanel remote-backup:run
```

Local backups contain a site's files and database metadata and are managed from
the site's Backup tab. The Admin Backup page configures scheduled S3-compatible
destinations for AWS S3, Wasabi, or DigitalOcean Spaces, tests credentials before
activation, and runs backups across all sites. Secrets are never returned by the
status command.

---

## Database Management

```bash
aidipanel db:add    --name mydb --user myuser
aidipanel db:delete --name mydb
aidipanel db:list
aidipanel db:users --site example.com
aidipanel db:user-add --site example.com --name appuser --db mydb
aidipanel db:backup --name mydb
# Output: /opt/aidipanel/storage/backups/mydb-<timestamp>.sql.gz
```

The web panel's Database tab manages databases and users and can install an
isolated phpMyAdmin runtime. Database passwords are transported over stdin and
stored by AidiPanel's encrypted credential broker rather than exposed in argv.

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

Per-site memory, upload, timeout, and additional PHP settings are managed from
the site's PHP settings form. The panel saves the policy and invokes
`php:settings` to rebuild the selected site's pool.

---

## SSL / TLS Management

See [ssl.md](ssl.md) for full documentation.

```bash
aidipanel ssl:install --domain example.com --email admin@example.com
aidipanel ssl:renew
aidipanel ssl:status
aidipanel ssl:import  --domain example.com --cert fullchain.pem --key privkey.pem
aidipanel ssl:check   --domain example.com
aidipanel ssl:force-https --domain example.com --action on
aidipanel ssl:hsts        --domain example.com --action on
aidipanel ssl:autorenew   --domain example.com --action status
aidipanel panel:domain --action status
aidipanel panel:ssl    --action status
```

HSTS ships off for new sites and should be enabled only after a trusted
certificate is active. `panel:domain` and `panel:ssl` configure a trusted bare
hostname for the panel while port 8443 remains the recovery route.

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

Panel login accounts are managed in the Admin Users page. Roles are `admin`,
`manager`, and `client`; client accounts receive explicit site assignments.

---

## System

```bash
aidipanel system:info
aidipanel system:cloud-metadata
aidipanel web-delivery:status
aidipanel log:tail --domain example.com --type access   # access | error | php
aidipanel self:update
```

`self:update` downloads the matching CLI and web-panel release assets, verifies
both against the release `SHA256SUMS`, and deploys them together. Existing panel
users, passwords, sites, databases, and runtime storage are preserved.

`system:cloud-metadata` reports sanitized cached provider metadata when
available. `web-delivery:status` returns read-only origin/Nginx delivery
diagnostics and intentionally exposes no raw configuration or listener output.

The root-only `demo:enable` and `demo:disable` commands toggle the public
read-only demo mode. They are deployment tools, not web-invokable panel actions.

---

## Quick Reference

```
SITE     site:add  site:delete  site:list  site:info  vhost:save
CACHE    cache:status  cache:purge  cache:enable  cache:disable  cache:config  cache:zone
SECURITY security:status  security:basic-auth  security:ip-block  security:cloudflare-only
FILES    files:*  sftp:*  cron:*
BACKUP   backup:*  remote-backup:*
DB       db:add  db:delete  db:list  db:users  db:user-*  db:backup
PHP      php:install  php:list  php:version  php:settings  php:restart  php:info
SSL      ssl:install  ssl:renew  ssl:status  ssl:import  ssl:force-https  ssl:hsts
SERVICE  service:status  service:restart  service:reload  service:start  service:stop
USER     user:list  user:delete  user:2fa-reset
SYSTEM   system:info  system:cloud-metadata  web-delivery:status  log:tail  self:update
```
