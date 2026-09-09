<?php
/**
 * Find WooCommerce products whose regular/sale price does not match the CSV
 * (e.g. the POS export). Read-only report - does not change any product.
 *
 * @package Woodmart_Child
 */

/**
 * Parse an uploaded CSV file and return price data keyed by SKU.
 * Mirrors the column handling in stock-update-csv.php: "price" is the regular
 * price, "sale_price" (or "sale price") is only expected to be applied when
 * lower than "price".
 *
 * @param string $csv_file_path Path to the CSV file.
 * @return array|WP_Error Array of SKU => array( 'name', 'price', 'sale_price', 'expected_sale_price' ), or WP_Error on failure.
 */
function skiff_get_price_data_from_csv( $csv_file_path ) {
	if ( ! file_exists( $csv_file_path ) ) {
		return new WP_Error( 'not_found', 'CSV file not found' );
	}

	$handle = fopen( $csv_file_path, 'r' );
	if ( $handle === false ) {
		return new WP_Error( 'unreadable', 'Could not open CSV file' );
	}

	$header = fgetcsv( $handle );
	if ( $header === false ) {
		fclose( $handle );
		return new WP_Error( 'no_header', 'Could not read CSV header' );
	}

	// Normalize so "sale_price" and "sale price" both match.
	$header_normalized = array_map(
		function( $cell ) {
			return str_replace( '_', ' ', strtolower( trim( $cell ) ) );
		},
		$header
	);
	$sku_index        = array_search( 'sku', $header_normalized );
	$name_index       = array_search( 'name', $header_normalized );
	$price_index      = array_search( 'price', $header_normalized );
	$sale_price_index = array_search( 'sale price', $header_normalized );

	if ( $sku_index === false ) {
		fclose( $handle );
		return new WP_Error( 'no_sku_column', 'SKU column not found in CSV' );
	}
	if ( $price_index === false ) {
		fclose( $handle );
		return new WP_Error( 'no_price_column', 'Price column not found in CSV' );
	}

	$data = array();
	while ( ( $row = fgetcsv( $handle ) ) !== false ) {
		$max_col = max( $sku_index, $price_index );
		if ( $name_index !== false ) {
			$max_col = max( $max_col, $name_index );
		}
		if ( $sale_price_index !== false ) {
			$max_col = max( $max_col, $sale_price_index );
		}
		if ( empty( $row ) || count( $row ) <= $max_col ) {
			continue;
		}

		$sku = trim( $row[ $sku_index ] );
		if ( $sku === '' ) {
			continue;
		}

		$csv_price      = trim( $row[ $price_index ] );
		$csv_sale_price = $sale_price_index !== false ? trim( $row[ $sale_price_index ] ) : '';

		// Same rule as the stock/price update script: sale_price only counts when lower than price.
		$expected_sale_price = '';
		if ( $csv_sale_price !== '' && is_numeric( $csv_sale_price ) && is_numeric( $csv_price ) && floatval( $csv_sale_price ) < floatval( $csv_price ) ) {
			$expected_sale_price = $csv_sale_price;
		}

		$data[ $sku ] = array(
			'name'                => $name_index !== false ? trim( $row[ $name_index ] ) : '',
			'price'               => $csv_price,
			'sale_price'          => $csv_sale_price,
			'expected_sale_price' => $expected_sale_price,
		);
	}
	fclose( $handle );

	return $data;
}

/**
 * Normalize a price value for comparison (numeric values compared to 2 decimal places,
 * everything else compared as a trimmed string; blank/null both normalize to '').
 *
 * @param string|null $value Raw price value.
 * @return string Normalized value.
 */
function skiff_normalize_price_for_compare( $value ) {
	if ( $value === null || $value === '' ) {
		return '';
	}
	if ( is_numeric( $value ) ) {
		return number_format( (float) $value, 2, '.', '' );
	}
	return trim( $value );
}

/**
 * Get every WooCommerce product (and variation) that has a SKU, with its regular/sale price, directly from the DB.
 *
 * @return array List of rows: product_id, sku, name, type, parent_id, regular_price, sale_price.
 */
function skiff_get_all_products_with_price() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT p.ID AS product_id, p.post_title AS name, p.post_type AS type, p.post_parent AS parent_id,
				sku.meta_value AS sku,
				regular.meta_value AS regular_price,
				sale.meta_value AS sale_price
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
		 LEFT JOIN {$wpdb->postmeta} regular ON regular.post_id = p.ID AND regular.meta_key = '_regular_price'
		 LEFT JOIN {$wpdb->postmeta} sale ON sale.post_id = p.ID AND sale.meta_key = '_sale_price'
		 WHERE p.post_type IN ('product', 'product_variation')
		 AND p.post_status != 'trash'
		 AND sku.meta_value != ''",
		ARRAY_A
	);

	return $rows ? $rows : array();
}

/**
 * Handle the uploaded CSV and build the price-mismatch report.
 */
