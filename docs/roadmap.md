# Roadmap

This roadmap is indicative and may change. It describes direction, not commitments or dates.

## Available now

- One-command install of the full stack + web panel
- Per-site Linux user + per-site PHP-FPM pool isolation
- Web root at `/home/<site-user>/htdocs/<domain>`
- PHP 8.5 default; 8.2 / 8.3 / 8.4 on-demand
- Nginx FastCGI Cache with per-site toggle and purge
- MariaDB / MySQL choice at install time
- Redis object cache
- Let's Encrypt SSL with auto-renewal
- CLI + web panel management
- Guarded site delete (validated by an ownership marker)
- File manager with chunked uploads, archive operations, and safe path containment
- Per-site database and database-user management with isolated phpMyAdmin sign-on
- Local site backups and scheduled S3-compatible remote backups
- Per-site cron jobs and a WordPress real-cron preset
- Opt-in jailed SFTP with passwords and managed SSH keys
- Per-site PHP version and runtime setting controls
- Basic Auth, IP blocking, Cloudflare-only origin access, and Cloudflare real-IP restoration
- Panel custom domain and trusted TLS certificate management
- Admin, manager, and client accounts with site assignment
- Two-factor authentication and recovery codes for panel accounts
- Service health, traffic history, cloud metadata, and web-delivery diagnostics

## Planned

- Additional performance options (e.g. HTTP/3, Brotli) under evaluation

## Under consideration

- Additional database engines / versions
- Multi-server / multi-node management
- Notifications and external monitoring integrations

Suggestions are welcome through the repository's issue tracker.
