# CSP Automation Manager

CSP Automation Manager is a WordPress plugin that helps site owners roll out strict Content Security Policy headers safely and incrementally.

It provides per-surface CSP profiles, nonce injection, source discovery, violation reporting, policy-change review, and audit-first rollout tools.

## Features

- Per-surface CSP profiles for `frontend`, `admin`, `login`, and `api`
- Strict defaults across CSP directives, including `upgrade-insecure-requests`, `child-src`, `fenced-frame-src`, and the `sandbox` document directive
- `report-sample` support in fetch directives so inline snippets can appear in violation reports
- `Reporting-Endpoints` and legacy `Report-To` headers emitted alongside CSP
- Per-request nonce injection for compatible WordPress script output
- Report-only rollout mode
- CSP violation reporting endpoint with content-type and origin checks
- Automatic purge of old violation reports
- Append-only audit log for significant plugin events
- Source discovery and administrator approval workflow
- Risk-ranked CSP change proposals with approve, reject, and revert decisions
- Revert-and-suppress workflow so a reversed source is not proposed again automatically
- Policy version snapshots, policy diffs, decision provenance, and deterministic rule findings
- Policy Audit admin view and privileged admin REST endpoints for current policy, pending reviews, decisions, history, and manual automation configuration
- Automation configuration scaffold that defaults every surface to `manual`; no proposal is auto-approved on install or upgrade
- Multi-surface scan support
- `strict-dynamic` with automatic host-source suppression
- Trusted Types directives, report-only by default
- Conflict detection for competing CSP headers
- Scheduled rescans with audit logging

All features shipped in the WordPress.org package are available locally without payment, remote entitlement checks, trialware-style feature locking, or third-party licensing calls.

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Installation

### From the WordPress plugin directory

Once published to WordPress.org:

1. Go to **Plugins -> Add New Plugin**.
2. Search for `CSP Automation Manager`.
3. Click **Install**, then **Activate**.

### Install from GitHub Releases

Tagged releases publish a ready-to-install ZIP to the
[Releases page](https://github.com/vcns/wp-csp-automation/releases).

1. Download `wp-csp-automation-vX.Y.Z.zip` from the release assets.
2. In WordPress go to **Plugins -> Add New Plugin -> Upload Plugin**.
3. Choose the downloaded ZIP and click **Install Now**.
4. Activate the plugin.

## Getting Started

1. Install and activate the plugin.
2. Run an initial scan from the CSP Manager dashboard.
3. Review and approve only the external sources your site actually requires.
4. Reject or revert unwanted sources so the same fingerprint is suppressed from future proposals.
5. Use the Policy Audit page to inspect why a proposal exists and what policy version resulted from decisions.
6. Stay in report-only mode until violations are understood.
7. Promote one surface at a time into enforce mode.

## Automation Posture

Automation is currently scaffolded but defaults to `manual` for every surface. The shipped plugin retains source discovery, manual review, deterministic risk classification, decision history, policy history, and rollback-oriented provenance.

Future automation and AI-assisted recommendation work must keep deterministic product rules as the authority. AI output must not directly modify an enforced CSP policy.

## External Services

The WordPress.org plugin package does not contact third-party services for plugin updates, licensing, checkout, telemetry, or remote product configuration.

The plugin emits CSP reporting headers that point browsers back to this WordPress site's own REST endpoint:

- `/wp-json/csp-manager/v1/report`

Browser-submitted CSP violation reports are validated and stored in the local WordPress database. They are not sent to any external provider by this plugin.

## Privacy

The plugin keeps operational data local to WordPress.

- Browser CSP violation reports are stored in the local database.
- Source inventory, hashes, scan logs, policy versions, and decision records are stored locally.
- No telemetry or background tracking is intended as part of the normal plugin runtime.

## Repository Guides

- Architecture: [docs/architecture.md](docs/architecture.md)
- Testing and quality: [docs/testing-and-quality.md](docs/testing-and-quality.md)
- Release and publishing: [docs/release-and-publishing.md](docs/release-and-publishing.md)
- Security policy: [SECURITY.md](SECURITY.md)

## GitHub Pages Help Site

The repository also publishes a public help site from the `docs/` directory:

- https://vcns.github.io/wp-csp-automation/

## Development And Release Flow

- `feature/*` and `fix/*` branches target `development`
- `release/*` branches are cut from `development`
- `main` is the release and publishing branch
- WordPress.org deployment is tag-driven

## Notes For Maintainers

- Keep `README.md` GitHub-friendly and concise.
- Keep `readme.txt` aligned with actual shipped behaviour for WordPress.org.
- Update both when installation, features, external services, or release flow changes.