function handle_find_price_mismatches_submit() {
	if ( ! isset( $_POST['find_price_mismatches_nonce'] ) || ! wp_verify_nonce( $_POST['find_price_mismatches_nonce'], 'find_price_mismatches_action' ) ) {
		return array( 'success' => false, 'error' => 'Security check failed' );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return array( 'success' => false, 'error' => 'Insufficient permissions' );
	}
	if ( empty( $_FILES['csv_upload']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_upload']['tmp_name'] ) ) {
		return array( 'success' => false, 'error' => 'Please upload a CSV file.' );
	}

	$csv_data = skiff_get_price_data_from_csv( $_FILES['csv_upload']['tmp_name'] );
	if ( is_wp_error( $csv_data ) ) {
		return array( 'success' => false, 'error' => $csv_data->get_error_message() );
	}

	$all_products = skiff_get_all_products_with_price();

	$mismatches   = array();
	$checked      = 0;
	foreach ( $all_products as $row ) {
		$sku = trim( $row['sku'] );
		if ( ! isset( $csv_data[ $sku ] ) ) {
			continue; // Not in CSV - reported by the "find missing products" tool instead.
		}
		$checked++;

		$csv_row = $csv_data[ $sku ];

		$price_mismatch = skiff_normalize_price_for_compare( $csv_row['price'] ) !== skiff_normalize_price_for_compare( $row['regular_price'] );
		$sale_mismatch   = skiff_normalize_price_for_compare( $csv_row['expected_sale_price'] ) !== skiff_normalize_price_for_compare( $row['sale_price'] );

		if ( ! $price_mismatch && ! $sale_mismatch ) {
			continue;
		}

		$mismatches[] = array(
			'product_id'          => $row['product_id'],
			'parent_id'           => $row['parent_id'],
			'type'                => $row['type'],
			'name'                => $row['name'],
			'sku'                 => $sku,
			'csv_price'           => $csv_row['price'],
			'db_price'            => $row['regular_price'],
			'price_mismatch'      => $price_mismatch,
			'csv_sale_price'      => $csv_row['expected_sale_price'],
			'db_sale_price'       => $row['sale_price'],
			'sale_mismatch'       => $sale_mismatch,
		);
	}

	return array(
		'success'       => true,
		'total_checked' => $checked,
		'mismatches'    => $mismatches,
	);
}

/**
 * Add admin menu for finding price mismatches.
 */
function add_find_price_mismatches_admin_menu() {
	add_submenu_page(
		'woocommerce',
		'Find Price Mismatches',
		'Find Price Mismatches',
		'manage_woocommerce',
		'find-price-mismatches',
		'render_find_price_mismatches_admin_page'
	);
}
add_action( 'admin_menu', 'add_find_price_mismatches_admin_menu' );

/**
 * Render admin page.
 */
function render_find_price_mismatches_admin_page() {
	$results = null;
	if ( isset( $_POST['find_price_mismatches_submit'] ) ) {
		$results = handle_find_price_mismatches_submit();
	}
	?>
	<div class="wrap">
		<h1>Find Price Mismatches</h1>
		<p>Upload the CSV (e.g. the POS export) to see which WooCommerce products have a regular or sale price that <strong>does not match</strong> the CSV. This is a read-only report - no products are changed.</p>

		<div class="card">
			<h2>Upload CSV</h2>
			<form method="post" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'find_price_mismatches_action', 'find_price_mismatches_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="csv_upload">CSV File</label>
						</th>
						<td>
							<input type="file" id="csv_upload" name="csv_upload" accept=".csv,text/csv,text/plain" class="regular-text" required>
							<p class="description">The CSV must have <strong>sku</strong> and <strong>price</strong> columns; <strong>sale_price</strong> is optional.</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="find_price_mismatches_submit" class="button button-primary" value="Find Price Mismatches">
				</p>
			</form>
		</div>

		<?php if ( $results !== null ) : ?>
			<?php if ( ! $results['success'] ) : ?>
				<div class="notice notice-error"><p><strong>Error:</strong> <?php echo esc_html( $results['error'] ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-info">
					<p>
						Checked <strong><?php echo esc_html( $results['total_checked'] ); ?></strong> products that exist in both WooCommerce and the CSV.<br>
						<strong><?php echo esc_html( count( $results['mismatches'] ) ); ?></strong> have a price mismatch.
					</p>
				</div>

				<?php if ( ! empty( $results['mismatches'] ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>SKU</th>
								<th>Type</th>
								<th>CSV Price</th>
								<th>WooCommerce Price</th>
								<th>CSV Sale Price</th>
								<th>WooCommerce Sale Price</th>
								<th>Edit</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $results['mismatches'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['product_id'] ); ?></td>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><?php echo esc_html( $row['sku'] ); ?></td>
									<td><?php echo esc_html( $row['type'] ); ?></td>
									<td<?php echo $row['price_mismatch'] ? ' style="background:#fef2f2;font-weight:600;"' : ''; ?>><?php echo esc_html( $row['csv_price'] ); ?></td>
									<td<?php echo $row['price_mismatch'] ? ' style="background:#fef2f2;font-weight:600;"' : ''; ?>><?php echo esc_html( $row['db_price'] === null ? '' : $row['db_price'] ); ?></td>
									<td<?php echo $row['sale_mismatch'] ? ' style="background:#fef2f2;font-weight:600;"' : ''; ?>><?php echo esc_html( $row['csv_sale_price'] ); ?></td>
									<td<?php echo $row['sale_mismatch'] ? ' style="background:#fef2f2;font-weight:600;"' : ''; ?>><?php echo esc_html( $row['db_sale_price'] === null ? '' : $row['db_sale_price'] ); ?></td>
									<td><a href="<?php echo esc_url( get_edit_post_link( $row['type'] === 'product_variation' ? $row['parent_id'] : $row['product_id'] ) ); ?>" target="_blank">Edit</a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
