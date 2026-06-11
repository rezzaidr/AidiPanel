# Architecture

AidiPanel is a server control panel for Ubuntu/Debian. It provisions a complete web stack and a small web panel to manage it. The design goals are a **small footprint**, **fast responses**, and **isolation between sites**.

## Components

| Layer | Software | Role |
|-------|----------|------|
| Web server | Nginx (mainline) | Serves sites, terminates TLS, runs FastCGI Cache |
| Runtime | PHP-FPM | One pool per site; default version 8.4 |
| Database | MariaDB / MySQL | Application data |
| Cache | Redis | Object cache and session storage |
| TLS | Certbot (Let's Encrypt) | Certificates with auto-renewal |
| Firewall | UFW + Fail2ban | Network and brute-force protection |
| Panel | PHP web app | Web UI on port 8443 |
| CLI | aidipanel | Manage the stack from SSH |

## Request Flow

```
Browser
   |
   v
Nginx + FastCGI Cache -- hit --> cached response (served from disk)
   |
  miss
   v
PHP-FPM (per-site pool, runs as the site user)
   |
   v
MariaDB / MySQL + Redis
```

Page caching is handled inside Nginx via FastCGI Cache, so there is no separate caching daemon. Caching is off by default per site and enabled explicitly.

## Per-Site Isolation

Each site is provisioned with:

- a **dedicated Linux user** with **no login shell** (`/usr/sbin/nologin`) and a locked password;
- a web root at **`/home/<site-user>/htdocs/<domain>`**;
- its **own PHP-FPM pool** that runs as the site user.

Nginx (`www-data`) reads each site's static files through group membership; the web root is `setgid` so files created by PHP keep the right group. Other site users are not in that group and cannot read the files. If one site's PHP is exploited, the process runs only as that site's user — this is **per-user process and file isolation**, not container-level sandboxing.

A `/home/<site-user>/.aidipanel-managed` marker (owned `root:root`) records which user/domain AidiPanel manages. Destructive operations (purge-delete) validate this marker before touching anything, so they can never remove an unmanaged account or directory.

## PHP Version Policy

`/etc/aidipanel/php.conf` is the single source of truth:

```sh
PHP_DEFAULT_VERSION="8.4"
PHP_AVAILABLE_VERSIONS="8.2 8.3 8.4 8.5"
```

Only the default version is installed at provisioning time. Other supported versions are installed **on-demand** (`aidipanel php:install --version <ver>`), which keeps installs lean. The CLI sources this file; the panel reads it to list versions and checks the filesystem for install state.

## Privileged Operations

The web panel runs as `www-data` and cannot perform privileged actions directly. It invokes a single wrapper, `aidipanel-web-run`, via a narrowly scoped sudoers rule:

```
www-data ALL=(root) NOPASSWD: /usr/local/sbin/aidipanel-web-run *
```

The wrapper hard-codes an allowlist of permitted subcommands (defense in depth: the wrapper allowlist **and** the CLI's own validation). Arguments are passed as separate argv values, never interpolated into a shell string.

## Panel Runtime

The panel runs under its own dedicated PHP-FPM service, **`aidipanel-fpm`**, on a private socket (`/run/aidipanel-fpm.sock`), isolated from the site PHP-FPM services so that reloading site pools never affects the panel.

## Directory Map

| Path | Purpose |
|------|---------|
| `/opt/aidipanel/` | Panel application + storage |
| `/etc/aidipanel/php.conf` | PHP version policy |
| `/home/<site-user>/htdocs/<domain>` | Site web root |
| `/etc/nginx/sites-available/` | Vhost configs |
| `/var/cache/nginx/fastcgi/` | FastCGI cache files |
| `/usr/local/bin/aidipanel` | CLI |
| `/usr/local/sbin/aidipanel-web-run` | Privileged wrapper |
