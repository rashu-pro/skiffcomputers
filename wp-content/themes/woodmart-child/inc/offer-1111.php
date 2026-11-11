<?php
/**
 * 11.11 Mega Sale campaign page.
 *
 * - Settings page: WooCommerce → 11.11 Campaign (option `skiff_1111_settings`).
 * - Campaign products: published, catalog-visible, in-stock products carrying the campaign
 *   product_tag (default `11-11`) whose sale price is live. Admins (manage_woocommerce) also
 *   see products whose sale is scheduled but not started yet ("preview").
 * - AJAX endpoint `skiff_1111_products` (logged-in + logged-out) for category switching / load more.
 * - Shortcode `[skiff_1111_countdown]`.
 *
 * Templates: page-templates/template-offer-1111.php, template-parts/offer-1111/*.php
 * Script:    js/offer-1111.js  — styles live in style.css (".skiff-1111" section).
 * CSV/WP-CLI helper for tagging products + scheduling sale prices: inc/offer-1111-import.php
 *
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

define( 'SKIFF_1111_OPTION', 'skiff_1111_settings' );
define( 'SKIFF_1111_TEMPLATE', 'page-templates/template-offer-1111.php' );
define( 'SKIFF_1111_TZ', 'Asia/Dhaka' );
define( 'SKIFF_1111_CACHE_TTL', 10 * MINUTE_IN_SECONDS );

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/**
 * Default settings (Bangla content is editable in the admin).
 *
 * @return array
 */
function skiff_1111_defaults() {
	return array(
		'start'             => '2026-11-11 00:00',
		'end'               => '2026-12-11 23:59',
		'title'             => '১১.১১ মেগা ডিল!',
		'subtitle'          => 'সাশ্রয়ের মহোৎসব',
		'summary'           => 'স্কিফ কম্পিউটার্স অনলাইন শপ থেকে পছন্দের পণ্য কিনে উপভোগ করুন <strong>বিশাল মূল্যছাড়</strong>!',
		'ended_text'        => 'Campaign has ended',
		'featured_title'    => 'Featured Products',
		'featured_subtitle' => 'Hand-picked 11.11 deals with the biggest savings',
		'terms_title'       => '১১.১১ ক্যাম্পেইনের শর্তাবলী',
		'terms'             => implode(
			"\n",
			array(
				'ক্যাম্পেইনের পণ্য অবশ্যই ক্যাম্পেইন চলাকালীন সময়ে অর্ডার করতে হবে।',
				'ক্যাম্পেইনটি শুধুমাত্র অনলাইন অর্ডারের ক্ষেত্রে প্রযোজ্য।',
				'ক্যাম্পেইনের পণ্যে অন্য কোনো অফার থাকলে যেকোনো একটি অফার উপভোগ করা যাবে।',
				'স্টক সীমিত; স্টক শেষ হলে অফার প্রযোজ্য নয়।',
				'অনিবার্য কারণে ক্যাম্পেইনে যেকোনো পরিবর্তনের অধিকার স্কিফ কম্পিউটার্স কর্তৃপক্ষ সংরক্ষণ করে।',
			)
		),
		'tag'               => '11-11',
		'per_page'          => 12,
		// featured_fallback: WC-featured campaign products; if none, all campaign products by discount.
		// featured_first:    all campaign products, featured ones first, then by discount.
		'featured_mode'     => 'featured_fallback',
		// show: keep the page (hero says ended); redirect: send visitors to the shop page.
		'after_end'         => 'show',
	);
}

/**
 * Current settings merged with defaults.
 *
 * @return array
 */
function skiff_1111_settings() {
	$saved = get_option( SKIFF_1111_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), skiff_1111_defaults() );
}

/**
 * Convert a "Y-m-d H:i" Dhaka-local string to a UTC timestamp.
 *
 * @param string $local      Local datetime.
 * @param bool   $end_of_min Add 59 seconds (inclusive end minute).
 * @return int 0 when invalid.
 */
