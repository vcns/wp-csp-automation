# Release And Publishing

## Purpose

This document describes the internal release path from GitHub development work to a shipped WordPress.org plugin release.

## Branching Model

- `feature/*` and `fix/*` branches target `development`
- `development` is the integration branch
- `release/*` branches are cut from `development` when stabilising a release
- `main` is the production and publishing branch

Operational rules:

- no direct commits to `development`
- no direct commits to `main`
- merge to protected branches by PR only
- production release artifacts come from `main` tags only

## Versioning

Update all release-facing version locations together:

- plugin header `Version` in `wp-csp-automation.php`
- `WP_CSP_VERSION` constant
- `readme.txt` stable tag
- `CHANGELOG.md`

If schema changes are included, also update:

- `WP_CSP_DB_VERSION`
- upgrade logic and schema docs

## Release Workflow

1. Merge approved feature and fix PRs into `development`.
2. Confirm CI is green and documentation is up to date.
3. Cut a `release/*` branch.
4. Bump versions, finalise changelog, and fix only release blockers.
5. Open a PR from `release/*` to `main`.
6. Merge using the repository's protected-branch policy.
7. Tag the merged `main` state, for example `v1.0.4`.
8. Let the tag-driven workflows build the GitHub Release ZIP and deploy to WordPress.org SVN.
9. Back-merge `main` or the release branch into `development`.

## Packaging Rules

The WordPress.org release ZIP should contain only the plugin directory contents required for runtime and compliance.

Include:

- plugin PHP files
- assets used by the plugin runtime
- `readme.txt`
- `LICENSE`
- translation files when added

Do not include:

- `.git`
- GitHub workflow files
- local test fixtures not needed at runtime
- development environment files
- secrets or example secrets
- internal docs and policy notes
- tools, vendor dependencies, or CI-only files

## WordPress.org Repository Model

WordPress.org plugin distribution uses SVN, even when GitHub is the development source of truth.

Typical mapping:

- GitHub `main` tag -> WordPress.org `tags/<version>`
- current stable release contents -> WordPress.org `trunk`
- banners and icons -> WordPress.org `assets` directory in SVN

## Workflow Files

- `.github/workflows/ci.yml` for PHP lint, PHPCS, Semgrep, secret scanning, and package creation
- `.github/workflows/pr-branch-policy.yml` for source-branch enforcement on PRs into `development` and `main`
- `.github/workflows/codeql.yml` for GitHub-native static analysis
- `.github/workflows/dast.yml` for disposable-environment baseline DAST
- `.github/workflows/pages.yml` for publishing the public GitHub Pages help site from `docs/`
- `.github/workflows/release-package.yml` for release-candidate ZIP validation and tag-driven GitHub Release ZIP publishing
- `.github/workflows/wporg-deploy.yml` for tag-driven deployment to WordPress.org SVN

## GitHub Release Artifacts

Tagged releases generate a ready-to-install ZIP asset:

- `wp-csp-automation-vX.Y.Z.zip`

The repository does not publish a custom WordPress update manifest or shared update-feed ZIP. WordPress.org-distributed installs receive update metadata from WordPress.org after SVN deployment.

Pull request and manual workflow runs produce ZIP artifacts for validation only.

## Public Docs Site

The repository includes a public GitHub Pages site in `docs/` for:

- install and rollout guidance
- operational help for CSP setup
- release and publishing references
- support and security policy signposting

Published URL:

- `https://vcns.github.io/wp-csp-automation/`

## WordPress.org Review Readiness

Before submission, verify:

- GPL-compatible licensing is clear
- `readme.txt` is valid and accurate
- the plugin display name complies with trademark restrictions
- no remote code execution or code download features exist
- no custom update process exists in the submitted package
- external service usage is disclosed clearly in `readme.txt`
- shipped functionality is not trialware and does not depend on remote licensing
- no obfuscated code is present
- no tracking without user consent is present

## Release Checklist

Before publishing each version:

- confirm version numbers are aligned
- confirm README.md, readme.txt, SECURITY.md, and docs/architecture.md are mutually consistent
- confirm branch protections and CI are active
- confirm `.github/workflows/release-package.yml` produced a release ZIP artifact from the release branch or tag
- confirm no development keys or test endpoints remain in code or docs
- confirm `readme.txt` sections reflect actual shipped behaviour
- confirm `.wordpress-org/assets/` artwork is current and matches the listing
- confirm repository secrets `SVN_USERNAME` and `SVN_PASSWORD` exist before tagging
- package from a clean checkout or CI workspace
- test installation from the packaged ZIP

## Rollback Planning

If a bad release ships:

- identify whether the issue is documentation, runtime, or migration related
- prepare a fixed patch release immediately if practical
- if WordPress.org deployment must be rolled back, restore the prior stable tag and trunk contents in SVN
- document the incident in changelog notes or internal ops records as appropriate
