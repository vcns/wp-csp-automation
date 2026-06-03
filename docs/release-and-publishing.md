# Release and Publishing

## Purpose

This document describes the internal release path from GitHub development work to a shipped WordPress.org plugin release.

## Branching model

Current intended flow:

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

The plugin uses semantic versioning for public releases.

Update all release-facing version locations together:

- plugin header `Version` in `wp-csp-automation.php`
- `WP_CSP_VERSION` constant
- `readme.txt` stable tag
- `CHANGELOG.md`

If schema changes are included, also update:

- `WP_CSP_DB_VERSION`
- upgrade logic and schema docs

## Release workflow

### 1. Stabilise in development

- merge approved feature and fix PRs into `development`
- confirm CI is green
- confirm docs are up to date

### 2. Cut release branch

Example:

- `release/0.2.1`

On the release branch:

- bump versions
- finalise changelog
- update any release-facing readme details
- fix only release blockers

### 3. Validate release branch

Run or confirm:

- full CI pipeline
- manual smoke test on a clean WordPress install
- test-mode premium flow verification
- uninstall cleanup verification

### 4. Merge to main

- open PR from `release/*` to `main`
- require review and passing checks
- merge using the repository's protected-branch policy

### 5. Tag and publish

- create annotated tag, for example `v0.2.1`
- build the release artifact from the tag on `main`
- deploy to WordPress.org SVN from the tagged state

### 6. Back-merge

- merge `main` or the release branch back into `development`
- confirm branches are aligned before new feature work continues

## Packaging rules

The WordPress.org release zip should contain only the plugin directory contents required for runtime and compliance.

Include:

- plugin PHP files
- assets used by the plugin runtime
- `readme.txt`
- `LICENSE`
- translation files when added

Do not include:

- `.git`
- GitHub workflow files in the deploy artifact if your deploy process filters them out
- local test fixtures not needed at runtime
- development environment files
- secrets or example secrets

## WordPress.org repository model

WordPress.org plugin distribution uses SVN, even if GitHub is the development source of truth.

Typical mapping:

- GitHub `main` tag -> WordPress.org `tags/<version>`
- current stable release contents -> WordPress.org `trunk`
- assets such as banners and icons -> WordPress.org `assets` directory in SVN

## Suggested deployment automation

Use GitHub Actions or another CI runner to:

1. trigger on version tags from `main`
2. build a clean plugin artifact
3. check the artifact contents
4. commit the release to the WordPress.org SVN repo
5. tag the release in SVN

Repository workflow files now provide the baseline automation:

- `.github/workflows/ci.yml` for PHP lint, PHPCS, Semgrep, secret scanning, and package creation
- `.github/workflows/pr-branch-policy.yml` for source-branch enforcement on PRs into `development` and `main`
- `.github/workflows/codeql.yml` for GitHub-native static analysis
- `.github/workflows/dast.yml` for disposable-environment baseline DAST
- `.github/workflows/release-package.yml` for release-candidate zip validation
- `.github/workflows/wporg-deploy.yml` for tag-driven deployment to WordPress.org SVN

Required GitHub repository secrets for WordPress.org deployment:

- `SVN_USERNAME`
- `SVN_PASSWORD`

## WordPress.org review readiness

Before first submission, verify:

- GPL-compatible licensing is clear
- `readme.txt` is valid and accurate
- no remote code execution or code download features exist
- external service usage is disclosed clearly in `readme.txt`
- premium upsell remains compliant with WordPress.org guidelines
- no obfuscated code is present
- no tracking without user consent is present

## Premium plugin directory caution

Because this plugin includes a paid upgrade path, review WordPress.org plugin guidelines carefully, especially around:

- upsell behaviour in wp-admin
- off-site service disclosures
- functionality split between free and paid versions
- branding and promotional copy

The free plugin should remain functional and useful on its own. Avoid making the WordPress.org listing feel like a thin paywall wrapper.

## Release checklist

Before publishing each version:

- confirm version numbers are aligned
- confirm branch protections and CI are active
- confirm remote config public key is correct for that release
- confirm no development keys or test endpoints remain in code or docs
- confirm `readme.txt` sections reflect actual shipped behaviour
- confirm `.wordpress-org/assets/` artwork is current and matches the listing
- confirm repository secrets `SVN_USERNAME` and `SVN_PASSWORD` exist before tagging
- package from a clean checkout or CI workspace
- test installation from the packaged zip

## Rollback planning

If a bad release ships:

- identify whether the issue is documentation, runtime, or migration related
- prepare a fixed patch release immediately if practical
- if WordPress.org deployment must be rolled back, restore the prior stable tag and trunk contents in SVN
- document the incident in changelog notes or internal ops records as appropriate
