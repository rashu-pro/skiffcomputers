# Architecture — Skiff Computers

> High-level overview of the theme file structure, WooCommerce template overrides, custom plugin
> architecture, and how the pieces fit together.
>
> **Keep this file updated** whenever a template override is added, a hook is added/removed in
> `functions.php`, or the POS-sync plugin's API surface changes.

---

## Site Overview

| Property       | Value                                             |
| -------------- | -------------------------------------------------- |
| Domain         | skiffcomputers.com                                  |
| CMS            | WordPress                                           |
| Language       | English                                             |
| E-commerce     | WooCommerce                                         |
| Theme          | Woodmart (premium) + `woodmart-child` (custom)      |
| Page Builder   | Elementor, Woodmart native header/shop builder, Slider Revolution |

---

## Theme File Structure

```
wp-content/themes/woodmart-child/
│
├── style.css                  # Compiled output — do not edit directly, edit assets/sass/ instead
├── style.css.map
├── functions.php               # Enqueues + WooCommerce filter/hook customizations
├── quick-view.php               # Override of Woodmart's AJAX quick-view template
├── screenshot.png               # WP admin theme screenshot
│
├── assets/sass/                 # SCSS source — edit here, compile to style.css
│   ├── style.scss                # Entry point — imports all partials in order
│   ├── _variable.scss            # Design tokens: brand colors
│   ├── _head.scss                 # Header overrides
│   ├── _foot.scss                 # Footer overrides (currently empty)
│   ├── _home.scss                 # Home page overrides (currently empty)
│   ├── _shop.scss                 # Shop/archive page overrides
│   ├── _custom.scss               # Misc overrides
│   ├── _product.scss              # Single product page overrides
│   ├── _woocommerce.scss          # General WooCommerce overrides
│   ├── _single_product.scss       # Additional single-product overrides
│   ├── _cart.scss                 # Cart page overrides
│   ├── _form.scss                 # Form element overrides
│   └── _thankyou.scss             # Order thank-you page overrides
│
├── js/
│   └── pos-inventory.js           # Client-side POS stock check (currently unused — see functions.php)
│
├── inc/                          # Custom includes, auto-loaded via inc/_include.php
│   ├── _include.php               # Loader — globs and requires every *.php under inc/ (skips configs/)
│   ├── stock-update-csv.php       # Admin tool: batch-update product stock/price from a CSV upload
│   ├── stock-update-csv.js        # Admin UI JS for the CSV stock-update tool
│   └── configs/                   # Woodmart header-builder config exports (not auto-loaded)
│       ├── header-builder-structure.php
│       └── header-sceleton.php
│
├── skiff-product-stock.csv          # Runtime data: current stock/price import file (gitignored)
├── skiff-product-stock-original.csv # Runtime data: backup of the above before last import (gitignored)
│
└── woocommerce/                  # Template overrides (path mirrors WooCommerce/Woodmart core)
    ├── archive-product.php
    ├── content-product-base.php
    ├── content-product-button.php
    ├── content-product-standard.php
    ├── content-product-standard-custom.php
    ├── content-single-product.php
    ├── cart/
    │   ├── cart.php
    │   ├── cart-item-data.php
    │   ├── cross-sells.php
    │   └── mini-cart.php
    ├── checkout/
    │   ├── form-checkout.php
    │   └── thankyou.php
    └── single-product/
        ├── add-to-cart/
        │   ├── grouped.php
        │   └── variable.php
        ├── meta.php
        ├── product-image.php
        ├── product-thumbnails.php
        ├── rating.php
        ├── related.php
        ├── sale-flash.php
        ├── tabs/tabs.php
        ├── title.php
        └── up-sells.php
```

---

## Custom Plugin: `skifftech-pos-sync-for-woocommerce`

```
wp-content/plugins/skifftech-pos-sync-for-woocommerce/
├── skifftech-pos-sync-for-woocommerce.php   # Plugin bootstrap
├── uninstall.php
├── composer.json / composer.lock             # Dependencies committed in vendor/ (no CI build step)
├── readme.md                                  # Setup + OAuth2 auth flow
├── API-INTEGRATION.md                         # Full REST API reference
├── includes/
│   ├── Activator.php                          # Creates OAuth tables + RSA/encryption keys on activation
│   ├── Uninstaller.php
│   ├── Keys.php
│   ├── Tables.php
│   ├── Psr7Bridge.php
│   ├── Admin/ClientsPage.php                  # WP Admin → Tools → POS Sync Clients (generate/revoke OAuth clients)
│   ├── OAuth/                                 # league/oauth2-server entities, grants, repositories
│   ├── Outbound/
│   │   ├── OrderSync.php                       # Pushes completed WooCommerce orders to the POS portal
│   │   └── PortalClient.php                    # HTTP client for the POS portal API
│   └── Rest/
│       ├── Authentication.php                  # Bearer-token auth for inbound REST routes
│       ├── ProductController.php               # POS → WooCommerce: create/update products
│       ├── SaleController.php                  # WooCommerce → POS: verify stock, record sales
│       └── TokenController.php                 # OAuth2 token endpoint
└── vendor/                                     # Composer dependencies (intentionally committed)
```

