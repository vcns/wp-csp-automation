# Architecture

## Purpose

WP CSP Automation Manager is a WordPress plugin that helps site owners roll out strict Content Security Policy controls without maintaining the entire policy by hand. It combines local discovery and policy management with an optional premium entitlement model powered by one-time Stripe checkout.

## Primary design principles

- Default-safe rollout: every surface starts in report-only mode.
- Local enforcement decisions: runtime feature access is resolved from local database state.
- No secrets in remote config: DNS-discovered configuration contains only public product metadata.
- WordPress-native integration: use core hooks, REST APIs, cron, transients, and HTTP APIs instead of parallel infrastructure.
- Progressive hardening: approvals and policy promotion are explicit human actions.

## Top-level component map

### Bootstrap

`wp-csp-automation.php`

Responsibilities:

- declares plugin metadata
- defines version and path constants
- registers the autoloader
- wires activation and deactivation hooks
- starts the plugin on `plugins_loaded`

### Lifecycle

`includes/class-activator.php`
`includes/class-deactivator.php`
`uninstall.php`

Responsibilities:

- create and seed custom tables
- register default settings and default policy profiles
- schedule daily cron jobs
- remove cron jobs on deactivation
- remove plugin-owned data on uninstall

### Core runtime coordinator

`includes/class-plugin.php`

Responsibilities:

- construct shared services
- register REST routes
- register admin UI and CSP runtime hooks
- expose the central singleton used by cross-cutting helpers

### CSP runtime

`includes/csp/*`

Responsibilities:

- create a per-request nonce
- inject nonce attributes into script and style tags
- build per-surface CSP headers
- discover remote sources from crawled pages
- record inline hashes
- ingest violation reports
- run scheduled and manual scans
- detect conflicting CSP headers

### Premium and payment runtime

`includes/modules/*`

Responsibilities:

- fetch remote premium-product configuration
- create Stripe Checkout sessions
- verify Stripe webhook signatures
- store local entitlements
- gate premium features
- provide structured operational logging

### Admin runtime

`includes/admin/*`
`assets/js/admin.js`
`assets/css/admin.css`

Responsibilities:

- render settings, dashboard, and entitlement pages
- support source review and mode switching
- trigger scans and config refreshes
- initiate checkout from the admin area

## Runtime request flow

## 1. WordPress boot

1. WordPress loads the plugin file.
2. The plugin singleton is initialized on `plugins_loaded`.
3. Shared services are instantiated.
4. Hooks for admin UI, REST endpoints, nonce generation, CSP emission, cron, and conflict detection are registered.

## 2. Frontend or admin page request

1. `Nonce_Manager` generates a random nonce early in the request lifecycle.
2. Script and inline-script attributes receive the nonce through WordPress 6.4+ hooks, with legacy fallback filters for broader compatibility.
3. `Policy_Builder` identifies the current surface: `frontend`, `admin`, `login`, or `api`.
4. The relevant profile is loaded from the database.
5. Approved sources and active hashes are merged into the directive set.
6. If enabled and licensed, advanced policy features such as `strict-dynamic` are added.
7. A CSP or CSP-Report-Only header is emitted via `send_headers`.

## 3. Scan flow

1. A scan is triggered manually or by WP Cron.
2. `Discovery` crawls the target URL for each allowed surface.
3. External origins are classified by directive type.
4. New origins are upserted into the source inventory as `pending`.
5. Hash retirement is run to mark previously seen inline hashes as stale when absent.
6. `Audit_Log` records scan summary and outcome.

## 4. Violation ingestion flow

1. Browser submits a violation report to `/wp-json/csp-manager/v1/report`.
2. `Violation_Reporter` normalizes both legacy CSP and Reporting API payload formats.
3. Per-surface transient-based rate limiting is enforced.
4. A fingerprint is computed to deduplicate repeat reports.
5. A new or existing row in the violation table is updated.

## 5. Premium checkout flow

1. Admin selects a product tier from the entitlement page.
2. `Checkout_Service` resolves the Stripe price ID from signed remote config.
3. The plugin creates a Stripe Checkout Session through the Stripe API.
4. Admin is redirected to Stripe-hosted checkout.
5. Stripe sends a webhook after payment completion.
6. `Webhook_Controller` verifies the signature and replay window.
7. `Entitlement_Store` grants or updates the local entitlement.
8. `Feature_Gate` exposes premium features based on local tier state.

## Surface model

The plugin treats each of the following as an independent policy surface:

- `frontend`
- `admin`
- `login`
- `api`

Each surface has its own policy profile, scan target, approval set, and violation data. This separation is central to avoiding over-broad CSP allowlists.

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
- browser reports are normalized, rate-limited, and deduplicated
- discovered sources are not auto-approved

## Security-critical decisions

These design choices should not be changed casually:

- entitlements are granted only from verified webhooks, never from redirect query parameters alone
- enforce mode remains blocked until at least one source or hash is approved for the target surface
- remote config must contain public metadata only, never keys or webhook secrets
- local entitlement checks must not make network calls during page rendering
- per-site identity is derived from site URL hash rather than stored in plain text everywhere

## Failure handling

### Remote config unavailable

- serve the cached config when available
- serve grace copy if current refresh fails but a stale signed copy exists
- write audit warnings for operator visibility

### Webhook replay or duplicate delivery

- reject invalid signatures
- use the processed-events table for idempotency

### Scan failure

- record the scan result in the scan log table
- preserve existing policy state
- do not auto-promote or auto-approve anything

## Operational dependencies

- WordPress 6.4+
- PHP 8.1+
- libsodium for strong remote-config verification
- outbound HTTPS to Stripe and the remote config endpoint
- WP Cron, or a server-side cron hitting WordPress regularly enough to execute scheduled scans

## Extension guidance

Future work should preserve existing seams:

- add new premium capabilities through `Feature_Gate`
- add new remote config values through the existing signed JSON contract
- add new scan types through `Scheduler` and `Discovery`
- keep admin actions behind capability checks and nonces
- document every new operational dependency before release
