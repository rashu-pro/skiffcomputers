<?php
/**
 * Product stock update from CSV
 *
 * @package Woodmart_Child
 */

define( 'SKIFF_STOCK_UPDATE_BATCH_SIZE', 100 );

/**
 * Get total data row count in CSV (excluding header)
 *
 * @param string $csv_file_path Path to the CSV file.
 * @return int|false Row count or false on error.
 */
function get_csv_stock_row_count( $csv_file_path ) {
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
 * Update product stock and price from CSV file - batch processing.
 * The CSV's "price" column is purchase/cost price and is ignored; the
 * "sale price" column is the actual selling price and is written to the
 * product's regular price.
 *
 * @param string $csv_file_path Path to the CSV file.
 * @param int    $offset       Row offset (0-based, after header).
 * @param int    $batch_size   Number of rows to process.
 * @param bool   $dry_run      If true, only logs what would be updated.
 * @return array Results array with updated, not_found, errors, and has_more flag.
 */
function update_product_stock_from_csv_batch( $csv_file_path, $offset = 0, $batch_size = SKIFF_STOCK_UPDATE_BATCH_SIZE, $dry_run = false ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return array(
			'success'   => false,
			'error'     => 'WooCommerce is not active',
			'updated'   => 0,
			'not_found' => array(),
			'errors'    => array(),
			'has_more'  => false,
		);
	}

	if ( ! file_exists( $csv_file_path ) ) {
		return array(
			'success'   => false,
			'error'     => 'CSV file not found',
			'updated'   => 0,
			'not_found' => array(),
			'errors'    => array(),
			'has_more'  => false,
		);
	}

	$handle = fopen( $csv_file_path, 'r' );
	if ( $handle === false ) {
		return array(
			'success'   => false,
			'error'     => 'Could not open CSV file',
			'updated'   => 0,
			'not_found' => array(),
			'errors'    => array(),
			'has_more'  => false,
		);
	}

	$header = fgetcsv( $handle );
	if ( $header === false ) {
		fclose( $handle );
		return array(
			'success'   => false,
			'error'     => 'Could not read CSV header',
			'updated'   => 0,
			'not_found' => array(),
			'errors'    => array(),
			'has_more'  => false,
		);
	}

	$header_lower     = array_map( 'strtolower', $header );
	$sku_index        = array_search( 'sku', $header_lower );
	$stock_index      = array_search( 'stock', $header_lower );
	$name_index       = array_search( 'name', $header_lower );
	// The CSV's "price" column is purchase/cost price - not used for the storefront price.
	// "sale price" is the actual selling price and is written to the product's regular price.
	$selling_price_index = array_search( 'sale price', $header_lower );

	if ( $sku_index === false || $stock_index === false ) {
		fclose( $handle );
		return array(
			'success'   => false,
			'error'     => 'SKU or stock column not found in CSV',
			'updated'   => 0,
			'not_found' => array(),
			'errors'    => array(),
			'has_more'  => false,
		);
	}

	$max_col = max( $sku_index, $stock_index );
	if ( $name_index !== false ) {
		$max_col = max( $max_col, $name_index );
	}
	if ( $selling_price_index !== false ) {
		$max_col = max( $max_col, $selling_price_index );
	}

	// Skip to offset
	for ( $i = 0; $i < $offset && ( fgetcsv( $handle ) !== false ); $i++ ) {
		// Skip rows.
	}

	$results = array(
		'success'    => true,
		'updated'    => 0,
		'not_found'  => array(),
		'errors'     => array(),
		'report_rows' => array(),
		'has_more'   => false,
	);

	$processed = 0;
	while ( $processed < $batch_size && ( $row = fgetcsv( $handle ) ) !== false ) {
		$line_number = $offset + $processed + 1; // +1 for header row
		$processed++;

		if ( empty( $row ) || count( $row ) <= $max_col ) {
			continue;
		}

		$sku   = trim( $row[ $sku_index ] );
		$stock = trim( $row[ $stock_index ] );
		$csv_name          = $name_index !== false ? trim( $row[ $name_index ] ) : '';
		$csv_selling_price = $selling_price_index !== false ? trim( $row[ $selling_price_index ] ) : '';

		if ( empty( $sku ) ) {
			continue;
		}

		$stock_quantity = is_numeric( $stock ) ? intval( $stock ) : null;
		if ( $stock_quantity === null ) {
			$results['errors'][]       = array( 'line' => $line_number, 'sku' => $sku, 'error' => 'Invalid stock value: ' . $stock );
			$results['report_rows'][]  = array(
				'product_name' => $csv_name,
				'sku'          => $sku,
				'price'        => $csv_selling_price,
				'is_updated'   => 'false',
				'sku_found'    => 'false',
			);
			continue;
		}

		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			$results['not_found'][]    = array( 'line' => $line_number, 'sku' => $sku );
			$results['report_rows'][]  = array(
				'product_name' => $csv_name,
				'sku'          => $sku,
				'price'        => $csv_selling_price,
				'is_updated'   => 'false',
				'sku_found'    => 'false',
			);
			continue;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$results['errors'][]      = array( 'line' => $line_number, 'sku' => $sku, 'product_id' => $product_id, 'error' => 'Product could not be loaded' );
			$results['report_rows'][] = array(
				'product_name' => $csv_name,
				'sku'          => $sku,
				'price'        => $csv_selling_price,
				'is_updated'   => 'false',
				'sku_found'    => 'true',
			);
			continue;
		}

		$product_name = $product->get_name();

		if ( ! $dry_run ) {
			$product->set_stock_quantity( $stock_quantity );
			$product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );

			if ( $csv_selling_price !== '' && is_numeric( $csv_selling_price ) ) {
				$product->set_regular_price( $csv_selling_price );
			}

			$product->save();
		}

		$results['updated']++;
		$results['report_rows'][] = array(
			'product_name' => $product_name,
			'sku'          => $sku,
			'price'        => $csv_selling_price,
			'is_updated'   => 'true',
			'sku_found'    => 'true',
		);
	}

	$next_row = fgetcsv( $handle );
	$results['has_more'] = $next_row !== false;
	fclose( $handle );

	return $results;
}