**Sync model:** SKU is the shared key between the POS system and WooCommerce.
- **POS → WooCommerce**: creates products and pushes stock updates via the OAuth2-secured REST API
  (`ProductController`).
- **WooCommerce → POS**: verifies live stock at checkout and pushes completed orders to the POS
  portal as invoices (`OrderSync`, `SaleController`).

Full endpoint reference: `wp-content/plugins/skifftech-pos-sync-for-woocommerce/API-INTEGRATION.md`.

### Alternate/legacy sync path: CSV stock import

`inc/stock-update-csv.php` (child theme) provides a manual, admin-triggered batch import that reads
`skiff-product-stock.csv` and writes stock/price onto matching WooCommerce products by SKU, in batches
of `SKIFF_STOCK_UPDATE_BATCH_SIZE` (100) rows. The CSV's `price` column (purchase/cost price) is
ignored; `sale price` is written to the product's regular price. This exists alongside the plugin's
REST-based sync — check which one is the active source of truth before touching stock data.

---

## CSS Architecture

Styles are authored in SCSS (`assets/sass/`) and compiled to `style.css`. There is no automated build
tool configured (no `package.json`/gulpfile) — compilation is manual (Dart Sass CLI, or an IDE/editor
Sass compiler extension), and the compiled `style.css` + `style.css.map` are committed directly.

**Entry point:** `assets/sass/style.scss` imports all partials in order:
```
variable → head → foot → home → shop → custom → product → woocommerce → single_product → cart → form → thankyou
```

**Edit flow:**
```
assets/sass/_partial.scss  →  sass compile  →  style.css (commit both)
```

### Key SCSS Files

| File              | Responsibility                                  |
| ------------------ | ------------------------------------------------ |
| `_variable.scss`  | Brand color tokens — change the palette here      |
| `_woocommerce.scss` | General WooCommerce page overrides               |
| `_shop.scss`      | Shop/category archive page overrides              |
| `_single_product.scss` / `_product.scss` | Single product page overrides |
| `_cart.scss` / `_form.scss` / `_thankyou.scss` | Cart, form, and thank-you page overrides |

---

## PHP Hooks (functions.php)

| Hook | Purpose | Status |
| --- | --- | --- |
| `wp_enqueue_scripts` → `woodmart_child_enqueue_styles` | Enqueues the compiled child `style.css` | Active |
| `woocommerce_short_description` → `woo_add_text_before_excerpt_single_product` | Prepends a "KEY FEATURES" heading to the product short description | Active |
| `woocommerce_add_to_cart_validation` → `check_pos_inventory_before_add_to_cart` | Real-time POS stock check before add-to-cart | **Disabled** (commented out) |
| `wp_ajax(_nopriv)_check_inventory_before_cart` → `check_inventory_before_cart_ajax` | AJAX POS stock check | **Disabled** (commented out) |

The live-POS-check path (`get_pos_inventory()`) still contains placeholder API URL/key values and is
not wired to the real POS API — the `skifftech-pos-sync-for-woocommerce` plugin is the maintained
sync path instead.

---

## How a Page Gets Built

```
WordPress Page / WooCommerce Shop-Loop
     ↓
WooCommerce template hierarchy (theme woocommerce/ overrides win over plugin defaults)
     ↓
Woodmart native builder / Elementor for visual layout blocks
     ↓
functions.php hooks (short description header, enqueues, etc.)
     ↓
Rendered HTML
```

---

## WordPress Configuration Notes

- Theme text domain: `woodmart` (inherited from the Woodmart parent — the child theme's `style.css`
  header declares `Template: woodmart`, `Text Domain: woodmart`)
- Permalink structure and nav menu locations: check **Settings → Permalinks** / **Appearance → Menus**
  in the live install — not yet documented here

---

## Decisions & Rationale

| Decision                                    | Reason                                                          |
| --------------------------------------------- | ------------------------------------------------------------------ |
| Woodmart (premium theme) as parent           | Full-featured WooCommerce storefront out of the box; child theme keeps customizations upgrade-safe |
| Custom OAuth2 REST plugin for POS sync        | Static shared keys are avoidable; per-client revocable credentials are safer for a third-party POS integration |
| Composer `vendor/` committed in the plugin    | No CI/build step exists yet; committing vendor/ is how dependencies ship to production |
| CSV batch-import tool kept alongside REST sync | Manual fallback/bulk-correction path for stock, independent of the live POS connection |

---

## Pages

| Page | URL Slug | Template | Status |
| ---- | -------- | -------- | ------ |

_(Add pages here as they are built)_
