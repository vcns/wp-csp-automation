# Threat Model

## Scope

This document captures the trust boundaries, threat actors, and security-critical invariants for CSP Automation Manager. The runtime flow is described in `docs/architecture.md`; this document focuses on adversarial framing.

## Trust boundaries

### Fully trusted

| Asset | Trust basis |
|---|---|
| Plugin PHP code | Installed by a WordPress admin with filesystem access |
| WordPress options and custom tables | Protected by WordPress authentication and capability checks |
| Server filesystem | Assumed uncompromised for normal plugin operation |

### Conditionally trusted (validated before use)

| Input | Validation applied |
|---|---|
| DNS TXT record | Used only to obtain the URL of the signed config payload; never executed |
| HTTPS remote config JSON | Ed25519 signature verified with `WP_CSP_CONFIG_PUBLIC_KEY` before any field is consumed |
| Stripe webhooks | HMAC-SHA256 signature verified against `wp_csp_webhook_secret`; 5-minute timestamp replay window enforced |
| Browser CSP violation reports | Content-Type enforced; `document-uri` hostname checked against `home_url()`; rate-limited at 500/hour per surface; deduplicated by SHA-256 fingerprint |
| Crawled HTML (discovery scan) | External origins extracted from resource tags; never executed or auto-approved |

### Explicitly untrusted

| Input | Rationale |
|---|---|
| Stripe redirect query parameters | Entitlement granted only from verified webhook events; redirect parameters are ignored entirely |
| Violation report payload content | Reports are client-generated and trivially spoofable; used for discovery only, never for policy decisions or auto-approval |
| Remote config values | Must contain only public product metadata; a secret appearing in remote config would be a critical defect |

## Threat actors

**Unauthenticated external attacker** — can POST to the violation report endpoint. Mitigated by Content-Type enforcement, cross-origin `document-uri` rejection, rate limiting, and parameterised DB writes. Spoofed reports cannot trigger auto-approval; discovered sources remain `pending` until an admin reviews them.

**Compromised delivery infrastructure** — DNS hijacking, TLS interception, or CDN compromise of the remote config origin. Mitigated by Ed25519 signature verification: a compromised delivery channel cannot forge a valid signature without the private key.

**Malicious co-installed plugin** — a plugin could intercept `apply_filters()` calls. The three infrastructure constants (`WP_CSP_CONFIG_PUBLIC_KEY`, `WP_CSP_CONFIG_DNS_RECORD`, `WP_CSP_WORKER_URL`) are PHP constants, not WordPress options or filterable values. A plugin cannot redirect config fetches or alter the verification key at runtime.

**Stripe webhook replay** — a captured valid webhook event replayed later. Mitigated by the 5-minute timestamp window in `verify_signature()`.

**Privilege-escalation via CSP lockout** — an enforce-mode policy that blocks admin JS could lock an admin out of wp-admin. Mitigated by requiring at least one approved source or hash before enforce mode is permitted, and by surfacing a persistent admin notice when the admin surface is in enforce mode (see `docs/architecture.md` §wp-admin constraint).

## Security-critical invariants

The following must never be changed without a full security review:

1. **Entitlements from webhooks only.** Premium features are granted only when a Stripe webhook with a valid HMAC-SHA256 signature and an in-tolerance timestamp is received. Redirect parameters, admin REST actions, and remote config values must never grant entitlements.

2. **No secrets in remote config.** The signed JSON payload must contain only public product metadata. If any key or credential appeared in remote config, every site that fetched it would be compromised.

3. **Enforce mode requires an approved source or hash.** The plugin blocks CSP enforce mode on a surface until at least one source or hash has been explicitly approved by an admin with the `manage_options` capability.

4. **Cross-origin violation reports are discarded silently.** Reports whose `document-uri` hostname does not match `home_url()` are dropped without revealing a rejection response, to avoid advertising the check to an attacker probing the endpoint.

5. **Infrastructure constants are PHP constants, not filters.** `WP_CSP_CONFIG_PUBLIC_KEY`, `WP_CSP_CONFIG_DNS_RECORD`, and `WP_CSP_WORKER_URL` use `defined() || define()` so they can be overridden only in `wp-config.php` (server-level). Making them filterable would allow any plugin to redirect signature verification or config fetches to an attacker-controlled endpoint.

6. **Violation report fields are never auto-approved.** Discovered `blocked-uri` values are stored with `approval_state = 'pending'`. Only an explicit admin action via the REST API (capability-checked, nonce-validated) can change the state to `approved`.

## Out of scope

The following do not qualify as security issues by themselves:

- Missing best-practice HTTP headers unrelated to this plugin's execution path
- Attacks that require direct admin (filesystem or database) access to the target install
- Issues caused by unsupported PHP, WordPress, or host configurations
- Requests to support end-of-life PHP versions
