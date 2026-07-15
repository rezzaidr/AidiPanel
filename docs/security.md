# Security Model

This document summarizes how AidiPanel isolates sites and limits privilege. It describes the current behavior; see the [roadmap](roadmap.md) for planned hardening.

## Site Isolation

- Each site runs under a **dedicated Linux user** with a **no-login shell** and a **locked password**. Site users cannot log in over SSH.
- Each site has its **own PHP-FPM pool** running as that user, so PHP for one site cannot read another site's files.
- Web roots live under `/home/<site-user>/htdocs/<domain>` with a `setgid` directory and group-based read access for Nginx only.
- **Site login is disabled by default.** Jailed SFTP-only access can be enabled
  per site with a password or managed SSH keys; interactive SSH stays disabled.

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

- **UFW** preserves existing rules, opens detected/current SSH ports, HTTP (80),
  HTTPS (443), and the configured panel port (8443 by default).
- **Fail2ban** is configured against brute-force attempts.
- The panel listens on its own HTTPS port with a self-signed certificate by default; user sites use Let's Encrypt.

## TLS

- Let's Encrypt certificates via Certbot, with automatic renewal.
- TLS 1.2/1.3 and OCSP stapling in managed HTTPS vhosts. HSTS is opt-in per site
  and should be enabled only after a trusted certificate is active.

## Release Authenticity

- Release manifests are signed offline with ECDSA P-256. The installer and CLI
  pin the corresponding public key and verify `SHA256SUMS.sig` before trusting
  any release checksum.
- `self:update` downloads and authenticates the manifest before executable
  assets, rejects signed downgrades, and has no checksum-only fallback.
- The one-line installer uses HTTPS for its initial bootstrap. The
  [verified installation path](installation.md#verify-before-running-recommended)
  authenticates the installer itself before root execution.
- The public-key DER SHA-256 fingerprint is
  `63cd4bf5ed9f184c9042977cec91e25d0928cc361c4e54bfb496d31f74f4d901` and must be checked through
  an independent AidiPanel channel, not only against the key attached to the
  same release.

## Application

- CSRF protection on state-changing requests.
- Session hardening and a brute-force throttle on panel login.
- Admin, manager, and client roles are enforced server-side, with per-site
  assignment for client accounts.
- Optional TOTP two-factor authentication includes one-time recovery codes.
  Confirmed TOTP seeds are stored with authenticated PHP Sodium encryption;
  the server key is kept outside SQLite at `/etc/aidipanel/totp.key` with
  `root:aidipanel` ownership and mode `0640`. This protects a database copy by
  itself, but not a root or panel-runtime compromise.
- Credentials are generated randomly at install and stored in `/opt/aidipanel/credentials.conf` (mode 600).
- `self:update` verifies the signed manifest and matching CLI/panel checksums
  before activation, and preserves existing users and runtime data.

Back up `/etc/aidipanel/totp.key` together with the panel SQLite database. A
restored database containing encrypted TOTP seeds needs the same key; if it is
lost, users must sign in with a recovery code or root must reset the account
with `aidipanel user:2fa-reset --user <name>` before 2FA can be enrolled again.

## Reporting

If you find a security issue, please report it privately through the repository's security advisory feature rather than a public issue.
