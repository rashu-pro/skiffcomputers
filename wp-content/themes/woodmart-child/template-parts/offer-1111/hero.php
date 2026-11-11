<?php
/**
 * 11.11 hero card: title, sub-headline, summary, countdown / ended message.
 *
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

$s = skiff_1111_settings();
?>
<section class="skiff-1111-hero" aria-labelledby="skiff-1111-title">
	<h1 id="skiff-1111-title" class="skiff-1111-hero__title"><?php echo esc_html( $s['title'] ); ?></h1>
	<?php if ( '' !== $s['subtitle'] ) : ?>
		<p class="skiff-1111-hero__subtitle"><?php echo esc_html( $s['subtitle'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $s['summary'] ) : ?>
		<p class="skiff-1111-hero__summary"><?php echo wp_kses( $s['summary'], skiff_1111_summary_allowed_html() ); ?></p>
	<?php endif; ?>
	<?php get_template_part( 'template-parts/offer-1111/countdown', null, array( 'context' => 'hero' ) ); ?>
</section>