function skiff_1111_to_timestamp( $local, $end_of_min = false ) {
	$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', (string) $local, new DateTimeZone( SKIFF_1111_TZ ) );
	if ( ! $dt ) {
		return 0;
	}
	return $dt->getTimestamp() + ( $end_of_min ? 59 : 0 );
}

/**
 * Campaign window as UTC timestamps.
 *
 * @return array{start:int,end:int}
 */
function skiff_1111_window() {
	$s = skiff_1111_settings();
	return array(
		'start' => skiff_1111_to_timestamp( $s['start'] ),
		'end'   => skiff_1111_to_timestamp( $s['end'], true ),
	);
}

/**
 * Campaign status right now.
 *
 * @return string upcoming|running|ended
 */
function skiff_1111_status() {
	$w   = skiff_1111_window();
	$now = time();
	if ( $w['start'] && $now < $w['start'] ) {
		return 'upcoming';
	}
	if ( $w['end'] && $now > $w['end'] ) {
		return 'ended';
	}
	return 'running';
}

/**
 * Whether the current user sees scheduled (not yet live) campaign prices.
 *
 * @return bool
 */
function skiff_1111_is_preview() {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * Sanitize settings on save.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function skiff_1111_sanitize_settings( $input ) {
	$old = skiff_1111_settings();
	$in  = is_array( $input ) ? $input : array();
	$out = $old;

	foreach ( array( 'start', 'end' ) as $key ) {
		if ( isset( $in[ $key ] ) ) {
			$raw = str_replace( 'T', ' ', sanitize_text_field( $in[ $key ] ) );
			$raw = substr( $raw, 0, 16 ); // Drop seconds if the browser sent them.
			if ( skiff_1111_to_timestamp( $raw ) ) {
				$out[ $key ] = $raw;
			} else {
				add_settings_error( SKIFF_1111_OPTION, 'skiff_1111_' . $key, sprintf( 'Invalid %s date/time — previous value kept.', $key ) );
			}
		}
	}
	if ( skiff_1111_to_timestamp( $out['end'], true ) <= skiff_1111_to_timestamp( $out['start'] ) ) {
		add_settings_error( SKIFF_1111_OPTION, 'skiff_1111_window', 'End must be after start — previous window kept.' );
		$out['start'] = $old['start'];
		$out['end']   = $old['end'];
	}

	foreach ( array( 'title', 'subtitle', 'ended_text', 'featured_title', 'featured_subtitle', 'terms_title' ) as $key ) {
		if ( isset( $in[ $key ] ) ) {
			$out[ $key ] = sanitize_text_field( $in[ $key ] );
		}
	}

	if ( isset( $in['summary'] ) ) {
		$out['summary'] = wp_kses( $in['summary'], skiff_1111_summary_allowed_html() );
	}
	if ( isset( $in['terms'] ) ) {
		$out['terms'] = sanitize_textarea_field( $in['terms'] );
	}
	if ( isset( $in['tag'] ) ) {
		$tag        = sanitize_title( $in['tag'] );
		$out['tag'] = '' !== $tag ? $tag : '11-11';
	}
	if ( isset( $in['per_page'] ) ) {
		$out['per_page'] = min( 48, max( 4, absint( $in['per_page'] ) ) );
	}
	if ( isset( $in['featured_mode'] ) && in_array( $in['featured_mode'], array( 'featured_fallback', 'featured_first' ), true ) ) {
		$out['featured_mode'] = $in['featured_mode'];
	}
	if ( isset( $in['after_end'] ) && in_array( $in['after_end'], array( 'show', 'redirect' ), true ) ) {
		$out['after_end'] = $in['after_end'];
	}

	return $out;
}

/**
 * Tags allowed in the hero summary.
 *
 * @return array
 */
function skiff_1111_summary_allowed_html() {
	return array(
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'br'     => array(),
	);
}

add_action(
	'admin_init',
	function () {
		register_setting(
			'skiff_1111',
			SKIFF_1111_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'skiff_1111_sanitize_settings',
				'default'           => skiff_1111_defaults(),
			)
		);
	}
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'woocommerce',
			'11.11 Campaign',
			'11.11 Campaign',
			'manage_woocommerce',
			'skiff-1111',
			'skiff_1111_render_settings_page'
		);
	}
);

