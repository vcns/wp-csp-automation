# Functional Requirement Specification

## Plugin: WordPress CSP Automation Manager

**Version:** 0.3  
**Date:** 7 June 2026  
**Status:** Active — aligned to codebase DB schema v4 and architecture.md

---

## 1. Purpose

This plugin automates generation, maintenance, and enforcement of a strict Content Security Policy (CSP) for WordPress sites. The goal is to maximise browser-enforced script execution controls without relying on `'unsafe-inline'`, `'unsafe-eval'`, or broad host allowlists, while providing a safe incremental rollout path through report-only mode and configurable promotion gates.

---

## 2. Governing Standards

CSP is governed by the W3C Web Application Security Working Group, not by IETF. This plugin targets the following normative references.

**W3C specifications:**

- Content Security Policy Level 2 — W3C Recommendation (2015)
- Content Security Policy Level 3 — W3C Working Draft, 5 May 2026 (`WD-CSP3-20260505`)
- Trusted Types — W3C Working Draft
- Upgrade Insecure Requests — W3C Candidate Recommendation
- Mixed Content — W3C Recommendation
- Reporting API — W3C Working Draft

**IETF documents:**

- RFC 7762 — Initial Assignment for the Content Security Policy Directives Registry (Informational). Establishes the IANA "Content Security Policy Directives" registry (last updated 2023-02-03). The registry lags the living standard and is not authoritative for the full directive set.
- RFC 7034 — HTTP Header Field X-Frame-Options (Informational). Documents the header superseded by CSP `frame-ancestors`.
- RFC 6454 — The Web Origin Concept (Proposed Standard). Defines origin serialisation used throughout CSP source-expression matching.
- RFC 9110 — HTTP Semantics, STD 97 (Internet Standard). HTTP field semantics referenced by CSP3 header ABNF.
- RFC 9651 — Structured Field Values for HTTP (Proposed Standard). Obsoletes RFC 8941. The `Reporting-Endpoints` header is a Structured Fields Dictionary per RFC 9651 and must be constructed accordingly.
- RFC 6797 — HTTP Strict Transport Security (Proposed Standard). Complementary to `upgrade-insecure-requests`; the upgrade directive does not replace HSTS.
- BCP 14 (RFC 2119 / RFC 8174) — Normative language conventions.

**Key clarification:** CSP Level 3 remains a Working Draft as of the date of this document. Directive availability is browser-dependent. Directive presence in this specification does not imply universal browser support. Safari in particular lacks support for `script-src-elem`, `script-src-attr`, `style-src-elem`, and `style-src-attr`; the plugin must maintain `script-src` and `style-src` as portable fallbacks.

---

## 3. Scope

The plugin covers:

- Per-request nonce generation and injection for scripts and styles.
- Static hash management for stable inline script and style blocks.
- Runtime and crawl-based discovery of effective resource requirements.
- Policy generation for multiple WordPress surfaces: `frontend`, `admin`, `login`, `api`.
- Report-only and enforcement modes with configurable promotion gates.
- CSP violation ingestion, deduplication, rate limiting, and retention management.
- Detection of competing CSP headers from plugins, server layers, and platform layers.
- Append-only operational audit logging.
- Optional premium features: multi-surface scan, `strict-dynamic`, Trusted Types, and additional analytics.

The plugin is limited to CSP hardening. It does not implement WAF rules, authentication hardening, malware cleanup, or patch automation.

---

## 4. Security Objectives

- Enforce strict script execution controls using nonces and optional hashes.
- Prevent uncontrolled inline execution paths (event-handler attributes, `javascript:` URLs).
- Minimise source allowlists to only observed and approved origins.
- Ensure policy changes are auditable, reversible, and testable before enforcement.
- Treat browser-submitted violation reports as advisory signal, not ground truth (they are client-generated and spoofable).

---

## 5. Functional Requirements

### 5.1 Per-Request Nonce Generation

