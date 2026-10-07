<?php
defined( 'ABSPATH' ) || exit;

/** Public identity changes must invalidate cached pages as soon as they publish. */
function car_dealer_clear_public_page_cache() {
	if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
}
add_action( 'customize_save_after', 'car_dealer_clear_public_page_cache' );
add_action( 'wp_update_nav_menu', 'car_dealer_clear_public_page_cache' );
add_action( 'updated_option', static function ( $option ) {
	if ( in_array( $option, array( 'car_dealer_theme_settings', 'blogname', 'blogdescription', 'site_icon', 'theme_mods_car-dealer' ), true ) ) {
		car_dealer_clear_public_page_cache();
	}
} );
