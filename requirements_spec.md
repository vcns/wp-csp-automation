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

- The plugin must generate a cryptographically random nonce for every eligible HTTP response using a **CSPRNG** (cryptographically secure pseudorandom number generator) producing **at least 128 bits of entropy**, Base64-encoded. (W3C CSP3 normative requirement; the current implementation uses `random_bytes(32)` = 256 bits, which satisfies this floor.)
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
- The builder must support optional `strict-dynamic` for `script-src`. When `'strict-dynamic'` is present, browsers ignore host-based allowlists in `script-src` (CSP3 §8.2); the builder must suppress those allowlists to avoid misleading noise.
- The plugin must support `upgrade-insecure-requests` as an optional directive (own W3C spec). This directive auto-upgrades `http→https` for sub-resource requests; it does **not** replace HSTS (RFC 6797). It must not be emitted on the `api` surface (REST responses have no navigable resources).
- The builder must support Trusted Types directives:
  - `require-trusted-types-for 'script'` — forces typed values into DOM XSS sinks.
  - `trusted-types <policy-list>` — allowlists Trusted Types policy names.
  - Both must default to **disabled**. When enabled, they must always be emitted as **report-only** regardless of surface mode (Chromium-strong; Baseline widely-available ~August 2028 per web-features; R5).
- The builder must support `sandbox` (document directive). `sandbox` is **ignored in CSP-Report-Only** mode and in `<meta http-equiv>` — the builder must suppress it in those contexts.
- The builder must support `child-src` as a **legacy directive**, set to match `worker-src` by default. Required because Safari falls back `worker-src → child-src → script-src`; without it, nonces bleed through to workers in Safari.
- The builder must **never emit** the following deprecated/removed directives:
  - `plugin-types` — removed; plugins are gone from the web platform.
  - `block-all-mixed-content` — obsolete; superseded by default browser auto-upgrade.
  - `navigate-to` — removed from the CSP3 spec.
  - `prefetch-src` — deprecated/non-standard; Chromium intent-to-remove.
- Note: `script-src-elem`, `script-src-attr`, `style-src-elem`, and `style-src-attr` lack Safari support as of CSP3 WD-20260505; `script-src` and `style-src` remain the portable fallback and must also be set.
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
- The plugin must support dual-report configuration:
  - `report-to <endpoint-name>` (CSP3; references the `Reporting-Endpoints` header)
  - `report-uri <url>` (deprecated in CSP3 but still the **most broadly supported** legacy fallback — must be kept)
- The plugin must emit the `Reporting-Endpoints` HTTP response header (a **Structured Fields Dictionary** per **RFC 9651**, which obsoletes RFC 8941) declaring the named endpoint referenced by `report-to`. Without this header, browsers silently discard the `report-to` directive.
- The plugin must also emit the legacy `Report-To` JSON header as a fallback for pre-Reporting-API browsers. Note: `Report-To` (JSON) is deprecated in favour of `Reporting-Endpoints` and must not be relied upon as the primary delivery mechanism.
- Multiple `Content-Security-Policy` headers combine to the **most restrictive union** — the plugin must detect and warn when other sources emit competing CSP headers.

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
- The endpoint must validate that the `Content-Type` header is `application/csp-report` or `application/reports+json` and reject other types with HTTP 400.
- The endpoint must validate that the `document-uri` in the report belongs to this site's origin; cross-origin reports must be silently discarded (CSP reports are client-generated and spoofable).
- Stored report fields must include both **legacy** (`application/csp-report`) and **Reporting API** (`application/reports+json`) field schemas, normalised to a single canonical internal schema:

  | Internal field | Legacy (`csp-report`) key | Reporting API (`body`) key |
  |----------------|---------------------------|----------------------------|
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
  | `sample` | `script-sample` | `sample` |

- The `sample` field is only populated when `'report-sample'` is present in the matching fetch directive. The plugin must include `'report-sample'` in its default `script-src`, `script-src-elem`, `style-src`, and `style-src-elem` directives.
- Additional stored fields: `referrer`, `user_agent`, `profile_surface`, `reported_at`, `fingerprint`, `occurrence_count`.

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

