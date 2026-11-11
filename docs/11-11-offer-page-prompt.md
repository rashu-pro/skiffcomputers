# Task: Build the 11.11 Mega Sale offer page for Skiff Computers

Read `CLAUDE.md` and `docs/DESIGN-SYSTEM.md` first and follow their conventions (child theme only,
SCSS in `assets/sass/`, never edit Woodmart parent / WooCommerce / third-party plugin files).

## Goal
A campaign landing page at `/11-11` (WordPress page, slug `11-11`) for the 11.11 sale. Layout reference
(structure only — do NOT copy their branding, text, or assets): a typical BD electronics 11.11 page has
1. Hero card: big "১১.১১ মেগা ডিল!" title, red sub-headline, one-line offer summary with bold highlights
   (max discount + promo code + free delivery), and a Days / Hours / Minutes / Seconds countdown.
2. Two-column body: LEFT sticky "Shop by Category" list (scrollable); RIGHT a section title
   ("Featured Products" + subtitle) and a 4-column product grid. Clicking a category swaps the grid
   (AJAX, no full reload) and the title. "Featured Products" is the first/default item.
3. Product card: purple/red pill badge top-left "Save: ৳X (-Y%)", image, product name (2-line clamp),
   sale price in brand red + regular price struck through. Whole card links to product; add an
   Add-to-cart button on hover/below using standard WooCommerce AJAX add-to-cart.
4. "Load more" (or pagination) under the grid.
5. Terms & conditions box at the bottom ("11.11 ক্যাম্পেইনের শর্তাবলী") as a bulleted list, editable.

## Implementation (in `wp-content/themes/woodmart-child/`)
- `inc/offer-1111.php` (auto-loaded by `inc/_include.php`). Contains:
  - Campaign config read from a small admin settings page (WooCommerce → 11.11 Campaign) stored in one
    option `skiff_1111_settings`: start datetime, end datetime (Asia/Dhaka), hero title, sub-title,
    summary text (allow <strong>), promo code, terms (textarea, one per line), product tag slug
    (default `11-11`), products per page (default 12), featured category behaviour.
  - Which products are in the campaign: products with product_tag `11-11` that are in stock and
    on sale. "Featured Products" = tagged products that are also WooCommerce *featured* (fallback:
    highest discount %). Sidebar categories = product_cat terms that contain ≥1 campaign product
    (cache this term list in a transient for 1h; bust it on `save_post_product`).
  - AJAX endpoint (`wp_ajax_` + `wp_ajax_nopriv_` `skiff_1111_products`, nonce-checked, sanitized
    `cat` + `page`) returning rendered card HTML + `has_more`. Use `WC_Product_Query`/`wc_get_products`.
  - Discount math: handle simple AND variable products (use min regular/sale of variations), round
    to whole Taka, show ৳ with `wc_price()`-compatible formatting.
  - After end datetime: hero shows "Campaign has ended", countdown hidden, grid still shows products
    (or redirect to shop — make it a setting).
  - Shortcode `[skiff_1111_countdown]` so the countdown can also be dropped into the home page
    (Elementor/Slider Revolution) or a header notice.
- Page template `page-templates/template-offer-1111.php` ("11.11 Offer"), using `get_header()` /
  `get_footer()` so Woodmart header/footer stay. Assign it to the `11-11` page (create the page via
  WP-CLI if available, otherwise tell me the steps).
