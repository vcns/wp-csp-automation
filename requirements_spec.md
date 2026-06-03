# Functional Requirement Specification
## Plugin: WordPress CSP Automation Manager
**Version:** 0.2 Hardened Draft  
**Date:** 2 June 2026

---

## 1. Purpose

This plugin automates generation, maintenance, and enforcement of a strict Content Security Policy (CSP) for WordPress sites, with the goal of maximizing browser-enforced script execution controls without relying on `'unsafe-inline'` or broad allowlists.

---

## 2. Scope

The plugin covers:

- Per-request nonce generation and nonce injection for scripts and styles.
- Static hash management for stable inline script and style blocks.
- Runtime and crawl-based discovery of effective resource requirements.
- Policy generation for multiple WordPress surfaces (front-end, admin, login, API/AJAX).
- Report-only and enforcement modes with promotion gates.
- CSP violation ingestion, deduplication, and analysis.
- Detection of competing CSP headers from plugins, server layers, and platform layers.

The plugin is limited to CSP improvements only and does not implement non-CSP hardening controls.

---

## 3. Security Objectives

- Enforce strict script execution controls using nonces and optional hashes.
- Prevent uncontrolled inline execution paths (event handler attributes, javascript: URLs).
- Minimize source allowlists to only observed and approved origins.
- Ensure policy changes are auditable, reversible, and testable before enforcement.

---

## 4. Functional Requirements

### 4.1 Per-Request Nonce Generation

- The plugin must generate a cryptographically random nonce for every eligible HTTP response using `random_bytes(32)` encoded as Base64.
- The nonce must never be reused across requests.
- The nonce must be inserted into CSP as `'nonce-{value}'` for `script-src` and `style-src` where applicable.

### 4.2 Script and Style Attribute Injection

- The plugin must inject nonce attributes using WordPress-native attribute hooks first:
  - `wp_script_attributes`
  - `wp_inline_script_attributes`
- The plugin must support compatibility fallback via:
  - `script_loader_tag`
  - `style_loader_tag`
- The plugin must not perform blind global string replacement across all `<script>` or `<style>` tags.
- The plugin must support opt-out for specific handles with explicit admin justification.

### 4.3 Multi-Surface Policy Profiles

- The plugin must generate and apply separate CSP profiles at minimum for:
  - Public front-end pages
  - `wp-admin` pages
  - `wp-login.php`
  - REST API and `admin-ajax.php` responses (where CSP is meaningful)
- Each surface profile must have independent overrides, history, and rollout state.

### 4.4 Strict CSP Directive Builder

- The plugin must build directives from nonce/hashes plus approved source lists.
- The plugin must produce directives including at minimum:
  - `default-src`
  - `script-src`
  - `script-src-elem`
  - `script-src-attr`
  - `style-src`
  - `style-src-elem`
  - `style-src-attr`
  - `img-src`
  - `font-src`
  - `connect-src`
  - `frame-src`
  - `frame-ancestors`
  - `base-uri`
  - `form-action`
  - `object-src`
  - `media-src`
  - `worker-src`
  - `manifest-src`
- The builder must support strict mode defaults:
  - `default-src 'none'`
  - `object-src 'none'`
  - `base-uri 'none'`
  - `script-src-attr 'none'`
  - `style-src-attr 'none'`
- The builder must support optional `strict-dynamic` for `script-src`.
- The plugin must forbid `'unsafe-inline'` and `'unsafe-eval'` in enforced mode unless explicitly approved by an administrator override with expiration, reason, and owner.

### 4.5 Hash Computation for Static Inline Blocks

- For stable inline script/style blocks, the plugin must compute SHA-256 hashes using `hash('sha256', $content, true)` with Base64 encoding.
- Hash records must include canonicalized content fingerprint, source context, and timestamp.
- Canonicalization rules must be deterministic and documented to avoid hash churn caused by insignificant formatting changes.
- Changed content must invalidate old hashes automatically.

### 4.6 Runtime Discovery and Crawl Discovery

- The plugin must combine two discovery inputs:
  - Runtime observation from CSP violation reports and rendered response analysis.
  - Scheduled authenticated and unauthenticated crawl of representative URLs.
- Static PHP/theme scanning may be used as supplemental signal only and must not be the sole source of policy decisions.
- Discovery must classify candidate sources by directive and owning component (plugin/theme/core/custom).

### 4.7 External Source Governance

- Every non-self source must have metadata:
  - Directive
  - First seen / last seen timestamps
  - Owning component
  - Approval state
  - Expiry (optional)
