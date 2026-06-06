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


== Screenshots ==

1. Dashboard — Profiles tab showing per-surface mode badges and Toggle Mode buttons
2. Dashboard — Source Inventory tab with pending and approved sources, per-row approve/deny actions
3. Dashboard — Violations tab showing occurrence-deduplicated violation reports
4. Dashboard — Scan Log tab with duration and policy-change indicator
5. Settings page — Stripe configuration, DNS config section, and cron schedule
6. Premium page — Product tier cards and post-checkout entitlement status
