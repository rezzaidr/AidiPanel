# Installation Guide

> **by rezzaid** — AidiPanel v1.2.0

---

## Requirements

| | Minimum | Recommended |
|--|---------|-------------|
| **OS** | Debian 11/12, Ubuntu 22.04/24.04 | Ubuntu 22.04 LTS |
| **RAM** | 512MB (1GB for WordPress) | 2GB+ |
| **Disk** | 5GB free | 20GB+ |
| **CPU** | 1 core | 2+ cores |
| **Arch** | x86_64, aarch64 | x86_64 |

---

## One-Command Install

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/rezzaidr/aidipanel/master/install.sh)
```

This installs **everything** and deploys the panel app automatically:
- Nginx + FastCGI Cache
- PHP 8.1, 8.2, 8.3 (multi-version)
- MariaDB 10.11 (LTS)
- Redis
- Certbot (Let's Encrypt)
- ProFTPD (SFTP)
- UFW firewall + Fail2ban
- AidiPanel CLI + Web Panel

At the end, it prints your **random panel password**. Save it.

---

## Install from ZIP

If you downloaded the release ZIP:

```bash
unzip aidipanel-v1.2.0.zip
cd aidipanel-v1.2.0
sudo bash install.sh
```

The installer auto-detects and deploys the `panel-app/` directory alongside it.

---

## Options

```bash
bash install.sh [OPTIONS]

  --port PORT           Panel HTTPS port (default: 8443)
  --db-engine ENGINE    Database engine (default: mariadb1011)
  --db-root-pass PASS   Set DB root password non-interactively
  --no-redis            Skip Redis installation
  --dry-run             Simulate install without changes
```

**Examples:**

```bash
# Custom port
bash install.sh --port 9443

# MySQL 8.4 instead of MariaDB
bash install.sh --db-engine mysql84

# Without Redis (saves ~50MB RAM)
bash install.sh --no-redis
```

---

## Database Engine Options

| Flag | Engine | Notes |
|------|--------|-------|
| `mariadb1011` | MariaDB 10.11 LTS | **Default** — best for WordPress |
| `mariadb114` | MariaDB 11.4 LTS | Newer LTS |
| `mariadb118` | MariaDB 11.8 | Latest |
| `mysql80` | MySQL 8.0 | — |
| `mysql84` | MySQL 8.4 LTS | — |

---

## After Install

### 1. Access the panel

```
https://<your-server-ip>:8443
```

Login: `admin` / `<random password shown at install>`

### 2. Add your first site

```bash
aidipanel site:add --domain example.com --type wordpress --php 8.3
```

### 3. Install SSL

```bash
aidipanel ssl:install --domain example.com --email admin@example.com
```

### 4. Deploy WordPress

```bash
cd /var/www/example.com/htdocs
curl -O https://wordpress.org/latest.tar.gz
tar xzf latest.tar.gz --strip-components=1
rm latest.tar.gz
chown -R www-data:www-data .
```

---

## Files & Directories

| Path | Description |
|------|-------------|
| `/opt/aidipanel/` | Panel home directory |
| `/opt/aidipanel/public/` | Panel web root |
| `/opt/aidipanel/storage/db/aidipanel.sqlite` | Panel database |
| `/opt/aidipanel/credentials.conf` | All generated credentials |
| `/opt/aidipanel/config/panel.conf` | Panel configuration |
| `/var/www/` | All hosted websites |
| `/etc/nginx/sites-available/` | Nginx vhost configs |
| `/var/cache/nginx/fastcgi/` | FastCGI cache files |
| `/usr/local/bin/aidipanel` | CLI tool |
| `/var/log/aidipanel-install.log` | Install log |

---

## Credentials

All credentials are saved to `/opt/aidipanel/credentials.conf`:

```bash
sudo cat /opt/aidipanel/credentials.conf
```

Keep this file secure — it contains database root password and panel admin password.

---

## Supported OS Versions

| OS | Version | Codename | Status |
|----|---------|----------|--------|
| Ubuntu | 22.04 LTS | jammy | ✓ Supported |
| Ubuntu | 24.04 LTS | noble | ✓ Supported |
| Debian | 12 | bookworm | ✓ Supported |
| Debian | 11 | bullseye | ✓ Supported |

---

## Uninstall

There is no automated uninstaller yet. To remove manually:

```bash
# Stop services
systemctl stop nginx php8.1-fpm php8.2-fpm php8.3-fpm mariadb redis-server proftpd

# Remove packages
apt-get purge -y nginx php8.1* php8.2* php8.3* mariadb-server redis-server proftpd-basic certbot

# Remove panel files
rm -rf /opt/aidipanel /var/cache/nginx/fastcgi
userdel aidipanel 2>/dev/null || true

# Remove UFW rules
ufw --force reset
```
