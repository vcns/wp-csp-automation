# Architecture

## Purpose

CSP Automation Manager is a WordPress plugin that helps site owners roll out strict Content Security Policy controls without maintaining the entire policy by hand. It combines local discovery and policy management with optional entitlement-gated premium capabilities. Billing and account management are expected to move through VCNS licensing services rather than being owned by the CSP runtime.

## Primary design principles

- Default-safe rollout: every surface starts in report-only mode.
- Local enforcement decisions: runtime feature access is resolved from local database state.
- No secrets in remote config: DNS-discovered configuration contains only public product metadata.
- WordPress-native integration: use core hooks, REST APIs, cron, transients, and HTTP APIs instead of parallel infrastructure.
- Progressive hardening: approvals and policy promotion are explicit human actions.
- Deterministic authority: recommendation systems may advise, but policy mutation remains controlled by local deterministic rules and administrator decisions.

## Top-level component map

### Bootstrap

`csp-automation-manager.php`

Responsibilities:

- declares plugin metadata
- defines version, path, and DB version constants (`WP_CSP_DB_VERSION`)
- registers the autoloader
- wires activation and deactivation hooks
- starts the plugin on `plugins_loaded`

### Lifecycle

`includes/class-activator.php`
`includes/class-deactivator.php`
`uninstall.php`

Responsibilities:

- create and seed custom tables, including audit, decision, policy version, and rule evaluation tables
- register default settings (including violation retention policy) and default policy profiles
- schedule daily cron jobs
- remove cron jobs on deactivation
- remove plugin-owned data on uninstall

### Core runtime coordinator

`includes/class-plugin.php`

Responsibilities:

- construct shared services
- register REST routes
- register admin UI and CSP runtime hooks
- run DB schema migrations via `maybe_upgrade_db()` on each boot when `WP_CSP_DB_VERSION` exceeds the stored option value
- expose the central singleton used by cross-cutting helpers

### CSP runtime

`includes/csp/*`

Responsibilities:

- create a per-request nonce (≥128-bit entropy from CSPRNG)
- inject nonce attributes into script and style tags
- build per-surface CSP headers (including `Reporting-Endpoints` and legacy `Report-To`)
- strip deprecated and forbidden directives from policy overrides at emit time
- discover remote sources from crawled pages
- record inline hashes
- ingest violation reports (with Content-Type and origin validation)
- risk-score discovered and report-learned source proposals before administrator approval
- record administrator approve/reject/revert decisions and suppress rejected/reverted fingerprints
- capture policy version snapshots for material decisions
- record deterministic rule findings for policy decisions
- run scheduled and manual scans (including post-scan violation purge)
- detect conflicting CSP headers

### Entitlement and payment runtime

`includes/modules/*`

Responsibilities:

- fetch remote premium-product configuration
- initiate account-management or checkout flows when configured
- verify Stripe webhook signatures
- store local entitlements
- gate premium features (`strict_dynamic`, `trusted_types`, `multi_surface_scan`)
- provide structured operational logging (append-only DB audit trail via `Audit_Log`)

### Admin runtime

`includes/admin/*`
`assets/js/admin.js`
`assets/css/admin.css`

Responsibilities:

- render settings, dashboard, and entitlement pages
- support source review and mode switching
- trigger scans and config refreshes
- initiate checkout from the admin area
- surface one-per-session warnings for known platform constraints (e.g. wp-admin strict CSP limitation)

## Runtime request flow

### 1. WordPress boot

1. WordPress loads the plugin file.
2. The plugin singleton is initialized on `plugins_loaded`.
3. `maybe_upgrade_db()` compares `WP_CSP_DB_VERSION` against the stored option; if the constant is higher, `Activator::activate()` is called and `dbDelta()` migrates the schema.
4. Shared services are instantiated.
5. Hooks for admin UI, REST endpoints, nonce generation, CSP emission, cron, and conflict detection are registered.

### 2. Frontend or admin page request

1. `Nonce_Manager` generates a random nonce early in the request lifecycle.
2. Script and inline-script attributes receive the nonce through WordPress 6.4+ hooks, with legacy fallback filters for broader compatibility.
3. `Policy_Builder` identifies the current surface: `frontend`, `admin`, `login`, or `api`.
4. The relevant profile is loaded from the database.
5. Approved sources and active hashes are merged into the directive set.
6. Forbidden or deprecated directives (`plugin-types`, `block-all-mixed-content`, `navigate-to`, `prefetch-src`) are stripped from overrides; any stripped directive is logged to `csp_audit_log` at `warning` severity.
7. If enabled and licensed, `'strict-dynamic'` is appended to `script-src`; approved host sources are suppressed from `script-src` at this point (browsers silently ignore host allowlists when `strict-dynamic` is present — CSP3 §8.2).
8. `sandbox` is skipped if null or if the profile is in report-only mode (CSP spec — `sandbox` is ignored in `Content-Security-Policy-Report-Only`).
9. Trusted Types directives (`require-trusted-types-for`, `trusted-types`) are skipped when their arrays are empty; when enabled they are always emitted as report-only regardless of surface mode.
10. Two additional headers are emitted before the CSP header:
    - `Reporting-Endpoints: csp-endpoint="<report_uri>"` — Structured Fields Dictionary (RFC 9651); required for browsers to honour `report-to csp-endpoint` in the CSP
    - `Report-To: {"group":"csp-endpoint","max_age":86400,"endpoints":[{"url":"<report_uri>"}]}` — deprecated JSON format retained as a legacy fallback for pre-Reporting-API browsers
