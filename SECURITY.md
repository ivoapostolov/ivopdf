# Security Policy

## Supported versions

Security fixes are provided for the latest released minor version of IvoPDF (the `1.x` series) and for the current `main` branch. Older releases are not patched; please upgrade.

| Version | Supported |
|---------|-----------|
| 1.x (latest) | Yes |
| < 1.0 / unreleased forks | No |

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Report privately through GitHub Security Advisories:
<https://github.com/ivoapostolov/ivopdf/security/advisories/new>

Include, where possible:

- the affected version or commit and PHP version;
- a minimal HTML/CSS input or script that reproduces the problem;
- the impact you observed (for example file disclosure, SSRF, denial of service, memory exhaustion).

You can expect an acknowledgement within a few days. Fixes are developed privately, and a coordinated disclosure and advisory are published once a patch is available.

## Scope notes

- `index.php` (the Studio playground) is a development tool. It only accepts requests from localhost by default; do not expose it publicly. If it runs behind a reverse proxy, set `IVOPDF_TRUSTED_PROXIES` to the proxy address(es) rather than enabling `IVOPDF_ALLOW_PUBLIC_STUDIO`.
- Rendering untrusted HTML safely requires keeping `isRemoteEnabled` off and restricting `allowedPaths`.
