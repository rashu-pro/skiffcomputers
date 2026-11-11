<?php
/**
 * 11.11 product card.
 *
 * The product link is "stretched" over the whole card with CSS (::after), so the whole card
 * opens the product page. No add-to-cart button on this page.
 *
 * @var array $args { product: WC_Product, price: array|null from skiff_1111_price_data() }
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

/** @var WC_Product $product */
$product   = $args['product'];
$price     = $args['price'];
$permalink = $product->get_permalink();
?>
<div class="skiff-1111-card">
	<?php if ( $price && $price['save'] > 0 ) : ?>
		<span class="skiff-1111-card__badge">
			Save: <?php echo wp_kses_post( wc_price( $price['save'], array( 'decimals' => 0 ) ) ); ?> (-<?php echo (int) $price['pct']; ?>%)
		</span>
	<?php endif; ?>
	<?php if ( $price && $price['scheduled'] ) : ?>
		<span class="skiff-1111-card__flag" title="Visible to shop managers only until the campaign starts">Scheduled</span>
	<?php endif; ?>

	<div class="skiff-1111-card__media">
		<?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>

	<h3 class="skiff-1111-card__name">
		<a class="skiff-1111-card__link" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
	</h3>

	<div class="skiff-1111-card__price">
		<?php if ( $price ) : ?>
			<ins><?php echo wp_kses_post( wc_price( $price['sale'] ) ); ?></ins>
			<del aria-label="Regular price"><?php echo wp_kses_post( wc_price( $price['regular'] ) ); ?></del>
		<?php else : ?>
			<?php echo wp_kses_post( $product->get_price_html() ); ?>
		<?php endif; ?>
	</div>
</div>
