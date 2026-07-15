=== WP CSP Automation Manager ===
Contributors: vcns
Tags: security, csp, content security policy, headers, wordpress security
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automates strict Content Security Policy rollout, violation reporting, source discovery, and policy-change review for WordPress.

== Description ==

WP CSP Automation Manager helps site owners roll out strict Content Security Policy headers safely and incrementally.

The plugin provides per-surface CSP profiles, nonce injection, source discovery, violation reporting, policy-change review, append-only audit records, and entitlement-gated premium capabilities.

== External services ==

This plugin can contact external services for optional update, configuration, licensing, and checkout functionality. Core CSP header generation runs locally and does not require a remote service during normal front-end page rendering.

= VCNS configuration and licensing services =

The plugin may contact VCNS services operated by VCNS Tech Ltd.

Service endpoints:
* `config.wp-csp.vcns.tech`
* `https://licensing.wp-csp.vcns.tech`

Purpose:
* discover signed non-secret product configuration;
* validate locally cached premium entitlements;
* start optional premium checkout or account-management flows.

Data sent:
* site URL or site-derived identifier;
* plugin version and product key;
* entitlement or checkout identifiers where premium functionality is used.

No Stripe API secret keys are stored in this WordPress installation. Stripe payment processing, where used, is handled outside WordPress by VCNS licensing services.

Service provider:
* VCNS Tech Ltd, https://vcns.tech/

= GitHub update metadata =

For GitHub-distributed builds, the plugin may request a public update manifest from:

* `https://vcns.github.io/wp-updates/wp-csp-automation/wp-csp-automation.json`

Purpose:
* map a VCNS-hosted update manifest into WordPress' native plugin update UI for builds installed outside WordPress.org.

Data sent:
* normal HTTPS request metadata such as IP address, user agent, and requested URL.

Service provider:
* GitHub, Inc., https://github.com/
* GitHub Terms of Service: https://docs.github.com/site-policy/github-terms/github-terms-of-service
* GitHub Privacy Statement: https://docs.github.com/site-policy/privacy-policies/github-privacy-statement

== Changelog ==

= 1.0.2 =

* Tightens the release package so development-only files, internal policy notes, and local cache files are excluded from distributed ZIP builds.
* Moves default configuration and licensing endpoints to VCNS-owned service hostnames while preserving `wp-config.php` overrides.
* Adds explicit external-services disclosure for VCNS configuration, licensing, checkout, and GitHub update metadata requests.
* Adds release workflow checks that fail if submission-only or development-only files are present in the packaged ZIP.

= 1.0.1 =
* Adds Reporting API headers, forbidden-directive filtering, violation sample persistence, audit logging, policy-change proposals, decision suppression, revert behaviour, violation rollups, and self-hosted update checking.

= 0.2.0 =
* Initial public plugin implementation.
