=== VCNS - CSP Manager ===
Contributors: sjackson0109
Tags: content-security-policy, csp, security, http-headers, hardening
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated Content Security Policy (CSP) management for WordPress — source discovery, hash inventory, violation reporting, and enforce/report-only switching.

== Description ==

VCNS CSP Manager automates the full lifecycle of a Content Security Policy for WordPress sites.

Most CSP guides tell you to write the header by hand. That's impractical for sites with plugins, page builders, and CDN assets. This plugin discovers what your site actually loads, builds the right header automatically, and lets you promote to enforce mode when you're confident.

**Free features — included, no account required:**

* Report-only CSP on all four surfaces (frontend, admin, login, REST API)
* Automatic source discovery via page crawl — the plugin visits your pages and records every external script, style, image, and font source
* Inline script and style hash inventory — `sha256-` hashes generated automatically so inline code doesn't need `unsafe-inline`
* Violation reporting endpoint — accepts browser reports in both CSP Level 2 (application/csp-report) and Reporting API (application/reports+json) formats; stored in your own database
* Manual and scheduled rescans (daily via WP-Cron)
* Source approval workflow — approve or deny each discovered source before it enters your policy
* Promotion gates — enforce mode requires approved sources and a configurable violation-free window, so you can't accidentally lock yourself out
* Audit log — append-only, immutable log of every policy change and admin action
* Dashboard — tabbed view of policy profiles, source inventory, violations, and scan history

**Pro features — VCNS hosted service (optional add-on):**

* `strict-dynamic` in script-src — suppresses host allowlists when nonce-based scripts are in use (CSP Level 3 §8.2)
* Trusted Types directives (`require-trusted-types-for`, `trusted-types`) — deployed report-only first
* Multi-surface scanning — crawl admin, login, and API surfaces in addition to the frontend (frontend scanning is always free)
* Priority email support

Pro features are delivered as part of the VCNS hosted licensing service. No premium code is included in this plugin. Purchasing is entirely optional and the free tier is fully functional for most sites.

== External Services ==

This plugin does **not** contact any external server in its free configuration. All violation reports are stored locally in your WordPress database.

Premium users who choose to purchase (via the **CSP Manager → Premium** page) consent to the following external connections:

1. **Entitlement verification** — A one-way SHA-256 hash of your site URL is sent to `wp-csp-config.jacksonfamily.me` (a Cloudflare Worker operated by VCNS Tech Ltd) to confirm your licence status. No personally identifiable information is transmitted; the hash cannot be reversed to discover your URL.

2. **Remote product configuration** — The plugin fetches a cryptographically signed JSON document via a DNS TXT record (served by VCNS Tech Ltd infrastructure). This document contains feature-tier definitions and pricing metadata only — no personal data is collected or sent.

3. **Stripe payment processing** — Purchases redirect you to a Stripe-hosted checkout page. Your payment details never touch this WordPress installation; all Stripe secret keys are stored as Cloudflare Worker secrets. Stripe's privacy policy: https://stripe.com/privacy

All external connections use HTTPS with SSL certificate verification enforced. VCNS Tech Ltd privacy policy: https://github.com/vcns/wp-csp-automation/blob/main/COMMERCIAL_TERMS.md

== Installation ==

1. Install via **Plugins → Add New Plugin → Upload Plugin** in your WordPress admin, or search for "VCNS CSP Manager".
2. Activate the plugin.
3. Go to **CSP Manager → Dashboard** in the admin menu.
4. Click **Run Manual Scan** to discover sources for your frontend.
5. On the **Sources** tab, approve the discovered sources.
6. Once your violation reports are clear (visible on the **Violations** tab), use the profile toggle to switch from report-only to enforce mode.

== Frequently Asked Questions ==

= What is a Content Security Policy? =

A Content Security Policy (CSP) is an HTTP response header that tells browsers which sources of scripts, styles, images, and other resources are permitted for your site. It is one of the most effective defences against cross-site scripting (XSS) attacks. See: https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP

= Does this plugin work without purchasing Pro? =

Yes. The free tier includes report-only mode, source discovery, violation reporting, hash inventory, and the full admin dashboard. Purchasing is optional.

= Will switching to enforce mode break my site? =

The plugin is designed for a careful rollout. Start in report-only mode, run a scan, review violation reports, approve sources, and only promote to enforce once the violation window has cleared. The plugin will not let you enable enforce mode until at least one source or hash is approved and the violation-free window (default: 24 hours) has elapsed.

= Does the plugin send violation data externally? =

No. Violation reports from browsers are stored in your WordPress database only. They are rate-limited to 500 per hour per surface to prevent DB flooding.

= Is any data sent externally on the free tier? =

No. The free tier makes no external HTTP requests.

= What happens if my Cloudflare Worker or the VCNS service goes down? =

The plugin serves a stale cached entitlement and config for up to 72 hours (configurable) before degrading to the free tier. Your CSP header continues to be emitted throughout; only premium features are affected.

= Is this compatible with caching plugins? =

Yes for the CSP header itself — it is emitted via WordPress's `send_headers` hook before any caching layer. The violation reporting endpoint (`/wp-json/csp-manager/v1/report`) must not be cached; most caching plugins exclude REST API routes by default.

= Can I self-host the config server? =

Advanced users can override `WP_CSP_CONFIG_DNS_RECORD` and `WP_CSP_WORKER_URL` in `wp-config.php` to point to their own infrastructure. See the GitHub repository for details.

== Screenshots ==

1. Dashboard — per-surface policy profiles showing mode (report-only / enforce / disabled) with toggle controls.
2. Source inventory — discovered external sources listed with domain, directive, approval state, and last-seen timestamp.
3. Violations tab — recent CSP violation reports with blocked URI, violated directive, and occurrence count.
4. Settings page — remote config DNS record, scan schedule, notification email, and entitlement grace period.

== Changelog ==

= 0.2.0 =
* CSP Level 3 header alignment: all 18 standard directives emitted; Reporting-Endpoints header (RFC 9651) added alongside deprecated Report-To JSON fallback.
* Violation reporter hardened: Content-Type validation, cross-origin document-URI guard, non-atomic rate-limit replaced with transient-based soft cap.
* Immutable audit log: append-only `csp_audit_log` table with severity levels.
* Feature gate: premium features (strict_dynamic, trusted_types, multi_surface_scan) gated behind VCNS hosted entitlement.
* Promotion gates: enforce mode now requires approved sources/hashes and a configurable violation-free window.
* Dashboard refactor: tabbed UI (Profiles, Sources, Violations, Scan Log) with paginated source inventory.
* PHPCBF line-ending normalisation; `.gitattributes` added.
* Dev dependencies reduced: phpcompatibility packages removed; wrangler moved to npx invocation.

= 0.1.0 =
* Initial release: basic CSP header generation, source discovery, and violation endpoint.

== Upgrade Notice ==

= 0.2.0 =
Database schema updated to version 4. The plugin runs migrations automatically on activation — no manual steps needed.
