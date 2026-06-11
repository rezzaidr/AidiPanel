# Sites Management Guide

This guide covers adding, configuring, and removing websites in AidiPanel.

---

## The Per-Site Model

Every site AidiPanel creates gets:

- a **dedicated, no-login Linux user** (e.g. `example`)
- a web root at **`/home/<site-user>/htdocs/<domain>`**
- its **own PHP-FPM pool** that runs as that user

This keeps sites isolated from one another: a compromised site's PHP runs only as that site's user and cannot read other sites' files. SFTP/SSH login for site users is **disabled by default**.

---

## Adding a Site

### Via CLI

```bash
aidipanel site:add --domain example.com --user example --type wordpress
```

| Option | Default | Description |
|--------|---------|-------------|
| `--domain` | *(required)* | Domain name |
| `--user` | *(derived from domain)* | Dedicated Linux user for the site |
| `--type` | `php` | `wordpress`, `laravel`, `php`, `static`, `proxy` |
| `--php` | `8.4` | PHP version (must be installed; see PHP management) |

### Via Web Panel

1. Log into `https://<server-ip>:8443`
2. Go to **Sites → Add Site**
3. Fill in domain, site user, type, and PHP version
4. Click **Create**

### Site Types

| Type | Description | FastCGI Cache |
|------|-------------|---------------|
| `wordpress` | WordPress / WooCommerce | Available, off by default |
| `laravel` | Laravel application | Available, off by default |
| `php` | Generic PHP application | Available, off by default |
| `static` | Static HTML/CSS/JS | Not needed |
| `proxy` | Reverse proxy to another service | Not needed |

---

## Directory Structure

When you add `example.com` with user `example`, AidiPanel creates:

```
/home/example/
├── htdocs/example.com/   ← web root (upload your files here)
├── tmp/                  ← PHP upload/session tmp (per-site)
├── logs/                 ← per-site PHP error log
└── .aidipanel-managed    ← ownership marker (used by guarded delete)
```

**Nginx config:**

```
/etc/nginx/sites-available/example.com.conf
/etc/nginx/sites-enabled/example.com.conf   ← symlink
```

---

## Installing WordPress

```bash
# 1. Add the site
aidipanel site:add --domain example.com --user example --type wordpress

# 2. Install SSL
aidipanel ssl:install --domain example.com --email admin@example.com

# 3. Create a database
aidipanel db:add --name wp_example --user wp_example_user

# 4. Download WordPress
cd /home/example/htdocs/example.com
curl -O https://wordpress.org/latest.tar.gz
tar xzf latest.tar.gz --strip-components=1
rm latest.tar.gz

# 5. Set ownership to the site user
chown -R example:example /home/example/htdocs/example.com
```

Then complete WordPress setup at `https://example.com`.

---

## Installing Laravel

```bash
# 1. Add the site
aidipanel site:add --domain myapp.com --user myapp --type laravel

# 2. Deploy
cd /home/myapp/htdocs/myapp.com
git clone https://github.com/your/repo.git .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate

# 3. Ownership
chown -R myapp:myapp /home/myapp/htdocs/myapp.com

# 4. SSL
aidipanel ssl:install --domain myapp.com --email admin@myapp.com
```

For Laravel sites the vhost web root points at the `public/` directory.

---

## Switching PHP Version

```bash
# Via CLI (the version must already be installed)
aidipanel php:version --domain example.com --set 8.3

# Or via Panel → Sites → example.com → PHP Version
```

Changing the version recreates the site's PHP-FPM pool under the new version and repoints the vhost.

---

## Nginx Config Editor

```bash
# View current config
cat /etc/nginx/sites-available/example.com.conf

# Or: Web Panel → Sites → example.com → Edit Nginx Config
```

After editing through the panel, the config is tested (`nginx -t`) and reloaded automatically, with rollback on failure.

---

## Nginx Vhost Template

A typical WordPress vhost (`/etc/nginx/sites-available/example.com.conf`):

```nginx
server {
    listen 443 ssl http2;
    server_name example.com www.example.com;

    root /home/example/htdocs/example.com;
    index index.php index.html;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    include /etc/nginx/snippets/fastcgi-cache.conf;

    location ~* \.(jpg|jpeg|png|gif|css|js|svg|woff2|webp)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm-example.sock;
        fastcgi_cache aidipanel_fcgi;
        fastcgi_cache_valid 200 1h;
        fastcgi_cache_bypass $skip_cache;
        fastcgi_no_cache $skip_cache;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\. { deny all; }
    location ~ ^/(wp-config\.php|xmlrpc\.php)$ { deny all; }
}
```

Each site has its own FPM socket — `php<ver>-fpm-<site-user>.sock`.

---

## Deleting a Site

```bash
aidipanel site:delete --domain example.com
```

From the **web panel**, Delete performs a full guarded purge (behind a type-the-domain confirmation): it removes the vhost, the PHP-FPM pool, the panel DB record, the Linux site user, and the site's home directory — freeing the domain and user for immediate reuse. The purge only proceeds when the `.aidipanel-managed` marker validates, so it can never remove an unmanaged user or home.

The CLI default is more conservative — it removes the vhost and pool but keeps the home directory. Databases and SSL certificates are removed separately:

```bash
aidipanel db:delete --name wp_example
certbot delete --cert-name example.com
```

---

## File Permissions

AidiPanel sets ownership to the site user with a setgid web root so Nginx (group member) can read static files:

```bash
# Files are owned by the site user
chown -R example:example /home/example/htdocs/example.com

# WordPress uploads (writable by PHP, which runs as the site user)
chmod 755 /home/example/htdocs/example.com/wp-content/uploads/

# Laravel storage
chmod -R 775 /home/example/htdocs/example.com/storage/
chmod -R 775 /home/example/htdocs/example.com/bootstrap/cache/
```

---

## Multi-Domain / Aliases

The vhost includes the apex and `www` host by default:

```nginx
server_name example.com www.example.com;
```

For separate domains pointing at the same files, add them as separate sites or edit the vhost manually.

---

## Site Logs

```bash
# Via CLI
aidipanel log:tail --domain example.com --type access
aidipanel log:tail --domain example.com --type error

# Direct files
tail -f /var/log/nginx/example.com-access.log
tail -f /var/log/nginx/example.com-error.log
```
