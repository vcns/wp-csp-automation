/**
 * CSP Automation Manager — Config + Licensing Worker
 *
 * Routes:
 *   GET  /            Serve signed product config JSON
 *   POST /checkout    Create Stripe Checkout Session → { checkout_url }
 *   POST /webhook     Receive Stripe webhook, grant entitlement in KV
 *   GET  /entitlement Check site entitlement → { entitled, tier, granted_at }
 *
 * ── Secrets ──────────────────────────────────────────────────────────────────
 * Set these in Cloudflare dashboard → Workers → your worker → Settings →
 * Variables → Secrets, or via:  wrangler secret put <NAME>
 *
 *   STRIPE_TEST_SECRET_KEY      sk_test_…
 *   STRIPE_LIVE_SECRET_KEY      sk_live_…
 *   STRIPE_TEST_WEBHOOK_SECRET  whsec_…
 *   STRIPE_LIVE_WEBHOOK_SECRET  whsec_…
 *
 * ── Environment Variables ─────────────────────────────────────────────────────
 * Set in wrangler.toml [vars] or dashboard → Settings → Variables:
 *
 *   STRIPE_MODE   "test" | "live"   (controls which key set is active)
 *
 * ── KV Namespace ──────────────────────────────────────────────────────────────
 * Create a KV namespace called ENTITLEMENTS and bind it in wrangler.toml:
 *
 *   [[kv_namespaces]]
 *   binding = "ENTITLEMENTS"
 *   id      = "<your-namespace-id>"
 *
 * ── Stripe Webhook Registration ───────────────────────────────────────────────
 * Register two webhook endpoints in your Stripe dashboard:
 *   Test: https://config.csp-automation-manager.vcns.tech/webhook?mode=test
 *   Live: https://config.csp-automation-manager.vcns.tech/webhook?mode=live
 * Events: checkout.session.completed, checkout.session.async_payment_succeeded
 */

// ── Signed product config ─────────────────────────────────────────────────────
// Signature is computed over this object (excluding the signature field) using
// your Ed25519 secret key. See .private_key/sign-config.php to regenerate.
// Key order matters — json_encode() must produce identical output on the PHP side.

