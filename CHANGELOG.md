# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project follows semantic versioning for plugin releases.

## [Unreleased] — branch: feature/formal-alignment

### Added

- **`Reporting-Endpoints` header** — `Policy_Builder::emit_header()` now emits a `Reporting-Endpoints: csp-endpoint="<url>"` Structured Fields Dictionary header (RFC 9651) before every CSP header. Without this header, browsers silently discard any `report-to` directive in the CSP. Also emits the legacy `Report-To` JSON header as a fallback for pre-Reporting-API browsers; that format is deprecated per RFC 9651 but retained for compatibility.
- **Forbidden directive denylist** — `Policy_Builder` gained the `FORBIDDEN_DIRECTIVES` class constant listing `plugin-types`, `block-all-mixed-content`, `navigate-to`, and `prefetch-src`. These are stripped from profile overrides at emit time; any stripping is logged to `csp_audit_log` at `warning` severity.
- **`strict-dynamic` host-source suppression** — When `strict-dynamic` is active and licensed, approved host sources are suppressed from `script-src` at emit time. Browsers silently ignore host allowlists when `strict-dynamic` is present (CSP3 §8.2); emitting them was creating misleading policy noise.
- **`upgrade-insecure-requests` directive** — Added to default policy profiles for `frontend`, `admin`, and `login` surfaces (not `api`). Serialised as a standalone boolean token (no source list). Does not replace HSTS (RFC 6797).
- **`child-src 'none'` directive** — Added to default profiles as a legacy Safari fallback for `worker-src` (worker-src → child-src → script-src fallback chain in Safari).
- **`fenced-frame-src 'none'` directive** — Added to default profiles as a forward-looking Privacy Sandbox directive.
- **`sandbox` directive support** — `Policy_Builder` now handles `sandbox: null` (disabled) and skips `sandbox` entirely when the profile is in report-only mode (CSP spec — `sandbox` is ignored in `Content-Security-Policy-Report-Only`).
- **Trusted Types directives** — `require-trusted-types-for` and `trusted-types` added to default profiles (both disabled by default with empty arrays). When enabled, always emitted as report-only regardless of surface enforcement mode (Chromium-strong; not yet cross-browser). Gated behind `trusted_types` premium feature key.
- **`'report-sample'` keyword in defaults** — Added to `script-src`, `script-src-elem`, `style-src`, and `style-src-elem` across all surfaces. Without this keyword, browsers never populate the `script-sample`/`sample` field in violation reports.
- **`sample` column in `csp_violation_reports`** — New `sample varchar(256) DEFAULT NULL` column stores the inline script/style snippet from violation reports (legacy field: `script-sample`; Reporting API field: `sample`). DB version bumped from 2 → 3.
- **`csp_audit_log` table (eighth table)** — Append-only structured audit log for all significant plugin events. Columns: `id`, `component`, `event`, `detail`, `severity`, `user_id`, `created_at`. No `UPDATE` or `DELETE` is ever issued against this table. DB version bumped from 3 → 4.
- **Violation retention purge** — `Scheduler::run_daily_scan()` now calls `purge_old_violations()` after each scan. Deletes `csp_violation_reports` rows older than `wp_csp_violation_retention_days` days (new option, default 90). Set to `0` to disable. Purge count logged to `csp_audit_log`.
- **`Content-Type` validation on violation endpoint** — `Violation_Reporter::handle()` rejects requests with an unsupported `Content-Type` with HTTP 400. Accepted types: `application/csp-report`, `application/reports+json`, `application/json`.
- **Cross-origin `document-uri` check** — `Violation_Reporter::store_report()` silently discards reports whose `document-uri` hostname does not match the WordPress site origin (RFC 6454). CSP reports are client-generated and spoofable.
- **`sample` field normalisation** — `map_csp_report()` maps legacy `script-sample`; `map_reporting_api()` maps Reporting API `sample`; both write to the new `sample` column.
- **wp-admin CSP limitation notice** — `Admin_UI::maybe_show_admin_csp_warning()` displays a one-per-session info notice when the admin surface profile mode is `enforce`, referencing WordPress core Trac #59446 (wp-admin cannot yet receive strict nonce-based CSP without risk of breakage).
- **`wp_csp_violation_retention_days` option** — Seeded to `90` on activation. Registered and sanitised in admin settings.
- **Audit log DB persistence** — `Audit_Log::log()` now calls `write_to_db()` (new private method) before `push_admin_notice()`. The DB record is the immutable audit trail; the wp_options queue is for transient admin display only.
- **Feature gate documentation** — `Feature_Gate` class docblock now lists all premium feature keys: `trusted_types`, `strict_dynamic`, `multi_surface_scan`.

### Changed

- `WP_CSP_DB_VERSION` bumped from `'2'` to `'4'` (v3 = sample column; v4 = audit log table).
- Policy builder emits `Reporting-Endpoints` and `Report-To` headers immediately before the CSP header — any code that expects the CSP to be the first header will need updating.

### Fixed

- **Silent `report-to` bug** — The CSP string referenced `csp-endpoint` in `report-to` without ever declaring the endpoint via `Reporting-Endpoints:`. Browsers silently discarded all `report-to` directives; violation reports via the Reporting API were never delivered. Fixed by emitting `Reporting-Endpoints:` in `emit_header()`.

## [0.2.0] - 2026-06-03

### Added
- Initial public plugin implementation for WordPress 6.4+ and PHP 8.1+.
- Bootstrap file with plugin headers, constants, autoloader, activation, deactivation, and uninstall hooks.
- Database installer for seven custom tables covering policy profiles, source inventory, hash inventory, violation reports, scan logs, entitlements, and processed Stripe webhook events.
- Per-surface CSP engine for frontend, admin, login, and REST API requests.
- Strict defaults for all 18 CSP directives, including `'none'` defaults where appropriate.
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
- Public WordPress.org `readme.txt` and repository documentation set.

### Security
- Enforced prepared SQL access for parameterised queries and consistent escaping in admin output.
- Added promotion gate so enforce mode is blocked until at least one approved source or hash exists.
- Restricted admin actions to `manage_options` users with nonce verification.
- Kept Stripe secret material out of browser-delivered code and remote DNS configuration.

## Release policy
- `main` is the shipping branch for tagged releases.
- WordPress.org release artifacts are built from a clean tag only.
- Database schema changes must increment `WP_CSP_DB_VERSION` and include upgrade logic.