- The plugin must generate a cryptographically secure random nonce for every eligible HTTP response using a CSPRNG providing at least 128 bits of entropy, Base64-encoded. The implementation uses `random_bytes(32)` (256 bits), which satisfies this floor.
- The nonce must never be reused across requests.
- The nonce must be inserted into the CSP as `'nonce-{value}'` for `script-src`, `script-src-elem`, `style-src`, and `style-src-elem` where applicable.

### 5.2 Script and Style Attribute Injection

- The plugin must inject nonce attributes using WordPress-native attribute hooks first:
  - `wp_script_attributes` (WordPress 6.4+)
  - `wp_inline_script_attributes` (WordPress 6.4+)
- The plugin must support compatibility fallback via:
  - `script_loader_tag`
  - `style_loader_tag`
- The plugin must not perform blind global string replacement across all `<script>` or `<style>` tags.
- The plugin must support opt-out for specific script handles with explicit admin justification recorded in the audit log.
- Scripts emitted outside the WordPress script API (e.g. hardcoded in templates or admin pages) will not receive nonce attributes. This is a platform constraint, not a plugin defect. See §9 for the known constraint on the admin surface.

### 5.3 Multi-Surface Policy Profiles

- The plugin must generate and apply separate CSP profiles for at minimum the following surfaces:
  - `frontend` — public front-end pages
  - `admin` — `wp-admin` pages (best-effort; see §9)
  - `login` — `wp-login.php`
  - `api` — REST API and `admin-ajax.php` responses
- Each surface profile must have independent directives, overrides, mode, history, and rollout state.
- Surface detection must be performed at request time before header emission.

### 5.4 Strict CSP Directive Builder

The builder produces a semicolon-delimited directive string per the CSP3 serialisation grammar.

**Directives the builder must support:**

Fetch directives (each falls back to `default-src` when unset):

- `default-src` — fallback for all unset fetch directives
- `script-src` — JavaScript execution; portable fallback for `script-src-elem` and `script-src-attr`
- `script-src-elem` — `<script>` elements (CSP3; not supported in Safari — keep `script-src` as fallback)
- `script-src-attr` — inline event-handler attributes (CSP3; not supported in Safari)
- `style-src` — CSS sources; portable fallback for `style-src-elem` and `style-src-attr`
- `style-src-elem` — `<style>` and `<link rel="stylesheet">` (CSP3)
- `style-src-attr` — inline `style=` attributes (CSP3)
- `img-src`
- `font-src`
- `connect-src` — `fetch()`, `XMLHttpRequest`, `WebSocket`, `EventSource`, `<a ping>`
- `frame-src` — iframes (deprecated in CSP2, un-deprecated in CSP3)
- `child-src` — legacy fallback for `frame-src` and `worker-src`; retained for Safari worker-src compatibility
- `worker-src` — `Worker`, `SharedWorker`, `ServiceWorker` (CSP3)
- `manifest-src` — web app manifests (CSP3)
- `media-src` — `<audio>`, `<video>`, `<track>`
- `object-src` — `<object>`, `<embed>`
- `fenced-frame-src` — `<fencedframe>` (experimental; privacy-focused)

Document directives (do not fall back to `default-src`):

- `base-uri` — restricts `<base>` element URLs; prevents base-tag hijacking
- `sandbox` — applies iframe-style sandbox flags; emitted in HTTP header only; silently ignored in `Content-Security-Policy-Report-Only` and in `<meta http-equiv>`

Navigation directives (do not fall back to `default-src`):

- `form-action` — restricts `<form>` submission targets
- `frame-ancestors` — permitted parent frames; supersedes `X-Frame-Options` (RFC 7034); cannot be delivered via `<meta http-equiv>`

Standalone directives:

- `upgrade-insecure-requests` — upgrades `http:` subresource requests to `https:` at the browser before loading; evaluated before mixed-content blocking; does not replace HSTS (RFC 6797)

Reporting directives:

- `report-to` — references an endpoint name defined by the `Reporting-Endpoints` response header; uses the Reporting API (payload: `application/reports+json`)
- `report-uri` — deprecated in CSP3 but retained as a required legacy fallback for browsers without Reporting API support; POSTs `application/csp-report`

**Directives the builder must never emit:**

- `plugin-types` — removed from CSP3
- `block-all-mixed-content` — obsolete; superseded by browser-native auto-upgrade; a no-op when `upgrade-insecure-requests` is set
- `navigate-to` — removed from CSP3
- `prefetch-src` — deprecated and never formally shipped; Chromium intent-to-remove

Any `plugin-types`, `block-all-mixed-content`, `navigate-to`, or `prefetch-src` values present in admin override configurations must be stripped at emit time and the removal logged to `csp_audit_log` at `warning` severity.

**Strict-mode defaults:**

- `default-src 'none'`
- `object-src 'none'`
- `base-uri 'none'`
- `script-src-attr 'none'`
- `style-src-attr 'none'`

**Source expression keywords supported:**

`'self'`, `'none'`, `'unsafe-inline'` (blocked in enforce mode unless overridden), `'unsafe-eval'` (blocked in enforce mode unless overridden), `'strict-dynamic'` (premium; see §5.16), `'unsafe-hashes'`, `'report-sample'`, nonce sources `'nonce-{base64}'`, hash sources `'sha256-{base64}'` / `'sha384-{base64}'` / `'sha512-{base64}'`, scheme sources, host sources.

The plugin must forbid `'unsafe-inline'` and `'unsafe-eval'` in enforce mode unless explicitly approved by an administrator override with expiration timestamp, reason, and owner identity, all recorded in `csp_audit_log`.

**`'report-sample'` keyword:**

The builder must include `'report-sample'` in fetch directives that cover inline content (`script-src`, `style-src`, and their `-elem` variants). When present, browsers include a short inline snippet in the `sample` field of violation reports. This field is captured and stored per §5.13.

### 5.5 Hash Computation for Static Inline Blocks

- For stable inline script and style blocks, the plugin must compute SHA-256 hashes using `hash('sha256', $content, true)` with Base64 encoding, producing `'sha256-{base64}'` source expressions.
- Hash records must include a canonicalised content fingerprint, source context, and timestamp.
- Canonicalisation must normalise line endings (`\r\n` and `\r` to `\n`) only. Aggressive whitespace stripping must not be applied, as it changes the hash value relative to the browser's calculation.
- Changed content must automatically retire the old hash entry and produce a new one.
- Hash-based approval is an alternative to nonces for truly static inline content. For dynamic inline blocks, nonces remain the preferred approach.

### 5.6 Runtime Discovery and Crawl Discovery

- The plugin must combine two discovery inputs:
  - Runtime observation from CSP violation reports and rendered response analysis.
  - Scheduled and manual crawl of representative URLs per surface.
- Static PHP or theme scanning may be used as supplemental signal only and must not be the sole basis for policy decisions.
- Discovery must classify candidate sources by CSP directive and owning component (plugin, theme, core, custom).
- Discovery for surfaces other than `frontend` is a premium feature.

### 5.7 External Source Governance

- Every non-self source must carry:
  - Directive
  - First-seen and last-seen timestamps
  - Owning component
  - Approval state (`pending`, `approved`, `denied`)
  - Optional expiry
- The policy builder must include only `approved` sources in enforce mode.
- Sources not observed within a configurable staleness window must be flagged for review and removal.

### 5.8 Header Emission and Precedence

- The plugin must emit CSP headers through the WordPress `send_headers` hook, with conditional logic per surface.
- The plugin must support:
  - `Content-Security-Policy` — enforcement mode
  - `Content-Security-Policy-Report-Only` — testing and staged rollout mode
