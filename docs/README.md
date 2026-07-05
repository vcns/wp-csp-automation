# Internal Documentation

This directory contains the internal operating and engineering documentation for WP CSP Automation Manager.

## Audience

These documents are written for:

- maintainers of the plugin codebase
- release managers
- security reviewers
- operators managing Stripe and remote config infrastructure

## Document map

- `architecture.md` — high-level system design, runtime flow, and responsibility boundaries
- `database-schema.md` — custom table definitions, relationships, policy decision ledger, and operational notes
- `remote-config-and-signing.md` — DNS-discovered remote config contract, signing process, and key handling
- `stripe-operations.md` — Stripe product, checkout, webhook, and entitlement operations
- `testing-and-quality.md` — expected validation workflow, CI scope, and manual verification checklist
- `release-and-publishing.md` — versioning, branching, packaging, and WordPress.org publishing flow
- `wordpress-org-assets.md` — listing artwork, screenshot requirements, and WordPress.org asset handling

## Source of truth

The following files remain authoritative alongside these docs:

- `wp-csp-automation.php` for plugin metadata and version constants
- `requirements_spec.md` for the current functional requirements baseline
- `readme.txt` for public-facing WordPress.org plugin directory content
- `CHANGELOG.md` for release history
- `SECURITY.md` for vulnerability reporting policy
- `docs/index.html`, `docs/styles.css`, and `docs/404.html` for the public GitHub Pages help site content
- `.github/workflows/*` for the enforced CI, PR policy, packaging, and deployment automation

## Maintenance rule

Whenever the plugin changes in a way that affects runtime behaviour, infrastructure setup, release flow, or operational risk, update the corresponding document in this directory as part of the same change.
