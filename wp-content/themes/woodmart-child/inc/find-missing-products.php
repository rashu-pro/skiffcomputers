<?php
/**
 * Find WooCommerce products that exist in the store but are missing from a CSV
 * (e.g. the POS export). Read-only report — does not delete anything.
 *
 * @package Woodmart_Child
 */

/**
 * Parse an uploaded CSV file and return the set of SKUs it contains.
 *
 * @param string $csv_file_path Path to the CSV file.
 * @return array|WP_Error Set of trimmed SKUs (as array keys) or WP_Error on failure.
 */
function skiff_get_skus_from_csv( $csv_file_path ) {
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

	$header_lower = array_map( 'strtolower', $header );
	$sku_index    = array_search( 'sku', $header_lower );

	if ( $sku_index === false ) {
		fclose( $handle );
		return new WP_Error( 'no_sku_column', 'SKU column not found in CSV' );
	}

	$skus = array();
	while ( ( $row = fgetcsv( $handle ) ) !== false ) {
		if ( empty( $row ) || count( $row ) <= $sku_index ) {
			continue;
		}
		$sku = trim( $row[ $sku_index ] );
		if ( $sku !== '' ) {
			$skus[ $sku ] = true;
		}
	}
	fclose( $handle );

	return $skus;
}

/**
 * Get every WooCommerce product (and variation) that has a SKU, directly from the DB.
 *
 * @return array List of rows: product_id, sku, name, type, stock, stock_status, price.
 */
function skiff_get_all_products_with_sku() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT p.ID AS product_id, p.post_title AS name, p.post_type AS type, p.post_parent AS parent_id,
				sku.meta_value AS sku,
				stock.meta_value AS stock,
				price.meta_value AS price
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
		 LEFT JOIN {$wpdb->postmeta} stock ON stock.post_id = p.ID AND stock.meta_key = '_stock'
		 LEFT JOIN {$wpdb->postmeta} price ON price.post_id = p.ID AND price.meta_key = '_price'
		 WHERE p.post_type IN ('product', 'product_variation')
		 AND p.post_status != 'trash'
		 AND sku.meta_value != ''",
		ARRAY_A
	);

	return $rows ? $rows : array();
}

/**
 * AJAX/POST handler: compare uploaded CSV against the DB and return products missing from the CSV.
 */
function handle_find_missing_products_submit() {
	if ( ! isset( $_POST['find_missing_products_nonce'] ) || ! wp_verify_nonce( $_POST['find_missing_products_nonce'], 'find_missing_products_action' ) ) {
		return array( 'success' => false, 'error' => 'Security check failed' );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return array( 'success' => false, 'error' => 'Insufficient permissions' );
	}
	if ( empty( $_FILES['csv_upload']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_upload']['tmp_name'] ) ) {
		return array( 'success' => false, 'error' => 'Please upload a CSV file.' );
	}

	$csv_skus = skiff_get_skus_from_csv( $_FILES['csv_upload']['tmp_name'] );
	if ( is_wp_error( $csv_skus ) ) {
		return array( 'success' => false, 'error' => $csv_skus->get_error_message() );
	}

	$all_products = skiff_get_all_products_with_sku();

	$missing = array();
	foreach ( $all_products as $row ) {
		if ( ! isset( $csv_skus[ trim( $row['sku'] ) ] ) ) {
			$missing[] = $row;
		}
	}

	return array(
		'success'       => true,
		'total_checked' => count( $all_products ),
		'total_in_csv'  => count( $csv_skus ),
		'missing'       => $missing,
	);
}

/**
 * Add admin menu for finding products missing from CSV.
 */
function add_find_missing_products_admin_menu() {
	add_submenu_page(
		'woocommerce',
		'Find Products Missing from CSV',
		'Find Missing Products',
		'manage_woocommerce',
		'find-missing-products',
		'render_find_missing_products_admin_page'
	);
}
add_action( 'admin_menu', 'add_find_missing_products_admin_menu' );

/**
 * Render admin page.
 */
function render_find_missing_products_admin_page() {
	$results = null;
	if ( isset( $_POST['find_missing_products_submit'] ) ) {
		$results = handle_find_missing_products_submit();
	}
	?>
	<div class="wrap">
		<h1>Find Products Missing from CSV</h1>
		<p>Upload the CSV (e.g. the POS export) to see which WooCommerce products <strong>do not appear</strong> in it. This is a read-only report — no products are changed or deleted.</p>

		<div class="card">
			<h2>Upload CSV</h2>
			<form method="post" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'find_missing_products_action', 'find_missing_products_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="csv_upload">CSV File</label>
						</th>
						<td>
							<input type="file" id="csv_upload" name="csv_upload" accept=".csv,text/csv,text/plain" class="regular-text" required>
							<p class="description">The CSV must have a <strong>sku</strong> column.</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="find_missing_products_submit" class="button button-primary" value="Find Missing Products">
				</p>
			</form>
		</div>

		<?php if ( $results !== null ) : ?>
			<?php if ( ! $results['success'] ) : ?>
				<div class="notice notice-error"><p><strong>Error:</strong> <?php echo esc_html( $results['error'] ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-info">
					<p>
						Checked <strong><?php echo esc_html( $results['total_checked'] ); ?></strong> products/variations in WooCommerce
						against <strong><?php echo esc_html( $results['total_in_csv'] ); ?></strong> SKUs in the CSV.<br>
						<strong><?php echo esc_html( count( $results['missing'] ) ); ?></strong> products are in WooCommerce but not in the CSV.
					</p>
				</div>

				<?php if ( ! empty( $results['missing'] ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>SKU</th>
								<th>Type</th>
								<th>Stock</th>
								<th>Price</th>
								<th>Edit</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $results['missing'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['product_id'] ); ?></td>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><?php echo esc_html( $row['sku'] ); ?></td>
									<td><?php echo esc_html( $row['type'] ); ?></td>
									<td><?php echo esc_html( $row['stock'] === null ? '' : $row['stock'] ); ?></td>
									<td><?php echo esc_html( $row['price'] === null ? '' : $row['price'] ); ?></td>
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
