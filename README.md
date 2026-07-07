# AidiPanel

> Nginx server control panel with built-in FastCGI Cache — lightweight by design.

AidiPanel is a server control panel for Ubuntu/Debian VPS, built on Nginx + FastCGI Cache + PHP-FPM + MariaDB/MySQL + Redis. It installs the full stack and a web panel in one command, and focuses on a small, fast footprint: page caching lives inside Nginx itself, and each site runs under its own isolated Linux user.

## Install

Start with a fresh Ubuntu/Debian VPS and fully update the base system first:

```bash
sudo apt update
sudo apt upgrade -y
```

If the upgrade creates `/var/run/reboot-required`, run `sudo reboot`, reconnect,
and only then start the AidiPanel installer:

```bash
curl -fsSL https://get.aidipanel.com | sudo bash
```

The installer deploys the full stack and web panel. A random admin password is printed at the end and saved to `/opt/aidipanel/credentials.conf`.

### Verify before running (recommended)

`get.aidipanel.com` redirects to the latest release installer. To review the script and check its checksum before running it as root:

```bash
curl -fLO https://github.com/rezzaidr/AidiPanel/releases/latest/download/install-aidipanel.sh
curl -fLO https://github.com/rezzaidr/AidiPanel/releases/latest/download/SHA256SUMS
grep ' install-aidipanel.sh$' SHA256SUMS | sha256sum -c -   # must print: install-aidipanel.sh: OK
sudo bash install-aidipanel.sh
```

Use a fresh, dedicated VPS: the installer provisions the system web/database stack and is not intended to adopt an existing production server.

## Stack

- **Nginx** (official mainline) with FastCGI Cache
- **PHP-FPM** — 8.5 installed by default; 8.2, 8.3, 8.4 available on-demand, switchable per site
- **MariaDB** 12.3 LTS by default (MariaDB 11.8 / 11.4 / 10.11 or MySQL 9.7 / 8.4 / 8.0 selectable at install)
- **Redis** for object cache and session storage
- **Certbot** (Let's Encrypt) with automatic renewal
- **UFW** firewall + **Fail2ban**

## Included in v1.2.0

- Site management for WordPress, PHP, Laravel, static sites, and reverse proxies
- File manager, per-site databases and phpMyAdmin, cron jobs, and PHP tuning
- Local site backups plus scheduled S3-compatible remote backups
- Opt-in jailed SFTP with password or SSH-key authentication
- Per-site Basic Auth, IP blocking, Cloudflare-only origin access, SSL controls, and cache controls
- Admin, manager, and client panel accounts with site assignment and optional two-factor authentication
- Service status, traffic metrics, cloud metadata, and read-only web-delivery diagnostics

## Site Isolation

Each site gets a **dedicated, no-login Linux user** and its **own PHP-FPM pool** running as that user:

```
/home/<site-user>/htdocs/<domain>
```

If one site's PHP is compromised, the process runs only as that site's user and cannot read other sites' files. This is per-user process/file isolation — not container-level sandboxing. Login is disabled by default; jailed SFTP-only access can be enabled per site without enabling an interactive SSH shell.

## Supported OS

| OS | Versions |
|----|----------|
| Ubuntu | 22.04 LTS (jammy), 24.04 LTS (noble), 26.04 LTS (resolute) |
| Debian | 11 (bullseye), 12 (bookworm) |

Architecture: x86_64 and aarch64.

> On Ubuntu 26.04 only MariaDB 11.8 / 12.3 can install (MySQL and MariaDB 10.11 / 11.4 are upstream-blocked). Recommended: Ubuntu 24.04 LTS.

## Install Options

```
--port PORT           Panel HTTPS port (default: 8443)
--db-engine ENGINE    mariadb123 | mariadb118 | mariadb114 | mariadb1011 | mysql97 | mysql84 | mysql80
--db-root-pass PASS   Set DB root password non-interactively
--no-redis            Skip Redis installation
--dry-run             Simulate without making changes
```

## CLI

```bash
aidipanel site:add    --domain example.com --user example --type php
aidipanel ssl:install --domain example.com --email admin@example.com
aidipanel cache:purge --domain example.com
aidipanel db:add      --name mydb --user myuser
aidipanel service:status
```

WordPress creation installs WordPress, its database, and its admin account in one transaction, so it requires the WordPress setup fields documented in [Sites](docs/sites.md). The default PHP version (8.5) is used when `--php` is omitted. See [docs/cli.md](docs/cli.md) for the full reference.

## Documentation

- [Installation](docs/installation.md)
- [Sites](docs/sites.md)
- [CLI reference](docs/cli.md)
- [FastCGI Cache](docs/fastcgi-cache.md)
- [SSL / TLS](docs/ssl.md)
- [Security model](docs/security.md)
- [Security policy](SECURITY.md)
- [Architecture](docs/architecture.md)
- [Roadmap](docs/roadmap.md)

## Support AidiPanel

AidiPanel is built and maintained independently.

If this project helps you manage servers more easily, save time, or learn server management with less confusion, consider supporting the development.

Your sponsorship helps fund VPS testing, documentation, security improvements, backup workflows, installer testing, and continued development.

[Become a Sponsor](https://github.com/sponsors/rezzaidr)

## License

Licensed under the MIT License — see [LICENSE](LICENSE).
