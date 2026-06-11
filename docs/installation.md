# Installation Guide

## Requirements

| | Minimum | Recommended |
|--|---------|-------------|
| **OS** | Debian 11/12, Ubuntu 22.04/24.04 | Ubuntu 24.04 LTS |
| **RAM** | 512MB (1GB for WordPress) | 2GB+ |
| **Disk** | 5GB free | 20GB+ |
| **CPU** | 1 core | 2+ cores |
| **Arch** | x86_64, aarch64 | x86_64 |

---

## One-Command Install

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/rezzaidr/AidiPanel/master/install.sh)
```

This installs the full stack and deploys the web panel automatically:

- Nginx + FastCGI Cache
- PHP 8.4 (default) — 8.2 / 8.3 / 8.4 / 8.5 available on-demand
- MariaDB 10.11 LTS (default database engine)
- Redis
- Certbot (Let's Encrypt)
- UFW firewall + Fail2ban
- AidiPanel CLI + web panel

At the end, it prints a **random panel password**. Save it — it is also written to `/opt/aidipanel/credentials.conf`.

---

## Install from a Release Archive

If you downloaded a release archive:

```bash
unzip aidipanel-<version>.zip
cd aidipanel-<version>
sudo bash install.sh
```

The installer detects and deploys the `panel-app/` directory alongside it.

---

## Options

```bash
bash install.sh [OPTIONS]

  --port PORT           Panel HTTPS port (default: 8443)
  --db-engine ENGINE    Database engine (default: mariadb1011)
  --db-root-pass PASS   Set DB root password non-interactively
  --no-redis            Skip Redis installation
  --dry-run             Simulate install without making changes
```

**Examples:**

```bash
# Custom panel port
bash install.sh --port 9443

# MySQL 8.4 instead of MariaDB
bash install.sh --db-engine mysql84

# Without Redis
bash install.sh --no-redis
```

---

## Database Engine Options

| Flag | Engine | Notes |
|------|--------|-------|
| `mariadb1011` | MariaDB 10.11 LTS | **Default** |
| `mariadb114` | MariaDB 11.4 LTS | Newer LTS |
| `mariadb118` | MariaDB 11.8 | Latest |
| `mysql80` | MySQL 8.0 | — |
| `mysql84` | MySQL 8.4 LTS | — |

---

## After Install

### 1. Access the panel

```
https://<server-ip>:8443
```

Login with `admin` and the random password shown at the end of the install.

### 2. Add your first site

```bash
aidipanel site:add --domain example.com --user example --type wordpress
```

This creates a dedicated Linux user `example`, the web root at `/home/example/htdocs/example.com`, and a PHP-FPM pool running as that user. PHP defaults to 8.4 — pass `--php 8.3` to choose another installed version.

### 3. Install SSL

```bash
aidipanel ssl:install --domain example.com --email admin@example.com
```

### 4. Deploy WordPress

```bash
cd /home/example/htdocs/example.com
curl -O https://wordpress.org/latest.tar.gz
tar xzf latest.tar.gz --strip-components=1
rm latest.tar.gz
chown -R example:example .
```

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

## Supported OS Versions

| OS | Version | Codename |
|----|---------|----------|
| Ubuntu | 22.04 LTS | jammy |
| Ubuntu | 24.04 LTS | noble |
| Debian | 12 | bookworm |
| Debian | 11 | bullseye |

---

## Uninstall

There is no automated uninstaller yet. To remove manually:

```bash
# Stop services
systemctl stop nginx php8.4-fpm aidipanel-fpm mariadb redis-server

# Remove packages
apt-get purge -y nginx 'php8.*' mariadb-server redis-server certbot

# Remove panel files
rm -rf /opt/aidipanel /etc/aidipanel /var/cache/nginx/fastcgi
userdel aidipanel 2>/dev/null || true

# Reset UFW rules
ufw --force reset
```

Site users and their home directories under `/home/` are left untouched; remove them individually if desired.