/**
 * `register_setting` uses the `manage_options` capability by default; let shop managers save too.
 */
add_filter(
	'option_page_capability_skiff_1111',
	function () {
		return 'manage_woocommerce';
	}
);

/**
 * Render the settings page (+ CSV import box from offer-1111-import.php).
 */
function skiff_1111_render_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$s      = skiff_1111_settings();
	$name   = SKIFF_1111_OPTION;
	$page   = skiff_1111_get_page();
	$status = skiff_1111_status();
	$field  = function ( $key ) use ( $name ) {
		return esc_attr( $name . '[' . $key . ']' );
	};
	?>
	<div class="wrap">
		<h1>11.11 Campaign</h1>
		<p>
			Status: <strong><?php echo esc_html( ucfirst( $status ) ); ?></strong>
			<?php if ( $page ) : ?>
				&middot; <a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank" rel="noopener">View page</a>
			<?php else : ?>
				&middot; No page uses the “11.11 Offer” template yet.
			<?php endif; ?>
			&middot; Campaign products right now: <strong><?php echo (int) count( skiff_1111_get_items( false ) ); ?></strong> live
			/ <strong><?php echo (int) count( skiff_1111_get_items( true ) ); ?></strong> incl. scheduled.
		</p>
		<?php settings_errors( SKIFF_1111_OPTION ); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'skiff_1111' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Campaign window (Asia/Dhaka)</th>
					<td>
						<input type="datetime-local" name="<?php echo $field( 'start' ); // phpcs:ignore ?>" value="<?php echo esc_attr( str_replace( ' ', 'T', $s['start'] ) ); ?>" required>
						&rarr;
						<input type="datetime-local" name="<?php echo $field( 'end' ); // phpcs:ignore ?>" value="<?php echo esc_attr( str_replace( ' ', 'T', $s['end'] ) ); ?>" required>
						<p class="description">The end minute is inclusive (23:59 runs until 23:59:59). Changing the window does <strong>not</strong> move product sale dates — re-run the CSV import below so the scheduled sale prices match.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-title">Hero title</label></th>
					<td><input id="skiff-1111-title" class="regular-text" type="text" name="<?php echo $field( 'title' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['title'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-subtitle">Sub-title</label></th>
					<td><input id="skiff-1111-subtitle" class="regular-text" type="text" name="<?php echo $field( 'subtitle' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['subtitle'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-summary">Summary</label></th>
					<td>
						<textarea id="skiff-1111-summary" class="large-text" rows="3" name="<?php echo $field( 'summary' ); // phpcs:ignore ?>"><?php echo esc_textarea( $s['summary'] ); ?></textarea>
						<p class="description">Allowed tags: &lt;strong&gt;, &lt;b&gt;, &lt;em&gt;, &lt;br&gt;.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-ended">“Ended” message</label></th>
					<td><input id="skiff-1111-ended" class="regular-text" type="text" name="<?php echo $field( 'ended_text' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['ended_text'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-ftitle">Featured section</label></th>
					<td>
						<input id="skiff-1111-ftitle" class="regular-text" type="text" name="<?php echo $field( 'featured_title' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['featured_title'] ); ?>" placeholder="Title"><br>
						<input class="regular-text" type="text" name="<?php echo $field( 'featured_subtitle' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['featured_subtitle'] ); ?>" placeholder="Subtitle">
					</td>
				</tr>
				<tr>
					<th scope="row">Featured behaviour</th>
					<td>
						<label><input type="radio" name="<?php echo $field( 'featured_mode' ); // phpcs:ignore ?>" value="featured_fallback" <?php checked( $s['featured_mode'], 'featured_fallback' ); ?>> Only campaign products marked “Featured” (★) in WooCommerce — if none, all campaign products by highest discount %</label><br>
						<label><input type="radio" name="<?php echo $field( 'featured_mode' ); // phpcs:ignore ?>" value="featured_first" <?php checked( $s['featured_mode'], 'featured_first' ); ?>> All campaign products — featured first, then by highest discount %</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-tag">Product tag slug</label></th>
					<td><input id="skiff-1111-tag" type="text" name="<?php echo $field( 'tag' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['tag'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-pp">Products per page</label></th>
					<td><input id="skiff-1111-pp" type="number" min="4" max="48" step="1" name="<?php echo $field( 'per_page' ); // phpcs:ignore ?>" value="<?php echo (int) $s['per_page']; ?>"></td>
				</tr>
				<tr>
					<th scope="row">After the campaign ends</th>
					<td>
						<label><input type="radio" name="<?php echo $field( 'after_end' ); // phpcs:ignore ?>" value="show" <?php checked( $s['after_end'], 'show' ); ?>> Keep the page — hero shows the “ended” message, grid shows the tagged products at normal prices</label><br>
						<label><input type="radio" name="<?php echo $field( 'after_end' ); // phpcs:ignore ?>" value="redirect" <?php checked( $s['after_end'], 'redirect' ); ?>> Redirect visitors to the shop page (admins still see the page)</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="skiff-1111-ttitle">Terms &amp; conditions</label></th>
					<td>
						<input id="skiff-1111-ttitle" class="regular-text" type="text" name="<?php echo $field( 'terms_title' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['terms_title'] ); ?>"><br>
						<textarea class="large-text" rows="8" name="<?php echo $field( 'terms' ); // phpcs:ignore ?>"><?php echo esc_textarea( $s['terms'] ); ?></textarea>
						<p class="description">One condition per line.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php
		if ( function_exists( 'skiff_1111_render_import_box' ) ) {
			skiff_1111_render_import_box();
		}
		?>
	</div>
	<?php
}

