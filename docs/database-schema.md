# Database Schema

## Overview

The plugin creates eight custom tables on activation. All table names are prefixed with the site's configured WordPress table prefix (default `wp_`). Tables are created and migrated via `dbDelta()` in `includes/class-activator.php`; the current schema version is tracked in the `wp_csp_db_version` option and compared against the `WP_CSP_DB_VERSION` constant on every boot.

| Version | Change |
|---------|--------|
| v1 | Initial schema — seven tables |
| v2 | `csp_policy_profiles` gains `override_expires_at`, `override_owner` |
| v3 | `csp_violation_reports` gains `sample` column |
| v4 | `csp_audit_log` append-only table added |

## Table list

### `csp_policy_profiles`

Purpose:

- stores per-surface CSP policy mode, directive configuration, and temporary override state

Key columns:

- `id`
- `surface` — `frontend`, `admin`, `login`, `api`
- `mode` — `disabled`, `report-only`, `enforce`
- `directives` — JSON array map of directive name → source list (e.g. `{"script-src":["'self'"],"img-src":["'self'","data:"]}`)
- `overrides` — JSON map of admin-applied temporary directive overrides merged on top of `directives` at emit time
- `strict_dynamic` — `0` or `1`; when `1` and the `strict_dynamic` feature is licensed, `'strict-dynamic'` is appended to `script-src` and approved host sources are suppressed from `script-src` (host allowlists are silently ignored by browsers when `strict-dynamic` is present — CSP3 §8.2)
- `override_expires_at` — UTC datetime at which the current override should be considered stale
- `override_owner` — identifier of the admin user who applied the override
- `created_at`, `updated_at`

Operational notes:

- seeded on activation with strict defaults (`default-src 'none'`, `object-src 'none'`, `base-uri 'none'`, etc.)
- one logical row per surface; `surface` is UNIQUE-constrained
- `strict_dynamic` should be ignored unless the `strict_dynamic` feature gate is licensed
- directives that are deprecated or removed by W3C (`plugin-types`, `block-all-mixed-content`, `navigate-to`, `prefetch-src`) are stripped from `overrides` at emit time and never appear in the emitted header

### `csp_source_inventory`

Purpose:

- stores discovered external origins that may be added to a surface's CSP policy

Key columns:

- `id`
- `surface`
- `directive` — the CSP directive this source applies to (e.g. `script-src`, `img-src`)
- `source_uri` — full URL of the discovered source
- `source_scheme` — scheme component (e.g. `https`)
- `source_host` — host component as it should appear in the CSP directive value
- `owner_component` — plugin or theme that introduced this source (if detectable)
- `owner_type` — `plugin`, `theme`, `core`, or `custom`
- `approval_state` — `pending`, `approved`, `denied`
- `first_seen_at`, `last_seen_at`
- `approved_at` — set when an admin approves the row
- `expires_at` — optional expiry; stale approved sources should be flagged for review
- `notes` — free-text admin annotation

Operational notes:

- discovery upserts rows by `(surface, directive, source_host)` rather than inserting duplicates
- approval state is operator-controlled only; sources are never auto-approved
- same-origin resources must not be stored as inventory rows
- only `approved` rows are included in emitted CSP headers

### `csp_hash_inventory`

Purpose:

- stores SHA-256 (or SHA-384 / SHA-512) content hashes for inline script and style blocks, enabling hash-based CSP allowlisting without `'unsafe-inline'`

Key columns:

- `id`
- `surface`
- `directive` — `script-src` or `style-src`
- `hash_algo` — `sha256`, `sha384`, or `sha512`
- `hash_value` — Base64-encoded hash of the raw block content
- `content_fingerprint` — deterministic fingerprint of the raw content used for deduplication and change detection
- `source_file` — the template or PHP file that emits this block, if detectable
- `source_context` — optional surrounding context for operator review
- `status` — `active`, `retired`
- `first_seen_at`, `last_seen_at`
- `retired_at` — set when the block is no longer observed during rescans

Operational notes:

- hashes are computed from observed inline content; there is no approval workflow because the hash already binds to exact content
- stale hashes (blocks no longer emitted) are marked `retired` during scheduled rescans
- policy construction includes only `active` hashes
- any whitespace or formatting change in the inline block produces a different hash — canonicalization at capture time is critical for stability

### `csp_violation_reports`

Purpose:

- records browser-submitted CSP violations, normalised from both the legacy `application/csp-report` format (CSP Level 2/3) and the modern `application/reports+json` format (Reporting API)