11. The CSP or CSP-Report-Only header is emitted via `send_headers`.

### 3. Scan flow

1. A scan is triggered manually or by WP Cron.
2. `Audit_Log::start_scan()` opens a `csp_scan_logs` record with status `running`.
3. `Discovery` crawls the target URL for each allowed surface.
4. External origins are classified by directive type.
5. New origins are upserted into the source inventory as `pending`.
6. `Policy_Change_Manager` assigns a risk level and skips any source whose latest administrator decision suppresses the same fingerprint.
7. Hash retirement is run to mark previously seen inline hashes as stale when absent.
8. `Audit_Log::finish_scan()` records scan summary and sets status to `completed` or `failed`.
9. `Scheduler::purge_old_violations()` deletes `csp_violation_reports` rows older than `wp_csp_violation_retention_days` days (default 90); the count deleted is logged to `csp_audit_log`.

### 4. Violation ingestion flow

1. Browser submits a violation report to `/wp-json/csp-manager/v1/report`.
2. `Violation_Reporter` validates the `Content-Type` header; requests with a content type other than `application/csp-report`, `application/reports+json`, or `application/json` are rejected with HTTP 400.
3. The payload is normalised from either the legacy `application/csp-report` format (hyphenated field names: `document-uri`, `blocked-uri`, `script-sample`, etc.) or the Reporting API `application/reports+json` format (camelCase field names: `documentURL`, `blockedURL`, `sample`, etc.).
4. The `document-uri` hostname is compared against the WordPress site origin (RFC 6454); reports from a different origin are silently discarded — CSP reports are client-generated and spoofable.
5. Per-surface transient-based rate limiting is enforced (500 reports/hour).
6. A fingerprint is computed over `(profile_surface, blocked_uri, violated_directive)` to deduplicate repeat reports.
7. The `sample` field (inline script/style snippet, populated only when `'report-sample'` is in the emitting directive) is captured and stored in `csp_violation_reports.sample`.
8. A new or existing row in the violation table is upserted; duplicate fingerprints increment `occurrence_count`.
9. While the learning window is open, host-based cross-origin blocked URLs become pending source proposals through `Policy_Change_Manager`; rejected or reverted fingerprints are not proposed again unless a later administrator approval clears suppression.

### 5. Policy change-control flow

1. Discovery and report-endpoint learning create pending source proposals, not approved policy.
2. `Policy_Change_Manager` computes a stable fingerprint from `(surface, directive, source_host)`.
3. High-risk proposals include script/style execution, connection, form, frame, worker, wildcard, cleartext HTTP, broad browser schemes, and unsafe keyword patterns.
4. `Decision_Engine` evaluates proposals through versioned deterministic rules and returns risk, hard exclusions, automation eligibility, and rule findings.
5. Administrators approve, reject, or revert proposals from the Source Inventory queue.
6. Every decision is appended to `csp_policy_change_decisions`, mirrored to `csp_audit_log`, and linked to deterministic rule findings in `csp_decision_rule_evaluations`.
7. Approved and reverted decisions capture a `csp_policy_versions` snapshot for the affected surface.
8. Rejected and reverted decisions set suppression on that fingerprint; future automation skips the same source until a later approval becomes the newest decision.

### 6. Policy audit flow

1. Administrators open **CSP Manager -> Policy Audit**.
2. The current surface summary shows CSP mode, automation mode, latest policy version, pending proposal count, unresolved high-risk count, and the latest captured header.
3. The review queue lists pending proposals with surface, directive, source, risk, evidence count, first seen, and last seen.
4. Recent decisions show actor, state, surface, directive, source, risk, decision-engine version, and linked policy version.
5. Privileged REST endpoints under `/wp-json/csp-manager/v1/admin/*` expose policy history, policy diffs, decisions, pending reviews, and automation configuration for richer future UI workflows.

### 7. Premium entitlement flow

