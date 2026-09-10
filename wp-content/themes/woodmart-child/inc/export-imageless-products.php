<?php
/**
 * List/export WooCommerce products that have no featured image set.
 * Read-only - does not change any product. Uses the same "no image" test
 * (no featured image / _thumbnail_id) as inc/hide-imageless-products.php.
 *
 * @package Woodmart_Child
 */

/**
 * Get every published/private product with no featured image, directly from the DB.
 *
 * @return array List of rows: product_id, name, sku, price (regular price).
 */
function skiff_get_products_without_image() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT p.ID AS product_id, p.post_title AS name,
				sku.meta_value AS sku,
				regular.meta_value AS price
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} thumb ON thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
		 LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
		 LEFT JOIN {$wpdb->postmeta} regular ON regular.post_id = p.ID AND regular.meta_key = '_regular_price'
		 WHERE p.post_type = 'product'
		 AND p.post_status IN ('publish', 'private')
		 AND ( thumb.meta_value IS NULL OR thumb.meta_value = '' )
		 ORDER BY p.post_title ASC",
		ARRAY_A
	);

	return $rows ? $rows : array();
}

/**
 * Add admin menu for listing/exporting products without an image.
 */
function add_export_imageless_products_admin_menu() {
	add_submenu_page(
		'woocommerce',
		'Products Without Image',
		'Products Without Image',
		'manage_woocommerce',
		'products-without-image',
		'render_export_imageless_products_admin_page'
	);
}
add_action( 'admin_menu', 'add_export_imageless_products_admin_menu' );

/**
 * Render admin page.
 */
function render_export_imageless_products_admin_page() {
	$rows = skiff_get_products_without_image();
	$export_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=skiff_export_imageless_products_csv' ),
		'skiff_export_imageless_products_csv'
	);
	?>
	<div class="wrap">
		<h1>Products Without Image</h1>
		<p>Published/private products with no featured image set. These are automatically hidden from the shop, category pages, and search (see <strong>hide-imageless-products.php</strong>) until an image is added.</p>

		<p>
			<strong><?php echo esc_html( count( $rows ) ); ?></strong> product<?php echo count( $rows ) === 1 ? '' : 's'; ?> found.
			<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">Export CSV (name, price, sku)</a>
		</p>

		<?php if ( ! empty( $rows ) ) : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Name</th>
						<th>Price</th>
						<th>SKU</th>
						<th>Edit</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['product_id'] ); ?></td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( $row['price'] === null ? '' : $row['price'] ); ?></td>
							<td><?php echo esc_html( $row['sku'] === null ? '' : $row['sku'] ); ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row['product_id'] ) ); ?>" target="_blank">Edit</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Stream the products-without-image list as a CSV download.
 */
function skiff_handle_export_imageless_products_csv() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Insufficient permissions' );
	}
	check_admin_referer( 'skiff_export_imageless_products_csv' );

	$rows = skiff_get_products_without_image();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="products-without-image-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$output = fopen( 'php://output', 'w' );
	fwrite( $output, "\xEF\xBB\xBF" ); // BOM, for Excel UTF-8 compatibility.
	fputcsv( $output, array( 'name', 'price', 'sku' ) );
	foreach ( $rows as $row ) {
		fputcsv( $output, array( $row['name'], $row['price'], $row['sku'] ) );
	}
	fclose( $output );
	exit;
}
add_action( 'admin_post_skiff_export_imageless_products_csv', 'skiff_handle_export_imageless_products_csv' );
