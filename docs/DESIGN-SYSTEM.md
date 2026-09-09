# Design System — Skiff Computers

> Tokens below are extracted from the live child theme: `assets/sass/_variable.scss` and the compiled
> header rules in `style.css`. Woodmart (the parent theme) supplies most component styling — this file
> only tracks Skiff Computers' brand overrides on top of it.
>
> **Keep this file updated** whenever a brand color changes, or a mockup is added to `designs/` that
> introduces a new token/component worth tracking here.

---

## Color Tokens

Defined in `wp-content/themes/woodmart-child/assets/sass/_variable.scss`:

```scss
$red_primary:  #DA2127;
$red_dark:     #9B171C;
$grey_whitish: #f1faee;
$blue-dark:    #003049;
$blue-light:   #004367;
```

| Token           | Value     | Use                                                              |
| --------------- | --------- | ----------------------------------------------------------------- |
| `$red_primary`  | `#DA2127` | Header bottom bar background, search button, layered-nav accents  |
| `$red_dark`     | `#9B171C` | Main/general header background                                    |
| `$grey_whitish` | `#f1faee` | Shop/archive page background                                      |
| `$blue-dark`    | `#003049` | Layered-nav checkbox border                                       |
| `$blue-light`   | `#004367` | (declared — check current usages before relying on it)             |

These are currently only wired into a handful of hard-coded `!important` header rules in `style.css`
(compiled from `_head.scss`) rather than referenced everywhere via the SCSS variables — when adding new
brand-colored elements, prefer importing `_variable.scss` and using `$red_primary` / `$red_dark` instead
of re-typing hex values.

### Known Direct Usages (compiled `style.css`)

```css
.whb-header-bottom            { background-color: #DA2127 !important; }
.whb-general-header           { background-color: #9B171C !important; border: 0 !important; }
.searchform .searchsubmit     { background-color: #DA2127; color: #fff; }
.post-type-archive-product .main-page-wrapper,
.woodmart-archive-shop .main-page-wrapper { background-color: #f1faee; }
.area-sidebar-shop .woodmart-woocommerce-layered-nav li.wc-layered-nav-term a:before {
  border: 2px solid #003049;
}
```

---

## Typography & Layout

Not overridden by the child theme — the site inherits Woodmart's typography, spacing, and grid system
from the parent theme and its Customizer settings (**Appearance → Customize → Woodmart Options**).
Check the live Customizer settings before assuming a value; nothing here is duplicated from Woodmart's
own (extensive) design system to avoid drift.

---

## Components Overridden by the Child Theme

| Component | Override location | Notes |
| --- | --- | --- |
| Header bottom bar / general header | `_head.scss` → compiled into `style.css` | Brand red/dark-red bands, white nav text/icons |
| Search form | `_head.scss` | White input, red submit button |
| Shop/archive background | `_shop.scss` | Whitish background (`$grey_whitish`) instead of Woodmart default |
| Layered nav (sidebar filters) | `_shop.scss` | Custom checkbox styling, hides term counts, tighter spacing |
| Product short description | `functions.php` (PHP, not CSS) | Injects a "KEY FEATURES" `<h4>` heading before the excerpt |
| Cart / Checkout / Thank-you pages | `_cart.scss`, `_form.scss`, `_thankyou.scss` | Page-specific spacing/style tweaks |
| Product quick-view | `quick-view.php` | Full template override of Woodmart's AJAX quick-view markup |

---

## Using `designs/`

The `designs/` directory is where static HTML mockups or prototypes for new pages/sections should be
dropped before they're built into the theme (see `designs/README.md`). When a mockup lands there,
extract any new color, spacing, or component values into this file the same way `_variable.scss` was
extracted above — the mockup is the source of truth for *new* design decisions; this file is the source
of truth for what's actually implemented in the child theme.

---

## Open Items

- No documented type scale, breakpoints, or button/card component spec beyond what Woodmart itself
  provides out of the box — if/when a custom design mockup is added to `designs/`, use it to fill in
  this section properly (see the `skifftech.com` project's `docs/DESIGN-SYSTEM.md` for the level of
  detail to aim for).
- `$blue-light` (`#004367`) is declared but its current usage in compiled CSS wasn't confirmed — verify
  before treating it as an active brand color.
