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

## Release Authenticity

AidiPanel release manifests use an offline ECDSA P-256 signature. Installers and
`self:update` pin the release public key, verify `SHA256SUMS.sig`, and only
then trust the asset checksums. There is no checksum-only fallback in
`self:update`.

The public-key DER SHA-256 fingerprint is:

```text
63cd4bf5ed9f184c9042977cec91e25d0928cc361c4e54bfb496d31f74f4d901
```

Verify this value through `https://aidipanel.com/security` or the published
AidiPanel DNS record rather than trusting only a key downloaded with the same
release.

## Maintainer Release Procedure

The signing key is initialized once and reused across releases until a planned
rotation. It is not regenerated for every version. For each release, commit the
version changes, start from a completely clean worktree, then:

```powershell
git tag vX.Y.Z
git push origin vX.Y.Z
.\tools\release\sign-release.ps1 vX.Y.Z
```

The tag workflow builds an unpublished draft without access to the private key.
The maintainer-controlled private key remains outside the repository and CI.
After that workflow succeeds, the local signing command verifies the local and
remote tag commits, validates every draft artifact against the exact tag,
creates or resumes the detached signature without replacing it, verifies the
remote copy, and only then publishes the release.

Key rotation must be planned so existing installations can transition from the
old pinned key. If compromise is suspected, stop publication, disclose the
affected key through the security channels, and distribute the replacement
trust anchor through an authenticated recovery release or documented manual
recovery. Never silently replace the pinned key.

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
