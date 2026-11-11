<?php
/**
 * 11.11 campaign: bulk-tag products and schedule their campaign sale prices from a CSV.
 *
 * CSV columns (header row required, case-insensitive, "sale_price" or "sale price"):
 *   sku, sale_price
 *
 * For each row: finds the product (or variation) by SKU, sets its sale price, sets the sale
 * dates to the campaign window from WooCommerce → 11.11 Campaign (exact Asia/Dhaka times), and
 * adds the campaign product_tag (to the parent for variations). WooCommerce's own scheduled-sale
 * events then switch prices on/off at those exact times — nothing to undo by hand.
 *
 * The regular price is never touched (it is owned by the POS sync).
 *
 * Admin: WooCommerce → 11.11 Campaign (upload box under the settings).
 * WP-CLI:
 *   wp skiff-1111 import products.csv [--dry-run]
 *   wp skiff-1111 reset [--dry-run]
 *   wp skiff-1111 status
 *
 * Loaded by inc/_include.php (before offer-1111.php — only call its functions inside hooks/functions).
 *
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse the campaign CSV.
 *
 * @param string $path File path.
 * @return array|WP_Error Rows: [ 'line' => int, 'sku' => string, 'sale_price' => string ].
 */
function skiff_1111_parse_csv( $path ) {
	if ( ! is_readable( $path ) ) {
		return new WP_Error( 'skiff_1111_csv', 'CSV file not found or not readable.' );
	}
	$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $handle ) {
		return new WP_Error( 'skiff_1111_csv', 'Could not open CSV file.' );
	}

	$header = fgetcsv( $handle, 0, ',', '"', '\\' );
	if ( false === $header ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'skiff_1111_csv', 'Could not read CSV header.' );
	}
	// Normalize so "sale_price", "Sale Price" and a UTF-8 BOM all match.
	$header = array_map(
		function ( $cell ) {
			$cell = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cell );
			return str_replace( '_', ' ', strtolower( trim( $cell ) ) );
		},
		$header
	);
	$sku_i  = array_search( 'sku', $header, true );
	$sale_i = array_search( 'sale price', $header, true );
	if ( false === $sku_i || false === $sale_i ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'skiff_1111_csv', 'CSV must have "sku" and "sale_price" columns.' );
	}

	$rows = array();
	$line = 1;
	while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		++$line;
		if ( array( null ) === $row ) {
			continue; // Blank line.
		}
		$rows[] = array(
			'line'       => $line,
			'sku'        => isset( $row[ $sku_i ] ) ? trim( $row[ $sku_i ] ) : '',
			'sale_price' => isset( $row[ $sale_i ] ) ? trim( str_replace( ',', '', $row[ $sale_i ] ) ) : '',
		);
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return $rows;
}

/**
 * Apply campaign rows.
 *
 * @param array $rows    From skiff_1111_parse_csv().
 * @param bool  $dry_run Validate only.
 * @return array { updated: string[], skipped: string[] }
 */
