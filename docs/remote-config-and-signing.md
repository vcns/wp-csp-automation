# Remote Config and Signing

## Purpose

The plugin uses a DNS-discovered remote configuration document to publish premium product metadata without embedding Stripe product details directly in the codebase. This allows price IDs and feature mappings to be changed without shipping a new plugin version.

## Non-negotiable rule

Remote config is public metadata only.

Never place any of the following in the remote config JSON or DNS TXT record:

- Stripe secret keys
- Stripe webhook secrets
- private signing keys
- WordPress admin secrets
- customer or entitlement data

## Discovery format

The plugin reads a DNS TXT record whose default constant is:

- `_csp-config.csp-automation-manager.vcns.tech`

Expected TXT record format:

- `v=1;cfg=https://example.com/path/to/config.json`

Field meanings:

- `v=1` — config-discovery format version
- `cfg=` — HTTPS URL to the signed JSON document

## JSON payload contract

The expected JSON document includes:

- `version`
- `issued`
- `expires`
- `products`
- `features`
- `checkout_policy`
- `signature`

### `products`

An array of product definitions. Each product should include enough metadata for the admin UI and checkout initiation, for example:

- `product_key`
- `tier`
- `label`
- `description`
- `price_id`
- `badge`
- `features`

### `features`

A tier-to-feature map used by `Feature_Gate` to determine access.

### `checkout_policy`

A container for public operational toggles such as purchase availability or UI text. Keep it public and non-sensitive.

## Signature model

The plugin expects an Ed25519 detached signature, base64-encoded and embedded in the `signature` field.

Verification flow:

1. Fetch JSON over HTTPS.
2. Remove or exclude the `signature` field from the signed payload representation.
3. Verify the detached signature against the hardcoded public key constant in the plugin.
4. Accept and cache the config only if signature verification succeeds, or if the host lacks libsodium and the operator accepts the degraded mode.

## Key management

### Public key

- stored in the plugin constant `WP_CSP_CONFIG_PUBLIC_KEY`
- safe to ship with the plugin
- must be replaced before production use

### Private key

- never committed to the repository
- never stored in WordPress options
- never exposed through CI logs
- should be stored in a dedicated secrets manager or equivalent secure environment

## Recommended signing process

1. Construct the JSON payload without the `signature` field.
2. Canonicalize the payload consistently before signing.
3. Sign the canonical payload with the private Ed25519 key.
4. Base64-encode the detached signature.
5. Insert the signature into the final JSON document.
6. Publish the final JSON over HTTPS.
7. Update the DNS record only if the endpoint location changed.

## Canonicalization guidance

The signing process must be deterministic. Use one stable serialization strategy and do not change it between releases without updating both signer and verifier expectations.

Recommended approach:

- use UTF-8 JSON
- use a stable key order
- avoid pretty-printing differences if the signer depends on exact bytes
- define the exact serialization in the external signing script and keep that script version-controlled outside the public plugin if it contains privileged operational logic

## Caching model

The plugin caches the validated config in a transient and may also keep a grace copy for temporary failure handling.

Operational implications:

- config changes may not be visible immediately on all sites
- expiry windows must be long enough to survive transient DNS or hosting issues
- operators should document expected propagation time before changing product metadata during a launch

## Failure scenarios

### DNS record missing

- no fresh config can be discovered
- plugin should fall back to cached or grace config if present
- audit warnings should be generated

### HTTPS endpoint unavailable

- same fallback behaviour as above
- no entitlement state should be revoked just because config refresh failed

### Invalid signature

- reject the payload
- continue using the last good cached config if available
- investigate publishing pipeline or key mismatch immediately

### libsodium unavailable

- current implementation logs a warning and continues in degraded mode
- this should be treated as a hosting exception, not a normal production posture

## Operational checklist

Before production launch:

- replace the placeholder public key constant in the plugin
- generate and secure the private signing key
- publish the initial signed config
- verify DNS TXT resolution from multiple networks
- verify plugin-side refresh succeeds
- verify product labels and price IDs match live Stripe configuration
- document key rotation ownership and emergency procedures

## Key rotation guidance

Key rotation requires coordination because old plugin installs trust only the public key they ship with.

Recommended options:

- rotate keys only in a planned plugin release that updates the embedded public key
- if dual-key support is later added, document overlap windows explicitly

Do not rotate signing keys ad hoc without a compatible client update path.
