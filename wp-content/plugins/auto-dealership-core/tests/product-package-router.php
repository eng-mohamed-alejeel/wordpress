<?php
/** Loopback test server for extracted distributable packages; never loads source wp-config. */
if ( PHP_SAPI !== 'cli-server' ) { http_response_code( 404 ); exit; }
$file = (string) getenv( 'ADC_PRODUCT_TEST_CONFIG' );
$config = json_decode( (string) @file_get_contents( $file ), true );
if ( ! is_array( $config ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $config['database'] ?? '' ) || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) { http_response_code( 503 ); exit; }
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$root = realpath( dirname( __DIR__, 4 ) );
if ( preg_match( '#\A/(?:wp-content|wp-includes|wp-admin)/.+\.(css|js|svg|png|jpe?g|webp|woff2?)\z#i', $path, $match ) ) {
	$base = str_starts_with( $path, '/wp-content/' ) ? $config['content'] : $root;
	$relative = str_starts_with( $path, '/wp-content/' ) ? substr( $path, strlen( '/wp-content' ) ) : $path;
	$asset = realpath( $base . $relative );
	if ( ! $asset || ! str_starts_with( $asset, realpath( $base ) . DIRECTORY_SEPARATOR ) || ! is_file( $asset ) ) { http_response_code( 404 ); exit; }
	$types = array( 'css'=>'text/css', 'js'=>'application/javascript', 'svg'=>'image/svg+xml', 'woff2'=>'font/woff2', 'woff'=>'font/woff', 'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp' );
	header( 'Content-Type: ' . $types[strtolower( $match[1] )] ); readfile( $asset ); exit;
}
define( 'ABSPATH', $root . '/' );
define( 'WP_USE_THEMES', true );
define( 'DB_NAME', $config['database'] ); define( 'DB_HOST', '127.0.0.1:' . $config['port'] ); define( 'DB_USER', 'root' ); define( 'DB_PASSWORD', '' ); define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'WP_CONTENT_DIR', $config['content'] ); define( 'WP_CONTENT_URL', 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/wp-content' );
define( 'WP_SITEURL', 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] ); define( 'WP_HOME', WP_SITEURL );
define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'DISABLE_WP_CRON', true ); define( 'WP_DEBUG', false );
if ( str_starts_with( $path, '/wp-admin/' ) ) { define( 'WP_ADMIN', true ); }
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) { define( $key, hash( 'sha512', $config['token'] . $key ) ); }
$table_prefix = 'test_';
require ABSPATH . 'wp-includes/plugin.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
require ABSPATH . 'wp-settings.php';
if ( '/adc-product-session' === $path ) {
	if ( ! hash_equals( $config['token'], (string) ( $_GET['key'] ?? '' ) ) ) { http_response_code( 403 ); exit; }
	wp_set_current_user( $config['admin'] ); wp_set_auth_cookie( $config['admin'], false, false ); header( 'Content-Type: application/json' ); echo '{"authenticated":true}'; exit;
}
if ( '/wp-admin/admin-post.php' === $path ) { do_action( 'admin_post_' . sanitize_key( $_POST['action'] ?? '' ) ); exit; }
if ( '/wp-admin/options-general.php' === $path ) {
	if ( ! current_user_can( 'manage_options' ) ) { http_response_code( 403 ); exit; }
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	$branch_page = 'adc-branch-locations' === ($_GET['page'] ?? '');
	set_current_screen( $branch_page ? 'settings_page_adc-branch-locations' : 'settings_page_adc-platform-setup' );
	do_action( 'admin_enqueue_scripts', $branch_page ? 'settings_page_adc-branch-locations' : 'settings_page_adc-platform-setup' );
	wp_enqueue_style( 'common' ); wp_enqueue_style( 'forms' );
	wp_dequeue_script('wp-auth-check');
	echo '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1">'; wp_print_styles(); echo '</head><body class="wp-admin"><main style="padding:16px">';
	if ($branch_page) { \AutoDealership\Core\BranchLocations::admin(); } else { \AutoDealership\Core\ProductSetup::render(); }
	echo '</main>'; if ($branch_page) { wp_print_scripts(array('adc-branch-editor')); } echo '</body></html>'; exit;
}
if (str_starts_with($path, '/wp-admin/')) { http_response_code(404); exit; }
wp();
require ABSPATH . WPINC . '/template-loader.php';
