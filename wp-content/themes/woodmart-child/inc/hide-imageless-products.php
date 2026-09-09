<?php
/**
 * Hide products with no featured image from the catalog and search, while
 * keeping them reachable by direct link and fully editable in admin.
 *
 * A product is considered "no image" when it has no featured image set
 * (WC_Product::get_image_id()) - this is what the shop/category grid uses,
 * regardless of gallery images.
 *
 * @package Woodmart_Child
 */

define( 'SKIFF_HIDDEN_FOR_NO_IMAGE_META', '_skiff_hidden_for_no_image' );
define( 'SKIFF_VISIBILITY_BEFORE_HIDE_META', '_skiff_visibility_before_hide' );

/**
 * Hide a product (saving its prior catalog visibility so it can be restored)
 * if it has no featured image, or restore it once an image is added back -
 * but only if we're the ones who hid it, so a manually-hidden product with
 * an image is left alone.
 *
 * @param int             $product_id Product ID.
 * @param WC_Product|null $product    Product object, if already available.
 */
function skiff_sync_product_visibility_for_image( $product_id, $product = null ) {
	static $processing = array();
	if ( isset( $processing[ $product_id ] ) ) {
		return;
	}

	if ( ! ( $product instanceof WC_Product ) ) {
		$product = wc_get_product( $product_id );
	}
	if ( ! $product ) {
		return;
	}

	$has_image          = (bool) $product->get_image_id();
	$current_visibility = $product->get_catalog_visibility();
	$we_hid_it          = $product->get_meta( SKIFF_HIDDEN_FOR_NO_IMAGE_META ) === 'yes';
	$needs_save         = false;

	if ( ! $has_image ) {
		if ( 'hidden' !== $current_visibility ) {
			$product->update_meta_data( SKIFF_VISIBILITY_BEFORE_HIDE_META, $current_visibility );
			$product->update_meta_data( SKIFF_HIDDEN_FOR_NO_IMAGE_META, 'yes' );
			$product->set_catalog_visibility( 'hidden' );
			$needs_save = true;
		}
	} elseif ( $we_hid_it ) {
		$restore_to = $product->get_meta( SKIFF_VISIBILITY_BEFORE_HIDE_META );
		$product->set_catalog_visibility( $restore_to ? $restore_to : 'visible' );
		$product->delete_meta_data( SKIFF_HIDDEN_FOR_NO_IMAGE_META );
		$product->delete_meta_data( SKIFF_VISIBILITY_BEFORE_HIDE_META );
		$needs_save = true;
	}

	if ( $needs_save ) {
		$processing[ $product_id ] = true;
		$product->save();
		unset( $processing[ $product_id ] );
	}
}
add_action( 'woocommerce_new_product', 'skiff_sync_product_visibility_for_image', 10, 2 );
add_action( 'woocommerce_update_product', 'skiff_sync_product_visibility_for_image', 10, 2 );

/**
 * One-time pass over the existing catalog so products that already lack an
 * image are hidden immediately, not only the next time they're saved.
 */
function skiff_hide_imageless_products_initial_run() {
	if ( get_option( 'skiff_hide_imageless_products_migrated' ) ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$product_ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $product_ids as $product_id ) {
		skiff_sync_product_visibility_for_image( $product_id );
	}

	update_option( 'skiff_hide_imageless_products_migrated', 1 );
}
add_action( 'admin_init', 'skiff_hide_imageless_products_initial_run' );
