<?php
/**
 * 11.11 "Shop by Category" list (desktop: sticky sidebar; mobile: horizontal chip bar).
 *
 * Links are real `?cat=slug` URLs, so they work (and are shareable) without JavaScript;
 * js/offer-1111.js intercepts clicks and swaps the grid over AJAX.
 *
 * @var array $args { current: string slug ('' = featured), categories: array[] }
 * @package Woodmart_Child
 */

defined( 'ABSPATH' ) || exit;

$s        = skiff_1111_settings();
$current  = $args['current'];
$base_url = get_permalink();
$entries  = array_merge(
	array(
		array(
			'slug' => '',
			'name' => $s['featured_title'],
		),
	),
	$args['categories']
);
?>
<nav class="skiff-1111-cats" aria-label="Shop by Category">
	<h2 class="skiff-1111-cats__title">Shop by Category</h2>
	<ul class="skiff-1111-cats__list">
		<?php foreach ( $entries as $entry ) : ?>
			<?php
			$is_current = $entry['slug'] === $current;
			$url        = '' === $entry['slug'] ? $base_url : add_query_arg( 'cat', $entry['slug'], $base_url );
			?>
			<li>
				<a class="skiff-1111-cats__link<?php echo $is_current ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $url ); ?>"
					data-cat="<?php echo esc_attr( $entry['slug'] ); ?>"
					<?php echo $is_current ? 'aria-current="true"' : ''; ?>>
					<?php echo esc_html( $entry['name'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
