<?php
/**
 * Enqueue script and styles for child theme
 */

require_once get_stylesheet_directory() . '/inc/_include.php';

function woodmart_child_enqueue_styles() {
	wp_enqueue_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', array( 'woodmart-style' ), woodmart_get_theme_info( 'Version' ) );
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