Key columns:

- `id`
- `profile_surface` — the surface that issued the policy generating this violation
- `blocked_uri` — the URL or token that was blocked
- `document_uri` — the page URL where the violation occurred
- `violated_directive` — the directive as reported in the `violated-directive` field
- `effective_directive` — the directive actually enforced (may differ from `violated_directive` due to fallback)
- `original_policy` — the full policy string active when the violation occurred
- `source_file` — file and URL containing the offending script or style
- `line_number`, `column_number`
- `status_code` — HTTP status of the document that triggered the violation
- `disposition` — `enforce` or `report`
- `referrer`
- `user_agent`
- `sample` — first ~40 characters of the offending inline block; populated only when `'report-sample'` is present in the emitting directive (legacy field: `script-sample`; Reporting API field: `sample`)
- `reported_at` — UTC datetime of first or most recent report
- `fingerprint` — SHA-256 of `(profile_surface, blocked_uri, violated_directive)` used for deduplication
- `occurrence_count` — incremented on each duplicate report

Operational notes:

- the endpoint validates `Content-Type` and rejects non-CSP payloads with HTTP 400
- the endpoint validates that `document-uri` belongs to this site's origin; cross-origin reports are silently discarded (CSP reports are client-generated and spoofable)
- duplicate reports (same fingerprint) increment `occurrence_count` rather than inserting new rows
- rows are purged automatically after `wp_csp_violation_retention_days` days (default: 90) by the daily cron scan; set to `0` to disable purging

### `csp_scan_logs`

Purpose:

- records the execution history of scheduled and manual policy rescans

Key columns:

- `id`
- `trigger_type` — `manual`, `cron`
- `status` — `running`, `completed`, `failed`
- `sources_added`, `sources_removed` — count of source inventory changes
- `hashes_added`, `hashes_removed` — count of hash inventory changes
- `policy_changed` — `0` or `1`; set when the scan altered the effective policy
- `diff_summary` — JSON summary of specific policy changes for operator review
- `warnings` — JSON array of non-fatal issues encountered during the scan
- `started_at`
- `completed_at`

Operational notes:

- used for operator auditability and troubleshooting
- `diff_summary` and `warnings` should remain compact enough for admin rendering
- a `running` row with no `completed_at` may indicate a stuck or killed cron job

### `csp_entitlements`

Purpose:

- stores site-local premium licence state granted after verified Stripe webhook delivery

Key columns:

- `id`
- `site_identity` — truncated SHA-256 hash of the site URL; binds the entitlement to a specific WordPress install
- `product_key` — identifies the premium product tier (e.g. `wp-csp-automation`)
- `tier` — `free`, `pro`
- `status` — `active`, `revoked`, `expired`, `grace`
- `stripe_customer_id`, `stripe_session_id`, `stripe_payment_intent_id`
- `config_version` — the remote config version active at grant time
- `granted_at`
- `expires_at` — if populated, entitlement expires at this UTC datetime
- `revoked_at`, `revocation_reason`
- `grace_until` — deadline before `grace` status downgrades to `expired`
- `last_validated_at`
- `created_at`, `updated_at`

Operational notes:

- feature checks use this table only and must not make network calls during page rendering
- grace handling is based on `last_validated_at` plus the configured grace hours option
- `stripe_session_id` is UNIQUE-constrained to prevent duplicate grants from webhook retries

### `csp_processed_events`

Purpose:

- stores processed Stripe webhook event IDs for idempotency; prevents duplicate entitlement logic on webhook retries

Key columns:

- `id`
- `stripe_event_id` — Stripe-assigned event identifier; UNIQUE-constrained
- `stripe_session_id` — Checkout Session ID if applicable
- `event_type` — the Stripe event type (e.g. `checkout.session.completed`)
- `processed_at`
- `outcome` — `granted`, `revoked`, `ignored`, `error`
- `detail` — short human-readable description for support and debugging

Operational notes:

- before processing any webhook event, the handler checks this table and skips if the `stripe_event_id` is already present
- outcome strings should remain stable to support log triage

### `csp_audit_log`

Purpose:

- append-only structured log of all significant plugin events (policy changes, scan results, override grants, webhook processing, config failures, and forbidden-directive suppression)

Key columns:

- `id`
- `component` — originating module (e.g. `policy_builder`, `webhook`, `scheduler`, `config_resolver`)
- `event` — machine-readable event type (e.g. `forbidden_directive_stripped`, `signature_failed`, `violations_purged`)
- `detail` — human-readable description
- `severity` — `info`, `warning`, `error`
- `user_id` — WordPress user ID of the logged-in admin who triggered the event, if applicable
- `created_at` — UTC datetime

