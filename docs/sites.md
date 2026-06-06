# Sites Management Guide

> **by rezzaid** — AidiPanel v1.2.0

This guide covers adding, configuring, and removing websites from AidiPanel.

---

## Adding a Site

### Via CLI

```bash
aidipanel site:add --domain example.com --type wordpress --php 8.3
```

### Via Web Panel

1. Log into `https://<server-ip>:8443`
2. Click **Sites** → **Add Site**
3. Fill in domain, type, and PHP version
4. Click **Create**

### Site Types

| Type | Description | FastCGI Cache |
|------|-------------|---------------|
| `wordpress` | WordPress + WooCommerce optimized | Available, off by default |
| `laravel` | Laravel with queue worker support | Available, off by default |
| `php` | Generic PHP application | Available, off by default |
| `static` | Static HTML/CSS/JS only | — Not needed |
| `proxy` | Reverse proxy to another service | — Not needed |

---

## Directory Structure

When you add `example.com`, AidiPanel creates:

```
/var/www/example.com/
├── htdocs/           ← Web root (upload your files here)
│   └── index.php
└── logs/             ← Per-site logs (symlinked from /var/log/nginx/)
```

**Nginx config:**

```
/etc/nginx/sites-available/example.com.conf
/etc/nginx/sites-enabled/example.com.conf  ← symlink
```

---

## Installing WordPress

After adding a site:

```bash
# 1. Add the site
aidipanel site:add --domain example.com --type wordpress --php 8.3

# 2. Install SSL
aidipanel ssl:install --domain example.com --email admin@example.com

# 3. Create WordPress database
aidipanel db:add --name wp_example --user wp_example_user

# 4. Download WordPress
cd /var/www/example.com/htdocs
curl -O https://wordpress.org/latest.tar.gz
tar xzf latest.tar.gz --strip-components=1
rm latest.tar.gz

# 5. Set permissions
chown -R www-data:www-data /var/www/example.com/
```

Then complete WordPress setup at `https://example.com`.

---

## Installing Laravel

```bash
# 1. Add the site
aidipanel site:add --domain myapp.com --type laravel --php 8.3

# 2. Deploy Laravel (example via git)
cd /var/www/myapp.com/htdocs
git clone https://github.com/your/repo.git .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate

# 3. Set permissions
chown -R www-data:www-data storage bootstrap/cache

# 4. Install SSL
aidipanel ssl:install --domain myapp.com --email admin@myapp.com
```

---

## Switching PHP Version

```bash
# Via CLI
aidipanel php:version --domain example.com --php 8.2

# Or via Panel → Sites → example.com → PHP Version
```

---

## Nginx Config Editor

AidiPanel lets you edit the Nginx config for any site directly:

```bash
# CLI — view current config
cat /etc/nginx/sites-available/example.com.conf

# Web Panel → Sites → example.com → Edit Nginx Config
```

After editing, the panel automatically tests (`nginx -t`) and reloads.

---

## Nginx Vhost Template

A typical WordPress vhost (`/etc/nginx/sites-available/example.com.conf`):

```nginx
server {
    listen 443 ssl http2;
    server_name example.com www.example.com;

    root /var/www/example.com/htdocs;
    index index.php index.html;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    # FastCGI cache exclusion rules
    include /etc/nginx/snippets/fastcgi-cache.conf;

    # Static files
    location ~* \.(jpg|jpeg|png|gif|css|js|svg|woff2|webp)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # WordPress permalinks
    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    # PHP with FastCGI cache
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_cache aidipanel_fcgi;
        fastcgi_cache_valid 200 1h;
        fastcgi_cache_bypass $skip_cache;
        fastcgi_no_cache $skip_cache;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Security: deny hidden files and sensitive paths
    location ~ /\. { deny all; }
    location ~ ^/(wp-config\.php|xmlrpc\.php)$ { deny all; }
}
```

---

## Deleting a Site

```bash
aidipanel site:delete --domain example.com
```

This removes:
- Nginx vhost (`/etc/nginx/sites-available/example.com.conf`)
- Nginx symlink from `sites-enabled`
- Site record from panel database

**Does NOT remove:**
- Files in `/var/www/example.com/` (must delete manually)
- Database (use `aidipanel db:delete`)
- SSL certificate (use `certbot delete --cert-name example.com`)

---

## File Permissions

AidiPanel sets recommended permissions automatically:

```bash
# Standard web files
chown -R www-data:www-data /var/www/example.com/htdocs/

# WordPress uploads (writable by PHP)
chmod 755 /var/www/example.com/htdocs/wp-content/uploads/

# Laravel storage (writable by PHP)
chmod -R 775 /var/www/example.com/htdocs/storage/
chmod -R 775 /var/www/example.com/htdocs/bootstrap/cache/
```

---

## Multi-Domain / Aliases

To add `www.example.com` as an alias for `example.com`, the vhost already includes both:

```nginx
server_name example.com www.example.com;
```

For multiple separate domains pointing to the same files, add them as separate sites or edit the vhost manually.

---

## Site Logs

```bash
# Tail access log
aidipanel log:tail --domain example.com --type access

# Tail error log
aidipanel log:tail --domain example.com --type error

# Direct log files
tail -f /var/log/nginx/example.com-access.log
tail -f /var/log/nginx/example.com-error.log
```
