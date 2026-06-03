=== WP CSP Automation Manager ===
Contributors:      sjackson0109
Donate link:       https://github.com/sjackson0109/wp-csp-automation
Tags:              csp, content-security-policy, security-headers, security, nonce
Requires at least: 6.4
Tested up to:      6.7
Stable tag:        0.2.0
Requires PHP:      8.1
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automate strict Content Security Policy management for WordPress: per-surface profiles, nonce injection, source discovery, violation reporting, and a premium tier via Stripe.

== Description ==

WP CSP Automation Manager takes the pain out of managing Content Security Policies on WordPress. Instead of writing and maintaining CSP headers by hand, the plugin discovers external resources, generates correct `sha256-` hashes for inline blocks, injects per-request nonces using the native WordPress 6.4+ attribute API, and emits a fully-formed CSP (or `Content-Security-Policy-Report-Only`) header on every page load.

= Free features =

* Per-surface CSP profiles for `frontend`, `admin`, `login`, and `api` — configured and managed independently
* Strict defaults for all 18 CSP directives — `object-src 'none'`, `base-uri 'none'`, `frame-ancestors 'none'`, etc.
* Per-request nonce injection via `wp_script_attributes` / `wp_inline_script_attributes` (WP 6.4+) with legacy `script_loader_tag` / `style_loader_tag` fallback
* Report-Only mode for safe, zero-disruption rollout — the browser reports violations but blocks nothing
* CSP violation reporting endpoint (`/wp-json/csp-manager/v1/report`) — compatible with CSP Level 3 and the Reporting API
* Deduplication and rate-limiting on incoming violation reports (500/hour/surface)
* Basic source discovery: crawl the frontend surface, classify external URLs into CSP directives, store in the local database
* Source approval / deny workflow — each discovered origin requires explicit approval before it is added to the policy
* Promotion gate: enforce mode is only available after at least one source or hash has been approved for that surface
* Conflict detection: detects competing CSP headers from other plugins or server configuration
* Daily WP Cron rescan with email notification when the policy changes

= Premium features (licence required) =

* Multi-surface crawl (admin, login, API surfaces in addition to frontend)
* `strict-dynamic` support — simplifies nonce-based policies
* CSV export for violation analytics
* Extended source inventory REST API

Premium access is purchased inside the plugin via Stripe Checkout. Entitlement is stored locally in your site's database; there is no remote entitlement check on each page load. Purchasing requires the plugin to have valid Stripe API keys configured in **CSP Manager → Settings**.

= Privacy =

No personal data is transmitted to third parties. Violation reports submitted by browsers are stored locally in your WordPress database. The only outbound HTTP calls are:

1. To Stripe (during checkout session creation) — initiated by the admin user
2. To an HTTPS endpoint you control (for remote product configuration) — a signed JSON document you host

= External services =

This plugin connects to the following external services:

