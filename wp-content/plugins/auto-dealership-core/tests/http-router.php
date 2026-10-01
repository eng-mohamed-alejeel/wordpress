<?php
/** Router for localhost-only HTTP authentication tests against the disposable database. */
if ( PHP_SAPI !== 'cli-server' ) { http_response_code( 404 ); exit; }
$config = json_decode( base64_decode( (string) getenv( 'ADC_HTTP_TEST_CONFIG' ), true ), true );
if ( ! is_array( $config ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $config['database'] ?? '' ) ) { http_response_code( 503 ); exit; }
$theme_test = '1' === getenv( 'ADC_THEME_TEST' );
if ( $theme_test ) {
	$asset = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( preg_match( '#\A/(?:wp-content/(?:themes/car-dealer|plugins/auto-dealership-core/assets)/|wp-includes/).+\.(?:css|js|svg|png|jpg|jpeg|webp|woff2?)\z#i', $asset ) ) {
		$root = realpath( dirname( __DIR__, 4 ) ); $file = realpath( $root . $asset );
		if ( $file && str_starts_with( $file, $root . DIRECTORY_SEPARATOR ) && is_file( $file ) ) { return false; }
		http_response_code( 404 ); exit;
	}
}
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'DB_NAME', $config['database'] );
define( 'DB_USER', $config['user'] );
define( 'DB_PASSWORD', $config['password'] );
define( 'DB_HOST', $config['host'] );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$test_secret = hash( 'sha512', (string) getenv( 'ADC_HTTP_TEST_CONFIG' ) . '|wordpress-isolated-http' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $secret_name ) {
	define( $secret_name, hash( 'sha512', $test_secret . '|' . $secret_name ) );
}
unset( $test_secret, $secret_name );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', false );
define( 'WP_CONTENT_DIR', __DIR__ . '/isolated-content' );
define( 'WP_CONTENT_URL', $theme_test ? 'http://127.0.0.1:' . (int) $_SERVER['SERVER_PORT'] . '/wp-content' : 'http://adc-verification.invalid/content' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'WP_SITEURL', $theme_test ? 'http://127.0.0.1:' . (int) $_SERVER['SERVER_PORT'] : 'http://adc-verification.invalid' );
define( 'WP_HOME', WP_SITEURL );
define( 'WP_USE_THEMES', $theme_test );
$session_key = hash( 'sha256', (string) getenv( 'ADC_HTTP_TEST_CONFIG' ) . '|session' );
$table_prefix = 'test_';
unset( $config );
if ( '/wp-admin/admin-ajax.php' === (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) { define( 'DOING_AJAX', true ); }
require ABSPATH . 'wp-settings.php';
add_filter( 'pre_wp_mail', '__return_true' );
if ( $theme_test && '/adc-test-reset-rate' === (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) {
	if ( ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) || ! hash_equals( $session_key, (string) ( $_GET['key'] ?? '' ) ) ) { http_response_code( 403 ); exit; }
	global $wpdb;
	$wpdb->delete( \AutoDealership\Database\Schema::table( 'request_limits' ), array( 'policy_key'=>'intake' ), array( '%s' ) );
	header( 'Content-Type: text/plain' );
	echo 'ok';
	exit;
}
if ( '/adc-test-session' === (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) {
	if ( ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) || ! hash_equals( $session_key, (string) ( $_GET['key'] ?? '' ) ) ) { http_response_code( 403 ); exit; }
	$user_id = absint( $_GET['user_id'] ?? 0 );
	if ( ! get_userdata( $user_id ) ) { http_response_code( 404 ); exit; }
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, false, false );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	exit;
}
$request_path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
if ( $theme_test && '/adc-test-review' === $request_path ) {
	if ( ! current_user_can( 'manage_options' ) ) { http_response_code( 403 ); exit; }
	get_header();
	\AutoDealership\Admin\RequestPage::render( absint( $_GET['lead_id'] ?? 0 ) );
	get_footer(); exit;
}
if ( '/adc-test-nonce' === $request_path ) {
	if ( ! is_user_logged_in() ) { http_response_code( 401 ); exit; }
	$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
	if ( '' === $action ) { http_response_code( 400 ); exit; }
	header( 'Content-Type: text/plain' );
	echo wp_create_nonce( $action );
	exit;
}
if ( '/wp-admin/admin-post.php' === $request_path ) {
	$action = sanitize_key( $_REQUEST['action'] ?? '' );
	if ( '' === $action ) { http_response_code( 400 ); exit; }
	do_action( ( is_user_logged_in() ? 'admin_post_' : 'admin_post_nopriv_' ) . $action );
	http_response_code( 400 );
	exit;
}
if ( '/wp-admin/admin-ajax.php' === $request_path ) {
	require_once ABSPATH . 'wp-content/themes/car-dealer/inc/contact-form-manager.php';
	$action = sanitize_key( $_REQUEST['action'] ?? '' );
	if ( ! in_array( $action, array( 'car_dealer_contact', 'car_dealer_booking' ), true ) ) { http_response_code( 400 ); exit; }
	do_action( ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . $action );
	http_response_code( 400 ); exit;
}
wp();
require ABSPATH . WPINC . '/template-loader.php';
