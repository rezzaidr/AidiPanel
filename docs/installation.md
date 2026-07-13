# Installation Guide

## Requirements

| | Minimum | Recommended |
|--|---------|-------------|
| **OS** | Debian 11/12, Ubuntu 22.04/24.04/26.04 | Ubuntu 24.04 LTS |
| **RAM** | 512MB (1GB for WordPress) | 2GB+ |
| **Disk** | 5GB free | 20GB+ |
| **CPU** | 1 core | 2+ cores |
| **Arch** | x86_64, aarch64 | x86_64 |

> Install on a fresh, dedicated VPS. AidiPanel provisions the system Nginx,
> PHP-FPM, database, Redis, firewall defaults, and service configuration; it is
> not an in-place importer for an existing production web stack.

Before installing AidiPanel, update and upgrade the fresh base system:

```bash
sudo apt update
sudo apt upgrade -y
```

Wait for both commands to finish successfully. If
`/var/run/reboot-required` exists afterward, run `sudo reboot`, reconnect to the
server, and continue only after the reboot completes.

---

## Quick Install

On a fresh VPS:

```bash
curl -fsSL https://get.aidipanel.com | sudo bash
```

`get.aidipanel.com` redirects to the installer from the latest stable GitHub
release. To pass installer options through the pipe, use `bash -s --`:

```bash
curl -fsSL https://get.aidipanel.com | sudo bash -s -- --db-engine mysql84 --port 9443
```

## Verify Before Running (recommended)

`curl | sudo bash` relies on HTTPS to bootstrap the installer. The installer
then verifies the signed CLI and panel artifacts, but the following path also
authenticates the installer before it is executed as root:

```bash
curl -fLO https://github.com/rezzaidr/AidiPanel/releases/latest/download/install-aidipanel.sh
curl -fLO https://github.com/rezzaidr/AidiPanel/releases/latest/download/SHA256SUMS
curl -fLO https://github.com/rezzaidr/AidiPanel/releases/latest/download/SHA256SUMS.sig
curl -fLO https://github.com/rezzaidr/AidiPanel/releases/latest/download/release-signing-public.pub

openssl pkey -pubin -in release-signing-public.pub -outform DER \
  | sha256sum
openssl dgst -sha256 -verify release-signing-public.pub \
  -signature SHA256SUMS.sig SHA256SUMS
grep ' install-aidipanel.sh$' SHA256SUMS | sha256sum -c -
sudo bash install-aidipanel.sh
```

The expected DER SHA-256 public-key fingerprint is
`63cd4bf5ed9f184c9042977cec91e25d0928cc361c4e54bfb496d31f74f4d901`. Compare it with the independently
published value at `https://aidipanel.com/security` or in the AidiPanel DNS
record—not only with a key delivered by the same GitHub release. Continue only
when OpenSSL prints `Verified OK` and the checksum command prints
`install-aidipanel.sh: OK`.

This installs the full stack and deploys the web panel automatically:

- Nginx + FastCGI Cache
- PHP 8.5 (default) — 8.2 / 8.3 / 8.4 available on-demand
- MariaDB 12.3 LTS (default database engine)
- Redis
- Certbot (Let's Encrypt)
- UFW firewall + Fail2ban
- AidiPanel CLI + web panel

At the end, it prints a **random panel password**. Save it — it is also written to `/opt/aidipanel/credentials.conf`.

---

## Install from a Source Archive

If you downloaded the GitHub source archive for a tagged release:

```bash
unzip AidiPanel-<version>.zip
cd AidiPanel-<version>
sudo bash install.sh
```

The installer detects and deploys the `panel-app/` directory alongside it.

---

## Options

```bash
sudo bash install.sh [OPTIONS]

  --port PORT           Panel HTTPS port (default: 8443)
  --db-engine ENGINE    Database engine (default: mariadb123)
  --db-root-pass PASS   Set DB root password non-interactively
  --no-redis            Skip Redis installation
  --dry-run             Simulate install without making changes
```

**Examples:**

```bash
# Custom panel port
sudo bash install.sh --port 9443

# MySQL 8.4 instead of MariaDB
sudo bash install.sh --db-engine mysql84

# Without Redis
sudo bash install.sh --no-redis
```

---

## Database Engine Options

| Flag | Engine | Notes |
|------|--------|-------|
| `mariadb123` | MariaDB 12.3 LTS | **Default** |
| `mariadb118` | MariaDB 11.8 | — |
| `mariadb114` | MariaDB 11.4 LTS | — |
| `mariadb1011` | MariaDB 10.11 LTS | — |
| `mysql97` | MySQL 9.7 LTS | — |
| `mysql84` | MySQL 8.4 LTS | — |
| `mysql80` | MySQL 8.0 | — |

> On Ubuntu 26.04 only MariaDB 11.8 / 12.3 can install (MySQL and MariaDB 10.11 / 11.4 are upstream-blocked).

---

## After Install

### 1. Access the panel

```
https://<server-ip>:8443
```

Login with `admin` and the random password shown at the end of the install.

### 2. Add your first site

```bash
sudo aidipanel site:add --domain example.com --user example --type php
```

This creates a dedicated Linux user `example`, the web root at
`/home/example/htdocs/example.com`, and a PHP-FPM pool running as that user.
PHP defaults to 8.5; pass `--php 8.3` to choose another installed version.

For WordPress, the web panel provides the simplest setup form. The equivalent
CLI flow installs WordPress, its database, and its admin account together:

```bash
printf '%s\n' 'StrongPassword123!' |
sudo aidipanel site:add \
  --domain blog.example.com \
  --user blog \
  --type wordpress \
  --wp-title 'Example Blog' \
  --wp-admin-user admin \
  --wp-admin-pass-stdin \
  --wp-admin-email admin@example.com
```

Use a unique password and avoid a literal `--wp-admin-pass` on a shared shell
because it can be retained in shell history. Subdirectory multisite is available
with `--wp-multisite subdir`; wildcard subdomain multisite is not yet managed.

### 3. Install SSL

```bash
sudo aidipanel ssl:install --domain example.com --email admin@example.com
```

Once a trusted certificate is active, enable force HTTPS and HSTS from the
site's SSL tab when appropriate.

---

## Files & Directories

| Path | Description |
|------|-------------|
| `/opt/aidipanel/` | Panel home directory |
| `/opt/aidipanel/public/` | Panel web root |
| `/opt/aidipanel/storage/db/aidipanel.sqlite` | Panel database |
| `/opt/aidipanel/credentials.conf` | Generated credentials |
| `/etc/aidipanel/php.conf` | PHP version policy (default + available) |
| `/home/<site-user>/htdocs/<domain>` | Site web root |
| `/etc/nginx/sites-available/` | Nginx vhost configs |
| `/var/cache/nginx/fastcgi/` | FastCGI cache files |
| `/usr/local/bin/aidipanel` | CLI tool |
| `/var/log/aidipanel-install.log` | Install log |

---

## Credentials

All generated credentials are saved to `/opt/aidipanel/credentials.conf`:

```bash
sudo cat /opt/aidipanel/credentials.conf
```

Keep this file secure — it contains the database root password and panel admin password.

---

## Updating an Existing Installation

Do not run the installer again on an installed server; it refuses to overwrite
an existing panel. Use the verified updater instead:

```bash
sudo aidipanel self:update
```

The updater downloads matching CLI and panel release assets, verifies them
against `SHA256SUMS`, and preserves panel users, passwords, sites, databases,
configuration, and runtime storage.

---

## Supported OS Versions

| OS | Version | Codename |
|----|---------|----------|
| Ubuntu | 26.04 LTS | resolute |
| Ubuntu | 24.04 LTS | noble |
| Ubuntu | 22.04 LTS | jammy |
| Debian | 12 | bookworm |
| Debian | 11 | bullseye |

> Recommended: Ubuntu 24.04 LTS. On 26.04 only MariaDB 11.8 / 12.3 can install (MySQL and MariaDB 10.11 / 11.4 are upstream-blocked).

---

## Uninstall

There is no automated uninstaller yet. Back up all sites and databases, then
follow the [manual uninstall guide](uninstall.md). It separates panel-only,
single-site, and full-stack removal without resetting unrelated firewall rules.

Site users and their home directories under `/home/` are left untouched; remove them individually if desired.
