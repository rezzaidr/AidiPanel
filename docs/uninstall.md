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
```

Review what `autoremove` proposes before confirming.
