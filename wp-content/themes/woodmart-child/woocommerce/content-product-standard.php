<?php
/**
 * The template for displaying product content in the standard loop
 *
 * Based on Woodmart 8.6.2 content-product-standard.php. Skiff customizations:
 * - archive cards get the `product-wrapper-archive` class and a short excerpt under the title
 * - star rating is not shown
 * - on archives the compare button sits under the add-to-cart button; elsewhere compare/quick view
 *   buttons are shown under the add-to-cart button instead of over the image
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.6.0
 */

global $product;

do_action( 'woocommerce_before_shop_loop_item' );

$product_class = is_archive() ? ' product-wrapper-archive' : '';
?>

<div class="wd-product-wrapper product-wrapper<?php echo esc_attr( $product_class ); ?>">
	<div class="wd-product-thumb product-element-top wd-quick-shop">
		<a href="<?php echo esc_url( get_permalink() ); ?>" class="wd-product-img-link product-image-link" tabindex="-1" aria-label="<?php echo esc_attr( get_the_title() ); ?>">
			<?php
			/**
			 * Hook woocommerce_before_shop_loop_item_title.
			 *
			 * @hooked woodmart_template_loop_product_thumbnails_gallery - 5
			 * @hooked woocommerce_show_product_loop_sale_flash - 10
			 * @hooked woodmart_template_loop_product_thumbnail - 10
			 */
			do_action( 'woocommerce_before_shop_loop_item_title' );
			?>
		</a>

		<?php
		do_action( 'woodmart_shop_loop_product_thumbnail' );

		if ( 'no' === woodmart_loop_prop( 'grid_gallery' ) || ! woodmart_loop_prop( 'grid_gallery' ) ) {
			woodmart_hover_image();
		}
		?>
	</div>

	<div class="product-element-bottom">
		<?php
			echo woodmart_swatches_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<?php
			/**
			 * Trigger woocommerce_shop_loop_item_title hook.
			 *
			 * @hooked woocommerce_template_loop_product_title - 10
			 */
			do_action( 'woocommerce_shop_loop_item_title' );

			if ( is_archive() ) {
				echo '<div class="short-info">' . do_shortcode( get_the_excerpt() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		?>

		<?php
			woodmart_product_categories();
			woodmart_product_brands_links();
			woodmart_product_sku();
			woodmart_stock_status_after_title();
		?>

		<?php
			remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );

			/**
			 * Trigger woocommerce_after_shop_loop_item_title hook.
			 *
			 * @hooked woocommerce_template_loop_price - 10
			 */
			do_action( 'woocommerce_after_shop_loop_item_title' );
		?>

		<div class="wd-add-btn wd-add-btn-replace">
			<?php if ( woodmart_loop_prop( 'product_quantity' ) ) : ?>
				<?php woodmart_product_quantity( $product ); ?>
			<?php endif ?>

			<?php do_action( 'woodmart_add_loop_btn' ); ?>
		</div>

		<?php if ( is_archive() ) : ?>
			<div class="btn-after-btn">
				<?php woodmart_add_to_compare_loop_btn(); ?>
			</div>
		<?php else : ?>
			<div class="btn-extra">
				<?php woodmart_enqueue_js_script( 'btns-tooltip' ); ?>
				<?php woodmart_add_to_compare_loop_btn(); ?>
				<?php woodmart_quick_view_btn( get_the_ID() ); ?>
				<?php do_action( 'woodmart_product_action_buttons' ); ?>
			</div>
		<?php endif; ?>

		<?php do_action( 'woocommerce_after_shop_loop_item' ); ?>

		<?php if ( woodmart_loop_prop( 'progress_bar' ) ) : ?>
			<?php woodmart_stock_progress_bar(); ?>
		<?php endif ?>

		<?php if ( woodmart_loop_prop( 'timer' ) ) : ?>
			<?php woodmart_product_sale_countdown( array( 'products_hover' => 'standard' ) ); ?>
		<?php endif ?>
	</div>
</div>
