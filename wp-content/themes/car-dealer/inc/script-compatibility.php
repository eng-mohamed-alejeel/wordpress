<?php
/** Protect WordPress's script dependencies wherever they are actually loaded. */
defined( 'ABSPATH' ) || exit;

function car_dealer_preserve_wordpress_underscore( $scripts ) {
	if ( ! isset( $scripts->registered['underscore'] ) || $scripts->get_data( 'underscore', 'car_dealer_protected' ) ) {
		return;
	}
	$file = get_template_directory() . '/assets/js/wordpress-underscore.js';
	if ( ! is_readable( $file ) ) { return; }
	if ( $scripts->add_inline_script( 'underscore', file_get_contents( $file ), 'after' ) ) {
		$scripts->add_data( 'underscore', 'car_dealer_protected', true );
	}
}
add_action( 'wp_default_scripts', 'car_dealer_preserve_wordpress_underscore', 20 );

// A plugin may have initialized the script registry before the theme was loaded.
if ( isset( $GLOBALS['wp_scripts'] ) && $GLOBALS['wp_scripts'] instanceof WP_Scripts ) {
	car_dealer_preserve_wordpress_underscore( $GLOBALS['wp_scripts'] );
}