/**
 * First published page using the 11.11 template.
 *
 * @return int Page ID or 0.
 */
function skiff_1111_get_page() {
	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => SKIFF_1111_TEMPLATE, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return $pages ? (int) $pages[0] : 0;
}

/* -------------------------------------------------------------------------
 * Pricing
 * ---------------------------------------------------------------------- */

/**
 * Campaign price data for a product.
 *
 * Simple products: their own regular/sale price. Variable products: the in-stock variation with
 * the lowest sale price (so the badge, sale and struck-through price all describe one real variation).
 * Prices are display prices (respecting the shop's tax display setting).
 *
 * @param WC_Product $product Product.
 * @param bool       $preview Include sales that are scheduled but not started yet.
 * @return array|null { regular, sale, save, pct, scheduled } or null when not on campaign sale.
 */
function skiff_1111_price_data( $product, $preview ) {
	if ( $product->is_type( 'variable' ) ) {
		$candidates = array_filter( array_map( 'wc_get_product', $product->get_visible_children() ) );
	} else {
		$candidates = array( $product );
	}

	$best = null;
	$now  = time();
	foreach ( $candidates as $p ) {
		if ( ! $p->is_in_stock() ) {
			continue;
		}
		$regular = (float) $p->get_regular_price( 'edit' );
		$sale    = $p->get_sale_price( 'edit' );
		if ( '' === (string) $sale || $regular <= 0 || (float) $sale >= $regular ) {
			continue;
		}

		$live = $p->is_on_sale();
		if ( ! $live ) {
			// Only admins previewing see sales that are scheduled to start in the future.
			$from = $p->get_date_on_sale_from( 'edit' );
			$to   = $p->get_date_on_sale_to( 'edit' );
			if ( ! $preview || ! $from || $from->getTimestamp() <= $now || ( $to && $to->getTimestamp() < $now ) ) {
				continue;
			}
		}

		$regular_d = (float) wc_get_price_to_display( $p, array( 'price' => $regular ) );
		$sale_d    = (float) wc_get_price_to_display( $p, array( 'price' => (float) $sale ) );
		if ( null === $best || $sale_d < $best['sale'] ) {
			$best = array(
				'regular'   => $regular_d,
				'sale'      => $sale_d,
				'scheduled' => ! $live,
			);
		}
	}

	if ( null === $best ) {
		return null;
	}

	$best['save'] = (int) round( $best['regular'] - $best['sale'] );
	$best['pct']  = $best['regular'] > 0 ? (int) round( ( $best['regular'] - $best['sale'] ) / $best['regular'] * 100 ) : 0;
	return $best;
}