* **Stripe** (`https://api.stripe.com`) — used to create one-time checkout sessions and receive payment webhooks. Governed by [Stripe's Terms of Service](https://stripe.com/legal) and [Privacy Policy](https://stripe.com/privacy). Only called when Stripe keys are configured and an admin user initiates a checkout.
* **Your remote config endpoint** — a domain and URL you configure. The plugin fetches a signed JSON document over HTTPS. You control this endpoint entirely.

== Installation ==

= Minimum requirements =

* WordPress 6.4 or higher
* PHP 8.1 or higher
* The `sodium` PHP extension (present by default in PHP 8.1+) — used for Ed25519 signature verification

= From your WordPress dashboard =

1. Go to **Plugins → Add New Plugin**.
2. Search for **WP CSP Automation Manager**.
3. Click **Install Now**, then **Activate**.

= Manual installation =

1. Download the `.zip` from the [WordPress Plugin Directory](https://wordpress.org/plugins/wp-csp-automation/).
2. Go to **Plugins → Add New Plugin → Upload Plugin**.
3. Select the `.zip` and click **Install Now**, then **Activate**.

= After activation =

1. Go to **CSP Manager → Settings** and optionally enter your Stripe API keys (only needed for premium purchase).
2. Go to **CSP Manager → Dashboard → Scan Log** and click **Run Scan Now**.
3. Switch to the **Source Inventory** tab and review discovered origins. Approve the ones your site genuinely needs.
4. Monitor the **Violations** tab while running in Report-Only mode.
5. When you are confident in the approved source list, promote a surface to Enforce mode from the **Profiles** tab.

== Frequently Asked Questions ==

= Will this break my site? =

No. Every surface starts in **Report-Only** mode. The browser reports violations but does not block any resources. You can review violations and approve sources before ever enabling Enforce mode.

= What is the promotion gate? =

The plugin blocks switching to Enforce mode unless at least one source or hash has been approved for that surface. This prevents accidental lockout.

= Should I approve every discovered source? =

No. Review each origin carefully. Approve only sources that are genuinely required for your site to function correctly. Deny anything that looks like third-party advertising, tracking, or unknown origins.

= Where are violation reports stored? =

Locally in your WordPress database in the `{prefix}csp_violation_reports` table. Nothing is sent to any external service. Reports are deduplicated by a SHA-256 fingerprint of the surface, blocked URI, and violated directive.

= Does this plugin require Stripe? =

No. All free features work without entering Stripe credentials. Stripe is only required to purchase a premium licence.

= Is the premium licence tied to one WordPress install? =

Yes. The licence is bound to the SHA-256 hash of your site URL. Migrating to a different domain invalidates the licence.

= What PHP extensions are required? =

* `json` — standard in all PHP 8.x builds
* `hash` — standard in all PHP 8.x builds
* `sodium` — bundled with PHP 8.1+ (libsodium); required for Ed25519 signature verification on the remote config

If the sodium extension is unavailable, the plugin logs an audit warning and continues operating — but the remote config is accepted without cryptographic verification.

= Is this compatible with caching plugins? =

The plugin emits CSP headers via the `send_headers` WordPress hook. Most caching plugins (WP Super Cache, W3 Total Cache, WP Rocket) cache headers alongside page output. Because the CSP nonce is generated fresh per request, **pages that rely on nonces must not be served from a full-page cache without nonce exclusion rules**. The plugin does not automatically configure your caching plugin's page exclusion list.

= I switched to Enforce mode and my site broke. What do I do? =

Click **Toggle Mode** again on the Profiles tab to return to Report-Only. Then review the **Violations** tab to identify the blocked resources, approve the required sources, and try Enforce mode again.

= How do I report a security vulnerability? =

Do not open a public GitHub issue. See SECURITY.md in the repository, or email security@wp-csp-automation.dev.

== Screenshots ==

1. Dashboard — Profiles tab showing per-surface mode badges and Toggle Mode buttons
2. Dashboard — Source Inventory tab with pending and approved sources, per-row approve/deny actions
3. Dashboard — Violations tab showing occurrence-deduplicated violation reports
4. Dashboard — Scan Log tab with duration and policy-change indicator
5. Settings page — Stripe configuration, DNS config section, and cron schedule
6. Premium page — Product tier cards and post-checkout entitlement status

== Changelog ==

= 0.2.0 =
* Initial public release.
* Per-surface CSP profiles (`frontend`, `admin`, `login`, `api`) with strict `'none'` defaults for all 18 directives.
* Per-request nonce injection via WP 6.4+ `wp_script_attributes` / `wp_inline_script_attributes` hooks with legacy fallback.
* SHA-256 inline block hashing with stale-hash retirement on rescan.
* URL crawl-based source discovery using `DOMDocument`; classifies external URLs into CSP directive buckets.
* `Content-Security-Policy` and `Content-Security-Policy-Report-Only` header emission via `send_headers`.
* Promotion gate: enforce mode blocked until at least one source or hash is approved per surface.
* CSP violation reporting REST endpoint: handles CSP Level 3 and Reporting API formats; rate-limited and deduplicated.
* Stripe Checkout integration (`mode=payment`): session creation, HMAC-SHA256 webhook signature verification, idempotent fulfillment.
* Per-site entitlement store bound to SHA-256 hash of site URL; configurable grace period (default 72 h).
* Feature gate with hardcoded free-tier capabilities.
* DNS TXT record-based remote configuration: `v=1;cfg=https://…` format; Ed25519 signature verification.
* Transient cache with configurable TTL and grace window for remote config.
* Daily WP Cron rescan with email notification on policy change.
* Conflict detection for competing CSP headers from other plugins or server configuration.
* Admin UI: Dashboard (profiles, sources, violations, scan log), Settings, and Premium pages.
* Full uninstall routine: drops all 7 custom tables and all `wp_csp_*` options.

== Upgrade Notice ==

= 0.2.0 =
Initial release. No upgrade path from a prior version exists.
