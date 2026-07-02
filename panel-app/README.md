# AidiPanel Web Panel App

This is the web panel application for AidiPanel. It is deployed automatically by `install.sh`.

## Manual Deploy

If you need to re-deploy or update the panel app:

```bash
cd panel-app/
sudo bash deploy-panel.sh
```

On an existing installation, deployment preserves panel accounts, passwords,
sites, databases, configuration, and runtime storage. A random admin password is
generated only when deploying a clean panel database with no existing admin.

For normal release upgrades, prefer `sudo aidipanel self:update`; it downloads
the matching CLI and panel assets, verifies `SHA256SUMS`, and deploys both together.

## Structure

```
panel-app/
├── deploy-panel.sh     ← Deploy script
├── bin/                ← Scheduled panel helpers
├── build/              ← Frontend asset build scripts
├── resources/          ← Frontend source files
├── public/             ← Web root (Nginx serves this)
│   └── index.php       ← Entry point
├── app/
│   ├── Core/           ← Framework core (Router, DB, Auth, Session)
│   ├── Controllers/    ← Page controllers
│   ├── Views/          ← PHP templates
│   └── Middleware/     ← Auth + CSRF middleware
```

Runtime storage is preserved separately at `/opt/aidipanel/storage`; it is not
part of the release application tree.

## Stack

- Pure PHP, no framework (Router + SQLite + Alpine.js)
- No Composer needed
- Runs as the dedicated `aidipanel` user through `aidipanel-fpm`
- Served by Nginx on port 8443 by default

## Admin Password

Generated randomly on the initial installation and saved to
`/opt/aidipanel/credentials.conf`. Re-deploying does not rotate it.

```bash
sudo grep PANEL_ADMIN /opt/aidipanel/credentials.conf
```