- Templates: `template-parts/offer-1111/hero.php`, `sidebar.php`, `card.php`, `terms.php`.
- JS: `js/offer-1111.js` (vanilla JS, no jQuery dependency except for Woo's add-to-cart events):
  countdown driven by a server-provided **UTC timestamp** in a data attribute (so page caching by
  WP Super Cache / LiteSpeed doesn't freeze it), category switching, load more, loading skeletons,
  `history.replaceState` with `?cat=slug` so category links are shareable and the server renders
  that category on first load.
- SCSS: new partial `assets/sass/_offer_1111.scss`, imported in `style.scss`, using `$red_primary`,
  `$red_dark` from `_variable.scss`. Add new tokens there if needed (e.g. a purple badge colour) and
  document them in `docs/DESIGN-SYSTEM.md`. Page background light grey, white rounded cards
  (8px radius, subtle shadow). Load **Hind Siliguri** (Google Fonts) only on this template for Bangla.
  Then compile: `sass assets/sass/style.scss style.css --style=compressed` (tell me if sass isn't installed).
- Enqueue the JS/CSS-for-font only when `is_page_template('page-templates/template-offer-1111.php')`
  or the shortcode is used.
- Responsive: grid 4 cols ≥1200px, 3 cols tablet, 2 cols mobile; on mobile the category sidebar becomes
  a horizontal scrollable chip bar sticky under the header.
- SEO: Yoast title/meta for the page ("Skiff Computers 11.11 Mega Sale 2026 | Biggest Tech Deals in
  Chattogram"), and output `Offer`-friendly markup only via WooCommerce's existing product schema.

## Offer mechanics to set up (WooCommerce config — do via WP-CLI where possible, else give me steps)
- Coupon `1111`: free shipping, minimum spend ৳500, valid only during the campaign window,
  usage-limit per user 1 (confirm with me before creating).
- Shipping: a Free Shipping method that "requires a valid free shipping coupon" in the BD zone(s);
  express delivery (if a method exists) must stay paid. If the free shipping must be capped at ৳100,
  explain the options rather than hacking it.
- Campaign prices: use WooCommerce scheduled sale prices (sale from/to = campaign window) so prices
  revert automatically. **Check `skifftech-pos-sync-for-woocommerce` first** — confirm the POS sync
  does not overwrite `_sale_price` / sale dates; if it does, tell me before changing anything.
- Provide a WP-CLI or admin helper to bulk-tag products `11-11` from a CSV of SKUs + sale price
  (reuse patterns from `inc/stock-update-csv.php`).

## Also
- Add an "11.11 Deals" item to the header (Woodmart header builder is in DB — just tell me how) and
  a home-page banner/countdown via the shortcode.
- Add the page to the Pages table in `CLAUDE.md`.
- Test locally on Laragon: empty state (no tagged products), a category with 1 product, variable
  products, out-of-stock exclusion, countdown after expiry, mobile layout, and that the AJAX endpoint
  works for logged-out users with caching on. Check PHP errors with Query Monitor.
- Don't commit or push until I review. Show me a summary of files created/changed at the end.

## Content (Bangla, editable in settings)
- Title: ১১.১১ মেগা ডিল!
- Sub-title: সাশ্রয়ের মহোৎসব
- Summary: স্কিফ কম্পিউটার্স অনলাইন শপ থেকে পছন্দের পণ্য কিনে পাচ্ছেন সর্বোচ্চ **[X] টাকা মূল্যছাড়**!
  এছাড়াও **"1111"** প্রমোকোড ব্যবহার করে উপভোগ করুন **ফ্রি ডেলিভারি**!
- Terms (one per line):
  - ক্যাম্পেইনের পণ্য অবশ্যই ক্যাম্পেইন চলাকালীন সময়ে অর্ডার করতে হবে।
  - ক্যাম্পেইনটি শুধুমাত্র অনলাইন অর্ডারের ক্ষেত্রে প্রযোজ্য।
  - সর্বনিম্ন ৫০০ টাকার অর্ডারে "1111" প্রমোকোড দিয়ে ফ্রি ডেলিভারি প্রযোজ্য।
  - এক্সপ্রেস ডেলিভারির ক্ষেত্রে ফ্রি ডেলিভারি অফার প্রযোজ্য নয়।
  - ক্যাম্পেইনের পণ্যে অন্য কোনো অফার থাকলে যেকোনো একটি অফার উপভোগ করা যাবে।
  - স্টক সীমিত; স্টক শেষ হলে অফার প্রযোজ্য নয়।
  - অনিবার্য কারণে ক্যাম্পেইনে যেকোনো পরিবর্তনের অধিকার স্কিফ কম্পিউটার্স কর্তৃপক্ষ সংরক্ষণ করে।

Start by exploring the repo and the POS-sync plugin, then give me a short implementation plan and the
open questions before writing code.
