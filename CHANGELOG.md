# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project follows semantic versioning for plugin releases.

## [Unreleased]

No unreleased changes yet.

## [0.3.0] - 2026-07-12

This release includes database migrations through schema version 6. Existing installations can migrate directly from earlier schema versions through the normal `dbDelta()` activation path. CSP runtime behaviour remains local and does not depend on remote billing, licensing, or update services during normal page rendering.

### Added

- `Reporting-Endpoints` header emission alongside CSP headers, with legacy `Report-To` fallback.
- Forbidden directive denylist for removed or deprecated CSP directives.
- `strict-dynamic` host-source suppression when licensed and enabled.
- `upgrade-insecure-requests`, `child-src`, `fenced-frame-src`, sandbox handling, and Trusted Types defaults in policy profiles.
- `'report-sample'` defaults for script and style directives.
- `sample` column in `csp_violation_reports` for violation snippets. DB version 3.
- `csp_audit_log` append-only table. DB version 4.
- Violation retention purge with `wp_csp_violation_retention_days`.
- Content-Type validation, cross-origin `document-uri` rejection, and sample-field normalisation for violation reports.
- wp-admin enforce-mode limitation notice.
- CSP policy change proposals, risk classification, administrator approve/reject/revert decisions, and rejected/reverted fingerprint suppression.
- `csp_policy_change_decisions` append-only decision ledger. DB version 5.
- Violation report rollups with `first_reported_at`, `last_reported_at`, unique fingerprint upsert support, and occurrence counts. DB version 6.
- Self-hosted update checking for GitHub-distributed builds. The shared `vcns/wp-updates` feed remains tracked in the updater consolidation PR.

### Changed

- Plugin version metadata now targets `0.3.0` for the next release after `0.2.0`.
- `WP_CSP_DB_VERSION` is `6`.
- Policy builder emits reporting headers immediately before the CSP header.
- Product copy no longer describes all premium access as a one-time payment; entitlement-gated capabilities are compatible with future VCNS Portal account management.

### Fixed

- Fixed silent Reporting API delivery failure where the CSP string referenced `report-to csp-endpoint` without declaring the endpoint through `Reporting-Endpoints`.

## [0.2.0] - 2026-06-03

### Added

- Initial public plugin implementation for WordPress 6.4+ and PHP 8.1+.
- Bootstrap file with plugin headers, constants, autoloader, activation, deactivation, and uninstall hooks.
- Database installer for seven custom tables covering policy profiles, source inventory, hash inventory, violation reports, scan logs, entitlements, and processed Stripe webhook events.
- Per-surface CSP engine for frontend, admin, login, and REST API requests.
- Strict defaults for all CSP directives.
- Nonce generation and injection through native WordPress 6.4+ script attribute hooks with legacy tag-filter fallback.
- Policy builder capable of emitting strict `Content-Security-Policy` and `Content-Security-Policy-Report-Only` headers.
- Crawl-based discovery workflow for external sources with approval and deny actions.
- Inline block hash recording and retirement support.
- Violation reporting REST endpoint with deduplication and transient-based rate limiting.
- Daily scheduler for rescans and policy-change notifications.
- Conflict detector for duplicate CSP headers from other plugins or server layers.
- Stripe checkout session creation without the Stripe PHP SDK, using the WordPress HTTP API.
- Webhook verification with HMAC-SHA256 signing, replay-window tolerance, and idempotent event recording.
- Local entitlement store bound to a stable hash of the site URL, including configurable grace periods.
- Feature gate with explicit free-tier capabilities and local tier checks.
- Remote product configuration fetched from DNS-discovered HTTPS JSON with Ed25519 signature verification.
- Transient-cached remote configuration with configurable TTL and grace-window handling.
- Admin UI covering dashboard, settings, entitlement display, checkout initiation, and manual rescans.
- Full uninstall routine that drops all custom tables and removes plugin-owned `wp_csp_*` options.

### Security

- Enforced prepared SQL access for parameterised queries and consistent escaping in admin output.
- Added promotion gate so enforce mode is blocked until at least one approved source or hash exists.
- Restricted admin actions to `manage_options` users with nonce verification.
- Kept Stripe secret material out of browser-delivered code and remote DNS configuration.

## Release policy

- `main` is the shipping branch for tagged releases.
- WordPress.org release artifacts are built from a clean tag only.
- Database schema changes must increment `WP_CSP_DB_VERSION` and include upgrade logic.
