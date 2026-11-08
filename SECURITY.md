# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.0.x | Yes |

## Reporting a vulnerability

If you believe you have found a security issue in WentoCart, **do not** open a public issue and **do not** post exploit details publicly.

Report it privately so we can fix and disclose it responsibly:

1. Email [info@wentocart.com](mailto:info@wentocart.com) with a subject such as `SECURITY: short description`, or use the [contact form](https://wentocart.com/contact/) and choose **Security**.
2. Include the affected version or commit, environment (PHP and database), steps to reproduce, impact, and any suggested fix.
3. Allow a reasonable window for a patch before public disclosure.

We will acknowledge receipt, triage severity, and coordinate a fix and advisory when appropriate.

## Out of scope

- Denial of service through unbounded legitimate traffic, without a clear product defect
- Issues that require physical access or already-compromised admin credentials
- Social engineering of merchant staff

## Hardening notes for operators

- Keep `APP_DEBUG=false` and `API_PLAYGROUND_AUTO_INJECT_STOREFRONT_KEY=false` on public stores
- Treat storefront keys as public client identifiers; use Integration Bearer tokens for the Admin API
- Prefer Integration IP allowlists and per-token rate limits for production admin clients
- Rotate storefront and integration tokens on a schedule; revoke them immediately if they leak