- The policy builder must include only approved sources in enforcement mode.
- Stale sources not observed within a configurable window must be flagged for removal.

### 4.8 Header Emission and Precedence

- The plugin must emit headers through WordPress hook flow (e.g., `send_headers`) and must support conditional logic per surface.
- The plugin must support:
  - `Content-Security-Policy` (enforcement)
  - `Content-Security-Policy-Report-Only` (testing)
- The plugin must support dual-report configuration with:
  - `report-to`
  - `report-uri` (legacy fallback)
- The plugin must support `Reporting-Endpoints` header management for named report groups.

### 4.9 Conflict Detection

- On activation and scheduled audit, the plugin must detect duplicate/competing CSP headers from:
  - WordPress plugins/themes
  - `.htaccess`
  - Web server config (where observable)
  - Reverse proxy / CDN response headers (where observable)
- The plugin must warn when multiple CSP headers create accidental over-restriction.
- The plugin must provide guided remediation steps rather than only `.htaccess` edits.

### 4.10 Daily Scheduled Rescan and Rebuild

- The plugin must schedule a full discovery and rebuild job every 24 hours by WordPress cron.
- Default run time is 02:00 server time and must be configurable.
- Jobs must execute asynchronously and must not block front-end requests.
- Each run must produce an audit record containing policy diff summary, source changes, hash changes, and warnings.

### 4.11 Manual Rescan and Rebuild

- The dashboard must provide an immediate rescan/rebuild trigger.
- The process must run in background and stream progress status.
- On completion, results must include a human-readable policy diff.

### 4.12 Report-Only and Enforcement Promotion Gates

- Report-only mode must be independently configurable per surface profile.
- Enforcement promotion must require configurable gates, such as:
  - No unresolved high-severity violations for a defined time window.
  - All active non-self sources approved and attributed.
  - No temporary override past expiry.
- The plugin must support staged rollout percentages where feasible.

### 4.13 Violation Report Endpoint and Processing

- The plugin must expose a REST endpoint at `/wp-json/csp-manager/v1/report` for CSP reports.
- The endpoint must accept modern report payloads and legacy `application/csp-report` payloads.
- The processor must deduplicate high-volume repeats and apply rate limits.
- Stored report fields must include:
  - blocked URL
  - effective directive
  - violated directive
  - source file
  - line and column (if available)
  - disposition
  - user agent
  - profile/surface identifier
  - timestamp

### 4.14 Administrator Dashboard

- The dashboard must include:
  - Current enforced and report-only policies per surface
  - Policy history and diffs
  - Discovery inventory by directive and owner
  - Hash inventory with change history
  - Violation analytics and top offenders
  - Override workflow with reason, owner, and expiry
  - Rollout gate status
  - Manual rescan controls
  - Notification settings

### 4.15 Compatibility Profiles

- The plugin must include optional compatibility presets for common WordPress ecosystem components (e.g., security plugins, tag managers) but must keep strict defaults.
- Any preset inclusion must be transparent, reviewable, and overrideable.

---

## 5. Non-Functional Requirements

- The plugin must be compatible with WordPress 6.4+ and PHP 8.1+.
- The plugin must support multisite.
- All stored data must use custom tables suitable for high-volume reports and source inventories.
- Input must be sanitized and output escaped.
- Background jobs must be resilient to partial failure and resumable.
- Performance objective must be defined as a percentile SLO (for example, p95 response overhead target), not a fixed per-request absolute value.

---

## 6. Acceptance Criteria

- A strict nonce-based policy can be emitted in report-only mode for all configured surfaces.
- Nonces are present on WordPress-generated script tags via native attribute hooks.
- Enforced policy can run without `'unsafe-inline'` and `'unsafe-eval'` under validated rollout gates.
- Report ingestion supports both `report-to` and legacy `report-uri` formats.
- Conflicting CSP sources are detected and surfaced with remediation guidance.
- Daily and manual rebuild flows produce deterministic policy outputs and auditable diffs.

---

## 7. Out of Scope

- Non-CSP hardening controls (WAF rules, authentication hardening, malware cleanup, patch automation).
- Automatic source code refactoring of third-party plugin/theme inline JavaScript.
- Management of non-WordPress applications hosted on the same server.

---

## 8. Open Items

- Database schema definition for policy profiles, source inventory, hash inventory, and report events.
- REST API contract details for report ingestion and dashboard queries.
- Build and packaging specification.
- Default compatibility preset catalog and maintenance process.
