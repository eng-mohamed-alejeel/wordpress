<?php
/**
 * White-label presentation layer.
 * Core files, update checks, REST endpoints and WordPress capabilities remain untouched.
 *
 * @package Car_Dealer
 */
defined( 'ABSPATH' ) || exit;

function car_dealer_brand_name() {
	return wp_strip_all_tags( get_bloginfo( 'name' ) );
}

/* Do not publish platform generator information in the visible HTML or feeds. */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );
remove_action( 'wp_head', 'wp_oembed_add_host_js' );

function car_dealer_white_label_admin_bar( $admin_bar ) {
	$admin_bar->remove_node( 'wp-logo' );
}
add_action( 'admin_bar_menu', 'car_dealer_white_label_admin_bar', 999 );

function car_dealer_white_label_admin_text() {
	if ( ! current_user_can( 'manage_car_dealer' ) ) { return; }
	echo '<span>' . esc_html( car_dealer_brand_name() ) . ' — ' . esc_html__( 'نظام إدارة المعرض', 'car-dealer' ) . '</span>';
}
add_filter( 'admin_footer_text', 'car_dealer_white_label_admin_text' );
add_filter( 'update_footer', function () { return ''; }, 999 );

function car_dealer_white_label_admin_assets() {
	wp_enqueue_style( 'car-dealer-white-label-admin', get_template_directory_uri() . '/assets/css/white-label-admin.css', array(), wp_get_theme()->get( 'Version' ) );
}
add_action( 'admin_enqueue_scripts', 'car_dealer_white_label_admin_assets' );

function car_dealer_white_label_login_assets() {
	wp_enqueue_style( 'car-dealer-login', get_template_directory_uri() . '/assets/css/login.css', array(), wp_get_theme()->get( 'Version' ) );
}
add_action( 'login_enqueue_scripts', 'car_dealer_white_label_login_assets' );

function car_dealer_white_label_login_header() {
	echo '<div class="cd-login-brand"><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( car_dealer_brand_name() ) . '</a><p>' . esc_html__( 'نظام إدارة المعرض', 'car-dealer' ) . '</p></div>';
}
add_action( 'login_header', 'car_dealer_white_label_login_header' );
function car_dealer_white_label_login_url() { return home_url( '/' ); }
add_filter( 'login_headerurl', 'car_dealer_white_label_login_url' );
add_filter( 'login_headertext', 'car_dealer_brand_name' );

function car_dealer_white_label_admin_title( $admin_title, $title ) {
	return esc_html( car_dealer_brand_name() ) . ' — ' . wp_strip_all_tags( $title );
}
add_filter( 'admin_title', 'car_dealer_white_label_admin_title', 10, 2 );
