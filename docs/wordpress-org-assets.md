# WordPress.org Assets

## Purpose

This document defines the non-code assets and metadata needed for the WordPress.org plugin listing.

## Asset location model

WordPress.org listing artwork is stored in the SVN `assets` directory, not in the plugin runtime directory shipped to sites.

For local repository management, keep source or guidance files under `.wordpress-org/assets/` and publish the final generated files through the SVN deploy workflow or manual SVN operations.

## Expected WordPress.org asset filenames

Common filenames used by the WordPress.org directory:

- `icon-128x128.png`
- `icon-256x256.png`
- `banner-772x250.png`
- `banner-1544x500.png`
- `screenshot-1.png`
- `screenshot-2.png`
- `screenshot-3.png`
- `screenshot-4.png`
- `screenshot-5.png`
- `screenshot-6.png`

Use PNG unless there is a reason to choose another supported format.

## Recommended asset set for this plugin

Minimum viable listing assets:

- one square plugin icon in 128 and 256 variants
- one banner in standard and high-resolution variants
- six screenshots matching the screenshot list in `readme.txt`

## Content guidance

### Icon

Aim for:

- security-oriented branding
- high contrast at small sizes
- simple geometry, not dense text
- recognisable silhouette in the WordPress admin plugin list

Avoid:

- tiny unreadable words
- screenshot crops as icons
- excessive detail or gradients that muddy at 128 px

### Banner

Banner should communicate:

- CSP automation for WordPress
- security and control rather than generic marketing polish
- the plugin name in a readable way

Keep the composition safe for WordPress.org cropping behaviour.

### Screenshots

Suggested screenshot sequence:

1. Dashboard profiles tab
2. Source inventory approval workflow
3. Violation report table
4. Scan log
5. Settings page
6. Premium entitlement page

Each screenshot should represent the current UI accurately. Update screenshots when the admin UI changes materially.

## Branding constraints

Because the plugin has a paid upgrade path:

- avoid aggressive sales copy in the banner
- keep the free plugin's functional value visible
- do not make screenshots look like a locked paywall product
- keep claims accurate and supportable

## Repository handling

Recommended structure:

- `.wordpress-org/assets/` for source or export-ready listing assets
- `readme.txt` for screenshot captions and public plugin description
- `docs/release-and-publishing.md` for release flow

## Pre-publish asset checklist

Before first directory submission or a major visual refresh:

- confirm icon files export cleanly at 128 and 256 sizes
- confirm banner files meet expected dimensions
- confirm screenshot numbering matches `readme.txt`
- confirm no secrets, customer data, or live domains appear in screenshots
- confirm screenshots reflect the current plugin UI
- confirm assets comply with WordPress.org plugin guidelines
