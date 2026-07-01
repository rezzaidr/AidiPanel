# AidiPanel

> Nginx server control panel with built-in FastCGI Cache — lightweight by design.

AidiPanel is a server control panel for Ubuntu/Debian VPS, built on Nginx + FastCGI Cache + PHP-FPM + MariaDB/MySQL + Redis. It installs the full stack and a web panel in one command, and focuses on a small, fast footprint: page caching lives inside Nginx itself, and each site runs under its own isolated Linux user.

## Quick Install

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/rezzaidr/AidiPanel/master/install.sh)
```

One command installs the full stack **and** deploys the web panel. A random admin password is printed at the end and saved to `/opt/aidipanel/credentials.conf`.

## Stack

- **Nginx** (official mainline) with FastCGI Cache
- **PHP-FPM** — 8.4 installed by default; 8.2, 8.3, 8.4, 8.5 available on-demand, switchable per site
- **MariaDB** 10.11 LTS by default (MariaDB 11.4 / 11.8 or MySQL 8.0 / 8.4 selectable at install)
- **Redis** for object cache and session storage
- **Certbot** (Let's Encrypt) with automatic renewal
- **UFW** firewall + **Fail2ban**

## Site Isolation

Each site gets a **dedicated, no-login Linux user** and its **own PHP-FPM pool** running as that user:

```
/home/<site-user>/htdocs/<domain>
```

If one site's PHP is compromised, the process runs only as that site's user and cannot read other sites' files. This is per-user process/file isolation — not container-level sandboxing. SFTP/SSH login for site users is **disabled by default**.

## Supported OS

| OS | Versions |
|----|----------|
| Ubuntu | 22.04 LTS (jammy), 24.04 LTS (noble) |
| Debian | 11 (bullseye), 12 (bookworm) |

Architecture: x86_64 and aarch64.

## Install Options

```
--port PORT           Panel HTTPS port (default: 8443)
--db-engine ENGINE    mariadb1011 | mariadb114 | mariadb118 | mysql80 | mysql84
--db-root-pass PASS   Set DB root password non-interactively
--no-redis            Skip Redis installation
--dry-run             Simulate without making changes
```

## CLI

```bash
aidipanel site:add    --domain example.com --user example --type wordpress
aidipanel ssl:install --domain example.com --email admin@example.com
aidipanel cache:purge --domain example.com
aidipanel db:add      --name mydb --user myuser
aidipanel service:status
```

The default PHP version (8.4) is used when `--php` is omitted. See [docs/cli.md](docs/cli.md) for the full reference.

## Documentation

- [Installation](docs/installation.md)
- [Sites](docs/sites.md)
- [CLI reference](docs/cli.md)
- [FastCGI Cache](docs/fastcgi-cache.md)
- [SSL / TLS](docs/ssl.md)
- [Security model](docs/security.md)
- [Architecture](docs/architecture.md)
- [Roadmap](docs/roadmap.md)

## Support AidiPanel

AidiPanel is built and maintained independently.

If this project helps you manage servers more easily, save time, or learn server management with less confusion, consider supporting the development.

Your sponsorship helps fund VPS testing, documentation, security improvements, backup workflows, installer testing, and continued development.

[Become a Sponsor](https://github.com/sponsors/rezzaidr)

## License

Licensed under the MIT License — see [LICENSE](LICENSE).
