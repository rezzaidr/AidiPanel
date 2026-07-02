# Troubleshooting

## Install / panel

**Port 8443 already in use**
Choose another port at install time: `--port 9443`. Check what holds it with
`sudo ss -ltnp | grep :8443`.

**Nginx fails to start or reload**
Run `sudo nginx -t` to see the exact config error. AidiPanel rolls back a bad
vhost on save, but a manual edit can still break the global config.

**PHP version install fails**
Some PHP point releases lack every extension in the third-party repo. AidiPanel
skips repo-absent extension packages and logs them. Check
`/var/log/aidipanel-install.log` for the package that failed.

**Certbot / Let's Encrypt fails**
Usually DNS: the domain (and `www` if requested) must resolve to this server
before issuance. AidiPanel pre-checks DNS per name and reports which one failed.

**Redis not active**
`sudo systemctl status redis-server`. If it was skipped at install (`--no-redis`),
install it later and enable it.

**SQLite permission errors in the panel**
The panel runs as the dedicated `aidipanel` user; the database lives at
`/opt/aidipanel/storage/db/aidipanel.sqlite`. Ensure the `storage` tree is owned
by `aidipanel:aidipanel`.

## CI

**shellcheck job is red**
Run it locally: `shellcheck -S warning install.sh aidipanel panel-app/deploy-panel.sh`.
Warnings fail the job; fix them or justify with a scoped `# shellcheck disable=` line.
