<?php
/**
 * Include all PHP files from inc directory
 *
 * @package Woodmart_Child
 */

$inc_dir = __DIR__;

$files = array_merge(
	array_filter( (array) glob( $inc_dir . '/*.php' ) ),
	array_filter( (array) glob( $inc_dir . '/*/*.php' ) )
);

foreach ( $files as $file ) {
	// Skip self and config files (they require WOODMART_THEME_DIR and are loaded by parent theme)
	if ( basename( $file ) === '_include.php' ) {
		continue;
	}
	if ( strpos( $file, 'configs' ) !== false ) {
		continue;
	}
	require_once $file;
}
