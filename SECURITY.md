# Security Policy

## Supported Versions

AidiPanel is pre-1.0 and pre-release. Only the latest `master` build receives
security fixes. Pin a specific commit for production use and update deliberately.

## Reporting a Vulnerability

Please report security issues privately. Do **not** open a public issue for a
vulnerability.

- Open a GitHub Security Advisory on the repository, or
- Contact the maintainer through the address listed on the GitHub profile.

Include: affected component (installer, CLI, panel), steps to reproduce, and
the impact you observed. You can expect an initial response within a few days.

## Scope and Model

- The web panel runs as `www-data`. Root operations are performed through a
  single allow-listed sudo wrapper, not by giving the web user broad rights.
- Site isolation is per-site Linux user + dedicated PHP-FPM pool. This is
  process/file isolation, **not** container or VM sandboxing.
- SFTP/SSH for site users is disabled by default.

## Hardening Recommendations

- Run on a fresh VPS dedicated to AidiPanel.
- Put the panel behind a firewall; restrict the panel port to trusted IPs.
- Keep the host, Nginx, PHP, and database packages updated.
