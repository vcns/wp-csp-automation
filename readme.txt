=== CSP Automation Manager ===
Contributors: vcns
Tags: security, csp, content security policy, headers, wordpress security
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automates strict Content Security Policy rollout, violation reporting, source discovery, and policy-change review for WordPress.

== Description ==

CSP Automation Manager helps site owners roll out strict Content Security Policy headers safely and incrementally.

The plugin provides per-surface CSP profiles, nonce injection, source discovery, violation reporting, policy-change review, append-only audit records, policy history, and administrator-controlled rollout tools.

== External services ==

This WordPress.org build does not contact third-party services for plugin updates, licensing, checkout, telemetry, or remote product configuration.

The plugin emits CSP reporting headers that point browsers back to this WordPress site's own REST endpoint:

* `/wp-json/csp-manager/v1/report`

Purpose:
* receive browser-generated CSP violation reports for this site;
* store reports locally so administrators can review and refine policy safely.

Data handled:
* browser CSP violation report fields such as blocked URL, document URL, violated directive, referrer, user agent, line/column where provided, and an optional script sample where the active policy requests `report-sample`.

Reports are validated and stored in this site's WordPress database. They are not sent to any external provider by this plugin.

== Changelog ==

= 1.0.4 =

* Removes the custom runtime update checker and all third-party update manifest polling from the WordPress.org plugin package.
* Removes legacy external-service admin surfaces from the WordPress.org plugin package.
* Makes all shipped CSP capabilities available locally without payment, remote entitlement checks, or trialware-style feature locking.
* Updates package copy and disclosures for WordPress.org guideline alignment.

= 1.0.3 =

* Renames the public plugin display name to `CSP Automation Manager` to comply with WordPress.org plugin naming requirements.

= 1.0.2 =

* Tightens the release package so development-only files, internal policy notes, and local cache files are excluded from distributed ZIP builds.
* Adds release workflow checks that fail if submission-only or development-only files are present in the packaged ZIP.

= 1.0.1 =
* Adds Reporting API headers, forbidden-directive filtering, violation sample persistence, audit logging, policy-change proposals, decision suppression, revert behaviour, violation rollups, policy history, and review APIs.

= 0.2.0 =
* Initial public plugin implementation.
