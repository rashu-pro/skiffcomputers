# Designs

Static HTML design mockups / prototypes for skiffcomputers.com pages and sections go here — one
self-contained `.html` file per mockup, following the convention used in the `skifftech.com` project's
`designs/` folder (e.g. `skiff-homepage-v6.html`, `skiff-blog.html`).

This folder is currently empty: skiffcomputers.com was built directly on the purchased Woodmart theme
without a separate static-mockup design phase, so there's nothing to carry over from an existing design
file. Drop new mockups here as they're produced (e.g. a redesigned homepage, a new landing page, a
seasonal promo layout) before they get built into `wp-content/themes/woodmart-child/`.

## Conventions (matching skifftech.com)

- Name files `skiff-<page-or-section>.html` (e.g. `skiff-homepage.html`, `skiff-laptop-category.html`).
- Keep each mockup self-contained (inline or locally-referenced CSS/JS) so it can be opened directly in
  a browser without a build step.
- Once a mockup is implemented in the theme, extract its color/typography/component values into
  [`../docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md) so that file stays the single source of truth
  for what's actually live on the site.
