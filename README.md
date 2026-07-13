# WP CSP Automation Manager

WP CSP Automation Manager is a WordPress plugin that helps site owners roll out strict Content Security Policy headers safely and incrementally.

It provides per-surface CSP profiles, nonce injection, source discovery, violation reporting, and optional entitlement-gated premium capabilities.

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
- Risk-ranked CSP change proposals with administrator approve/reject decisions
- Revert-and-suppress workflow so a reversed source is not proposed again automatically
- Policy version snapshots, policy diffs, decision provenance, and deterministic rule findings for reviewable CSP changes
- Policy Audit admin view and privileged admin REST endpoints for current policy, pending reviews, decisions, history, and manual automation configuration
- Automation configuration scaffold that defaults every surface to `manual`; no proposal is auto-approved on install or upgrade
- Promotion gate before switching a surface into enforce mode
- Conflict detection for competing CSP headers
- Scheduled rescans with audit logging

### Premium features

- Multi-surface scan support
- `strict-dynamic` with automatic host-source suppression (CSP3 §8.2)
- Trusted Types (`require-trusted-types-for`, `trusted-types`) — report-only by default
- Additional analytics and export surfaces
- Local entitlement handling after verified Stripe webhook delivery

NOTE: WP CSP Automation is open-source software released under GPLv2 or later. The names, logos, trademarks, documentation branding, hosted services, and commercial offerings are proprietary to VCNS Tech Ltd and are governed separately by the Trademark Policy and Commercial Terms.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- `libsodium` available for Ed25519 signature verification

## Installation

### Install from GitHub Releases

Every tagged release publishes a ready-to-install ZIP to the
[Releases page](https://github.com/vcns/wp-csp-automation/releases).

**Option A — WordPress admin upload (recommended)**

1. Download `wp-csp-automation-vX.Y.Z.zip` from the Assets section of the release.
2. In WordPress go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the downloaded ZIP and click **Install Now**.
4. Activate the plugin.

**Option B — SFTP / SCP**

1. Download `wp-csp-automation-vX.Y.Z.zip` from the Assets section of the release.
2. Extract the ZIP locally — it unpacks to a single `wp-csp-automation/` folder.
3. Upload that folder to `/wp-content/plugins/` on your server via SFTP or SCP.
4. Activate the plugin from **Plugins → Installed Plugins** in the WordPress admin.

### Self-hosted update checks

The plugin includes a self-hosted update checker for GitHub-distributed builds. WordPress does not automatically poll arbitrary JSON endpoints for plugins outside WordPress.org, so WP CSP Automation Manager reads a public GitHub Pages manifest and maps it into the native WordPress plugin update UI.

Default manifest endpoint:

- https://vcns.github.io/wp-csp-automation/updates/wp-csp-automation.json

Tagged stable releases update that manifest with the latest version and the direct GitHub Release ZIP download URL. Pre-release tags still publish release assets, but they do not replace the stable update endpoint.

### From the WordPress plugin directory

Once published to WordPress.org:

1. Go to **Plugins → Add New Plugin**.
2. Search for `WP CSP Automation Manager`.
3. Click **Install**, then **Activate**.

### After activation

1. Open `CSP Manager -> Settings`.
2. Run an initial scan from the dashboard.
3. Review discovered sources in the inventory.
4. Stay in report-only mode while reviewing violations.
5. Use **CSP Manager -> Policy Audit** to inspect pending decisions, decision history, and captured policy versions.
6. Promote one surface at a time into enforce mode when the approved inventory is stable.

## Getting started

1. Install and activate the plugin.
2. Run an initial scan from the CSP Manager dashboard.
3. Review and approve only the external sources your site actually requires.
4. Reject or revert unwanted sources so the same fingerprint is suppressed from future automation.
5. Use the Policy Audit page to inspect why a proposal exists and what policy version resulted from decisions.
6. Stay in report-only mode until violations are understood.
7. Promote one surface at a time into enforce mode.

## Automation and AI posture

Automation is currently scaffolded but defaults to `manual` for every surface. The free tier retains source discovery, manual review, deterministic risk classification, decision history, policy history, and rollback-oriented provenance. Future paid automation and AI-assisted recommendation work must keep deterministic product rules as the authority; AI output must not directly modify an enforced CSP policy.

## Premium activation

Premium activation uses VCNS licensing services. Stripe may be used as a billing event source, but Stripe API keys never touch the WordPress installation.

1. Open **CSP Manager -> Entitlement** in the WordPress admin.
2. Click **Upgrade to Pro** or manage your plan through the available VCNS account-management flow.
3. Complete the billing or account-management steps shown by VCNS.
4. The licensing service records the entitlement after the billing or subscription event is verified.
5. Return to the Entitlement page; the plugin syncs automatically and activates entitled features.

Important behaviour:

- Free features do not require any payment or configuration at all.
- Premium access is not granted from the browser redirect alone; it requires a verified entitlement from the licensing service.
- Existing legacy entitlements remain site-bound. Future VCNS Portal entitlements may use product instance and organisation identifiers.
- No Stripe keys are stored in WordPress or visible in the plugin settings.

## Stripe and external services

Stripe may be used for premium billing flows. Stripe interaction happens through VCNS licensing services; the WordPress plugin never holds or transmits Stripe keys.

- The plugin sends site identity and return URLs to the licensing service when an administrator starts a premium flow.
- Stripe delivers webhook events directly to VCNS licensing infrastructure where applicable, which verifies signatures and records entitlements.
- The plugin does not require the Stripe PHP SDK.
- The plugin does not perform per-request remote licence checks during normal runtime; entitlement state is cached locally and re-synced on demand.

The plugin also fetches a remote configuration document from a DNS-discovered HTTPS endpoint.

- This remote config contains public product metadata only (version, feature flags, pricing display).
- It is signature-verified with Ed25519 when `libsodium` is available.
- It never contains Stripe secrets, webhook secrets, or private signing keys.

## Privacy

The plugin keeps most operational data local to WordPress.

- Browser-submitted CSP violation reports are stored in the local database.
- Source inventory, hashes, scan logs, and entitlements are stored locally.
- External billing services are contacted only during administrator-initiated premium flows or server-to-server billing events.
- The remote config endpoint is contacted only to fetch public signed product metadata.

No telemetry or background tracking is intended as part of the normal plugin runtime.

## Repository guides

- Architecture: [docs/architecture.md](docs/architecture.md)
- Remote config and signing: [docs/remote-config-and-signing.md](docs/remote-config-and-signing.md)
- Stripe operations: [docs/stripe-operations.md](docs/stripe-operations.md)
- Testing and quality: [docs/testing-and-quality.md](docs/testing-and-quality.md)
- Release and publishing: [docs/release-and-publishing.md](docs/release-and-publishing.md)
- Self-hosted update endpoint: [docs/update-endpoint.md](docs/update-endpoint.md)
- Security policy: [SECURITY.md](SECURITY.md)

## GitHub Pages help site

The repository also publishes a public help site from the `docs/` directory:

- https://vcns.github.io/wp-csp-automation/

## Development and release flow

- `feature/*` and `fix/*` branches target `development`
- `release/*` branches are cut from `development`
- `main` is the release and publishing branch
- WordPress.org deployment is tag-driven

## Notes for maintainers

- Keep `README.md` GitHub-friendly and concise.
- Keep `readme.txt` aligned with actual shipped behaviour for WordPress.org.
- Update both when installation, features, external services, or release flow changes.
