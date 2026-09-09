<?php
/**
 * Create WooCommerce products from CSV rows whose SKU doesn't exist yet.
 * Existing SKUs are left untouched - use the stock/price CSV tool for those.
 *
 * Expected CSV columns: sku, name (both required); category, brand, unit,
 * price, sale_price, stock (all optional).
 *
 * @package Woodmart_Child
 */

define( 'SKIFF_IMPORT_PRODUCTS_BATCH_SIZE', 50 );

/**
 * Get total data row count in CSV (excluding header)
 *
 * @param string $csv_file_path Path to the CSV file.
 * @return int|false Row count or false on error.
 */
function skiff_get_csv_import_row_count( $csv_file_path ) {
	if ( ! file_exists( $csv_file_path ) ) {
		return false;
	}
	$handle = fopen( $csv_file_path, 'r' );
	if ( $handle === false ) {
		return false;
	}
	fgetcsv( $handle ); // Skip header.
	$count = 0;
	while ( fgetcsv( $handle ) !== false ) {
		$count++;
	}
	fclose( $handle );
	return $count;
}

/**
 * Find an existing term by name in a taxonomy, or create it.
 *
 * @param string $name     Term name.
 * @param string $taxonomy Taxonomy slug.
 * @return int|null Term ID, or null on failure.
 */
function skiff_import_resolve_term_id( $name, $taxonomy ) {
	$term = get_term_by( 'name', $name, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) $term->term_id;
	}

	$created = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $created ) ) {
		return null;
	}
	return (int) $created['term_id'];
}

/**
 * Build a "pa_brand" product attribute (global attribute) for the given brand name,
 * creating the taxonomy term if it doesn't exist yet. Returns null if the
 * "pa_brand" attribute isn't set up on this site.
 *
 * @param string $brand_name Brand name from the CSV.
 * @return WC_Product_Attribute|null
 */
function skiff_import_build_brand_attribute( $brand_name ) {
	$taxonomy = 'pa_brand';
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term_id = skiff_import_resolve_term_id( $brand_name, $taxonomy );
	if ( ! $term_id ) {
		return null;
	}

	$attribute = new WC_Product_Attribute();
	$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
	$attribute->set_name( $taxonomy );
	$attribute->set_options( array( $term_id ) );
	$attribute->set_position( 0 );
	$attribute->set_visible( true );
	$attribute->set_variation( false );

	return $attribute;
}

/**
 * Create products in batches from CSV rows whose SKU doesn't already exist.
 *
 * @param string $csv_file_path Path to the CSV file.
 * @param int    $offset        Row offset (0-based, after header).
 * @param int    $batch_size    Number of rows to process.
 * @param bool   $dry_run       If true, only reports what would be created.
 * @return array Results array with created, skipped_existing, errors, report_rows, has_more.
 */