- The `sandbox` directive must be omitted in report-only mode (per the CSP specification; it is ignored in `Content-Security-Policy-Report-Only`).
- The plugin must emit `Reporting-Endpoints` and legacy `Report-To` headers alongside every CSP header that contains `report-to`:
  - `Reporting-Endpoints: csp-endpoint="{report_uri}"` — Structured Fields Dictionary per RFC 9651; required for browsers to honour `report-to csp-endpoint`
  - `Report-To: {"group":"csp-endpoint","max_age":86400,"endpoints":[{"url":"{report_uri}"}]}` — deprecated JSON format retained for browsers without Reporting API support
- The plugin must note that `Report-To` (the JSON header) is deprecated in favour of `Reporting-Endpoints` (RFC 9651). Both must be emitted for maximum compatibility.
- Emitting `report-to` without a corresponding `Reporting-Endpoints` header is a silent failure in most browsers. The plugin must always emit `Reporting-Endpoints` when `report-to` is present.
- `report-uri` must always be included alongside `report-to` as a legacy fallback, since Reporting API support remains incomplete across browsers.
- Header delivery via `<meta http-equiv>` must never be used for `frame-ancestors`, `sandbox`, `report-uri`, or the `Content-Security-Policy-Report-Only` header, as the CSP specification prohibits these in `<meta>` delivery.

### 5.9 Conflict Detection

- On activation and scheduled audit, the plugin must detect duplicate or competing CSP headers from:
  - WordPress plugins and themes
  - `.htaccess` and web server configuration (where observable)
  - Reverse proxy and CDN response headers (where observable via HTTP HEAD probe)
- The plugin must warn when multiple CSP headers create accidental over-restriction.
- The plugin must provide guided remediation steps appropriate to the source of the conflict, not only `.htaccess` edits.
- Conflict detection probes must be throttled to avoid excessive HTTP requests; a 24-hour transient gate is the minimum throttle interval.

### 5.10 Daily Scheduled Rescan and Rebuild

- The plugin must schedule a full discovery and rebuild job every 24 hours via WordPress cron.
- The default run time is 02:00 server time and must be configurable.
- Jobs must execute asynchronously and must not block front-end requests.
- Each run must produce an audit record in `csp_scan_logs` containing policy diff summary, source changes, hash changes, and warnings.
- Each daily scan run must trigger a purge of `csp_violation_reports` rows older than `wp_csp_violation_retention_days` days (default 90 days). Setting this option to `0` disables automatic purging. The count of purged rows must be written to `csp_audit_log`.

### 5.11 Manual Rescan and Rebuild

- The dashboard must provide an immediate rescan and rebuild trigger.
- The process must run in the background via WordPress AJAX and stream progress status to the dashboard.
- On completion, results must include a human-readable policy diff.
- Manual scan runs also trigger the violation report purge per §5.10.

### 5.12 Report-Only and Enforcement Promotion Gates

- Report-only mode must be independently configurable per surface profile.
- Enforcement promotion must require all of the following configurable gates to pass:
  - No unresolved high-severity violations within a configurable time window.
  - All active non-self sources in the inventory for the surface are in `approved` state.
  - No active temporary override has passed its expiry timestamp.
- The plugin must block mode promotion and surface a clear reason when any gate fails.
- The plugin must support staged rollout percentages where feasible.

### 5.13 Violation Report Endpoint and Processing

- The plugin must expose a REST endpoint at `/wp-json/csp-manager/v1/report`.
- The endpoint must validate the `Content-Type` request header before processing. Requests with a content type other than `application/csp-report`, `application/reports+json`, or `application/json` must be rejected with HTTP 400.
- The endpoint must accept:
  - Legacy `application/csp-report` payloads (hyphenated field names per CSP Level 2)
  - Reporting API `application/reports+json` payloads (camelCase field names per Reporting API)
