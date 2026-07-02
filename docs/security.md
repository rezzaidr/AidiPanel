# Security Model

This document summarizes how AidiPanel isolates sites and limits privilege. It describes the current behavior; see the [roadmap](roadmap.md) for planned hardening.

## Site Isolation

- Each site runs under a **dedicated Linux user** with a **no-login shell** and a **locked password**. Site users cannot log in over SSH.
- Each site has its **own PHP-FPM pool** running as that user, so PHP for one site cannot read another site's files.
- Web roots live under `/home/<site-user>/htdocs/<domain>` with a `setgid` directory and group-based read access for Nginx only.
- **SFTP/SSH access for site users is disabled by default.**

This is per-user process and file isolation, not container-level sandboxing.

## Privilege Boundary

- The web panel runs as the dedicated `aidipanel` system user. Nginx remains
  `www-data`; it can connect to the panel FastCGI socket but cannot invoke the
  root wrapper or read panel runtime storage.
- It can only invoke `aidipanel-web-run`, a root wrapper allowed through a single narrow sudoers rule.
- The wrapper enforces a fixed allowlist of subcommands; anything else is rejected.
- CLI arguments are passed as argv values and never interpolated into shell strings.

## Guarded Deletion

Purge-delete (remove the Linux user, home, and all site resources) only proceeds when the `/home/<site-user>/.aidipanel-managed` marker:

- exists and is owned `root:root`,
- is not writable by the site user,
- and matches the domain and user the panel resolved from its database.

If any check fails, the operation is refused and nothing is removed. This prevents the panel from ever deleting an account or directory it did not create.

## Network

- **UFW** is enabled and allows only SSH (22), HTTP (80), HTTPS (443), and the panel port (8443 by default).
- **Fail2ban** is configured against brute-force attempts.
- The panel listens on its own HTTPS port with a self-signed certificate by default; user sites use Let's Encrypt.

## TLS

- Let's Encrypt certificates via Certbot, with automatic renewal.
- Strong defaults: TLS 1.2/1.3 only, HSTS, OCSP stapling.

## Application

- CSRF protection on state-changing requests.
- Session hardening and a brute-force throttle on panel login.
- Credentials are generated randomly at install and stored in `/opt/aidipanel/credentials.conf` (mode 600).

## Reporting

If you find a security issue, please report it privately through the repository's security advisory feature rather than a public issue.