function skiff_import_new_products_from_csv_batch( $csv_file_path, $offset = 0, $batch_size = SKIFF_IMPORT_PRODUCTS_BATCH_SIZE, $dry_run = false ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return array(
			'success' => false,
			'error'   => 'WooCommerce is not active',
		);
	}
	if ( ! file_exists( $csv_file_path ) ) {
		return array(
			'success' => false,
			'error'   => 'CSV file not found',
		);
	}

	$handle = fopen( $csv_file_path, 'r' );
	if ( $handle === false ) {
		return array(
			'success' => false,
			'error'   => 'Could not open CSV file',
		);
	}

	$header = fgetcsv( $handle );
	if ( $header === false ) {
		fclose( $handle );
		return array(
			'success' => false,
			'error'   => 'Could not read CSV header',
		);
	}

	// Normalize so "sale_price" and "sale price" both match.
	$header_normalized = array_map(
		function( $cell ) {
			return str_replace( '_', ' ', strtolower( trim( $cell ) ) );
		},
		$header
	);
	$sku_index      = array_search( 'sku', $header_normalized );
	$name_index     = array_search( 'name', $header_normalized );
	$category_index = array_search( 'category', $header_normalized );
	$brand_index    = array_search( 'brand', $header_normalized );
	$unit_index     = array_search( 'unit', $header_normalized );
	$price_index    = array_search( 'price', $header_normalized );
	$sale_price_index = array_search( 'sale price', $header_normalized );
	$stock_index    = array_search( 'stock', $header_normalized );

	if ( $sku_index === false || $name_index === false ) {
		fclose( $handle );
		return array(
			'success' => false,
			'error'   => 'SKU or name column not found in CSV',
		);
	}

	$max_col = max( $sku_index, $name_index );
	foreach ( array( $category_index, $brand_index, $unit_index, $price_index, $sale_price_index, $stock_index ) as $optional_index ) {
		if ( $optional_index !== false ) {
			$max_col = max( $max_col, $optional_index );
		}
	}

	// Skip to offset.
	for ( $i = 0; $i < $offset && ( fgetcsv( $handle ) !== false ); $i++ ) {
		// Skip rows.
	}

	$results = array(
		'success'         => true,
		'created'         => 0,
		'skipped_existing' => array(),
		'errors'          => array(),
		'report_rows'     => array(),
		'has_more'        => false,
	);

	$processed = 0;
	while ( $processed < $batch_size && ( $row = fgetcsv( $handle ) ) !== false ) {
		$line_number = $offset + $processed + 1; // +1 for header row.
		$processed++;

		if ( empty( $row ) || count( $row ) <= $max_col ) {
			continue;
		}

		$sku  = trim( $row[ $sku_index ] );
		$name = trim( $row[ $name_index ] );

		if ( empty( $sku ) || empty( $name ) ) {
			continue;
		}

		$existing_id = wc_get_product_id_by_sku( $sku );
		if ( $existing_id ) {
			$results['skipped_existing'][] = array( 'line' => $line_number, 'sku' => $sku, 'product_id' => $existing_id );
			$results['report_rows'][]      = array(
				'sku'        => $sku,
				'name'       => $name,
				'status'     => 'skipped_existing',
				'product_id' => $existing_id,
			);
			continue;
		}

		$csv_category   = $category_index !== false ? trim( $row[ $category_index ] ) : '';
		$csv_brand      = $brand_index !== false ? trim( $row[ $brand_index ] ) : '';
		$csv_unit       = $unit_index !== false ? trim( $row[ $unit_index ] ) : '';
		$csv_price      = $price_index !== false ? trim( $row[ $price_index ] ) : '';
		$csv_sale_price = $sale_price_index !== false ? trim( $row[ $sale_price_index ] ) : '';
		$csv_stock      = $stock_index !== false ? trim( $row[ $stock_index ] ) : '';

		if ( $dry_run ) {
			$results['created']++;
			$results['report_rows'][] = array(
				'sku'        => $sku,
				'name'       => $name,
				'status'     => 'would_create',
				'product_id' => '',
			);
			continue;
		}

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );

		if ( $csv_price !== '' && is_numeric( $csv_price ) ) {
			$product->set_regular_price( $csv_price );

			if ( $csv_sale_price !== '' && is_numeric( $csv_sale_price ) && floatval( $csv_sale_price ) < floatval( $csv_price ) ) {
				$product->set_sale_price( $csv_sale_price );
			}
		}

		// Stock is status-only - no exact quantity is stored, matching the rest of the site's tooling.
		$stock_quantity = is_numeric( $csv_stock ) ? floatval( $csv_stock ) : null;
		$product->set_stock_status( ( $stock_quantity === null || $stock_quantity > 0 ) ? 'instock' : 'outofstock' );

		if ( $csv_category !== '' ) {
			$category_id = skiff_import_resolve_term_id( $csv_category, 'product_cat' );
			if ( $category_id ) {
				$product->set_category_ids( array( $category_id ) );
			}
		}

		if ( $csv_brand !== '' ) {
			$attribute = skiff_import_build_brand_attribute( $csv_brand );
			if ( $attribute ) {
				$product->set_attributes( array( 'pa_brand' => $attribute ) );
			}
		}

		if ( $csv_unit !== '' ) {
			$product->update_meta_data( '_skiff_unit', $csv_unit );
		}

		try {
			$product_id = $product->save();
		} catch ( Exception $e ) {
			$results['errors'][]      = array( 'line' => $line_number, 'sku' => $sku, 'error' => $e->getMessage() );
			$results['report_rows'][] = array(
				'sku'        => $sku,
				'name'       => $name,
				'status'     => 'error: ' . $e->getMessage(),
				'product_id' => '',
			);
			continue;
		}

		if ( ! $product_id ) {
			$results['errors'][]      = array( 'line' => $line_number, 'sku' => $sku, 'error' => 'Product could not be saved' );
			$results['report_rows'][] = array(
				'sku'        => $sku,
				'name'       => $name,
				'status'     => 'error: Product could not be saved',
				'product_id' => '',
			);
			continue;
		}

		$results['created']++;
		$results['report_rows'][] = array(
			'sku'        => $sku,
			'name'       => $name,
			'status'     => 'created',
			'product_id' => $product_id,
		);
	}

	$next_row = fgetcsv( $handle );
	$results['has_more'] = $next_row !== false;
	fclose( $handle );

	return $results;
}

