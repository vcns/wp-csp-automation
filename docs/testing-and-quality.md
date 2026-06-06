# Testing and Quality

## Objective

This document defines the expected validation baseline for development, review, and release.

## Quality gates

Every meaningful change should be validated at four levels where applicable:

- syntax correctness
- static analysis and coding standards
- behaviour verification in WordPress
- security-focused regression checks

## Local validation baseline

Before opening a pull request, maintainers should aim to validate:

- PHP syntax for changed files
- plugin activation on a clean WordPress install
- admin page load without fatal errors or warnings
- relevant REST endpoint behaviour
- any changed scan, policy, or entitlement paths

## Recommended CI pipeline

The repository should enforce these checks on pull requests:

### Fast checks

- PHP syntax lint
- PHPCS with WordPress standards
- PHPStan at a stable level for the codebase
- secret scanning
- dependency or advisory scanning where Composer dependencies exist

### Integration checks

- boot WordPress in a disposable environment
- activate the plugin
- verify all eight custom tables are created (including `csp_audit_log`)
- verify admin pages load
- verify REST routes register and respond with expected status codes

### Security checks

- CodeQL for PHP
- Semgrep or equivalent SAST rules for PHP and WordPress anti-patterns
- ZAP baseline or similar DAST against a disposable test site

## Manual test matrix

### Activation and upgrade

- activate plugin on a clean site; confirm all eight tables exist (`csp_policy_profiles`, `csp_source_inventory`, `csp_hash_inventory`, `csp_violation_reports`, `csp_scan_logs`, `csp_entitlements`, `csp_processed_events`, `csp_audit_log`)
- confirm default options are seeded including `wp_csp_violation_retention_days` (should be `90`)
- simulate DB upgrade path: set `wp_csp_db_version` to a lower version, reload; confirm `maybe_upgrade_db()` fires and new schema is applied without data loss
- deactivate and confirm cron event is removed
- uninstall and confirm tables and options are removed

### CSP runtime

- verify nonce appears on expected script tags
- verify CSP header exists for frontend, admin, login, and API surfaces as configured
- verify report-only mode does not break page rendering
- verify enforce mode is blocked when no approvals exist

**Reporting-Endpoints and Report-To:**

- load the frontend and run `curl -I <site_url>`; response must contain a `Reporting-Endpoints:` header with value `csp-endpoint="<report_endpoint_url>"`
- response must also contain a `Report-To:` header with the legacy JSON group structure
- the emitted CSP string must contain `report-to csp-endpoint` referencing the endpoint name declared in both headers

**Policy correctness:**

- verify `report-sample` keyword appears in the default `script-src`, `script-src-elem`, `style-src`, and `style-src-elem` directives
- verify `upgrade-insecure-requests` appears as a standalone token (no source list) in frontend, admin, and login surfaces; confirm it does not appear in the api surface
- verify `child-src 'none'` appears in the emitted policy (Safari worker-src fallback)
- verify approved sources and active hashes appear in the emitted header
- insert a forbidden directive (`navigate-to 'self'`) directly into a profile's `overrides` JSON; reload and confirm: (a) `navigate-to` does not appear in the emitted CSP header; (b) a `warning`-severity event with event type `forbidden_directive_stripped` is present in `csp_audit_log`
- enable `strict_dynamic` on a licensed profile; verify `'strict-dynamic'` is present in `script-src` and that approved host sources are absent from `script-src` (hosts are silently ignored by browsers when `strict-dynamic` is present — CSP3 §8.2)

**Sandbox:**

- set `sandbox` to `null` in a profile; verify `sandbox` is absent from the emitted header
- set profile mode to `report-only`; even if `sandbox` is non-null, verify it is absent from the emitted `Content-Security-Policy-Report-Only` header

**Trusted Types:**

- with `require-trusted-types-for` set to `[]`, verify both Trusted Types directives are absent from the emitted header
- enable Trusted Types on a licensed profile in enforce mode; verify the directives are emitted as `Content-Security-Policy-Report-Only` regardless of surface mode (Trusted Types defaults to report-only — Chromium-strong, not yet cross-browser)

**wp-admin surface:**

- set admin surface profile mode to `enforce`; load an admin page; confirm a one-per-session info notice is displayed referencing WordPress core Trac #59446; confirm the notice does not reappear on subsequent admin page loads in the same session (transient key: `wp_csp_admin59446_warned_{user_id}`)

### Discovery and inventory