### 4.16 Known Platform Constraints

The following limitations are structural and must be surfaced to administrators rather than silently worked around:

- **wp-admin strict CSP (WordPress core Trac #59446):** WordPress core does not yet nonce-stamp all inline scripts in the admin interface. The admin surface CSP profile is therefore **best-effort**; some admin UI components may be blocked under strict enforcement. The plugin must display an informational notice when the admin surface is promoted to enforce mode.
- **Hardcoded `<script>` tags in core themes (Trac #63806):** Some bundled WordPress themes emit `<script>` tags that bypass the script enqueueing APIs and will not receive nonces. These will be blocked by a strict nonce-based CSP.
- **Script API requirement:** Only scripts registered via the WordPress script APIs (`wp_enqueue_script`, `wp_add_inline_script`, `wp_print_inline_script_tag`, `wp_enqueue_script_module`) are automatically nonce-stamped. Third-party inline scripts that bypass these APIs must be approved via hash or source allowlist.
- **`sandbox` directive limitations:** The `sandbox` directive is ignored by browsers in `Content-Security-Policy-Report-Only` mode and in `<meta http-equiv>` delivery. The plugin must suppress `sandbox` in both contexts.
- **Trusted Types cross-browser availability:** As of June 2026, Trusted Types has strong Chromium/Chrome/Edge support (≥83) but lacks Safari support. The Baseline "widely available" milestone is projected around August 2028. The plugin must default Trusted Types to report-only and must not promote it to enforce mode automatically.

---

## 5. Non-Functional Requirements

- The plugin must be compatible with WordPress 6.4+ and PHP 8.1+.
- The plugin must support multisite. Network-level policies must take precedence over site-level policies where a network administrator has locked settings; site administrators may only tighten, not loosen, network policy.
- All stored data must use custom tables suitable for high-volume reports and source inventories.
- Input must be sanitized and output escaped.
- Background jobs must be resilient to partial failure and resumable.
- Performance objective must be defined as a percentile SLO (for example, p95 response overhead target), not a fixed per-request absolute value.
- **Capability and permission model:** The capability required for approving sources or overriding CSP directives must be documented. Different operations may require different capabilities (e.g., `manage_options` for policy promotion, a narrower cap for source approval review).
- **Immutable audit log:** All policy changes, source approvals, override grants, and scan events must be written to an append-only database table (`csp_audit_log`). No `UPDATE` or `DELETE` must ever be issued against this table.
- **Data retention:** Violation reports must be automatically purged after a configurable retention window (default 90 days). A value of 0 must mean keep forever. Purge must run as part of the daily cron scan.
- **Report endpoint abuse protection:** The `/report` endpoint must validate the request `Content-Type` header and reject non-CSP content types with HTTP 400. The `document-uri` field must be validated against the site origin; cross-origin reports must be silently discarded. The existing per-surface rate limit (500/hour) must be maintained.
- **Dashboard accessibility and internationalisation:** All admin UI strings must be wrapped in translation functions. Admin notices must use ARIA-compatible markup.
- **Multisite network-vs-site policy precedence:** Must be defined before multisite support is considered complete.

---

## 6. Acceptance Criteria

- A strict nonce-based policy can be emitted in report-only mode for all configured surfaces.
- Nonces are present on WordPress-generated script tags via native attribute hooks.
- Enforced policy can run without `'unsafe-inline'` and `'unsafe-eval'` under validated rollout gates.
- Report ingestion supports both `report-to` and legacy `report-uri` formats.
- Conflicting CSP sources are detected and surfaced with remediation guidance.
- Daily and manual rebuild flows produce deterministic policy outputs and auditable diffs.
- **R5 (Trusted Types):** A Trusted Types report-only policy (`require-trusted-types-for 'script'`) can be emitted on any surface and its violations are ingested and stored by the `/report` endpoint.
- **R6 (upgrade-insecure-requests):** `upgrade-insecure-requests` can be enabled per surface and appears in the emitted CSP header. Enabling it must not cause `block-all-mixed-content` to be emitted (it is in the denylist).
- **R7 (report-sample):** When `'report-sample'` is present in `script-src`, a synthetic inline violation produces a non-empty `sample` field in the stored `csp_violation_reports` row.
- **R4 (denylist):** Inserting any of `plugin-types`, `block-all-mixed-content`, `navigate-to`, or `prefetch-src` directly into a profile's `overrides` JSON must result in those directives being absent from the emitted CSP header, and an audit log warning must be written.
- **R1/Reporting-Endpoints:** After plugin activation, `curl -I <site>` must return both a `Reporting-Endpoints: csp-endpoint="..."` header and a `Content-Security-Policy:` (or `Content-Security-Policy-Report-Only:`) header containing `report-to csp-endpoint`.
- **Emitted policy quality:** The enforced mode CSP policy for the frontend surface must pass an external CSP evaluator (e.g., Google CSP Evaluator) with no `'unsafe-inline'` or `'unsafe-eval'` present.

---

## 7. Standards and References

CSP is governed by the **W3C Web Application Security Working Group**, not IETF. The IANA "Content Security Policy Directives" registry (last updated 2023-02-03, 16 CSP2 directives only) lags the living standard and is **not authoritative** for the current directive set.

| Document | Body | Status | Relevance |
|----------|------|--------|-----------|
| [CSP Level 2](https://www.w3.org/TR/CSP2/) | W3C | **Recommendation** (2015) | Baseline fetch/document/navigation/reporting directives |
| [CSP Level 3](https://www.w3.org/TR/CSP3/) (WD-20260505) | W3C | **Working Draft** | Current normative reference; widely implemented despite WD status |
| [Trusted Types](https://www.w3.org/TR/trusted-types/) | W3C | Working Draft | `require-trusted-types-for`, `trusted-types` directives |
| [Upgrade Insecure Requests](https://www.w3.org/TR/upgrade-insecure-requests/) | W3C | Candidate Recommendation | `upgrade-insecure-requests` directive |
| [Mixed Content](https://www.w3.org/TR/mixed-content/) | W3C | Recommendation | Context for why `block-all-mixed-content` is now obsolete |
| [CSP: Embedded Enforcement](https://www.w3.org/TR/csp-embedded-enforcement/) | W3C | Working Draft | `csp` iframe attribute, `Sec-Required-CSP`, `Allow-CSP-From` |
| [Reporting API](https://www.w3.org/TR/reporting/) | W3C | Working Draft | `Reporting-Endpoints` header; `application/reports+json` format |
| **RFC 7762** | IETF | Informational | Establishes IANA CSP Directives registry; registration policy "Specification Required" |
| **RFC 7034** | IETF | Informational | `X-Frame-Options`; superseded by CSP `frame-ancestors` |
| **RFC 6454** | IETF | Proposed Standard | The Web Origin Concept — foundation of CSP source matching |
| **RFC 9110** (STD 97) | IETF | **Internet Standard** | HTTP Semantics; CSP3 ABNF references §5.6.1 for list rules |
| **RFC 5234** (STD 68) | IETF | **Internet Standard** | ABNF — grammar notation used throughout all CSP specs |
| **RFC 9651** | IETF | Proposed Standard | Structured Field Values — `Reporting-Endpoints` is a Dictionary; **obsoletes RFC 8941** |
| **RFC 6797** | IETF | Proposed Standard | HTTP Strict Transport Security (HSTS); `upgrade-insecure-requests` does not replace it |
| **BCP 14** (RFC 2119 + RFC 8174) | IETF | Best Current Practice | Normative language conventions (MUST, SHOULD, etc.) |

---

## 8. Out of Scope

- Non-CSP host-header security, hardening controls (WAF rules, authentication hardening, malware cleanup, patch automation).
- Server or client-side caching
- Automatic source code refactoring of third-party plugin/theme inline JavaScript (might be great to consider in future, but i'm not capable of doing this - perhaps one day)
- Management of non-WordPress applications hosted on the same server.

---

## 9. Open Items

- Database schema definition for policy profiles, source inventory, hash inventory, and report events.
- REST API contract details for report ingestion and dashboard queries.
- Build and packaging specification.
- Default compatibility preset catalog and maintenance process.