function skiff_1111_import_rows( array $rows, $dry_run ) {
	$s       = skiff_1111_settings();
	$window  = skiff_1111_window();
	$report  = array(
		'updated' => array(),
		'skipped' => array(),
	);
	$seen    = array();

	if ( $window['end'] <= time() ) {
		$report['skipped'][] = 'Campaign end is in the past — set the window in the settings first. Nothing imported.';
		return $report;
	}

	foreach ( $rows as $row ) {
		$where = sprintf( 'Line %d (%s)', $row['line'], '' !== $row['sku'] ? $row['sku'] : 'no SKU' );

		if ( '' === $row['sku'] ) {
			$report['skipped'][] = $where . ': empty SKU.';
			continue;
		}
		if ( isset( $seen[ $row['sku'] ] ) ) {
			$report['skipped'][] = $where . ': duplicate SKU (first seen on line ' . $seen[ $row['sku'] ] . ').';
			continue;
		}
		$seen[ $row['sku'] ] = $row['line'];

		$id      = wc_get_product_id_by_sku( $row['sku'] );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			$report['skipped'][] = $where . ': SKU not found.';
			continue;
		}
		if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			$report['skipped'][] = $where . ': ' . $product->get_type() . ' product — list each variation SKU instead.';
			continue;
		}
		if ( ! is_numeric( $row['sale_price'] ) || (float) $row['sale_price'] <= 0 ) {
			$report['skipped'][] = $where . ': sale price "' . $row['sale_price'] . '" is not a positive number.';
			continue;
		}

		$regular = (float) $product->get_regular_price( 'edit' );
		$sale    = (float) $row['sale_price'];
		if ( $regular <= 0 ) {
			$report['skipped'][] = $where . ': product has no regular price.';
			continue;
		}
		if ( $sale >= $regular ) {
			$report['skipped'][] = sprintf( '%s: sale price %s is not below the regular price %s.', $where, $row['sale_price'], $product->get_regular_price( 'edit' ) );
			continue;
		}

		$parent_id = $product->get_parent_id();
		$tag_on    = $parent_id ? $parent_id : $product->get_id();

		if ( ! $dry_run ) {
			$product->set_sale_price( wc_format_decimal( $sale ) );
			$product->set_date_on_sale_from( $window['start'] );
			$product->set_date_on_sale_to( $window['end'] );
			$product->save();
			wp_set_object_terms( $tag_on, $s['tag'], 'product_tag', true );
		}

		$report['updated'][] = sprintf(
			'%s: #%d %s — %s → %s (-%d%%)%s',
			$where,
			$product->get_id(),
			$product->get_name(),
			$product->get_regular_price( 'edit' ),
			wc_format_decimal( $sale ),
			(int) round( ( $regular - $sale ) / $regular * 100 ),
			$product->is_in_stock() ? '' : ' [currently out of stock — hidden until back in stock]'
		);
	}

	if ( ! $dry_run ) {
		skiff_1111_flush_cache();
	}
	return $report;
}

/**
 * Remove the campaign: clears sale prices/dates that match the campaign window and removes the tag.
 * Sale prices with other dates (unrelated promotions) are left alone.
 *
 * @param bool $dry_run Report only.
 * @return array { updated: string[], skipped: string[] }
 */
function skiff_1111_reset( $dry_run ) {
	$s      = skiff_1111_settings();
	$window = skiff_1111_window();
	$report = array(
		'updated' => array(),
		'skipped' => array(),
	);

	$ids = wc_get_products(
		array(
			'status' => array( 'publish', 'draft', 'pending', 'private' ),
			'tag'    => array( $s['tag'] ),
			'limit'  => -1,
			'return' => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		$targets = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );

		foreach ( $targets as $target ) {
			$to = $target->get_date_on_sale_to( 'edit' );
			if ( $to && abs( $to->getTimestamp() - $window['end'] ) <= DAY_IN_SECONDS ) {
				if ( ! $dry_run ) {
					$target->set_sale_price( '' );
					$target->set_date_on_sale_from( null );
					$target->set_date_on_sale_to( null );
					$target->save();
				}
				$report['updated'][] = sprintf( '#%d %s: campaign sale price cleared.', $target->get_id(), $target->get_name() );
			} elseif ( '' !== (string) $target->get_sale_price( 'edit' ) ) {
				$report['skipped'][] = sprintf( '#%d %s: has a sale price with different dates — left unchanged.', $target->get_id(), $target->get_name() );
			}
		}

		if ( ! $dry_run ) {
			wp_remove_object_terms( $id, $s['tag'], 'product_tag' );
		}
		$report['updated'][] = sprintf( '#%d %s: "%s" tag removed.', $id, $product->get_name(), $s['tag'] );
	}

	if ( ! $dry_run ) {
		skiff_1111_flush_cache();
	}
	return $report;
}

