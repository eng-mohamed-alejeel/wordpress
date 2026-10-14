<?php
/** Loopback-only browser test router, never a production endpoint. */
if ( PHP_SAPI !== 'cli-server' ) { http_response_code( 404 ); exit; }
$configuration = json_decode( (string) file_get_contents( (string) getenv( 'ADC_VERIFICATION_HTTP_CONFIG' ) ), true );
if ( ! is_array( $configuration ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $configuration['database'] ?? '' ) || '127.0.0.1:33381' !== ( $configuration['host'] ?? '' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'DB_NAME', $configuration['database'] ); define( 'DB_USER', $configuration['user'] ); define( 'DB_PASSWORD', $configuration['password'] ); define( 'DB_HOST', $configuration['host'] ); define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'ADC_TEST_HTTP_CONFIG', $configuration['http_config'] );
$secret = hash( 'sha512', ADC_TEST_HTTP_CONFIG . '|wordpress-isolated-http' );
foreach ( array( 'AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT' ) as $key ) { define( $key, hash( 'sha512', $secret . '|' . $key ) ); }
unset( $secret, $configuration );
define( 'WP_INSTALLING', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'DISABLE_WP_CRON', true ); define( 'WP_DEBUG', false );
define( 'WP_CONTENT_DIR', __DIR__ . '/isolated-content' ); define( 'WP_CONTENT_URL', 'http://127.0.0.1:33382/content' ); define( 'WP_SITEURL', 'http://127.0.0.1:33382' ); define( 'WP_HOME', WP_SITEURL );
$table_prefix = 'test_';
require ABSPATH . 'wp-includes/plugin.php';
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
require ABSPATH . 'wp-settings.php';
require __DIR__ . '/../auto-dealership-core.php';
\AutoDealership\Leads\NewsletterService::boot();
global $wpdb;
if ( isset( $_GET['adc_test_state'] ) && is_string( $_GET['adc_test_state'] ) && preg_match( '/\A[a-f0-9]{64}\z/', $_GET['adc_test_state'] ) ) {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT purpose,subject_id,used_at FROM ' . \AutoDealership\Accounts\EmailVerification::table() . ' WHERE selector=%s', $_GET['adc_test_state'] ), ARRAY_A );
	$status = $row ? ( 'account' === $row['purpose'] ? ( \AutoDealership\Accounts\EmailVerification::blocked( (int) $row['subject_id'] ) ? 'pending' : 'active' ) : $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . \AutoDealership\Leads\NewsletterService::subscribers() . ' WHERE id=%d', $row['subject_id'] ) ) ) : 'missing';
	header( 'Content-Type: application/json' ); echo wp_json_encode( array( 'status'=>$status, 'used'=>(bool) ( $row['used_at'] ?? false ) ) ); exit;
}
\AutoDealership\Accounts\EmailVerification::page();
http_response_code( 404 );