- The `document-uri` (legacy) or `documentURL` (Reporting API) field must be validated against the WordPress site origin per RFC 6454. Reports from a different origin must be silently discarded. CSP reports are client-generated and spoofable.
- The processor must apply per-surface transient-based rate limiting (500 reports per hour).
- Repeat reports must be deduplicated by a fingerprint over `(profile_surface, blocked_uri, violated_directive)`, incrementing `occurrence_count` on duplicates.
- Stored report fields must include:

| Field | Source — legacy | Source — Reporting API |
|-------|----------------|----------------------|
| `profile_surface` | derived from `document-uri` | derived from `documentURL` |
| `blocked_uri` | `blocked-uri` | `blockedURL` |
| `document_uri` | `document-uri` | `documentURL` |
| `violated_directive` | `violated-directive` | `violatedDirective` |
| `effective_directive` | `effective-directive` | `effectiveDirective` |
| `original_policy` | `original-policy` | `originalPolicy` |
| `source_file` | `source-file` | `sourceFile` |
| `line_number` | `line-number` | `lineNumber` |
| `column_number` | `column-number` | `columnNumber` |
| `status_code` | `status-code` | `statusCode` |
| `disposition` | `disposition` | `disposition` |
| `referrer` | `referrer` | `referrer` |
| `sample` | `script-sample` | `sample` |
| `user_agent` | HTTP `User-Agent` request header | HTTP `User-Agent` request header |
| `fingerprint` | computed | computed |
| `occurrence_count` | maintained locally | maintained locally |
| `reported_at` | server timestamp | server timestamp |

The `sample` field is only populated by the browser when `'report-sample'` is present in the emitting directive.

### 5.14 Trusted Types (Premium)

- The plugin must support the Trusted Types directives as a premium feature, always defaulting to report-only mode regardless of the surface profile's enforcement state.
- Supported directives:
  - `require-trusted-types-for 'script'` — enforces typed values into DOM XSS sinks (`innerHTML`, `document.write`, etc.)
  - `trusted-types {policy-list}` — allowlists Trusted Types policy names created via `trustedTypes.createPolicy()`
- Trusted Types support is Chromium/Chrome/Edge 83+ only as of the date of this document. MDN (February 2026) states cross-browser support. W3C web-features/Baseline projects broad availability approximately August 2028. The plugin must not promote Trusted Types to enforce mode automatically; the administrator must explicitly enable enforcement when satisfied with report-only coverage.
- When Trusted Types arrays are empty, these directives must be omitted from the header entirely.

### 5.15 Upgrade Insecure Requests

- The plugin must support `upgrade-insecure-requests` as a configurable directive per surface profile.
- When enabled, the browser upgrades `http:` subresource requests, same-origin navigations, and form submissions to `https:` before loading.
- The plugin must note in the admin UI that `upgrade-insecure-requests` does not replace HSTS (RFC 6797) for top-level navigation.
- `block-all-mixed-content` must never be offered or emitted. It is obsolete and is a no-op when `upgrade-insecure-requests` is present.

### 5.16 Strict-Dynamic (Premium)

- The plugin must support `'strict-dynamic'` as an optional addition to `script-src`, gated behind the `strict_dynamic` premium capability.
- When `'strict-dynamic'` is present, browsers ignore host-based allowlist entries in `script-src`. The plugin must suppress host sources from `script-src` at emit time when `'strict-dynamic'` is active, per CSP3 §8.2, to avoid misleading policy noise.
- A nonce- or hash-trusted script may propagate trust to scripts it loads dynamically without each requiring its own nonce.

### 5.17 Administrator Dashboard

- The dashboard must include:
  - Current enforced and report-only policies per surface
  - Policy history and diffs
  - Discovery inventory by directive and owning component
  - Hash inventory with change history
  - Violation analytics and top offenders by surface and directive
  - Override workflow with reason, owner, and expiry
  - Rollout gate status per surface
  - Manual rescan controls with live progress feedback
  - Notification settings
  - One-per-session warning when the admin surface is in enforce mode (wp-admin strict CSP constraint; see §9)

### 5.18 Compatibility Profiles