/**
 * Get temp directory for CSV uploads (separate from the stock-update tool's,
 * so the two tools don't share upload state).
 */
function skiff_import_products_temp_dir() {
	$upload_dir = wp_upload_dir();
	$temp_dir   = $upload_dir['basedir'] . '/import-products-temp';
	if ( ! file_exists( $temp_dir ) ) {
		wp_mkdir_p( $temp_dir );
	}
	$htaccess = $temp_dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, 'deny from all' );
	}
	return $temp_dir;
}

/**
 * Validate that path is within our temp directory.
 */
function skiff_import_products_validate_temp_path( $path ) {
	$real_path = realpath( $path );
	$temp_dir  = realpath( skiff_import_products_temp_dir() );
	return $real_path && $temp_dir && strpos( $real_path, $temp_dir ) === 0;
}

/**
 * AJAX: Upload CSV and prepare for batch processing.
 */
function handle_import_products_upload_ajax() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'import_products_csv_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ) );
	}
	if ( empty( $_FILES['csv_upload']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_upload']['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => 'Please upload a CSV file.' ) );
	}

	$file      = $_FILES['csv_upload'];
	$file_type = wp_check_filetype( $file['name'] );
	$is_csv    = in_array( $file['type'], array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' ) ) || $file_type['ext'] === 'csv';
	if ( ! $is_csv || $file['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( array( 'message' => 'Invalid file. Please upload a valid CSV file.' ) );
	}

	$temp_dir  = skiff_import_products_temp_dir();
	$temp_name = 'import_' . wp_unique_id() . '.csv';
	$temp_path = $temp_dir . '/' . $temp_name;

	if ( ! move_uploaded_file( $file['tmp_name'], $temp_path ) ) {
		wp_send_json_error( array( 'message' => 'Could not save uploaded file.' ) );
	}

	$total_rows = skiff_get_csv_import_row_count( $temp_path );
	if ( $total_rows === false ) {
		@unlink( $temp_path );
		wp_send_json_error( array( 'message' => 'Could not read CSV file.' ) );
	}

	wp_send_json_success( array(
		'temp_path'  => $temp_path,
		'total_rows' => $total_rows,
		'batch_size' => SKIFF_IMPORT_PRODUCTS_BATCH_SIZE,
	) );
}
add_action( 'wp_ajax_import_products_upload', 'handle_import_products_upload_ajax' );

/**
 * AJAX: Process one batch.
 */
function handle_import_products_batch_ajax() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'import_products_csv_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ) );
	}

	$temp_path = isset( $_POST['temp_path'] ) ? sanitize_text_field( $_POST['temp_path'] ) : '';
	$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$dry_run   = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';

	if ( ! skiff_import_products_validate_temp_path( $temp_path ) ) {
		wp_send_json_error( array( 'message' => 'Invalid file path.' ) );
	}

	$results = skiff_import_new_products_from_csv_batch( $temp_path, $offset, SKIFF_IMPORT_PRODUCTS_BATCH_SIZE, $dry_run );

	if ( $results['success'] && ! $results['has_more'] ) {
		@unlink( $temp_path );
	}

	if ( $results['success'] ) {
		wp_send_json_success( $results );
	} else {
		wp_send_json_error( $results );
	}
}
add_action( 'wp_ajax_import_products_batch', 'handle_import_products_batch_ajax' );

/**
 * Add admin menu for importing new products from CSV.
 */
