# Stripe Operations

## Purpose

This document covers the internal operational model for premium purchase handling.

## Commercial model

- one-time payment, not recurring subscription
- entitlement is granted locally after verified webhook receipt
- no per-request remote licence check during normal plugin runtime

## Stripe objects required

At minimum, production setup needs:

- one product per purchasable tier or offer
- one price object per product and currency combination
- a webhook endpoint targeting the plugin REST route

The plugin currently expects one-time Checkout Sessions using Stripe-hosted checkout.

## Required plugin settings

In the admin settings page, operators can configure:

- Stripe mode: `test` or `live`
- publishable key
- secret key
- webhook secret

These values are stored as WordPress options and must be treated as sensitive where applicable.

## Required webhook events

Configure Stripe to send at least:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`

The plugin only grants access from verified webhook events.

## Checkout session creation

The plugin creates Stripe Checkout Sessions through the WordPress HTTP API instead of the Stripe PHP SDK.

Current characteristics:

- endpoint: `https://api.stripe.com/v1/checkout/sessions`
- mode: `payment`
- metadata includes site identity, product key, and plugin version
- success and cancel handling are informational only

Operational rule:

Do not change fulfilment to depend on browser redirect success parameters. The webhook remains the source of truth.

## Entitlement grant flow

1. Admin initiates purchase from the plugin UI.
2. Plugin maps the selected product to a Stripe `price_id` using remote config.
3. Stripe processes payment.
4. Stripe posts the webhook event.
5. Plugin verifies signature and timestamp tolerance.
6. Processed-events table is checked to avoid double-processing.
7. Entitlement is created or updated in the local database.

## Site identity model

The plugin binds purchases to the current site using a truncated SHA-256 hash of the site URL.

Implications:

- licence state is effectively site-specific
- changing the site URL can invalidate the stored identity match
- migrations between domains should be treated as a support scenario if commercial policy allows reassignment

## Production setup checklist

Before opening sales:

- create live Stripe products and prices
- put the live `price_id` values into the signed remote config
- configure live webhook endpoint URL
- copy live publishable and secret keys into plugin settings
- copy live webhook signing secret into plugin settings
- perform a full test-mode purchase before switching to live mode
- perform at least one live low-value transaction if your commercial process requires live-path validation

## Test-mode checklist

Use Stripe test mode to validate:

- checkout session creation succeeds
- product labels in the UI map to the intended price IDs
- successful payment triggers entitlement grant
- duplicate webhook delivery is ignored safely
- failed async payment path logs correctly without granting access

## Common operational failures

### Checkout session creation fails

Likely causes:

- invalid secret key
- mismatched mode versus price ID
- invalid or missing `price_id` in remote config
- outbound HTTP blocked by host

Actions:

- verify mode
- verify keys
- verify remote config payload
- inspect audit log and Stripe dashboard logs

### Webhook signature verification fails

Likely causes:

- wrong webhook secret configured in the plugin
- multiple webhook endpoints with mismatched secrets
- reverse proxy altering the request body unexpectedly

Actions:

- compare configured secret with Stripe endpoint secret
- verify the endpoint path
- inspect raw event delivery logs in Stripe

### Entitlement missing after successful payment

Likely causes:

- webhook not configured
- webhook failing with 401 or 500
- idempotency record written incorrectly
- site identity mismatch in metadata handling

Actions:

- inspect Stripe delivery logs
- inspect the processed-events table
- inspect the entitlements table
- verify plugin log entries around webhook processing

## Security rules

- never log full secret keys or webhook secrets
- never expose secret material in admin JavaScript or public endpoints
- keep webhook endpoint public but unauthenticated only because signature verification is mandatory
- treat Stripe dashboard access as privileged production access

## Support notes

When investigating payment issues, collect:

- WordPress URL of the affected site
- plugin version
- Stripe mode in use
- Stripe event ID
- Stripe Checkout Session ID
- relevant row from processed events
- relevant row from entitlements

Avoid requesting or sharing full secret keys in support threads.
