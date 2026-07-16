# Documentation

This directory contains the public help site and internal operating documentation for CSP Automation Manager.

## Public Help Site

The following files are published through GitHub Pages and are intended for WordPress administrators, site owners, and support staff:

- `index.html` - public landing page, product explanation, learning curve, rollout model, and help index
- `user-guide.html` - end-user guide covering installation, configuration, scanning, approvals, enforcement, and troubleshooting
- `faq.html` - practical FAQ covering rollout, approvals, violations, compatibility, privacy, and incident recovery
- `styles.css` - shared styles for the public help pages
- `404.html` - GitHub Pages not-found page

## Internal Documentation

These documents are written for maintainers, release managers, security reviewers, and operators managing release infrastructure:

- `architecture.md` - high-level system design, runtime flow, and responsibility boundaries
- `database-schema.md` - custom table definitions, relationships, policy decision ledger, and operational notes
- `testing-and-quality.md` - expected validation workflow, CI scope, and manual verification checklist
- `release-and-publishing.md` - versioning, branching, packaging, and WordPress.org publishing flow
- `wordpress-org-assets.md` - listing artwork, screenshot requirements, and WordPress.org asset handling

## Source Of Truth

The following files remain authoritative alongside these docs:

- `wp-csp-automation.php` for plugin metadata, constants, autoloading, and version values
- `requirements_spec.md` for the current functional requirements baseline
- `README.md` for repository-level installation and feature summary
- `CHANGELOG.md` for release history
- `SECURITY.md` for vulnerability reporting policy
- `.github/workflows/*` for CI, PR policy, packaging, release, and deployment automation

## Maintenance Rule

Whenever the plugin changes in a way that affects runtime behaviour, user workflow, configuration, release flow, or operational risk, update the corresponding document in this directory as part of the same change.
