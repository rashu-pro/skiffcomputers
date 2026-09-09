<?php
/**
 * Single Product title
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/title.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see        https://docs.woothemes.com/document/template-structure/
 * @author     WooThemes
 * @package    WooCommerce/Templates
 * @version    4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

global $product;
$is_quick_view = woodmart_loop_prop( 'is_quick_view' );
?>

<h1 itemprop="name" class="product_title wd-entities-title">
	<?php if ( $is_quick_view ) : ?>
		<a href="<?php the_permalink(); ?>">
	<?php endif; ?>

		<div class="title-holder">
            <?php echo get_the_title(); ?>
            <div class="product_meta">

                <?php do_action( 'woocommerce_product_meta_start' ); ?>


                <?php if ( wc_product_sku_enabled() && ( $product->get_sku() || $product->is_type( 'variable' ) ) ) : ?>
                    <?php $sku = $product->get_sku(); ?>

                    <span class="meta-single sku_wrapper"><?php esc_html_e( 'SKU:', 'woocommerce' ); ?> <span class="sku"><?php echo true == $sku ? $sku : esc_html__( 'N/A', 'woocommerce' ); ?></span></span>
                <?php endif; ?>

                <?php echo wc_get_product_tag_list( $product->get_id(), '<span class="meta-sep">,</span> ', '<span class="tagged_as">' . _n( 'Tag:', 'Tags:', count( $product->get_tag_ids() ), 'woocommerce' ) . ' ', '</span>' ); ?>

                <?php do_action( 'woocommerce_product_meta_end' ); ?>

            </div>
        </div>

	<?php if ( $is_quick_view ) : ?>
		</a>
	<?php endif; ?>
</h1>