/* -------------------------------------------------------------------------
 * Campaign product list (cached)
 * ---------------------------------------------------------------------- */

/**
 * Transient key.
 *
 * @param string $type    items|cats.
 * @param bool   $preview Preview flag.
 * @param string $status  Campaign status.
 * @return string
 */
function skiff_1111_cache_key( $type, $preview, $status ) {
	return 'skiff_1111_' . $type . '_' . ( $preview ? 'p' : 'l' ) . '_' . $status;
}

/**
 * All campaign items, sorted by discount % (desc), then Taka saved (desc).
 *
 * While the campaign is upcoming/running only products on (campaign) sale are included. After it
 * ends, sale prices revert automatically, so every in-stock tagged product is listed at its normal
 * price (only relevant when "after the campaign ends" = keep the page).
 *
 * @param bool|null $preview Defaults to the current user's capability.
 * @return array[] { id, cats, featured, price }
 */
function skiff_1111_get_items( $preview = null ) {
	$preview = null === $preview ? skiff_1111_is_preview() : (bool) $preview;
	$status  = skiff_1111_status();
	$key     = skiff_1111_cache_key( 'items', $preview, $status );
	$items   = get_transient( $key );
	if ( is_array( $items ) ) {
		return $items;
	}

	$s   = skiff_1111_settings();
	$ids = wc_get_products(
		array(
			'status'       => 'publish',
			'tag'          => array( $s['tag'] ),
			'stock_status' => 'instock',
			'visibility'   => 'catalog',
			'limit'        => -1,
			'return'       => 'ids',
		)
	);

	$default_cat = (int) get_option( 'default_product_cat' );
	$items       = array();
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		$price = skiff_1111_price_data( $product, $preview );
		if ( ! $price && 'ended' !== $status ) {
			continue;
		}
		$items[] = array(
			'id'       => (int) $id,
			'cats'     => array_values( array_diff( array_map( 'intval', $product->get_category_ids() ), array( $default_cat ) ) ),
			'featured' => $product->is_featured(),
			'price'    => $price,
		);
	}

	usort(
		$items,
		function ( $a, $b ) {
			$pa = $a['price'] ? $a['price']['pct'] : -1;
			$pb = $b['price'] ? $b['price']['pct'] : -1;
			if ( $pa !== $pb ) {
				return $pb <=> $pa;
			}
			$sa = $a['price'] ? $a['price']['save'] : 0;
			$sb = $b['price'] ? $b['price']['save'] : 0;
			return $sb <=> $sa;
		}
	);

	set_transient( $key, $items, SKIFF_1111_CACHE_TTL );
	return $items;
}

/**
 * Sidebar categories: product_cat terms containing at least one campaign product. Cached 1h.
 *
 * @param bool|null $preview Preview flag.
 * @return array[] { id, slug, name, count }
 */
