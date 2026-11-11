<?php
/**
 * Template Name: 11.11 Offer
 * Template Post Type: page
 *
 * 11.11 Mega Sale landing page. Logic lives in inc/offer-1111.php.
 *
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$skiff_cat = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
if ( '' !== $skiff_cat && ! skiff_1111_find_category( $skiff_cat ) ) {
	$skiff_cat = ''; // Unknown / emptied category → fall back to Featured.
}
$skiff_result = skiff_1111_query( $skiff_cat, 1 );
$skiff_cards  = skiff_1111_render_cards( $skiff_result['items'] );
?>

<div class="wd-content-area site-content skiff-1111"
	data-skiff-1111
	data-cat="<?php echo esc_attr( $skiff_cat ); ?>"
	data-page="1"
	data-page-url="<?php echo esc_url( get_permalink() ); ?>"
	data-after-end="<?php echo esc_attr( skiff_1111_settings()['after_end'] ); ?>"
	data-shop-url="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">

	<?php get_template_part( 'template-parts/offer-1111/hero' ); ?>

	<div class="skiff-1111__body">
		<aside class="skiff-1111__sidebar">
			<?php
			get_template_part(
				'template-parts/offer-1111/sidebar',
				null,
				array(
					'current'    => $skiff_cat,
					'categories' => skiff_1111_get_categories(),
				)
			);
			?>
		</aside>

		<section class="skiff-1111__main" aria-labelledby="skiff-1111-section-title">
			<header class="skiff-1111__head">
				<h2 id="skiff-1111-section-title" class="skiff-1111__title" data-role="title"><?php echo esc_html( $skiff_result['title'] ); ?></h2>
				<p class="skiff-1111__subtitle" data-role="subtitle"><?php echo esc_html( $skiff_result['subtitle'] ); ?></p>
			</header>

			<div class="skiff-1111__grid" data-role="grid" aria-live="polite" aria-busy="false">
				<?php echo '' !== trim( $skiff_cards ) ? $skiff_cards : skiff_1111_empty_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>

			<div class="skiff-1111__more">
				<button type="button" class="button skiff-1111__more-btn" data-role="more" <?php echo $skiff_result['has_more'] ? '' : 'hidden'; ?>>Load more</button>
			</div>
		</section>
	</div>

	<?php get_template_part( 'template-parts/offer-1111/terms' ); ?>

	<?php
	while ( have_posts() ) :
		the_post();
		if ( '' !== trim( get_the_content() ) ) :
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry-content skiff-1111__content' ); ?>>
				<?php the_content(); ?>
			</article>
			<?php
		endif;
	endwhile;
	?>
</div>

<?php
get_footer();
