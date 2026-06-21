# Uninstalling AidiPanel

AidiPanel provisions a full stack (Nginx, PHP-FPM, a database server, Redis)
and a control panel. Removal is manual and deliberate. Read this fully before
running anything.

## What the installer added

- Panel app under `/opt/aidipanel` and its systemd service.
- A dedicated FPM service (`aidipanel-fpm`) and its socket.
- Nginx vhosts under the Nginx config directory, plus the FastCGI cache zone.
- Per-site Linux users and their PHP-FPM pools (one per site you created).
- Stack packages: Nginx, PHP, the database server, Redis, Certbot, UFW, Fail2ban.
- Config under `/etc/aidipanel` and logs under `/var/log/aidipanel-*`.

## Back up first

```bash
# Panel database (SQLite)
sudo cp /opt/aidipanel/storage/db/aidipanel.sqlite ~/aidipanel.sqlite.bak

# Site files and databases (per site)
sudo tar czf ~/sites-backup.tar.gz /home/*/htdocs
sudo aidipanel db:backup --name <dbname>   # repeat per database
```

## Remove the panel only (keep the stack and sites)

```bash
sudo systemctl disable --now aidipanel aidipanel-fpm
sudo rm -rf /opt/aidipanel /etc/aidipanel
sudo rm -f /etc/sudoers.d/aidipanel /usr/local/sbin/aidipanel-web-run /usr/local/bin/aidipanel
# Remove the panel vhost from Nginx, then: sudo nginx -t && sudo systemctl reload nginx
```

## Remove the Security tab / Network Rules artifacts

The per-site Security tab (Basic Auth, IP Blocking, and the direct-origin
"Cloudflare only" control) and the global Cloudflare real-IP foundation write
Nginx config that each site's vhost references through managed markers. **Turn
each feature off first** so the CLI removes the vhost markers and their includes
together — a leftover marker that points at a deleted include leaves Nginx
unable to start.

```bash
# Per site, disable the features (removes the vhost markers + includes):
sudo aidipanel security:basic-auth      --domain example.com --action disable
sudo aidipanel security:ip-block        --domain example.com --action disable --purge
sudo aidipanel security:cloudflare-only --domain example.com --action disable
```

If the CLI is already gone, remove the artifacts by hand. With the rest of
AidiPanel still installed, remove **only** the managed Cloudflare cron block and
leave the other jobs in place:

```bash
# Global Cloudflare real-IP foundation
sudo rm -f  /etc/nginx/conf.d/aidipanel-cloudflare.conf
sudo rm -rf /etc/nginx/aidipanel/cloudflare        # live ip-ranges.conf + realip.state
sudo rm -rf /usr/share/aidipanel/cloudflare        # packaged seed

# Per-site Security artifacts and their includes
sudo rm -rf /etc/nginx/aidipanel/security              # htpasswd + ip-block/cloudflare-only state
sudo rm -f  /etc/nginx/conf.d/aidipanel-security-*.conf  # Basic Auth includes
sudo rm -f  /etc/nginx/conf.d/aidipanel-ipblock-*.conf   # IP Blocking includes

# Lock files (only if present)
sudo rm -f  /run/lock/aidipanel-cloudflare.lock /run/lock/aidipanel-security-*.lock

# Remove ONLY the managed Cloudflare refresh block from the shared cron file
sudo sed -i '/# >>> AIDIPANEL_CLOUDFLARE_REFRESH >>>/,/# <<< AIDIPANEL_CLOUDFLARE_REFRESH <<</d' /etc/cron.d/aidipanel

# If you deleted includes by hand, also strip the matching
# "# >>> AIDIPANEL_* >>> ... <<<" marker blocks from each site vhost, then:
sudo nginx -t && sudo systemctl reload nginx
```

The full-stack removal below deletes the entire `/etc/nginx/aidipanel`,
`/usr/share/aidipanel`, and `/etc/cron.d/aidipanel` roots along with everything
else, so these per-feature steps are only needed when you keep the stack.

## Remove a single site

```bash
sudo aidipanel site:delete --domain example.com --purge --yes --user <site-user>
```

This removes the vhost, the FPM pool, the site Linux user, and the web root.

## Remove the full stack

Only on a server you intend to wipe. This uninstalls shared services other
software may depend on.

```bash
sudo apt-get purge nginx 'php*' mariadb-server mysql-server redis-server certbot
sudo apt-get autoremove --purge

# AidiPanel-managed roots that package removal leaves behind
sudo rm -rf /etc/nginx/aidipanel /usr/share/aidipanel /etc/cron.d/aidipanel
sudo rm -f  /run/lock/aidipanel-*.lock
```

Review what `autoremove` proposes before confirming.
