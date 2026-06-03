# Database Schema

## Overview

The plugin creates seven custom tables on activation. All table names are prefixed with the site's configured WordPress table prefix.

## Table list

### `csp_policy_profiles`

Purpose:

- stores per-surface policy mode and directive configuration

Key columns:

- `id`
- `surface` — `frontend`, `admin`, `login`, `api`
- `mode` — `disabled`, `report-only`, `enforce`
- `directives_json` — JSON representation of the directive set for the surface
- `report_uri_enabled`
- `report_to_enabled`
- `strict_dynamic_enabled`
- `updated_at`

Operational notes:

- seeded on activation with strict defaults
- one logical row per surface
- `strict_dynamic_enabled` should be ignored unless the feature gate allows it

### `csp_source_inventory`

Purpose:

- stores discovered external origins that may be added to a policy

Key columns:

- `id`
- `surface`
- `directive`
- `source_value`
- `approval_state` — `pending`, `approved`, `denied`
- `first_seen_at`
- `last_seen_at`
- `source_url`

Operational notes:

- discovery upserts rows instead of inserting duplicates
- approval state is operator-controlled only
- same-origin resources should not be stored as inventory rows

### `csp_hash_inventory`

Purpose:

- stores computed inline-content hashes for CSP allowlisting

Key columns:

- `id`
- `surface`
- `directive`
- `hash_value`
- `source_file`
- `status` — `active`, `retired`
- `first_seen_at`
- `last_seen_at`

Operational notes:

- hashes are stored without approval workflow because they are generated from observed content
- stale hashes are marked retired during scheduled rescans
- policy construction should include only active hashes

### `csp_violation_reports`

Purpose:

- records browser-reported CSP violations

Key columns:

- `id`
- `surface`
- `document_uri`
- `blocked_uri`
- `violated_directive`
- `effective_directive`
- `original_policy`
- `source_file`
- `line_number`
- `column_number`
- `occurrence_count`
- `fingerprint`
- `last_seen_at`

Operational notes:

- duplicate reports increment `occurrence_count`
- rows are keyed operationally by fingerprint, not by document URL alone
- retention and pruning policy should be added if the table grows significantly in production

### `csp_scan_logs`

Purpose:

- records the execution history of scheduled and manual scans

Key columns:

- `id`
- `trigger_type` — `manual`, `cron`
- `status` — `success`, `partial`, `failed`
- `started_at`
- `finished_at`
- `results_json`

Operational notes:

- used for operator auditability and troubleshooting
- results JSON should remain compact enough for admin rendering

### `csp_entitlements`

Purpose:

- stores site-local premium licence state

Key columns:

- `id`
- `site_identity`
- `product_key`
- `tier`
- `status` — `active`, `revoked`, `expired`, `grace`
- `stripe_session_id`
- `stripe_customer_id`
- `payment_intent_id`
- `granted_at`
- `last_validated_at`
- `meta_json`

Operational notes:

- site identity is derived from a truncated SHA-256 hash of the site URL
- feature checks should use this table only and should not require live network access
- grace handling is based on `last_validated_at` plus configured grace hours

### `csp_processed_events`

Purpose:

- stores processed Stripe webhook event IDs for idempotency

Key columns:

- `id`
- `event_id`
- `event_type`
- `processed_at`
- `outcome`

Operational notes:

- this table prevents duplicate grant logic on webhook retries
- outcome strings should be stable enough for support and debugging

## Relationships

The schema is intentionally loose and operational rather than deeply relational.

Primary runtime relationships:

- `csp_policy_profiles.surface` is joined logically with `csp_source_inventory.surface` and `csp_hash_inventory.surface`
- `csp_entitlements.site_identity` represents the active licence state for the local install
- `csp_processed_events.event_id` gates whether a Stripe event can mutate entitlements

## Index guidance

The following fields should remain indexed or uniquely constrained in the activation SQL where applicable:

- `surface`
- `approval_state`
- `status`
- `fingerprint`
- `event_id`
- `stripe_session_id`
- `site_identity`

If performance issues appear, first review indexes on:

- `csp_violation_reports(fingerprint)`
- `csp_source_inventory(surface, approval_state)`
- `csp_hash_inventory(surface, directive, status)`
- `csp_entitlements(site_identity, product_key)`

## Migration rules

Whenever schema changes are introduced:

1. Increment `WP_CSP_DB_VERSION`.
2. Update activation SQL in `includes/class-activator.php`.
3. Add upgrade logic in the plugin bootstrap path if existing installs must be migrated in place.
4. Update this document and `CHANGELOG.md`.
5. Test activation on a fresh install and upgrade on an existing install.

## Data lifecycle

### Created on activation

- all tables are created if absent
- default settings and default profiles are seeded

### Updated during runtime

- profiles mutate through admin actions
- source inventory and hashes mutate through scans
- violation reports mutate through browser reporting
- entitlements mutate through verified Stripe webhooks
- processed events mutate per webhook receipt

### Removed on uninstall

- all plugin tables are dropped
- all `wp_csp_*` options are deleted
- plugin transients are deleted
- scheduled cron events are cleared

## Operational risks

- uncontrolled growth in violation reports on noisy sites
- large source inventories on plugin-heavy installs
- stale entitlements if webhook setup is broken
- stale remote config if DNS or HTTPS endpoint management is neglected

These risks should be monitored in support and release operations.
