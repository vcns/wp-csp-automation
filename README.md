# WP CSP Automation Manager

WP CSP Automation Manager is a WordPress plugin that helps site owners roll out strict Content Security Policy headers safely and incrementally.

It provides per-surface CSP profiles, nonce injection, source discovery, violation reporting, and an optional premium entitlement model backed by one-time Stripe Checkout.

## Why there are two readme files

This repository intentionally uses both of these files:

- `README.md` for GitHub
- `readme.txt` for WordPress.org plugin directory metadata and parsing

`readme.txt` should stay in the repository because WordPress.org expects that format. `README.md` exists to make the GitHub repository readable in normal Markdown form.

## Features

### Free features

- Per-surface CSP profiles for `frontend`, `admin`, `login`, and `api`
- Strict defaults across all CSP directives, including `upgrade-insecure-requests`, `child-src` (Safari worker-src fallback), `fenced-frame-src`, and the `sandbox` document directive
- `'report-sample'` in fetch directives so inline code snippets appear in violation reports
- `Reporting-Endpoints` (RFC 9651) and legacy `Report-To` headers emitted automatically alongside CSP — required for browsers to deliver violation reports via the Reporting API
- Per-request nonce injection for compatible WordPress script output
- Report-only rollout mode
- CSP violation reporting endpoint — validates `Content-Type` and `document-uri` origin before storing
- Automatic purge of violation reports older than a configurable number of days (default 90), run after every daily scan
- Append-only audit log (`csp_audit_log`) for all significant plugin events
- Source discovery and approval workflow
- Promotion gate before switching a surface into enforce mode
- Conflict detection for competing CSP headers
- Scheduled rescans with audit logging

### Premium features

- Multi-surface scan support
- `strict-dynamic` with automatic host-source suppression (CSP3 §8.2)
- Trusted Types (`require-trusted-types-for`, `trusted-types`) — report-only by default
- Additional analytics and export surfaces
- Local entitlement handling after verified Stripe webhook delivery

## Requirements

- WordPress 6.4+
- PHP 8.1+
- `libsodium` available for Ed25519 signature verification

## Installation

### From the WordPress dashboard

1. Go to `Plugins -> Add New Plugin`.
2. Search for `WP CSP Automation Manager`.
3. Click install, then activate the plugin.

### Manual installation

1. Download the plugin ZIP package.
2. In WordPress, go to `Plugins -> Add New Plugin -> Upload Plugin`.
3. Select the ZIP file and install it.
4. Activate the plugin.

### After activation

1. Open `CSP Manager -> Settings`.
2. Run an initial scan from the dashboard.
3. Review discovered sources in the inventory.
4. Stay in report-only mode while reviewing violations.
5. Promote one surface at a time into enforce mode when the approved inventory is stable.

## Getting started

1. Install and activate the plugin.
2. Run an initial scan from the CSP Manager dashboard.
3. Review and approve only the external sources your site actually requires.
4. Stay in report-only mode until violations are understood.
5. Promote one surface at a time into enforce mode.

## Premium activation

Premium activation is handled through Stripe Checkout and local entitlement storage.

1. Configure the Stripe mode, publishable key, secret key, and webhook secret in the plugin settings.
2. Ensure your Stripe webhook endpoint points to the plugin REST route.
3. Start checkout from the plugin's premium or entitlement screen.
4. Complete the one-time Stripe payment.
5. Wait for the verified Stripe webhook to grant the entitlement locally.

Important behaviour:

- Free features do not require Stripe at all.
- Premium access is not granted from the browser redirect alone.
- The entitlement becomes active after the webhook is verified and stored locally.
- The entitlement is tied to the current site's identity.

## Stripe and external services

Stripe is used only for the premium purchase flow.

- The plugin creates one-time Stripe Checkout Sessions.
- It verifies Stripe webhook signatures before granting entitlements.
- It does not require the Stripe PHP SDK.
- It does not perform per-request remote licence checks during normal runtime.

The plugin also fetches a remote configuration document from a DNS-discovered HTTPS endpoint that you control.

- This remote config contains public product metadata only.
- It must not contain Stripe secrets, webhook secrets, or private signing keys.
- It is signature-verified with Ed25519 when `libsodium` is available.

## Privacy

The plugin keeps most operational data local to WordPress.

- Browser-submitted CSP violation reports are stored in the local database.
- Source inventory, hashes, scan logs, and entitlements are stored locally.
- Stripe is contacted only when an admin initiates premium purchase flow or when Stripe sends webhook events.
- The remote config endpoint is contacted only to fetch public signed product metadata.

No telemetry or background tracking is intended as part of the normal plugin runtime.

## Repository guides

- Public plugin directory content: [readme.txt](readme.txt)
- Architecture: [docs/architecture.md](docs/architecture.md)
- Remote config and signing: [docs/remote-config-and-signing.md](docs/remote-config-and-signing.md)
- Stripe operations: [docs/stripe-operations.md](docs/stripe-operations.md)
- Testing and quality: [docs/testing-and-quality.md](docs/testing-and-quality.md)
- Release and publishing: [docs/release-and-publishing.md](docs/release-and-publishing.md)
- Security policy: [SECURITY.md](SECURITY.md)

## GitHub Pages help site

The repository also publishes a public help site from the `docs/` directory:

- https://sjackson0109.github.io/wp-csp-automation/

## Development and release flow

- `feature/*` and `fix/*` branches target `development`
- `release/*` branches are cut from `development`
- `main` is the release and publishing branch
- WordPress.org deployment is tag-driven

## Notes for maintainers

- Keep `README.md` GitHub-friendly and concise.
- Keep `readme.txt` aligned with actual shipped behaviour for WordPress.org.
- Update both when installation, features, external services, or release flow changes.
