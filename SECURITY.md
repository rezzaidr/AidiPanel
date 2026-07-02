# Security Policy

## Supported Versions

Only the latest stable release receives security fixes. Install from
`releases/latest` (or `get.aidipanel.com`) and use `sudo aidipanel self:update`
to move an existing installation to the latest verified release. The `master`
branch is development code and is not the recommended production channel.

| Version | Supported |
|---------|-----------|
| Latest stable release | Yes |
| Older releases and prereleases | No |

## Reporting a Vulnerability

Please report security issues privately. Do **not** open a public issue for a
vulnerability.

- Open a GitHub Security Advisory on the repository, or
- Contact the maintainer through the address listed on the GitHub profile.

Include: affected component (installer, CLI, panel), steps to reproduce, and
the impact you observed. You can expect an initial response within a few days.

## Scope and Model

- The web panel runs as the dedicated `aidipanel` system user. Nginx remains
  `www-data` and can connect to the panel FastCGI socket, but cannot invoke the
  root wrapper or read panel runtime storage.
- Root operations pass through a single allow-listed sudo wrapper; the panel
  user does not receive general-purpose root access.
- Site isolation is per-site Linux user + dedicated PHP-FPM pool. This is
  process/file isolation, **not** container or VM sandboxing.
- Site login is disabled by default. Jailed SFTP-only access can be enabled per
  site with a password or managed SSH keys; interactive SSH shells stay disabled.

## Hardening Recommendations

- Run on a fresh VPS dedicated to AidiPanel.
- Put the panel behind a firewall; restrict the panel port to trusted IPs.
- Keep the host, Nginx, PHP, and database packages updated.
- Enable two-factor authentication for privileged panel accounts.