/* -------------------------------------------------------------------------
 * Admin UI (rendered inside the 11.11 Campaign settings page)
 * ---------------------------------------------------------------------- */

/**
 * Upload / reset box + last report.
 */
function skiff_1111_render_import_box() {
	$report_key = 'skiff_1111_report_' . get_current_user_id();
	$report     = get_transient( $report_key );
	if ( $report ) {
		delete_transient( $report_key );
	}
	$w = skiff_1111_settings();
	?>
	<hr>
	<h2>Campaign products (CSV)</h2>
	<p>
		Columns: <code>sku</code>, <code>sale_price</code>. Each product gets the sale price scheduled for
		<strong><?php echo esc_html( $w['start'] ); ?></strong> → <strong><?php echo esc_html( $w['end'] ); ?>:59</strong> (Asia/Dhaka)
		and the <code><?php echo esc_html( $w['tag'] ); ?></code> tag. Regular prices are not changed. Use variation SKUs for variable products.
	</p>
	<p><strong>Heads-up:</strong> running “Update Stock CSV” with a <code>sale_price</code> column during the campaign clears campaign sale prices on rows where that column is empty.</p>

	<?php if ( $report ) : ?>
		<div class="notice notice-<?php echo $report['skipped'] ? 'warning' : 'success'; ?>" style="padding:10px 12px">
			<p><strong><?php echo esc_html( $report['title'] ); ?></strong> — <?php echo count( $report['updated'] ); ?> applied, <?php echo count( $report['skipped'] ); ?> skipped.</p>
			<?php foreach ( array( 'skipped', 'updated' ) as $group ) : ?>
				<?php if ( $report[ $group ] ) : ?>
					<details <?php echo 'skipped' === $group ? 'open' : ''; ?>>
						<summary><?php echo esc_html( ucfirst( $group ) ); ?> (<?php echo count( $report[ $group ] ); ?>)</summary>
						<ul style="list-style:disc;margin-left:20px">
							<?php foreach ( $report[ $group ] as $line ) : ?>
								<li><?php echo esc_html( $line ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="skiff_1111_import">
		<?php wp_nonce_field( 'skiff_1111_import' ); ?>
		<input type="file" name="skiff_1111_csv" accept=".csv,text/csv" required>
		<label><input type="checkbox" name="dry_run" value="1" checked> Dry run (validate only)</label>
		<?php submit_button( 'Upload CSV', 'primary', 'submit', false ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px"
		onsubmit="return this.dry_run.checked || confirm('Remove the campaign tag and clear campaign sale prices from all tagged products?');">
		<input type="hidden" name="action" value="skiff_1111_reset">
		<?php wp_nonce_field( 'skiff_1111_reset' ); ?>
		<label><input type="checkbox" name="dry_run" value="1" checked> Dry run</label>
		<?php submit_button( 'Remove campaign from all products', 'secondary', 'submit', false ); ?>
	</form>
	<?php
}

/**
 * Store a report for the next settings-page load and redirect back.
 *
 * @param string $title  Heading.
 * @param array  $report Report.
 */
function skiff_1111_redirect_with_report( $title, array $report ) {
	$report['title'] = $title;
	set_transient( 'skiff_1111_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=skiff-1111' ) );
	exit;
}

add_action(
	'admin_post_skiff_1111_import',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'skiff_1111_import' );
		$dry_run = ! empty( $_POST['dry_run'] );

		$file = isset( $_FILES['skiff_1111_csv'] ) ? $_FILES['skiff_1111_csv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			skiff_1111_redirect_with_report( 'Upload failed', array( 'updated' => array(), 'skipped' => array( 'No file received.' ) ) );
		}
		if ( 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
			skiff_1111_redirect_with_report( 'Upload failed', array( 'updated' => array(), 'skipped' => array( 'Please upload a .csv file.' ) ) );
		}

		$rows = skiff_1111_parse_csv( $file['tmp_name'] );
		if ( is_wp_error( $rows ) ) {
			skiff_1111_redirect_with_report( 'Upload failed', array( 'updated' => array(), 'skipped' => array( $rows->get_error_message() ) ) );
		}

		wc_set_time_limit( 300 );
		skiff_1111_redirect_with_report( $dry_run ? 'Dry run' : 'Import complete', skiff_1111_import_rows( $rows, $dry_run ) );
	}
);

