<?php
/**
 * 11.11 countdown. Used by the hero and by the [skiff_1111_countdown] shortcode.
 *
 * The target times are UTC timestamps in data attributes; js/offer-1111.js picks the state
 * (upcoming → counts to start, running → counts to end, ended) from the visitor's clock, so a
 * cached copy of the page never shows a frozen or wrong countdown.
 *
 * @var array $args { context: hero|banner|inline, title, link, button }
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

$context = isset( $args['context'] ) ? $args['context'] : 'hero';
$window  = skiff_1111_window();
$status  = skiff_1111_status();
$s       = skiff_1111_settings();
$labels  = array(
	'upcoming' => 'শুরু হতে বাকি',
	'running'  => 'অফার শেষ হতে বাকি',
);
$units   = array(
	'd' => 'দিন',
	'h' => 'ঘণ্টা',
	'm' => 'মিনিট',
	's' => 'সেকেন্ড',
);
?>
<div class="skiff-1111-countdown skiff-1111-countdown--<?php echo esc_attr( $context ); ?>"
	data-skiff-countdown
	data-start="<?php echo (int) $window['start']; ?>"
	data-end="<?php echo (int) $window['end']; ?>"
	data-status="<?php echo esc_attr( $status ); ?>"
	<?php echo 'hero' !== $context ? 'data-hide-on-end' : ''; ?>>

	<?php if ( 'banner' === $context ) : ?>
		<div class="skiff-1111-countdown__title"><?php echo esc_html( $args['title'] ); ?></div>
	<?php endif; ?>

	<div class="skiff-1111-countdown__live" <?php echo 'ended' === $status ? 'hidden' : ''; ?>>
		<div class="skiff-1111-countdown__label" data-role="label"><?php echo esc_html( 'upcoming' === $status ? $labels['upcoming'] : $labels['running'] ); ?></div>
		<div class="skiff-1111-countdown__units" role="timer" aria-live="off">
			<?php foreach ( $units as $key => $label ) : ?>
				<div class="skiff-1111-countdown__unit">
					<span class="skiff-1111-countdown__num" data-unit="<?php echo esc_attr( $key ); ?>">--</span>
					<span class="skiff-1111-countdown__unit-label"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if ( 'hero' === $context ) : ?>
		<p class="skiff-1111-countdown__ended" data-role="ended" <?php echo 'ended' === $status ? '' : 'hidden'; ?>><?php echo esc_html( $s['ended_text'] ); ?></p>
	<?php endif; ?>

	<?php if ( 'banner' === $context && ! empty( $args['link'] ) ) : ?>
		<a class="skiff-1111-countdown__cta" href="<?php echo esc_url( $args['link'] ); ?>"><?php echo esc_html( $args['button'] ); ?></a>
	<?php endif; ?>
</div>
