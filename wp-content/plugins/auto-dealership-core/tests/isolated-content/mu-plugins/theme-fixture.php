<?php
/** Opt-in real-theme bootstrap for the disposable browser suite only. */
if ( '1' !== getenv( 'ADC_THEME_TEST' ) || PHP_SAPI !== 'cli-server' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { return; }
add_action( 'setup_theme', static function (): void {
	$slug = 'twentytwentyfive' === getenv( 'ADC_THEME_SLUG' ) ? 'twentytwentyfive' : 'car-dealer';
	$root = 'twentytwentyfive' === $slug ? WP_CONTENT_DIR . '/themes' : ABSPATH . 'wp-content/themes';
	if ( ! is_file( $root . '/' . $slug . '/style.css' ) ) { throw new \RuntimeException( 'Isolated theme fixture is missing.' ); }
	register_theme_directory( $root );
	add_filter( 'theme_root', static fn() => $root );
	foreach ( array( 'template','stylesheet' ) as $name ) { add_filter( 'pre_option_' . $name, static fn() => $slug ); }
	foreach ( array( 'template_root','stylesheet_root' ) as $name ) { add_filter( $name, static fn() => $root ); }
	foreach ( array( 'template_directory_uri','stylesheet_directory_uri' ) as $name ) { add_filter( $name, static fn() => home_url( '/wp-content/themes/' . $slug ) ); }
} );
add_filter( 'pre_wp_mail', '__return_true' );
// Fonts are external; the browser suite deliberately uses the local fallback font.
add_action( 'wp_enqueue_scripts', static function (): void {
	wp_deregister_style( 'car-dealer-font' ); wp_register_style( 'car-dealer-font', false );
}, 100 );
