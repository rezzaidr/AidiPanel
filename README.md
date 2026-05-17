<div align="center">

# ⚡ AidiPanel

**A lightweight, fast server control panel built on Nginx + FastCGI Cache + PHP-FPM + Redis**

[![Version](https://img.shields.io/badge/version-1.1.0-5C6BC0?style=flat-square)](https://github.com/rezzaidr/aidipanel/releases)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)
[![OS](https://img.shields.io/badge/OS-Debian%2011%2F12%20%7C%20Ubuntu%2022.04%2F24.04-orange?style=flat-square)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3-777BB4?style=flat-square)](#stack)

[**Install**](#installation) · [**Features**](#features) · [**Documentation**](#documentation) · [**Screenshots**](#screenshots)

</div>

---

## What is AidiPanel?

AidiPanel is a free, open-source server control panel inspired by CloudPanel — but with one key difference: instead of Varnish cache, AidiPanel uses **Nginx FastCGI Cache + Redis** for a simpler, more efficient caching architecture with no extra services.

Everything runs inside Nginx itself. No Varnish daemon, no additional reverse proxy layer. Just Nginx doing what it was built to do.

```bash
# Install in one command
curl -fsSL https://get.aidipanel.id | bash
```

---

## Features

| Feature | AidiPanel |
|---|---|
| Web server | Nginx (official repo) |
| Cache layer | FastCGI Cache (built into Nginx) |
| Object cache | Redis |
| PHP versions | 8.1, 8.2, 8.3 (switchable per site) |
| Database | MariaDB 10.11 / 11.4 / 11.8 · MySQL 8.0 / 8.4 |
| SSL | Let's Encrypt via Certbot (auto-renew) |
| FTP/SFTP | ProFTPD (SFTP only, port 2022) |
| Firewall | UFW |
| Brute-force | Fail2ban |
| Panel DB | SQLite (lightweight, no extra setup) |
| Panel port | HTTPS 8443 (configurable) |

**Site types supported:** WordPress · Laravel · PHP · Static HTML · Reverse Proxy

---

## Stack

```
Browser → Nginx (port 80/443)
              ↓
         FastCGI Cache  ←── Redis (object cache)
              ↓
         PHP-FPM (8.1 / 8.2 / 8.3)
              ↓
         MariaDB / MySQL
```

vs. CloudPanel:

```
Browser → Nginx → Varnish → PHP-FPM → MySQL + Redis
```

AidiPanel removes the Varnish layer entirely. Cache is handled natively by Nginx, reducing RAM usage and complexity.

---

## Requirements

| Resource | Minimum | Recommended |
|---|---|---|
| OS | Debian 11/12, Ubuntu 22.04/24.04 | Debian 12 |
| RAM | 1 GB | 2 GB+ |
| Disk | 10 GB free | 20 GB+ |
| Arch | x86_64, aarch64 | x86_64 |
| Access | Root SSH | Root SSH |

---

## Installation

```bash
# Download and run installer
curl -fsSL https://raw.githubusercontent.com/rezzaidr/aidipanel/main/install.sh -o install.sh
bash install.sh
```

**With options:**

```bash
# MariaDB 11.4 on port 8080
bash install.sh --db-engine mariadb114 --port 8080

# MySQL 8.0 with preset root password
bash install.sh --db-engine mysql80 --db-root-pass "your-password"

# See all options
bash install.sh --help
```

**Available `--db-engine` values:**

| Value | Database |
|---|---|
| `mariadb1011` | MariaDB 10.11 LTS ← **default, best for WordPress** |
| `mariadb114` | MariaDB 11.4 LTS |
| `mariadb118` | MariaDB 11.8 |
| `mysql80` | MySQL 8.0 |
| `mysql84` | MySQL 8.4 LTS |

**After install, deploy the panel web app:**

```bash
# Upload and deploy panel app
scp -r aidipanel-app/ root@<server-ip>:~/
cd ~/aidipanel-app && bash deploy-panel.sh
```

**Access the panel:**
```
https://<server-ip>:8443
Username: admin
Password: admin  ← change immediately!
```

---

## CLI Tool

After installation, manage your server with the `aidipanel` CLI:

```bash
# Sites
aidipanel site:add --domain example.com --type wordpress --php 8.3
aidipanel site:list
aidipanel site:info --domain example.com
aidipanel site:delete --domain example.com

# Cache
aidipanel cache:status
aidipanel cache:purge                        # purge all
aidipanel cache:purge --domain example.com   # purge per domain
aidipanel cache:purge --url https://example.com/page  # purge by URL
aidipanel cache:enable --domain example.com
aidipanel cache:disable --domain example.com

# Database
aidipanel db:add --name mydb --user myuser
aidipanel db:list
aidipanel db:backup --name mydb
aidipanel db:delete --name mydb

# PHP
aidipanel php:version --domain example.com --set 8.3
aidipanel php:restart
aidipanel php:list

# SSL
aidipanel ssl:install --domain example.com --email admin@example.com
aidipanel ssl:renew
aidipanel ssl:status

# Services
aidipanel service:status
aidipanel service:restart nginx
aidipanel service:restart php8.3-fpm

# System
aidipanel system:info
aidipanel log:tail --domain example.com --type access
```

---

## Repository Structure

```
aidipanel/
├── install.sh              ← Server installer (run via SSH)
├── aidipanel               ← CLI tool binary
├── aidipanel-app/          ← Panel web application (PHP)
│   ├── public/             ← Nginx webroot
│   ├── app/
│   │   ├── Core/           ← Router, Auth, DB, Session
│   │   ├── Controllers/    ← 10 controllers
│   │   ├── Views/          ← PHP templates
│   │   └── Middleware/     ← Auth + CSRF
│   └── deploy-panel.sh     ← Deploy script
├── docs/                   ← Documentation
└── .github/
    └── workflows/          ← GitHub Actions
```

---

## Documentation

- [Installation Guide](docs/installation.md)
- [CLI Reference](docs/cli.md)
- [FastCGI Cache Configuration](docs/fastcgi-cache.md)
- [Adding Sites](docs/sites.md)
- [SSL Configuration](docs/ssl.md)

---

## License

MIT License — free to use, modify, and distribute.

---

## Credits

Inspired by [CloudPanel](https://www.cloudpanel.io/) — a great project that showed how lightweight a server panel can be.

---

<div align="center">
Made with ⚡ by <a href="https://github.com/rezzaidr">rezzaidr</a>
</div>