/**
 * Update product stock and price (from the "sale price" column) from CSV file based on SKU (full file - use batch for large files)
 *
 * @param string $csv_file_path Path to the CSV file (relative to theme directory or absolute path)
 * @param bool   $dry_run       If true, only logs what would be updated without actually updating
 * @return array Results array with updated products, not found SKUs, and errors
 */
function update_product_stock_from_csv( $csv_file_path = 'skiff-product-stock.csv', $dry_run = false ) {
	// Check if WooCommerce is active
	if ( ! class_exists( 'WooCommerce' ) ) {
		return array(
			'success' => false,
			'error'   => 'WooCommerce is not active',
			'updated' => 0,
			'not_found' => 0,
			'errors'  => array(),
		);
	}

	// Get the full path to the CSV file
	if ( ! file_exists( $csv_file_path ) ) {
		// Try relative to theme directory
		$csv_file_path = get_stylesheet_directory() . '/' . $csv_file_path;
	}

	if ( ! file_exists( $csv_file_path ) ) {
		return array(
			'success'   => false,
			'error'     => 'CSV file not found: ' . $csv_file_path,
			'updated'   => 0,
			'not_found' => 0,
			'errors'    => array(),
		);
	}

	$results = array(
		'success'          => true,
		'updated'          => 0,
		'not_found'        => array(),
		'errors'           => array(),
		'updated_products' => array(),
	);

	// Open the CSV file
	$handle = fopen( $csv_file_path, 'r' );
	if ( $handle === false ) {
		return array(
			'success'   => false,
			'error'     => 'Could not open CSV file',
			'updated'   => 0,
			'not_found' => 0,
			'errors'    => array(),
		);
	}

	// Read the header row
	$header = fgetcsv( $handle );
	if ( $header === false ) {
		fclose( $handle );
		return array(
			'success'   => false,
			'error'     => 'Could not read CSV header',
			'updated'   => 0,
			'not_found' => 0,
			'errors'    => array(),
		);
	}

	// Find the index of SKU, stock, and sale price columns.
	// The CSV's "price" column is purchase/cost price and is ignored; "sale price"
	// is the actual selling price and is written to the product's regular price.
	$header_lower         = array_map( 'strtolower', $header );
	$sku_index            = array_search( 'sku', $header_lower );
	$stock_index          = array_search( 'stock', $header_lower );
	$selling_price_index  = array_search( 'sale price', $header_lower );

	if ( $sku_index === false ) {
		fclose( $handle );
		return array(
			'success'   => false,
			'error'     => 'SKU column not found in CSV',
			'updated'   => 0,
			'not_found' => 0,
			'errors'    => array(),
		);
	}

	if ( $stock_index === false ) {
		fclose( $handle );
		return array(
			'success'   => false,
			'error'     => 'Stock column not found in CSV',
			'updated'   => 0,
			'not_found' => 0,
			'errors'    => array(),
		);
	}

	$line_number = 1; // Start at 1 since we already read the header

	// Read each row
	while ( ( $row = fgetcsv( $handle ) ) !== false ) {
		$line_number++;

		// Skip empty rows
		$max_col = max( $sku_index, $stock_index );
		if ( $selling_price_index !== false ) {
			$max_col = max( $max_col, $selling_price_index );
		}
		if ( empty( $row ) || count( $row ) <= $max_col ) {
			continue;
		}

		$sku               = trim( $row[ $sku_index ] );
		$stock             = trim( $row[ $stock_index ] );
		$csv_selling_price = $selling_price_index !== false ? trim( $row[ $selling_price_index ] ) : '';

		// Skip if SKU is empty
		if ( empty( $sku ) ) {
			continue;
		}

		// Validate stock value
		$stock_quantity = is_numeric( $stock ) ? intval( $stock ) : null;
		if ( $stock_quantity === null ) {
			$results['errors'][] = array(
				'line'  => $line_number,
				'sku'   => $sku,
				'error' => 'Invalid stock value: ' . $stock,
			);
			continue;
		}

		// Find product by SKU
		$product_id = wc_get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			$results['not_found'][] = array(
				'line' => $line_number,
				'sku'  => $sku,
			);
			continue;
		}

		// Get the product object
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			$results['errors'][] = array(
				'line'        => $line_number,
				'sku'         => $sku,
				'product_id'  => $product_id,
				'error'       => 'Product object could not be loaded',
			);
			continue;
		}

		// Get current stock for logging
		$current_stock = $product->get_stock_quantity();

		if ( ! $dry_run ) {
			// Update stock quantity
			$product->set_stock_quantity( $stock_quantity );

			// Set stock status based on quantity
			if ( $stock_quantity > 0 ) {
				$product->set_stock_status( 'instock' );
			} else {
				$product->set_stock_status( 'outofstock' );
			}

			// Update price from the CSV's "sale price" column (the actual selling price), if present
			if ( $csv_selling_price !== '' && is_numeric( $csv_selling_price ) ) {
				$product->set_regular_price( $csv_selling_price );
			}

			// Save the product
			$product->save();
		}

		$results['updated']++;
		$results['updated_products'][] = array(
			'line'         => $line_number,
			'sku'          => $sku,
			'product_id'   => $product_id,
			'product_name' => $product->get_name(),
			'old_stock'    => $current_stock,
			'new_stock'    => $stock_quantity,
		);

		// Log the update
		error_log(
			sprintf(
				'Stock update %s: SKU %s (Product ID: %d) - Stock: %d -> %d',
				$dry_run ? '[DRY RUN]' : '',
				$sku,
				$product_id,
				$current_stock,
				$stock_quantity
			)
		);
	}

	fclose( $handle );

	// Log summary
	error_log(
		sprintf(
			'CSV Stock Update %s: %d products updated, %d SKUs not found, %d errors',
			$dry_run ? '[DRY RUN]' : '',
			$results['updated'],
			count( $results['not_found'] ),
			count( $results['errors'] )
		)
	);

	return $results;
}