1. Admin opens the entitlement page and starts the configured VCNS account-management flow.
2. Legacy installations may still initiate a compatibility checkout flow from signed remote config.
3. Billing events are handled by VCNS infrastructure, with Stripe acting as an external event source where applicable.
4. Verified entitlement data is cached locally by `Entitlement_Store`.
5. `Feature_Gate` exposes premium features from local entitlement state.
6. Normal CSP generation never performs a remote billing or entitlement lookup.

## Surface model

The plugin treats each of the following as an independent policy surface:

- `frontend`
- `admin`
- `login`
- `api`

Each surface has its own policy profile, scan target, approval set, and violation data. This separation is central to avoiding over-broad CSP allowlists.

**wp-admin surface constraint:** WordPress core Trac #59446 is unresolved — some core admin screens and bundled themes emit inline scripts outside the WordPress script API, preventing strict nonce-based enforcement for the admin surface. Strict enforcement on the admin surface is best-effort; the plugin surfaces a one-per-session admin notice when the admin profile mode is set to `enforce`.

## Trust boundaries

### Trusted local state

- plugin code
- WordPress options
- custom plugin tables
- capability checks and nonces in admin context

### Conditionally trusted external inputs

- DNS TXT record pointing to the remote config URL
- HTTPS remote config payload
- Stripe webhook requests
- browser-submitted CSP reports
- crawled HTML during discovery

Each of these inputs is validated before use:

- remote config is signature-verified when libsodium is available
- Stripe webhook bodies are HMAC-verified
- browser reports are validated for `Content-Type`, `document-uri` origin, normalized, rate-limited, and deduplicated
- discovered sources are not auto-approved
- rejected and reverted source fingerprints are not reintroduced by automation unless the latest administrator decision approves them

## Security-critical decisions

These design choices should not be changed casually:

- entitlements are granted only from verified webhooks, never from redirect query parameters alone
- enforce mode remains blocked until at least one source or hash is approved for the target surface
- remote config must contain public metadata only, never keys or webhook secrets
- local entitlement checks must not make network calls during page rendering
- per-site identity is derived from site URL hash rather than stored in plain text everywhere
- the `Reporting-Endpoints` header must always be emitted alongside any CSP containing `report-to`; without it browsers silently discard the directive and violation reports are never delivered
- `report-to` without a corresponding `Reporting-Endpoints` header is a silent failure — this is the most common misconfiguration in deployed CSP policies
- when `strict-dynamic` is active, host-based sources are suppressed from `script-src` at emit time; emitting them is harmless but creates misleading policy noise since browsers ignore them
- cross-origin violation reports are silently discarded; only reports whose `document-uri` matches the site's own origin are stored
- `csp_audit_log` is append-only — no `UPDATE` or `DELETE` may ever be issued against it; it is the permanent operational audit trail
- `csp_policy_change_decisions` is append-only; suppression is represented by the latest decision for a fingerprint, not by rewriting old decisions
- the violation retention purge uses `UTC_TIMESTAMP()` not `NOW()` to avoid timezone-offset errors in environments where MySQL and PHP have different local time configurations

## Failure handling

### Remote config unavailable

- serve the cached config when available
- serve grace copy if current refresh fails but a stale signed copy exists
- write audit warnings to `csp_audit_log` for operator visibility

### Webhook replay or duplicate delivery

- reject invalid signatures
- use the `csp_processed_events` table for idempotency

### Scan failure

- record the scan result in the scan log table with status `failed`
- preserve existing policy state
- do not auto-promote or auto-approve anything
- `csp_audit_log` receives a `scan_exception` event with the exception message at `error` severity

### Violation ingestion failure

- malformed or unsupported `Content-Type` → HTTP 400 immediately, no DB write
- cross-origin `document-uri` → silently discard, no DB write
- DB write failure → silently swallowed (violation ingestion must not produce a user-visible error)

### Violation table growth

- rows are automatically purged after `wp_csp_violation_retention_days` days (default 90) by the daily cron scan
- per-surface transient rate limiting (500 reports/hour) prevents ingestion storms from filling the table between purge cycles
- set `wp_csp_violation_retention_days` to `0` to disable purging (keep forever); operators should add external archival in that case

## Operational dependencies

- WordPress 6.4+
- PHP 8.1+
- libsodium for strong remote-config verification
- outbound HTTPS to Stripe and the remote config endpoint
- WP Cron, or a server-side cron hitting WordPress regularly enough to execute scheduled scans (includes post-scan violation purge and audit log writes)

## Extension guidance

Future work should preserve existing seams:

- add new premium capabilities through `Feature_Gate`
- keep commercial tier labels separate from internal capability identifiers such as `automation_low_risk`, `automation_advanced`, and `ai_recommendations`
- add new remote config values through the existing signed JSON contract
- add new scan types through `Scheduler` and `Discovery`
- keep admin actions behind capability checks and nonces
- all significant plugin events must be logged to `csp_audit_log` via `Audit_Log::log()` — not only the wp_options FIFO queue
- document every new operational dependency before release
