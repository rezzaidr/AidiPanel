# AidiPanel Web Panel App v1.2.0

This is the web panel application for AidiPanel. It is deployed automatically by `install.sh`.

## Manual Deploy

If you need to re-deploy or update the panel app:

```bash
cd panel-app/
sudo bash deploy-panel.sh
```

A new random admin password will be generated and shown.

## Structure

```
panel-app/
├── deploy-panel.sh     ← Deploy script
├── public/             ← Web root (Nginx serves this)
│   └── index.php       ← Entry point
├── app/
│   ├── Core/           ← Framework core (Router, DB, Auth, Session)
│   ├── Controllers/    ← Page controllers
│   ├── Views/          ← PHP templates
│   └── Middleware/     ← Auth + CSRF middleware
└── storage/            ← SQLite DB, logs (created on deploy)
```

## Stack

- Pure PHP, no framework (Router + SQLite + Alpine.js)
- No Composer needed
- Runs on port 8443 via Nginx + PHP-FPM

## Admin Password

Generated randomly at deploy time. Saved to `/opt/aidipanel/credentials.conf`.

```bash
sudo grep PANEL_ADMIN /opt/aidipanel/credentials.conf
```