function skiff_1111_get_categories( $preview = null ) {
	$preview = null === $preview ? skiff_1111_is_preview() : (bool) $preview;
	$status  = skiff_1111_status();
	$key     = skiff_1111_cache_key( 'cats', $preview, $status );
	$cats    = get_transient( $key );
	if ( is_array( $cats ) ) {
		return $cats;
	}

	$counts = array();
	foreach ( skiff_1111_get_items( $preview ) as $item ) {
		foreach ( $item['cats'] as $cat_id ) {
			$counts[ $cat_id ] = isset( $counts[ $cat_id ] ) ? $counts[ $cat_id ] + 1 : 1;
		}
	}

	$cats = array();
	if ( $counts ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'include'    => array_keys( $counts ),
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$cats[] = array(
					'id'    => (int) $term->term_id,
					'slug'  => $term->slug,
					'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'count' => $counts[ $term->term_id ],
				);
			}
		}
	}

	set_transient( $key, $cats, HOUR_IN_SECONDS );
	return $cats;
}

/**
 * Find a sidebar category by slug.
 *
 * @param string $slug Slug.
 * @return array|null
 */
function skiff_1111_find_category( $slug ) {
	foreach ( skiff_1111_get_categories() as $cat ) {
		if ( $cat['slug'] === $slug ) {
			return $cat;
		}
	}
	return null;
}

/**
 * One page of items for a category ('' = Featured Products).
 *
 * @param string $cat_slug Category slug or ''.
 * @param int    $page     1-based page.
 * @return array { items, has_more, total, title, subtitle }
 */
function skiff_1111_query( $cat_slug, $page ) {
	$s     = skiff_1111_settings();
	$items = skiff_1111_get_items();
	$cat   = '' !== $cat_slug ? skiff_1111_find_category( $cat_slug ) : null;

	if ( $cat ) {
		$list     = array_values(
			array_filter(
				$items,
				function ( $i ) use ( $cat ) {
					return in_array( $cat['id'], $i['cats'], true );
				}
			)
		);
		$title    = $cat['name'];
		$subtitle = sprintf( _n( '%d deal', '%d deals', count( $list ), 'woodmart' ), count( $list ) );
	} else {
		$featured = array_values(
			array_filter(
				$items,
				function ( $i ) {
					return $i['featured'];
				}
			)
		);
		if ( 'featured_first' === $s['featured_mode'] ) {
			$rest = array_values(
				array_filter(
					$items,
					function ( $i ) {
						return ! $i['featured'];
					}
				)
			);
			$list = array_merge( $featured, $rest );
		} else {
			$list = $featured ? $featured : $items;
		}
		$title    = $s['featured_title'];
		$subtitle = $s['featured_subtitle'];
	}

	$per    = max( 1, (int) $s['per_page'] );
	$page   = max( 1, (int) $page );
	$offset = ( $page - 1 ) * $per;

	return array(
		'items'    => array_slice( $list, $offset, $per ),
		'has_more' => count( $list ) > $offset + $per,
		'total'    => count( $list ),
		'title'    => $title,
		'subtitle' => $subtitle,
	);
}

/**
 * Render product cards.
 *
 * @param array[] $items Items from skiff_1111_query().
 * @return string HTML.
 */
function skiff_1111_render_cards( $items ) {
	ob_start();
	foreach ( $items as $item ) {
		$product = wc_get_product( $item['id'] );
		if ( ! $product ) {
			continue;
		}
		get_template_part(
			'template-parts/offer-1111/card',
			null,
			array(
				'product' => $product,
				'price'   => $item['price'],
			)
		);
	}
	return ob_get_clean();
}

/**
 * Empty-state HTML for the grid.
 *
 * @return string
 */
function skiff_1111_empty_html() {
	$msg = 'upcoming' === skiff_1111_status() && ! skiff_1111_is_preview()
		? 'Deals go live when the campaign starts — check back soon!'
		: 'No deals here right now — check back soon!';
	return '<p class="skiff-1111__empty">' . esc_html( $msg ) . '</p>';
}

/* -------------------------------------------------------------------------
 * Cache busting
 * ---------------------------------------------------------------------- */

/**
 * Delete all cached item/category lists.
 */
function skiff_1111_flush_cache() {
	foreach ( array( 'items', 'cats' ) as $type ) {
		foreach ( array( true, false ) as $preview ) {
			foreach ( array( 'upcoming', 'running', 'ended' ) as $status ) {
				delete_transient( skiff_1111_cache_key( $type, $preview, $status ) );
			}
		}
	}
}

