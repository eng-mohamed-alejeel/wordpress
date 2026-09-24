<?php
/** Conservative performance helpers migrated from legacy optimization ideas. */
defined( 'ABSPATH' ) || exit;

function car_dealer_performance_setup() {
	add_theme_support( 'html5', array( 'script', 'style' ) );
	add_image_size( 'car-thumb', 420, 280, true );
}
add_action( 'after_setup_theme', 'car_dealer_performance_setup' );

function car_dealer_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = array( 'href' => 'https://fonts.googleapis.com', 'crossorigin' => 'anonymous' );
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' );
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'car_dealer_resource_hints', 10, 2 );

function car_dealer_defer_theme_script( $tag, $handle ) {
	if ( 'car-dealer-main' !== $handle ) { return $tag; }
	return str_replace( ' src', ' defer src', $tag );
}
add_filter( 'script_loader_tag', 'car_dealer_defer_theme_script', 10, 2 );