- run manual scan
- verify discovered sources are classified into expected directives
- verify same-origin assets are excluded
- verify approval and deny actions persist correctly

### Violation reporting

**Format normalization:**

- submit a legacy `application/csp-report` payload with hyphenated field names (`document-uri`, `blocked-uri`, `script-sample`); verify row is created with all fields mapped correctly, including `sample` populated from `script-sample`
- submit a Reporting API `application/reports+json` payload with camelCase field names (`documentURL`, `blockedURL`, `sample`); verify row is created with all fields mapped correctly

**Content-Type rejection:**

- `POST` to `/wp-json/csp-manager/v1/report` with `Content-Type: text/plain`; response must be HTTP 400 with no row inserted
- `POST` with `Content-Type: application/xml`; response must be HTTP 400 with no row inserted
- `POST` with `Content-Type: application/json` (legacy browser fallback); response must be accepted (HTTP 200)

**Cross-origin document-uri:**

- submit a report with `document-uri` set to a different domain (e.g. `https://attacker.example/`); confirm no row is inserted in `csp_violation_reports` and no error is returned to the caller (silent discard)

**Sample field capture:**

- submit a report with `script-sample: "alert(1)"` (legacy) or `sample: "alert(1)"` (Reporting API); confirm `csp_violation_reports.sample` is populated with the truncated value

**Deduplication and rate limiting:**

- submit the same report twice (same surface, blocked-uri, violated-directive); confirm the second submission increments `occurrence_count` rather than inserting a second row
- verify per-surface transient rate limiting rejects excess reports after 500 in one hour

### Violation retention purge

- insert test rows into `csp_violation_reports` with `reported_at` set to a date older than 90 days
- trigger the daily cron scan (or call `Scheduler::run_daily_scan()` directly in a test)
- confirm the old rows are deleted and a `violations_purged` info event is present in `csp_audit_log`
- set `wp_csp_violation_retention_days` to `0`; trigger a scan; confirm no rows are deleted

### Audit log

- perform a policy change, a scan run, and a forbidden-directive injection; confirm `csp_audit_log` has corresponding rows with correct `component`, `event`, `severity`, and `user_id` values
- confirm no `UPDATE` or `DELETE` is ever issued against `csp_audit_log` (grep test: no `$wpdb->update` or `$wpdb->delete` call references `csp_audit_log` in any source file)

### Premium flow

- refresh remote config successfully
- create Stripe Checkout Session in test mode
- complete a successful test payment
- verify entitlement is granted only after webhook delivery
- resend the same webhook and confirm idempotency (`csp_processed_events.stripe_event_id` UNIQUE constraint)
- simulate async failure and confirm no entitlement is granted

## Release validation checklist

Before tagging a release:

- update version in `wp-csp-automation.php`
- update `WP_CSP_DB_VERSION` constant if schema changed; update version table in `docs/database-schema.md`
- update `readme.txt` stable tag if needed
- update `CHANGELOG.md`
- review public docs for accuracy
- run full CI pipeline on the release branch
- perform one clean-install smoke test from the packaged artifact
- confirm no development-only files are shipped unintentionally

## Test data hygiene

- do not commit real API keys, webhook secrets, or signing keys
- use Stripe test keys in development only
- do not store customer-identifying data in fixture payloads if avoidable

## Defect triage priorities

Highest severity:

- privilege escalation
- unauthenticated state changes
- broken webhook verification
- CSP header corruption that can break admin recovery
- entitlement grant without verified payment
- `Reporting-Endpoints` header missing when `report-to` is in the CSP (silent violation reporting failure)
- cross-origin violation reports accepted without origin validation (report spoofing)

High severity:

- enforce/report-only mode logic errors
- broken discovery classification
- persistent data loss on upgrade or uninstall
- invalid remote config acceptance
- forbidden directives (`plugin-types`, `block-all-mixed-content`, `navigate-to`, `prefetch-src`) appearing in emitted headers
- `csp_audit_log` receiving an `UPDATE` or `DELETE` (immutability violation)

Medium severity:

- admin UI defects without security impact
- false-positive violation ingestion
- noisy but non-breaking scans
- violation table unbounded growth when purge cron is misconfigured

## Definition of done

A change is ready to merge when:

- the behaviour is implemented
- affected docs are updated (including `docs/database-schema.md` version table for any schema change)
- relevant validation has been run
- known risks are explicit in the PR
- no unsupported shortcuts were introduced around security-critical flows