/**
 * Flush only when the product (or its parent) carries the campaign tag.
 *
 * @param int $post_id Product or variation ID.
 */
function skiff_1111_maybe_flush( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return;
	}
	$parent = (int) wp_get_post_parent_id( $post_id );
	$target = $parent ? $parent : $post_id;
	if ( has_term( skiff_1111_settings()['tag'], 'product_tag', $target ) ) {
		skiff_1111_flush_cache();
	}
}

add_action( 'save_post_product', 'skiff_1111_maybe_flush' );
add_action( 'woocommerce_new_product', 'skiff_1111_maybe_flush' );
add_action( 'woocommerce_update_product', 'skiff_1111_maybe_flush' );
add_action( 'woocommerce_update_product_variation', 'skiff_1111_maybe_flush' );
add_action( 'woocommerce_product_set_stock_status', 'skiff_1111_maybe_flush' );
add_action( 'woocommerce_variation_set_stock_status', 'skiff_1111_maybe_flush' );
add_action( 'wp_trash_post', 'skiff_1111_maybe_flush' );
add_action( 'before_delete_post', 'skiff_1111_maybe_flush' );
add_action(
	'set_object_terms',
	function ( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( in_array( $taxonomy, array( 'product_tag', 'product_cat' ), true ) && 'product' === get_post_type( $object_id ) ) {
			skiff_1111_flush_cache(); // Tag may just have been added or removed.
		}
	},
	10,
	4
);
add_action( 'edited_product_cat', 'skiff_1111_flush_cache' );
add_action( 'delete_product_cat', 'skiff_1111_flush_cache' );
add_action( 'update_option_' . SKIFF_1111_OPTION, 'skiff_1111_flush_cache' );

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

/**
 * AJAX: one page of cards. Read-only public data, so the nonce is enforced for logged-in users
 * only — logged-out visitors get the page from LiteSpeed's cache, where a baked-in nonce would
 * expire (and WooCommerce ties guest nonces to the session cookie).
 */
function skiff_1111_ajax_products() {
	if ( is_user_logged_in() ) {
		check_ajax_referer( 'skiff_1111', 'nonce' );
	}

	$cat  = isset( $_REQUEST['cat'] ) ? sanitize_title( wp_unslash( $_REQUEST['cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$page = isset( $_REQUEST['page'] ) ? min( 200, max( 1, absint( $_REQUEST['page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

	if ( '' !== $cat && ! skiff_1111_find_category( $cat ) ) {
		wp_send_json_error( array( 'message' => 'Unknown category' ), 404 );
	}

	$result = skiff_1111_query( $cat, $page );
	$html   = skiff_1111_render_cards( $result['items'] );
	if ( '' === trim( $html ) && 1 === $page ) {
		$html = skiff_1111_empty_html();
	}

	nocache_headers();
	wp_send_json_success(
		array(
			'html'     => $html,
			'has_more' => $result['has_more'],
			'total'    => $result['total'],
			'title'    => $result['title'],
			'subtitle' => $result['subtitle'],
			'status'   => skiff_1111_status(),
		)
	);
}
add_action( 'wp_ajax_skiff_1111_products', 'skiff_1111_ajax_products' );
add_action( 'wp_ajax_nopriv_skiff_1111_products', 'skiff_1111_ajax_products' );

/* -------------------------------------------------------------------------
 * Front end: assets, body class, redirect, cache TTL
 * ---------------------------------------------------------------------- */

/**
 * Is the current request the 11.11 page?
 *
 * @return bool
 */
function skiff_1111_is_template() {
	return is_page_template( SKIFF_1111_TEMPLATE );
}

/**
 * Register front-end assets.
 */
function skiff_1111_register_assets() {
	if ( wp_script_is( 'skiff-1111', 'registered' ) ) {
		return;
	}
	wp_register_style(
		'skiff-1111-font',
		'https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap',
		array(),
		null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	);

	$file = get_stylesheet_directory() . '/js/offer-1111.js';
	wp_register_script(
		'skiff-1111',
		get_stylesheet_directory_uri() . '/js/offer-1111.js',
		array(),
		file_exists( $file ) ? (string) filemtime( $file ) : '1.0.0',
		true
	);

	$s = skiff_1111_settings();
	wp_localize_script(
		'skiff-1111',
		'skiff1111',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			// Empty for guests (see skiff_1111_ajax_products()).
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'skiff_1111' ) : '',
			'preview' => skiff_1111_is_preview(),
			'i18n'    => array(
				'error'    => 'Could not load products. Please try again.',
				'upcoming' => 'শুরু হতে বাকি',
				'running'  => 'অফার শেষ হতে বাকি',
				'ended'    => $s['ended_text'],
			),
		)
	);
}

/**
 * Enqueue the countdown/grid script and the Bangla font (template or shortcode).
 */
function skiff_1111_enqueue_assets() {
	skiff_1111_register_assets();
	wp_enqueue_style( 'skiff-1111-font' );
	wp_enqueue_script( 'skiff-1111' );
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( skiff_1111_is_template() ) {
			skiff_1111_enqueue_assets();
			return;
		}
		// Shortcode in regular post content: enqueue early so the font loads in <head>.
		// (Elementor / Slider Revolution output enqueues from the shortcode callback instead.)
		$post = get_post();
		if ( is_singular() && $post && has_shortcode( $post->post_content, 'skiff_1111_countdown' ) ) {
			skiff_1111_enqueue_assets();
		}
	},
	20
);

