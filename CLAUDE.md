# Skiff Computers — WordPress Website

**Organization:** Skiff Computers (SKIFF Technologies)
**Website:** skiffcomputers.com
**Language:** English

---

## Project Overview

WooCommerce storefront for Skiff Computers, a computer/electronics retailer in Chattogram, Bangladesh.
Built on the premium **Woodmart** theme via a custom child theme (`woodmart-child`). Product catalog,
cart, and checkout run on WooCommerce; layouts use Elementor and Woodmart's own header/shop builder.
Inventory and orders are kept in sync with the in-store POS system through a custom in-house plugin,
`skifftech-pos-sync-for-woocommerce`.

---

## Stack

| Layer              | Tool                                                        |
| ------------------ | ------------------------------------------------------------ |
| CMS                | WordPress                                                     |
| E-commerce         | WooCommerce                                                   |
| Theme              | Woodmart (premium, purchased) + `woodmart-child` (custom)     |
| Page Builder       | Elementor + Woodmart native header/shop builder + Slider Revolution |
| Forms              | Contact Form 7 (+ Contact Form CFDB7 for submission storage)  |
| Email Delivery     | WP Mail SMTP                                                  |
| Newsletter         | Mailchimp for WP                                              |
| SEO                | Yoast SEO (`wordpress-seo`)                                   |
| Caching            | WP Super Cache                                                |
| Security / Audit   | WP Security Audit Log, Akismet                                |
| Backups / Migration| All-in-One WP Migration (+ Pro)                               |
| DB Maintenance     | Advanced Database Cleaner                                     |
| SVG Uploads        | Safe SVG                                                      |
| Custom Plugin      | `skifftech-pos-sync-for-woocommerce` (POS ↔ WooCommerce sync) |
| CSS Preprocessor   | SCSS, compiled manually to `style.css` (no build tool configured) |
| Local Dev          | Laragon                                                       |

---

## Project Structure

```
wp-content/themes/woodmart-child/
├── style.css              # Compiled CSS — do not edit directly, edit SCSS sources
├── style.css.map
├── functions.php           # Enqueues + WooCommerce hook customizations
├── quick-view.php          # Overrides Woodmart's quick-view template
├── assets/sass/            # SCSS source files
├── js/                     # JavaScript source files
├── inc/                    # Custom includes (auto-loaded, see inc/_include.php)
│   └── stock-update-csv.php  # Admin tool: bulk update product stock/price from CSV
└── woocommerce/            # Template overrides (copied from WooCommerce core/Woodmart)

wp-content/plugins/skifftech-pos-sync-for-woocommerce/
└── ...                     # Custom plugin (tracked in git) — see its own readme.md
```

---

## Build Commands

There is no automated build pipeline (no `package.json`/gulpfile in the child theme). SCSS is compiled
manually and the compiled output (`style.css`, `style.css.map`) is committed directly. Common ways to compile:

| Approach                         | Command / Tool                                          |
| --------------------------------- | -------------------------------------------------------- |
| Dart Sass CLI                    | `sass assets/sass/style.scss style.css --style=compressed` |
| VS Code "Live Sass Compiler" ext | Watches `assets/sass/` and writes `style.css` on save    |

Run from `wp-content/themes/woodmart-child/`.

For the POS-sync plugin, install PHP dependencies with:
```
cd wp-content/plugins/skifftech-pos-sync-for-woocommerce
composer install --no-dev
```

---

## Key Conventions

- Edit SCSS in `assets/sass/` — never edit `style.css` directly
- WooCommerce template overrides live in `woodmart-child/woocommerce/` — mirror the exact WooCommerce/Woodmart
  path when adding a new override so WordPress's template hierarchy picks it up
- Stock/price CSV imports (`skiff-product-stock*.csv`) are runtime business data — not committed, see `.gitignore`
- Do NOT edit WordPress core files, the Woodmart parent theme, or any third-party plugin files
- POS-sync plugin changes are documented in its own `readme.md` and `API-INTEGRATION.md` — check those first

### WooCommerce Hooks in Use (child theme)

```php
// Adds a "KEY FEATURES" heading before the product short description
add_filter( 'woocommerce_short_description', 'woo_add_text_before_excerpt_single_product', 20, 1 );
```

Several POS inventory-check hooks (`woocommerce_add_to_cart_validation`, AJAX handlers) exist in
`functions.php` but are currently **commented out / disabled** — real-time POS stock is instead kept in
sync via the `skifftech-pos-sync-for-woocommerce` plugin's REST API rather than a live per-request check.

---

## Pages

| Page | Template | Status |
| ---- | -------- | ------ |

_(Add pages here as they are built)_

---

## Design System

See [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md) — color tokens are extracted from
`assets/sass/_variable.scss` and the compiled header styles in `style.css`.

---

## Out of Scope

- No direct edits to WordPress core files
- No edits to the Woodmart parent theme or any third-party plugin files
- No inline styles — use SCSS in `assets/sass/`