/**
 * Get temp directory for CSV uploads
 */
function skiff_stock_update_temp_dir() {
	$upload_dir = wp_upload_dir();
	$temp_dir   = $upload_dir['basedir'] . '/stock-update-temp';
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
 * Validate that path is within our temp directory
 */
function skiff_stock_update_validate_temp_path( $path ) {
	$real_path = realpath( $path );
	$temp_dir  = realpath( skiff_stock_update_temp_dir() );
	return $real_path && $temp_dir && strpos( $real_path, $temp_dir ) === 0;
}

/**
 * AJAX: Upload CSV and prepare for batch processing
 */
function handle_stock_update_upload_ajax() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'update_stock_csv_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ) );
	}
	if ( empty( $_FILES['csv_upload']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_upload']['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => 'Please upload a CSV file.' ) );
	}

	$file     = $_FILES['csv_upload'];
	$file_type = wp_check_filetype( $file['name'] );
	$is_csv   = in_array( $file['type'], array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' ) ) || $file_type['ext'] === 'csv';
	if ( ! $is_csv || $file['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( array( 'message' => 'Invalid file. Please upload a valid CSV file.' ) );
	}

	$temp_dir  = skiff_stock_update_temp_dir();
	$temp_name = 'stock_' . wp_unique_id() . '.csv';
	$temp_path = $temp_dir . '/' . $temp_name;

	if ( ! move_uploaded_file( $file['tmp_name'], $temp_path ) ) {
		wp_send_json_error( array( 'message' => 'Could not save uploaded file.' ) );
	}

	$total_rows = get_csv_stock_row_count( $temp_path );
	if ( $total_rows === false ) {
		@unlink( $temp_path );
		wp_send_json_error( array( 'message' => 'Could not read CSV file.' ) );
	}

	wp_send_json_success( array(
		'temp_path'   => $temp_path,
		'total_rows'  => $total_rows,
		'batch_size'  => SKIFF_STOCK_UPDATE_BATCH_SIZE,
	) );
}
add_action( 'wp_ajax_stock_update_upload', 'handle_stock_update_upload_ajax' );

/**
 * AJAX: Process one batch
 */
function handle_stock_update_batch_ajax() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'update_stock_csv_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed' ) );
	}

	$temp_path = isset( $_POST['temp_path'] ) ? sanitize_text_field( $_POST['temp_path'] ) : '';
	$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$dry_run   = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';

	if ( ! skiff_stock_update_validate_temp_path( $temp_path ) ) {
		wp_send_json_error( array( 'message' => 'Invalid file path.' ) );
	}

	$results = update_product_stock_from_csv_batch( $temp_path, $offset, SKIFF_STOCK_UPDATE_BATCH_SIZE, $dry_run );

	if ( $results['success'] && ! $results['has_more'] ) {
		@unlink( $temp_path );
	}

	if ( $results['success'] ) {
		wp_send_json_success( $results );
	} else {
		wp_send_json_error( $results );
	}
}
add_action( 'wp_ajax_stock_update_batch', 'handle_stock_update_batch_ajax' );

