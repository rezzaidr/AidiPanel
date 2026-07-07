# Testing

This page describes how to verify AidiPanel on a throwaway server before relying on a change.

## Linting (no server required)

The installer and CLI are Bash; the panel is PHP. Static checks:

```bash
# Bash syntax
bash -n install.sh
bash -n aidipanel
bash -n panel-app/deploy-panel.sh

# Installer unit tests
bash tests/unit/installer_archive_test.sh

# Bash lint (optional)
shellcheck -S warning install.sh aidipanel

# PHP syntax (requires php-cli)
find panel-app -name '*.php' -print0 | xargs -0 -n1 php -l
```

The same checks run in CI on every push and pull request.

## Manual verification on a fresh VPS

Use a disposable Ubuntu 24.04 (or Debian 12) server — never one holding important data.

```bash
# 1. Run the installer
sudo bash install.sh --port 8443 --db-engine mariadb123

# 2. Open the panel and log in with the password printed at the end
#    https://<server-ip>:8443
```

### Smoke checklist

- Services active: `nginx`, the default `php8.5-fpm`, `aidipanel-fpm`, `mariadb`/`mysql`, `redis-server`.
- Add a plain PHP site; confirm it serves over HTTPS.
- Add a WordPress site; confirm it serves.
- Enable FastCGI cache; confirm `X-FastCGI-Cache: HIT` on the second request.
- Purge cache for the domain.
- Install SSL for a domain whose DNS points at the server.
- Edit a vhost with a valid config (reload succeeds) and an invalid one (automatic rollback).
- Create a database, back it up, delete it.
- Confirm a site's PHP runs as its own user and cannot read another site's files.

## Notes

- For SSL tests without a real domain, a wildcard DNS service that maps a hostname to the server IP is convenient, together with Let's Encrypt **staging** to avoid rate limits.
- `install.sh` is a one-shot installer by design: re-running it on an already-installed server is refused safely (non-zero exit), and the system keeps working.
