<?php
/**
 * 11.11 terms & conditions box (one condition per line in the settings).
 *
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

$s     = skiff_1111_settings();
$terms = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $s['terms'] ) ) );
if ( ! $terms ) {
	return;
}
?>
<section class="skiff-1111-terms" aria-labelledby="skiff-1111-terms-title">
	<h2 id="skiff-1111-terms-title" class="skiff-1111-terms__title"><?php echo esc_html( $s['terms_title'] ); ?></h2>
	<ul class="skiff-1111-terms__list">
		<?php foreach ( $terms as $line ) : ?>
			<li><?php echo esc_html( $line ); ?></li>
		<?php endforeach; ?>
	</ul>
</section>
