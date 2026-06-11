# Roadmap

This roadmap is indicative and may change. It describes direction, not commitments or dates.

## Available now

- One-command install of the full stack + web panel
- Per-site Linux user + per-site PHP-FPM pool isolation
- Web root at `/home/<site-user>/htdocs/<domain>`
- PHP 8.4 default; 8.2 / 8.3 / 8.4 / 8.5 on-demand
- Nginx FastCGI Cache with per-site toggle and purge
- MariaDB / MySQL choice at install time
- Redis object cache
- Let's Encrypt SSL with auto-renewal
- CLI + web panel management
- Guarded site delete (validated by an ownership marker)

## Planned

- **Per-site SFTP/SSH access** (opt-in, key-managed) — disabled by default today
- **File manager** in the panel
- **Backups** (site files + databases) with scheduling
- **Database tab** in the panel
- **Live health checks** for pools, sockets, and services
- **Two-factor authentication** for panel login
- Additional performance options (e.g. HTTP/3, Brotli) under evaluation

## Under consideration

- Per-site PHP `.ini` overrides from the panel
- Additional database engines / versions
- Multi-server / multi-node management

Suggestions are welcome through the repository's issue tracker.
