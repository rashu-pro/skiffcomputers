<?php
/**
 * Enqueue script and styles for child theme
 */

require_once get_stylesheet_directory() . '/inc/_include.php';

function woodmart_child_enqueue_styles() {
	wp_enqueue_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', array( 'woodmart-style' ), woodmart_get_theme_info( 'Version' ) );
	
	// Enqueue custom script for inventory check
	// wp_enqueue_script( 'pos-inventory-check', get_stylesheet_directory_uri() . '/js/pos-inventory.js', array( 'jquery' ), '1.0.0', true );
	// wp_localize_script( 'pos-inventory-check', 'pos_inventory_ajax', array(
		// 'ajax_url' => admin_url( 'admin-ajax.php' ),
		// 'nonce' => wp_create_nonce( 'pos_inventory_nonce' )
	// ) ); 
}
add_action( 'wp_enqueue_scripts', 'woodmart_child_enqueue_styles', 10010 );

/* Function for put default heading before short descriptions */
add_filter( 'woocommerce_short_description', 'woo_add_text_before_excerpt_single_product', 20, 1 );
function woo_add_text_before_excerpt_single_product( $post_excerpt ){

      $content= '<div class="short-description-header">
      <h4>'.__('KEY FEATURES').'</h4>
      </div>';
      return $content.''.$post_excerpt; 	  
}

/**
 * Check POS inventory before adding to cart
 */
// add_filter( 'woocommerce_add_to_cart_validation', 'check_pos_inventory_before_add_to_cart', 10, 5 );
function check_pos_inventory_before_add_to_cart( $passed, $product_id, $quantity, $variation_id = null, $variations = null ) {
    // Debug log to verify hook is working
    error_log( 'POS inventory check triggered for product ID: ' . $product_id . ', quantity: ' . $quantity . ', variation ID: ' . ($variation_id ?: 'none') );
    
    // Get the actual product ID (handle variations)
    $check_product_id = $variation_id ? $variation_id : $product_id;
    
    // Get current cart quantity for this product
    $cart_quantity = 0;
    if ( WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( $cart_item['product_id'] == $product_id && ( !$variation_id || $cart_item['variation_id'] == $variation_id ) ) {
                $cart_quantity += $cart_item['quantity'];
            }
        }
    }
    
    $total_quantity = $cart_quantity + $quantity;
    
    // Check POS inventory
//     $pos_stock = get_pos_inventory( $check_product_id );
    $pos_stock = false;
    
    if ( $pos_stock === false || $pos_stock < $total_quantity ) {
        wc_add_notice( __( 'Sorry, this product is out of stock or insufficient inventory.', 'woocommerce' ), 'error' );
        $passed = false;
    }
    
    return $passed;
}

/**
 * Function to get inventory from POS API
 */
function get_pos_inventory( $product_id ) {
    // Define your POS API endpoint and credentials
    $api_url = 'https://your-pos-api.com/inventory/' . $product_id; // Replace with actual API URL
    $api_key = 'your-api-key'; // Replace with actual API key
    
    // Make API request
    $response = wp_remote_get( $api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'timeout' => 10,
    ) );
    
    if ( is_wp_error( $response ) ) {
        // Log error or handle
        error_log( 'POS API Error: ' . $response->get_error_message() );
        return false;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'POS API JSON Error: ' . json_last_error_msg() );
        return false;
    }
    
    // Assuming the API returns {'stock': 10}
    return isset( $data['stock'] ) ? intval( $data['stock'] ) : false;
}

/**
 * AJAX handler to check inventory before add to cart
 */
// add_action( 'wp_ajax_check_inventory_before_cart', 'check_inventory_before_cart_ajax' );
// add_action( 'wp_ajax_nopriv_check_inventory_before_cart', 'check_inventory_before_cart_ajax' );
function check_inventory_before_cart_ajax() {
    // Check nonce
    if (!wp_verify_nonce($_POST['nonce'], 'pos_inventory_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    $product_id = intval( $_POST['product_id'] );
    $quantity = intval( $_POST['quantity'] );
    $variation_id = isset( $_POST['variation_id'] ) ? intval( $_POST['variation_id'] ) : null;
    
    if ( !$product_id || !$quantity ) {
        wp_send_json_error( 'Invalid parameters' );
    }
    
    // Get the actual product ID (handle variations)
    $check_product_id = $variation_id ? $variation_id : $product_id;
    
    // Get current cart quantity for this product
    $cart_quantity = 0;
    if ( WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( $cart_item['product_id'] == $product_id && ( !$variation_id || $cart_item['variation_id'] == $variation_id ) ) {
                $cart_quantity += $cart_item['quantity'];
            }
        }
    }
    
    $total_quantity = $cart_quantity + $quantity;
    
    // Check POS inventory
//     $pos_stock = get_pos_inventory( $check_product_id );
    $pos_stock = false;
    
    if ( $pos_stock === false || $pos_stock < $total_quantity ) {
        wp_send_json_success( array( 'out_of_stock' => true, 'available_stock' => $pos_stock ) );
    } else {
        wp_send_json_success( array( 'out_of_stock' => false ) );
    }
}