add_filter(
	'body_class',
	function ( $classes ) {
		if ( skiff_1111_is_template() ) {
			$classes[] = 'skiff-1111-page';
		}
		return $classes;
	}
);

add_action(
	'template_redirect',
	function () {
		if ( ! skiff_1111_is_template() ) {
			return;
		}
		$s = skiff_1111_settings();
		if ( 'ended' === skiff_1111_status() && 'redirect' === $s['after_end'] && ! skiff_1111_is_preview() ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ), 302 );
			exit;
		}
		// Keep LiteSpeed's public copy of this page short-lived (stock, start/end transitions).
		// No-op when LiteSpeed Cache isn't active.
		do_action( 'litespeed_control_set_ttl', SKIFF_1111_CACHE_TTL );
	}
);

/* -------------------------------------------------------------------------
 * Shortcode
 * ---------------------------------------------------------------------- */

/**
 * [skiff_1111_countdown title="" link="" button="Shop the deals" style="banner|inline"]
 *
 * - style="banner" (default): title + countdown + button linking to the 11.11 page.
 * - style="inline": just the countdown digits (for header notices / sliders).
 * Renders nothing once the campaign has ended.
 *
 * @param array $atts Attributes.
 * @return string
 */
function skiff_1111_countdown_shortcode( $atts ) {
	$s    = skiff_1111_settings();
	$atts = shortcode_atts(
		array(
			'title'  => $s['title'],
			'link'   => '',
			'button' => 'Shop the deals',
			'style'  => 'banner',
		),
		$atts,
		'skiff_1111_countdown'
	);

	if ( 'ended' === skiff_1111_status() ) {
		return '';
	}

	skiff_1111_enqueue_assets();

	$link = $atts['link'];
	if ( '' === $link ) {
		$page = skiff_1111_get_page();
		$link = $page ? get_permalink( $page ) : '';
	}

	ob_start();
	get_template_part(
		'template-parts/offer-1111/countdown',
		null,
		array(
			'context' => 'inline' === $atts['style'] ? 'inline' : 'banner',
			'title'   => $atts['title'],
			'link'    => $link,
			'button'  => $atts['button'],
		)
	);
	return ob_get_clean();
}
add_shortcode( 'skiff_1111_countdown', 'skiff_1111_countdown_shortcode' );
