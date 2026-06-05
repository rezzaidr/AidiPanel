# AidiPanel v1.2.8

> **by rezzaid** — Nginx Server Panel with FastCGI Cache

A free, lightweight VPS control panel built on Nginx + FastCGI Cache + PHP-FPM + Redis. No Varnish. No bloat. Installs in minutes.

## Quick Install

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/rezzaidr/aidipanel/main/install.sh)
```

One command installs the full stack **and** deploys the panel app automatically. A random admin password is shown at the end.

## Stack

- **Nginx** (official mainline) + FastCGI Cache
- **PHP-FPM** 8.1, 8.2, 8.3 (multi-version, switch per site)
- **MariaDB** 10.11 LTS (or MySQL 8.0/8.4)
- **Redis** (object cache, session store)
- **Certbot** (Let's Encrypt, auto-renewal)
- **UFW** + **Fail2ban** (security)
- **ProFTPD** (SFTP on port 2022)

## Supported OS

| OS | Versions |
|----|----------|
| Ubuntu | 22.04 LTS (jammy), 24.04 LTS (noble) |
| Debian | 11 (bullseye), 12 (bookworm) |

Architecture: x86_64 and aarch64

## Files in This Release

```
aidipanel-v1.2.8/
├── install.sh          ← Main installer (run this)
├── aidipanel           ← CLI tool (auto-installed by install.sh)
├── panel-app/          ← Web panel application (auto-deployed)
│   ├── deploy-panel.sh
│   ├── public/
│   ├── app/
│   └── storage/
├── docs/
│   ├── installation.md
│   ├── cli.md
│   ├── sites.md
│   ├── ssl.md
│   └── fastcgi-cache.md
└── index.html          ← Landing page (for GitHub Pages)
```

## Install Options

```
--port PORT           Panel HTTPS port (default: 8443)
--db-engine ENGINE    mariadb1011 | mariadb114 | mariadb118 | mysql80 | mysql84
--db-root-pass PASS   Set DB root password non-interactively
--no-redis            Skip Redis installation
--dry-run             Simulate without changes
```

## CLI Usage

```bash
aidipanel site:add    --domain example.com --type wordpress --php 8.3
aidipanel ssl:install --domain example.com --email admin@example.com
aidipanel cache:purge --domain example.com
aidipanel db:add      --name mydb --user myuser
aidipanel service:status
```

See [docs/cli.md](docs/cli.md) for full CLI reference.

## Changelog v1.2.8

- **Fix**: Auto-detect Ubuntu 24.04 noble + fix Nginx GPG/repo for noble
- **Fix**: RAM detection 0GB bug (now uses awk for accurate float display)
- **Fix**: Random panel admin password generated and displayed at install end
- **Fix**: One-command install — panel app deployed automatically
- **Fix**: Branding updated to "by rezzaid"
- **New**: Docs added: `cli.md`, `fastcgi-cache.md`, `sites.md`, `ssl.md`
- **Fix**: All 404 links on landing page corrected
- **Fix**: CLI supports both `mysql` and `mariadb` binary
- **Fix**: DB.php reads admin password from `credentials.conf` (no hardcoded 'admin')
- **Optimize**: Nginx worker_connections adaptive to RAM
- **Optimize**: PHP-FPM pool size adaptive to RAM
- **Optimize**: Redis maxmemory adaptive to RAM + persistence disabled (pure cache)
- **Optimize**: Nginx open_file_cache, tcp_fastopen, buffered logging

## License

MIT — free to use, modify, and distribute.