const CONFIG = {
  version: "1.0.0",
  expires: "2027-01-01T00:00:00Z",

  products: {
    "csp-automation-manager": {
      name: "CSP Automation Manager",
      amount: 1299,
      currency: "gbp",
      // Price IDs are stored in KV (STRIPE_TEST_PRICE_ID / STRIPE_LIVE_PRICE_ID)
      // so they can be rotated without re-signing this config.
      features: ["*"]
    }
  },

  features: {
    pro:  ["*"],
    free: [
      "csp_report_only",
      "basic_scan",
      "basic_dashboard",
      "violation_endpoint"
    ]
  },

  checkout_policy: {
    allow_promotion_codes:      true,
    billing_address_collection: "auto"
  },

  // Replace with output from .private_key/sign-config.php
  signature: "793pQPuSvM32+ZSde3xLuwvgHcr3ozB4Tbja7b3Ys9iYwsZr+owk7Wvn8FtspZ5fGjtQ6aJjkwnak7anMBNDBQ=="
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function timingSafeEqual(a, b) {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

async function verifyStripeSignature(rawBody, sigHeader, secret) {
  const pairs = {};
  for (const part of sigHeader.split(",")) {
    const idx = part.indexOf("=");
    if (idx > 0) pairs[part.slice(0, idx).trim()] = part.slice(idx + 1).trim();
  }
  if (!pairs.t || !pairs.v1) return false;

  const age = Math.abs(Date.now() / 1000 - parseInt(pairs.t, 10));
  if (age > 300) return false;

  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    "raw", enc.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false, ["sign"]
  );
  const raw = await crypto.subtle.sign("HMAC", key, enc.encode(`${pairs.t}.${rawBody}`));
  const expected = Array.from(new Uint8Array(raw))
    .map(b => b.toString(16).padStart(2, "0")).join("");

  return timingSafeEqual(expected, pairs.v1);
}

async function stripePost(path, params, secretKey) {
  const res = await fetch(`https://api.stripe.com/v1${path}`, {
    method: "POST",
    headers: {
      "Authorization":  `Bearer ${secretKey}`,
      "Content-Type":   "application/x-www-form-urlencoded",
      "Stripe-Version": "2024-06-20",
    },
    body: new URLSearchParams(params).toString(),
  });
  return { status: res.status, data: await res.json() };
}

// ── Route: GET / ──────────────────────────────────────────────────────────────

function handleConfig() {
  return new Response(JSON.stringify(CONFIG), {
    status: 200,
    headers: {
      "Content-Type":                "application/json",
      "Cache-Control":               "public, max-age=3600, stale-while-revalidate=86400",
      "Access-Control-Allow-Origin": "*",
    },
  });
}

// ── Route: POST /checkout ─────────────────────────────────────────────────────

async function handleCheckout(request, env) {
  let body;
  try {
    body = await request.json();
  } catch {
    return jsonResponse({ error: "invalid_json" }, 400);
  }

  const { site_identity, product_key = "csp-automation-manager", success_url, cancel_url } = body;

  if (!site_identity || !success_url || !cancel_url) {
    return jsonResponse({ error: "missing_fields" }, 400);
  }
  if (!/^[a-f0-9]{48}$/.test(site_identity)) {
    return jsonResponse({ error: "invalid_site_identity" }, 400);
  }
  if (!success_url.startsWith("https://") || !cancel_url.startsWith("https://")) {
    return jsonResponse({ error: "urls_must_be_https" }, 400);
  }

  const mode      = env.STRIPE_MODE || "test";
  const secretKey = mode === "live" ? env.STRIPE_LIVE_SECRET_KEY : env.STRIPE_TEST_SECRET_KEY;
  if (!secretKey) return jsonResponse({ error: "payment_not_configured" }, 503);

  const product = CONFIG.products[product_key];
  if (!product) return jsonResponse({ error: "unknown_product" }, 400);

  const kvKey   = mode === "live" ? "STRIPE_LIVE_PRICE_ID" : "STRIPE_TEST_PRICE_ID";
  const priceId = await env.ENTITLEMENTS.get(kvKey);
  if (!priceId) return jsonResponse({ error: "price_not_configured" }, 503);

  const params = {
    "mode":                    "payment",
    "line_items[0][price]":    priceId,
    "line_items[0][quantity]": "1",
    "success_url":             success_url,
    "cancel_url":              cancel_url,
    "metadata[site_identity]": site_identity,
    "metadata[product_key]":   product_key,
  };
  if (CONFIG.checkout_policy?.allow_promotion_codes)      params.allow_promotion_codes      = "true";
  if (CONFIG.checkout_policy?.billing_address_collection) params.billing_address_collection = CONFIG.checkout_policy.billing_address_collection;

  const { status, data } = await stripePost("/checkout/sessions", params, secretKey);

  if (status !== 200 || !data.url) {
    return jsonResponse({ error: data?.error?.message || "checkout_failed" }, 502);
  }

  return jsonResponse({ checkout_url: data.url });
}

// ── Route: POST /webhook ──────────────────────────────────────────────────────
// Register in Stripe dashboard as:
//   Test: <worker-url>/webhook?mode=test
//   Live: <worker-url>/webhook?mode=live

async function handleWebhook(request, env) {
  const rawBody  = await request.text();
  const sigHeader = request.headers.get("stripe-signature") || "";

  const url    = new URL(request.url);
  const mode   = url.searchParams.get("mode") || env.STRIPE_MODE || "test";
  const secret = mode === "live" ? env.STRIPE_LIVE_WEBHOOK_SECRET : env.STRIPE_TEST_WEBHOOK_SECRET;

  if (!secret) return jsonResponse({ error: "not_configured" }, 500);

  const valid = await verifyStripeSignature(rawBody, sigHeader, secret);
  if (!valid) return jsonResponse({ error: "invalid_signature" }, 401);

  let event;
  try { event = JSON.parse(rawBody); }
  catch { return jsonResponse({ error: "invalid_json" }, 400); }

  const type = event.type || "";

  if (type === "checkout.session.completed" || type === "checkout.session.async_payment_succeeded") {
    const session = event.data?.object || {};
    if (session.payment_status === "paid") {
      const siteIdentity = session.metadata?.site_identity;
      const productKey   = session.metadata?.product_key;

      if (siteIdentity && productKey && env.ENTITLEMENTS) {
        await env.ENTITLEMENTS.put(
          `entitlement:${siteIdentity}`,
          JSON.stringify({
            product_key:            productKey,
            tier:                   "pro",
            status:                 "active",
            granted_at:             new Date().toISOString(),
            stripe_session_id:      session.id         || null,
            stripe_customer_id:     session.customer   || null,
            stripe_payment_intent:  session.payment_intent || null,
          })
        );
      }
    }
  }

  return jsonResponse({ received: true });
}

// ── Route: GET /entitlement ───────────────────────────────────────────────────

async function handleEntitlement(request, env) {
  const url          = new URL(request.url);
  const siteIdentity = url.searchParams.get("site_identity") || "";

  if (!/^[a-f0-9]{48}$/.test(siteIdentity)) {
    return jsonResponse({ entitled: false, error: "invalid_site_identity" }, 400);
  }

  if (!env.ENTITLEMENTS) return jsonResponse({ entitled: false, error: "kv_not_bound" }, 503);

  const raw = await env.ENTITLEMENTS.get(`entitlement:${siteIdentity}`);
  if (!raw) return jsonResponse({ entitled: false });

  let record;
  try { record = JSON.parse(raw); }
  catch { return jsonResponse({ entitled: false }); }

  return jsonResponse({
    entitled:    record.status === "active",
    tier:        record.tier        || "pro",
    product_key: record.product_key || "csp-automation-manager",
    granted_at:  record.granted_at  || null,
  });
}

// ── Main handler ──────────────────────────────────────────────────────────────

export default {
  async fetch(request, env) {
    const { pathname } = new URL(request.url);
    const method = request.method;

    if (pathname === "/" || pathname === "/config") {
      return method === "GET"
        ? handleConfig()
        : new Response("Method not allowed", { status: 405 });
    }

    if (pathname === "/checkout") {
      return method === "POST"
        ? handleCheckout(request, env)
        : new Response("Method not allowed", { status: 405 });
    }

    if (pathname === "/webhook") {
      return method === "POST"
        ? handleWebhook(request, env)
        : new Response("Method not allowed", { status: 405 });
    }

    if (pathname === "/entitlement") {
      return method === "GET"
        ? handleEntitlement(request, env)
        : new Response("Method not allowed", { status: 405 });
    }

    return new Response("Not found", { status: 404 });
  }
};