add_action(
	'admin_post_skiff_1111_reset',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'skiff_1111_reset' );
		$dry_run = ! empty( $_POST['dry_run'] );
		wc_set_time_limit( 300 );
		skiff_1111_redirect_with_report( $dry_run ? 'Reset (dry run)' : 'Campaign removed', skiff_1111_reset( $dry_run ) );
	}
);

/* -------------------------------------------------------------------------
 * WP-CLI
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Manage the 11.11 campaign products.
	 */
	class Skiff_1111_CLI {

		/**
		 * Tag products and schedule campaign sale prices from a CSV (columns: sku, sale_price).
		 *
		 * ## OPTIONS
		 *
		 * <file>
		 * : Path to the CSV.
		 *
		 * [--dry-run]
		 * : Validate only.
		 *
		 * ## EXAMPLES
		 *
		 *     wp skiff-1111 import 1111-products.csv --dry-run
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 */
		public function import( $args, $assoc_args ) {
			$rows = skiff_1111_parse_csv( $args[0] );
			if ( is_wp_error( $rows ) ) {
				WP_CLI::error( $rows->get_error_message() );
			}
			$this->print_report( skiff_1111_import_rows( $rows, ! empty( $assoc_args['dry-run'] ) ), ! empty( $assoc_args['dry-run'] ) );
		}

		/**
		 * Remove the campaign tag and clear campaign-window sale prices.
		 *
		 * [--dry-run]
		 * : Report only.
		 *
		 * @param array $args       Positional.
		 * @param array $assoc_args Flags.
		 */
		public function reset( $args, $assoc_args ) {
			$this->print_report( skiff_1111_reset( ! empty( $assoc_args['dry-run'] ) ), ! empty( $assoc_args['dry-run'] ) );
		}

		/**
		 * Show the campaign window, status and product counts.
		 */
		public function status() {
			$s = skiff_1111_settings();
			$w = skiff_1111_window();
			WP_CLI::line( sprintf( 'Window (Asia/Dhaka): %s → %s:59', $s['start'], $s['end'] ) );
			WP_CLI::line( sprintf( 'Window (UTC):        %s → %s', gmdate( 'Y-m-d H:i:s', $w['start'] ), gmdate( 'Y-m-d H:i:s', $w['end'] ) ) );
			WP_CLI::line( 'Status:              ' . skiff_1111_status() );
			WP_CLI::line( 'Tag:                 ' . $s['tag'] );
			WP_CLI::line( 'Live products:       ' . count( skiff_1111_get_items( false ) ) );
			WP_CLI::line( 'Incl. scheduled:     ' . count( skiff_1111_get_items( true ) ) );
			WP_CLI::line( 'Categories (live):   ' . count( skiff_1111_get_categories( false ) ) );
		}

		/**
		 * Print a report.
		 *
		 * @param array $report  Report.
		 * @param bool  $dry_run Dry run flag.
		 */
		private function print_report( array $report, $dry_run ) {
			foreach ( $report['updated'] as $line ) {
				WP_CLI::line( ( $dry_run ? '[dry] ' : '' ) . $line );
			}
			foreach ( $report['skipped'] as $line ) {
				WP_CLI::warning( $line );
			}
			WP_CLI::success( sprintf( '%s%d applied, %d skipped.', $dry_run ? 'Dry run: ' : '', count( $report['updated'] ), count( $report['skipped'] ) ) );
		}
	}

	WP_CLI::add_command( 'skiff-1111', 'Skiff_1111_CLI' );
}