- The plugin must include optional compatibility presets for common WordPress ecosystem components (security plugins, tag managers, analytics) while keeping strict defaults.
- Any preset inclusion must be transparent, reviewable, and overrideable by the administrator.

### 5.19 Append-Only Audit Log

- All significant plugin events must be written to the `csp_audit_log` table via `Audit_Log::log()`.
- The audit log must be append-only. No `UPDATE` or `DELETE` statements may be issued against it.
- Auditable events include: source approvals, override grants and expirations, mode promotions and demotions, scan start and completion, scan exceptions, directive strip warnings, violation purge counts, conflict detections, and entitlement grants and revocations.

### 5.20 Remote Config and Signature Verification

- The plugin must discover the remote configuration URL by querying a DNS TXT record.
- The remote configuration document must be verified using Ed25519 signature verification when `libsodium` is available.
- The remote configuration must contain public product metadata only. It must never contain Stripe secrets, webhook secrets, or private signing keys.
- When verification fails, the plugin must fall back to a cached copy if available and log the failure to `csp_audit_log`.

### 4.16 Known Platform Constraints

The following limitations are structural and must be surfaced to administrators rather than silently worked around:

- **wp-admin strict CSP (WordPress core Trac #59446):** WordPress core does not yet nonce-stamp all inline scripts in the admin interface. The admin surface CSP profile is therefore **best-effort**; some admin UI components may be blocked under strict enforcement. The plugin must display an informational notice when the admin surface is promoted to enforce mode.
- **Hardcoded `<script>` tags in core themes (Trac #63806):** Some bundled WordPress themes emit `<script>` tags that bypass the script enqueueing APIs and will not receive nonces. These will be blocked by a strict nonce-based CSP.
- **Script API requirement:** Only scripts registered via the WordPress script APIs (`wp_enqueue_script`, `wp_add_inline_script`, `wp_print_inline_script_tag`, `wp_enqueue_script_module`) are automatically nonce-stamped. Third-party inline scripts that bypass these APIs must be approved via hash or source allowlist.
- **`sandbox` directive limitations:** The `sandbox` directive is ignored by browsers in `Content-Security-Policy-Report-Only` mode and in `<meta http-equiv>` delivery. The plugin must suppress `sandbox` in both contexts.
- **Trusted Types cross-browser availability:** As of June 2026, Trusted Types has strong Chromium/Chrome/Edge support (≥83) but lacks Safari support. The Baseline "widely available" milestone is projected around August 2028. The plugin must default Trusted Types to report-only and must not promote it to enforce mode automatically.

---

## 6. Non-Functional Requirements

- The plugin must be compatible with WordPress 6.4+ and PHP 8.1+.
- The plugin requires `libsodium` for Ed25519 remote-config signature verification. Absence of `libsodium` degrades to unverified config fetch with an audit log warning; it does not disable the plugin.
- The plugin must support WordPress multisite. Network-level and site-level policy precedence rules must be defined and documented before multisite is released as supported.
- All stored data must use custom tables suitable for high-volume violation reports and source inventories.
- Input must be sanitised and output escaped at all boundary points.
- Background jobs must be resilient to partial failure and resumable without loss of existing policy state.
- Performance overhead must be defined as a percentile SLO (target: p95 added latency per request ≤ 5 ms under normal load), not as a per-request absolute value.
- The `csp_audit_log` table must be append-only at the database level. No migration, admin action, or code path may issue `UPDATE` or `DELETE` against it.
- Violation report retention must be configurable via `wp_csp_violation_retention_days` (default 90 days, `0` = keep forever).
- The plugin must not perform remote network calls during normal page rendering. All remote calls (Stripe, remote config) are confined to admin-initiated or cron-scheduled paths.
- The violation report endpoint must apply per-surface rate limiting (500 reports per hour per surface) and `document-uri` origin validation before any database write, to protect against ingestion abuse.
- Admin actions that modify policy state must be protected by WordPress capability checks (`manage_options` minimum) and nonce verification.

---

## 7. Acceptance Criteria

- A strict nonce-based policy is emitted in report-only mode for all configured surfaces without errors.
- Nonces are present on WordPress-generated script and style tags via native WordPress 6.4+ attribute hooks.
- Enforced policy operates without `'unsafe-inline'` or `'unsafe-eval'` on the frontend surface under validated rollout gates.
- Report ingestion accepts both `application/csp-report` (legacy) and `application/reports+json` (Reporting API) payloads and stores normalised field data including `sample`.
- Reports from a `document-uri` not matching the site origin are silently discarded and not stored.
- Conflicting CSP headers from other sources are detected and surfaced in the dashboard with remediation guidance.
- Daily and manual rebuild flows produce deterministic policy outputs and auditable diff records in `csp_scan_logs`.
- The `Reporting-Endpoints` header (RFC 9651 Structured Fields Dictionary) and legacy `Report-To` header are emitted alongside every CSP header containing `report-to`.
- `upgrade-insecure-requests` can be enabled per surface without enabling `block-all-mixed-content`.
- The builder never emits `plugin-types`, `block-all-mixed-content`, `navigate-to`, or `prefetch-src`. Any such values in override config are stripped and logged.
- `'report-sample'` is present in applicable fetch directives, and the `sample` field is populated in stored violation records for inline violations.
- Trusted Types directives (premium) are only ever emitted in report-only mode regardless of surface enforcement state.
- When `'strict-dynamic'` is active, host-based allowlist sources are absent from the emitted `script-src`.
- All significant events are present in `csp_audit_log` with no gaps for activations, deactivations, scan runs, source approvals, mode changes, and override grants.

---

## 8. Known Platform Constraints

- **wp-admin strict CSP (WordPress Trac #59446 — unresolved):** Some WordPress core admin screens and bundled admin themes emit inline scripts outside the WordPress script API. Strict nonce-based enforcement for the admin surface is best-effort. The plugin must surface a one-per-session admin notice when the admin surface is placed into enforce mode.
- **Hardcoded inline scripts in themes (WordPress Trac #63806):** Some bundled core themes include `<script>` tags that bypass the WordPress script API and will not receive nonce attributes. These will be blocked under a strict enforce-mode policy.
- **Scripts must use the WordPress script API:** Scripts emitted via `wp_enqueue_script`, `wp_add_inline_script`, `wp_print_inline_script_tag`, and `wp_enqueue_script_module` are eligible for nonce injection. Scripts added by other means are not.
- **Safari CSP3 gap:** `script-src-elem`, `script-src-attr`, `style-src-elem`, and `style-src-attr` are not supported in Safari. The plugin must maintain `script-src` and `style-src` as the portable fallbacks alongside the granular CSP3 directives.
- **Trusted Types browser support:** As of June 2026, Trusted Types enforcement is reliable in Chromium-based browsers only. The plugin must default all Trusted Types directives to report-only and must not promote them to enforce mode automatically.
- **CSP reports are spoofable:** Violation reports are submitted by browsers and can be forged by any client. Report data should be treated as advisory signal for policy refinement, not as a security event log.

---

## 9. Out of Scope

- Non-CSP hardening controls: WAF rules, authentication hardening, malware cleanup, patch automation.
- Automatic source code refactoring of third-party plugin or theme inline JavaScript.
- Management of non-WordPress applications hosted on the same server.
- Server-side HSTS configuration (HSTS is governed by RFC 6797 and is outside this plugin's remit; `upgrade-insecure-requests` is not a substitute for HSTS at the top-level navigation level).

---

## 10. Open Items

- Default compatibility preset catalog and ongoing maintenance process for common WordPress ecosystem components.
- REST API contract documentation for report ingestion and dashboard queries.
- Build and packaging specification for WordPress.org submission.
- Multisite network-level vs site-level policy precedence rules.
- Formal p95 performance SLO measurement methodology and baseline.