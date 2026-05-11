# London Arbitration Week — WordPress theme

A like-for-like migration of the ExpressionEngine site at londonarbitrationweek.co.uk into a WordPress theme. Uses the original Foundation CSS, Typekit fonts (Poppins), markup structure, and image assets from the wget mirror.

## Setup

### 1. Install the theme

Upload the zip via Appearance → Themes → Add New → Upload Theme, then activate.

### 2. Create pages and assign templates

| Page title                          | Slug                                | Template                             |
|-------------------------------------|-------------------------------------|--------------------------------------|
| Home                                | `home`                              | Homepage                             |
| Sponsors & Supporting Organisations | `sponsors-supporting-organisations` | Sponsors & supporting organisations  |
| Our Patrons & Committee             | `patrons-and-committee`             | Patrons & committee                  |
| Contact & FAQs                      | `contact`                           | Contact & FAQs                       |
| Privacy Policy                      | `privacy-policy`                    | Privacy policy                       |

Then: Settings → Reading → set "A static page" with **Home** as the front page.

### 3. Permalinks

Settings → Permalinks → select "Post name" (or any non-default structure).

### 4. Privacy policy

The privacy policy page is a placeholder — paste the content from the existing site.

## What's included

All original assets from the wget mirror are bundled in the theme:

- `assets/css/` — Foundation 6, animate.css, and the original app.css
- `assets/js/` — Foundation, jQuery, WOW.js, matchHeight, and the original app.js
- `assets/images/` — Hero backgrounds, logos, SVGs, sponsorship PDFs
- `images/uploads/sponsors/_webp/` — All sponsor logos as webp files
- `images/uploads/profile-photos/_webp/` — Patron headshots as webp files
- `favicon_io/` — Favicons

Fonts are loaded from Adobe Typekit (Poppins) via the same kit IDs as the original site.

## Theme structure

```
law-theme/
├── assets/              ← Original CSS, JS, images from the live site
│   ├── css/
│   │   ├── foundation.css
│   │   ├── animate.min.css
│   │   └── app.css
│   ├── images/
│   │   ├── hero-bg.jpg
│   │   ├── banner-1.jpg
│   │   ├── scroll-bg.jpg
│   │   ├── patrons-and-committee-bg.jpg
│   │   ├── GettyImages-1308154764.jpg
│   │   ├── LAW-bottom-logo.svg
│   │   ├── linkedin-square.svg
│   │   ├── sponsorship-icon.svg
│   │   ├── LAW2026_Sponsorship_Brochure.pdf
│   │   └── LAW_SponsorshipPackages_2025.pdf
│   └── js/
│       ├── app.js
│       └── vendor/
│           ├── foundation.js
│           ├── jquery.js
│           ├── what-input.js
│           └── wow.min.js
├── favicon_io/
├── images/
│   └── uploads/
│       ├── profile-photos/_webp/   ← Patron headshots
│       └── sponsors/_webp/         ← Sponsor logos
├── parts/
│   ├── sponsors-grid.php           ← Shared sponsors grid (homepage + sponsors page)
│   └── supporting-orgs.php         ← Media partners + supporting orgs (sponsors page only)
├── footer.php
├── front-page.php                  ← Homepage
├── functions.php
├── header.php
├── index.php                       ← Generic fallback
├── page-contact.php                ← Contact & FAQs (Foundation accordion)
├── page-patrons.php                ← Patrons & Committee
├── page-privacy.php                ← Privacy policy (placeholder)
├── page-sponsors.php               ← Sponsors & Supporting Organisations
├── README.md
└── style.css                       ← WP theme declaration + admin bar fix only
```

## Notes

- The footer credit has been changed from "Site by Sears–Davies" to "Site by Square Eye".
- All content is hard-coded in the templates. To make it dynamic, the obvious next steps are:
  - Sponsors CPT + tier taxonomy
  - Patrons CPT + committee CPT
  - ACF repeater for FAQs
  - `wp_nav_menu()` for header/footer navigation
- The page templates use WordPress's `Template Name` convention — when you create each page, pick the right template from the Page Attributes panel.
- The `front-page.php` file is automatically used for the static front page — no template assignment needed there.