function add_import_products_admin_menu() {
	add_submenu_page(
		'woocommerce',
		'Import New Products from CSV',
		'Import New Products',
		'manage_woocommerce',
		'import-new-products-csv',
		'render_import_products_admin_page'
	);
}
add_action( 'admin_menu', 'add_import_products_admin_menu' );

/**
 * Enqueue script for batch processing on the import page.
 */
function skiff_import_products_enqueue_scripts( $hook ) {
	if ( strpos( $hook, 'import-new-products-csv' ) === false ) {
		return;
	}
	wp_enqueue_script(
		'skiff-import-products',
		get_stylesheet_directory_uri() . '/inc/import-new-products-from-csv.js',
		array( 'jquery' ),
		'1.0.0',
		true
	);
	wp_localize_script( 'skiff-import-products', 'skiffImportProducts', array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'import_products_csv_nonce' ),
		'batchSize' => SKIFF_IMPORT_PRODUCTS_BATCH_SIZE,
	) );
}
add_action( 'admin_enqueue_scripts', 'skiff_import_products_enqueue_scripts' );

/**
 * Render admin page for importing new products.
 */
function render_import_products_admin_page() {
	?>
	<div class="wrap">
		<h1>Import New Products from CSV</h1>
		<p>Creates a new WooCommerce product for every CSV row whose SKU <strong>doesn't already exist</strong>. Existing SKUs are left untouched - use <strong>Update Stock CSV</strong> for those.</p>

		<div id="skiff-import-results-wrap" style="display: none;"></div>

		<div id="skiff-import-progress-wrap" style="display: none; margin: 20px 0;">
			<div style="position: relative; margin-bottom: 4px;">
				<span id="skiff-import-progress-percent" style="font-size: 13px; font-weight: 600; color: #1d2327;">0%</span>
			</div>
			<div style="background: #c5d9ed; border-radius: 4px; overflow: hidden; height: 24px;">
				<div id="skiff-import-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
			</div>
			<p id="skiff-import-progress-text" style="margin: 8px 0 0; padding: 10px 12px; background: #f6f7f7; border-radius: 4px; color: #50575e; font-size: 13px;"></p>
		</div>

		<div class="card">
			<h2>Import Settings</h2>
			<form id="skiff-import-products-form" method="post" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'import_products_csv_action', 'import_products_csv_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="csv_upload">Upload CSV File</label>
						</th>
						<td>
							<input type="file"
								   id="csv_upload"
								   name="csv_upload"
								   accept=".csv,text/csv,text/plain"
								   class="regular-text">
							<p class="description">
								Large files are processed in batches of <?php echo esc_html( SKIFF_IMPORT_PRODUCTS_BATCH_SIZE ); ?> to avoid timeouts.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="dry_run">Dry Run</label>
						</th>
						<td>
							<label>
								<input type="checkbox" id="dry_run" name="dry_run" value="1">
								Run in test mode (no products will be created)
							</label>
							<p class="description">
								Check this to preview which SKUs would be created without actually creating them.
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit"
						   name="import_products"
						   id="skiff-import-submit"
						   class="button button-primary"
						   value="Import New Products">
				</p>
			</form>
		</div>

		<div class="card" style="margin-top: 20px;">
			<h2>CSV Format Requirements</h2>
			<p>The CSV file should have the following columns:</p>
			<ul>
				<li><strong>sku</strong> - Product SKU (required). Rows whose SKU already exists in WooCommerce are skipped.</li>
				<li><strong>name</strong> - Product title (required)</li>
				<li><strong>category</strong> - Product category name (optional; matched by name, created if it doesn't exist)</li>
				<li><strong>brand</strong> - Brand attribute value (optional; matched by name against the "Brand" attribute, created if it doesn't exist)</li>
				<li><strong>unit</strong> - Stored on the product for reference only (optional; not shown on the storefront)</li>
				<li><strong>price</strong> - Regular price (optional, numeric value; skipped if blank or non-numeric)</li>
				<li><strong>sale_price</strong> - Sale price, applied only when it's lower than "price" (optional)</li>
				<li><strong>stock</strong> - Numeric value used only to set stock status: greater than 0 (or blank) sets in stock, otherwise out of stock. No exact quantity is stored.</li>
			</ul>
			<p><strong>Note:</strong> New products are created as published simple products. Nothing about existing products is changed by this tool.</p>
		</div>
	</div>
	<?php
}