Operational notes:

- **this table is strictly append-only** — no `UPDATE` or `DELETE` is ever issued against it; it is the permanent, immutable audit trail
- `warning` and `error` events are additionally written to the PHP `error_log` and pushed to the admin notices FIFO queue (max 20 entries) for transient display
- events are written before the associated action completes where possible, so that failures are always recorded

## Relationships

The schema is intentionally loose and operational rather than deeply relational.

Primary runtime relationships:

- `csp_policy_profiles.surface` is joined logically with `csp_source_inventory.surface`, `csp_hash_inventory.surface`, and `csp_violation_reports.profile_surface`
- `csp_entitlements.site_identity` represents the active licence state for the local install
- `csp_processed_events.stripe_event_id` gates whether a Stripe event can mutate entitlements
- `csp_audit_log` is not joined to other tables; it records events by component name

## Index guidance

The following fields are indexed or uniquely constrained in the activation SQL:

- `csp_source_inventory`: `surface`, `directive`, `approval_state`; UNIQUE on `(surface, directive, source_host)`
- `csp_hash_inventory`: `surface`, `directive`, `status`; UNIQUE on `(directive, hash_value)`
- `csp_violation_reports`: `profile_surface`, `violated_directive`, `fingerprint`, `reported_at`
- `csp_scan_logs`: `status`, `trigger_type`
- `csp_entitlements`: `site_identity`, `product_key`, `status`; UNIQUE on `stripe_session_id`
- `csp_processed_events`: UNIQUE on `stripe_event_id`; index on `stripe_session_id`
- `csp_audit_log`: `severity`, `created_at`

If performance issues appear under high violation volume, first review:

- `csp_violation_reports(fingerprint)` — fingerprint lookup on every report ingestion
- `csp_violation_reports(reported_at)` — used by the daily purge query
- `csp_source_inventory(surface, approval_state)` — scanned on every header build
- `csp_hash_inventory(surface, directive, status)` — scanned on every header build
- `csp_entitlements(site_identity, product_key)` — checked on every feature gate call

## Migration rules

Whenever schema changes are introduced:

1. Increment `WP_CSP_DB_VERSION` in `wp-csp-automation.php`.
2. Update the `CREATE TABLE` SQL in `includes/class-activator.php`. `dbDelta()` handles adding new columns and new tables; it cannot drop columns or change column types.
3. Add explicit upgrade logic in `Plugin::maybe_upgrade_db()` for any change that `dbDelta()` cannot handle automatically.
4. Update this document, the version table at the top of this file, and `CHANGELOG.md`.
5. Test activation on a fresh install and the `maybe_upgrade_db()` path on an existing install.

## Data lifecycle

### Created on activation

- all eight tables are created if absent
- default settings and default per-surface policy profiles are seeded
- the `wp_csp_db_version` option is set to `WP_CSP_DB_VERSION`

### Updated during runtime

- profiles mutate through admin actions and scheduled scans
- source inventory and hashes mutate through scans
- violation reports are upserted from browser-submitted reports; old rows are purged by the daily cron
- entitlements mutate through verified Stripe webhooks
- processed events are appended per webhook receipt
- `csp_audit_log` is appended to by all significant plugin operations; never mutated in place

### Removed on uninstall

- all plugin tables are dropped
- all `wp_csp_*` options are deleted
- plugin transients are deleted
- scheduled cron events are cleared

## Operational risks

| Risk | Mitigation |
|------|-----------|
| High-volume violation reports filling the table | Automatic purge of rows older than `wp_csp_violation_retention_days` days (default 90) runs after every daily cron scan. Per-surface transient rate limiting (500 reports/hour) throttles ingestion. |
| Large source inventories on plugin-heavy installs | Review and deny unnecessary pending sources regularly. Expired approved sources are flagged automatically. |
| Stale entitlements if webhook setup is broken | Grace period allows continued access during transient Stripe outages; surfaced via audit log warnings. |
| Stale remote config if DNS or HTTPS endpoint is neglected | Grace-copy fallback serves the last verified config until the grace TTL expires; audit log warning is emitted. |
| Forbidden directives injected via overrides | `Policy_Builder::build_policy_string()` strips `plugin-types`, `block-all-mixed-content`, `navigate-to`, and `prefetch-src` from overrides at emit time and logs a warning to `csp_audit_log`. |
