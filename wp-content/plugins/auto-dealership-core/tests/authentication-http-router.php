<?php
/** Dedicated loopback test server; never usable as an Apache endpoint. */
if ( PHP_SAPI !== 'cli-server' || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) { http_response_code( 404 ); exit; }
$config = json_decode( base64_decode( (string) getenv( 'ADC_HTTP_TEST_CONFIG' ), true ), true );
if ( ! is_array( $config ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $config['database'] ?? '' )
	|| ! preg_match( '/\A127\.0\.0\.1:(\d+)\z/', $config['host'] ?? '', $port ) || (int) $port[1] === 3306 ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'DB_NAME', $config['database'] ); define( 'DB_USER', $config['user'] ); define( 'DB_PASSWORD', $config['password'] ); define( 'DB_HOST', $config['host'] );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
$secret = hash( 'sha512', (string) getenv( 'ADC_HTTP_TEST_CONFIG' ) . '|authentication-http' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) { define( $name, hash( 'sha512', $secret . $name ) ); }
define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'DISABLE_WP_CRON', true ); define( 'WP_DEBUG', false );
define( 'WP_CONTENT_DIR', __DIR__ . '/isolated-content' ); define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'WP_SITEURL', 'http://127.0.0.1:' . (int) $_SERVER['SERVER_PORT'] ); define( 'WP_HOME', WP_SITEURL );
define( 'WP_CONTENT_URL', WP_SITEURL . '/wp-content' );
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/rest' === $path ) { define( 'REST_REQUEST', true ); }
$table_prefix = 'test_';
require ABSPATH . 'wp-settings.php';
add_filter( 'pre_wp_mail', '__return_true' );
if ( '/rest' === $path ) { rest_get_server()->serve_request( '/wp/v2/users/me' ); exit; }
if ( '/login' === $path && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$user = wp_signon( array( 'user_login'=>(string) ( $_POST['login'] ?? '' ), 'user_password'=>(string) ( $_POST['password'] ?? '' ) ), false );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( is_wp_error( $user ) ? array( 'error'=>$user->get_error_code() ) : array( 'id'=>$user->ID ) ); exit;
}
http_response_code( 404 );