/**
 * Add admin menu for stock update
 */
function add_stock_update_admin_menu() {
	add_submenu_page(
		'woocommerce',
		'Update Stock from CSV',
		'Update Stock CSV',
		'manage_woocommerce',
		'update-stock-csv',
		'render_stock_update_admin_page'
	);
}
add_action( 'admin_menu', 'add_stock_update_admin_menu' );

/**
 * Enqueue script for batch processing on stock update page
 */
function skiff_stock_update_enqueue_scripts( $hook ) {
	if ( strpos( $hook, 'update-stock-csv' ) === false ) {
		return;
	}
	wp_enqueue_script(
		'skiff-stock-update',
		get_stylesheet_directory_uri() . '/inc/stock-update-csv.js',
		array( 'jquery' ),
		'1.0.0',
		true
	);
	wp_localize_script( 'skiff-stock-update', 'skiffStockUpdate', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'update_stock_csv_nonce' ),
		'batchSize' => SKIFF_STOCK_UPDATE_BATCH_SIZE,
	) );
}
add_action( 'admin_enqueue_scripts', 'skiff_stock_update_enqueue_scripts' );

/**
 * Render admin page for stock update
 */
function render_stock_update_admin_page() {
	?>
	<div class="wrap">
		<h1>Update Product Stock from CSV</h1>

		<div id="skiff-stock-results-wrap" style="display: none;"></div>

		<div id="skiff-stock-progress-wrap" style="display: none; margin: 20px 0;">
			<div style="position: relative; margin-bottom: 4px;">
				<span id="skiff-stock-progress-percent" style="font-size: 13px; font-weight: 600; color: #1d2327;">0%</span>
			</div>
			<div style="background: #c5d9ed; border-radius: 4px; overflow: hidden; height: 24px;">
				<div id="skiff-stock-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
			</div>
			<p id="skiff-stock-progress-text" style="margin: 8px 0 0; padding: 10px 12px; background: #f6f7f7; border-radius: 4px; color: #50575e; font-size: 13px;"></p>
		</div>

		<div class="card">
			<h2>Stock Update Settings</h2>
			<form id="skiff-stock-update-form" method="post" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'update_stock_csv_action', 'update_stock_csv_nonce' ); ?>

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
								Upload a CSV file with sku and stock columns. Large files (1000+ rows) are processed in batches of <?php echo esc_html( SKIFF_STOCK_UPDATE_BATCH_SIZE ); ?> to avoid timeouts.
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
								Run in test mode (no actual updates will be made)
							</label>
							<p class="description">
								Check this to preview what would be updated without actually updating products.
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit"
						   name="update_stock"
						   id="skiff-stock-submit"
						   class="button button-primary"
						   value="Update Stock from CSV">
				</p>
			</form>
		</div>

		<div class="card" style="margin-top: 20px;">
			<h2>CSV Format Requirements</h2>
			<p>The CSV file should have the following columns:</p>
			<ul>
				<li><strong>sku</strong> - Product SKU (required, used for matching)</li>
				<li><strong>stock</strong> - Stock quantity (required, numeric value)</li>
				<li><strong>sale price</strong> - Selling price, written to the product's price (optional, numeric value; skipped if blank or non-numeric)</li>
			</ul>
			<p><em>Note: the CSV's "price" column is purchase/cost price and is not used to update the product.</em></p>
			<p><strong>Note:</strong> Products are matched by SKU and updated in batches. Large files are processed automatically without timeout issues.</p>
		</div>
	</div>
	<?php
}
