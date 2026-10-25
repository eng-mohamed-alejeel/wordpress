<?php
/** Temporary administrator session for the dashboard performance baseline. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$path = ABSPATH . '.tmp/admin-performance-en-session.json';
if ( '--prepare' === ( $argv[1] ?? '' ) ) {
	if ( is_file( $path ) ) { throw new RuntimeException( 'Previous performance session exists.' ); }
	$login = 'adc_perf_en_' . bin2hex( random_bytes( 4 ) );
	$id = wp_insert_user( array(
		'user_login' => $login,
		'user_pass' => wp_generate_password( 32, true, true ),
		'user_email' => $login . '@example.invalid',
		'role' => 'administrator',
	) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	update_user_meta( $id, 'locale', 'en_US' );
	$expiry = time() + 900;
	$token = WP_Session_Tokens::get_instance( $id )->create( $expiry );
	file_put_contents( $path, wp_json_encode( array(
		'id' => (int) $id,
		'token' => $token,
		'cookies' => array(
			array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $id, $expiry, 'auth', $token ) ),
			array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $id, $expiry, 'logged_in', $token ) ),
		),
	) ) );
	echo "Temporary English performance session prepared.\n";
} elseif ( '--cleanup' === ( $argv[1] ?? '' ) && is_file( $path ) ) {
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! empty( $data['id'] ) && ! empty( $data['token'] ) ) { WP_Session_Tokens::get_instance( (int) $data['id'] )->destroy( $data['token'] ); }
	if ( ! empty( $data['id'] ) ) { wp_delete_user( (int) $data['id'] ); }
	unlink( $path );
	echo "Temporary English performance session destroyed.\n";
}
