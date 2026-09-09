# Skiff Computers — WordPress Website

WooCommerce storefront for [Skiff Computers](https://skiffcomputers.com), a computer/electronics
retailer in Chattogram, Bangladesh. Built on the premium **Woodmart** theme via a custom child theme
(`woodmart-child`). Product catalog and checkout run on WooCommerce; inventory and orders sync with the
in-store POS system through a custom plugin, `skifftech-pos-sync-for-woocommerce`.

---

## Tech Stack

| Layer             | Tool                                                     |
| ------------------ | --------------------------------------------------------- |
| CMS                | WordPress                                                  |
| E-commerce         | WooCommerce                                                |
| Theme              | Woodmart (premium) + `woodmart-child` (custom)             |
| Page Builder       | Elementor + Woodmart native builder + Slider Revolution    |
| Forms              | Contact Form 7                                             |
| SEO                | Yoast SEO                                                  |
| Caching            | WP Super Cache                                             |
| CSS Preprocessor   | SCSS, compiled manually (no build tool configured)          |
| Local Dev          | Laragon                                                    |

---

## Local Development Setup

### Prerequisites

- [Laragon](https://laragon.org/) with Apache + MySQL + PHP
- [Composer](https://getcomposer.org/) (for the POS-sync plugin's dependencies)
- A Sass compiler if you're editing styles (e.g. the Dart Sass CLI, or the VS Code "Live Sass Compiler" extension)

### Getting started

1. Clone the repo into your Laragon `www` directory:
   ```
   git clone <repo-url> skiffcomputers.com
   ```

2. Create the database in Laragon (or phpMyAdmin):
   ```
   Database name: skiffcomputers.com
   ```

3. Copy `wp-config-sample.php` to `wp-config.php` and fill in your local DB credentials:
   ```php
   define( 'DB_NAME',     'skiffcomputers.com' );
   define( 'DB_USER',     'root' );
   define( 'DB_PASSWORD', '' );
   define( 'DB_HOST',     'localhost' );
   ```

4. Import the database from the latest backup (via All-in-One WP Migration or a SQL dump).

5. Install the POS-sync plugin's PHP dependencies:
   ```bash
   cd wp-content/plugins/skifftech-pos-sync-for-woocommerce
   composer install --no-dev
   ```

6. Start editing SCSS in `wp-content/themes/woodmart-child/assets/sass/` and compile to `style.css`
   with your Sass tool of choice (see `CLAUDE.md` for compile commands).

---

## Project Structure

```
skiffcomputers.com/
├── wp-content/
│   ├── themes/
│   │   └── woodmart-child/         # Custom child theme — only tracked item in themes/
│   │       ├── assets/sass/        # SCSS source files (edit here)
│   │       ├── inc/                # Custom includes (stock-update-csv, etc.)
│   │       ├── js/                 # Theme JavaScript
│   │       ├── woocommerce/        # WooCommerce template overrides
│   │       └── functions.php
│   └── plugins/
│       └── skifftech-pos-sync-for-woocommerce/  # Custom POS↔WooCommerce sync plugin (tracked in git)
├── docs/
│   ├── ARCHITECTURE.md             # Theme structure, WooCommerce template overrides, plugin architecture
│   └── DESIGN-SYSTEM.md            # Color tokens, typography, components
├── designs/                        # Static HTML design mockups / prototypes (see designs/README.md)
└── README.md
```

WordPress core, third-party plugins (including the Woodmart parent theme), and runtime assets
(uploads, cache, logs, stock-sync CSV dumps) are excluded from git. See `.gitignore` for full details.

---

## Key Conventions

- Edit SCSS in `assets/sass/` — never touch `style.css` directly
- WooCommerce template overrides live in `woodmart-child/woocommerce/`, mirroring WooCommerce's own path structure
- Custom plugin lives in `wp-content/plugins/skifftech-pos-sync-for-woocommerce/` — see its own `readme.md`
  and `API-INTEGRATION.md` for the OAuth2 REST API it exposes
- Do not edit WordPress core, the Woodmart parent theme, or other third-party plugin files

---

## Documentation

| File | Contents |
| --- | --- |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Full theme file tree, WooCommerce template overrides, plugin architecture |
| [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md) | Color tokens, typography, buttons, layout |
| [`designs/README.md`](designs/README.md) | How to use the `designs/` folder for HTML mockups/prototypes |
| [`wp-content/plugins/skifftech-pos-sync-for-woocommerce/readme.md`](wp-content/plugins/skifftech-pos-sync-for-woocommerce/readme.md) | POS-sync plugin setup |
| [`wp-content/plugins/skifftech-pos-sync-for-woocommerce/API-INTEGRATION.md`](wp-content/plugins/skifftech-pos-sync-for-woocommerce/API-INTEGRATION.md) | POS-sync REST API reference |
