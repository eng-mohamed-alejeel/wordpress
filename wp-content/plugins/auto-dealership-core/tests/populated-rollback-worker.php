<?php
/** CLI worker for a copied, populated rollback rehearsal. Never boots the source database. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--write' ), true ) ) {
	fwrite( STDERR, "Usage: php populated-rollback-worker.php --inspect|--write < JSON configuration\n" );
	exit( 1 );
}
$config = json_decode( stream_get_contents( STDIN ), true );
if ( ! is_array( $config ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $config['database'] ?? '' ) || ! in_array( $config['host'] ?? '', array( '127.0.0.1', 'localhost' ), true ) || ! is_dir( $config['content_dir'] ?? '' ) ) {
	fwrite( STDERR, "Refusing non-isolated rollback configuration.\n" );
	exit( 2 );
}
$mode = $argv[1];
$content_dir = realpath( $config['content_dir'] );
if ( ! $content_dir || ! is_file( $content_dir . '/plugins/auto-dealership-core/auto-dealership-core.php' ) || ! is_file( $content_dir . '/themes/car-dealer/style.css' ) ) {
	fwrite( STDERR, "Missing copied release pair.\n" );
	exit( 2 );
}
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'DB_NAME', $config['database'] );
define( 'DB_USER', $config['user'] );
define( 'DB_PASSWORD', $config['password'] );
define( 'DB_HOST', $config['host'] );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_CONTENT_DIR', $content_dir );
define( 'WP_CONTENT_URL', 'http://rollback.invalid/content' );
define( 'WP_SITEURL', 'http://rollback.invalid' );
define( 'WP_HOME', WP_SITEURL );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', false );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) {
	define( $name, hash( 'sha512', $config['database'] . '|' . $name . '|rollback' ) );
}
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = 'rollback.invalid';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require ABSPATH . 'wp-settings.php';
add_filter( 'pre_wp_mail', '__return_true' );

use AutoDealership\Database\Schema;
use AutoDealership\Sales\SalesService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Payments\PaymentService;

global $wpdb;
if ( DB_NAME !== $config['database'] || ! class_exists( Schema::class ) || ! Schema::is_ready() || array() !== Schema::verify() || 'car-dealer' !== get_stylesheet() ) {
	fwrite( STDERR, "Copied release pair failed schema/theme boot.\n" );
	exit( 3 );
}
$table = static fn( string $key ): string => Schema::table( $key );
$count = static fn( string $key ): int => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table( $key ) );
$find_vehicle = static fn( string $stock ): ?array => $wpdb->get_row( $wpdb->prepare( 'SELECT id,status FROM ' . $table( 'vehicles' ) . ' WHERE stock_number=%s', $stock ), ARRAY_A );
$original_sale = $wpdb->get_row( 'SELECT id,status FROM ' . $table( 'sales' ) . ' ORDER BY id ASC LIMIT 1', ARRAY_A );
$original_payment = $wpdb->get_row( $wpdb->prepare( 'SELECT id,status FROM ' . $table( 'payment_confirmations' ) . ' WHERE reference=%s', 'DEMO-NO-FUNDS-002' ), ARRAY_A );
$original_delivery = $wpdb->get_row( 'SELECT id,status,vin_confirmed_by,approved_by,delivered_at FROM ' . $table( 'deliveries' ) . ' ORDER BY id ASC LIMIT 1', ARRAY_A );
if ( ! $original_sale || ! $original_payment || ! $original_delivery || 'pending_approval' !== $original_sale['status'] || 'verified' !== $original_payment['status'] || 'preparing' !== $original_delivery['status'] || ! empty( $original_delivery['vin_confirmed_by'] ) || ! empty( $original_delivery['approved_by'] ) || ! empty( $original_delivery['delivered_at'] ) ) {
	fwrite( STDERR, "Populated transactional fixture is missing or changed.\n" );
	exit( 4 );
}
$plugin_header = get_file_data( $content_dir . '/plugins/auto-dealership-core/auto-dealership-core.php', array( 'Version' => 'Version' ) );
$summary = array(
	'database' => DB_NAME,
	'plugin_version' => $plugin_header['Version'],
	'theme_version' => wp_get_theme()->get( 'Version' ),
	'schema' => Schema::VERSION,
	'counts' => array(),
	'original_sale_status' => $original_sale['status'],
	'original_payment_status' => $original_payment['status'],
	'original_delivery_status' => $original_delivery['status'],
);
foreach ( array( 'branches','vehicles','customers','leads','quotations','reservations','sales','finance_requests','payment_confirmations','deliveries','audit_events' ) as $key ) {
	$summary['counts'][$key] = $count( $key );
}
$rollback_payment = $wpdb->get_row( $wpdb->prepare( 'SELECT id,sale_id,status FROM ' . $table( 'payment_confirmations' ) . ' WHERE reference=%s', 'DEMO-ROLLBACK-NO-FUNDS' ), ARRAY_A );
if ( $rollback_payment ) {
	$rollback_sale = $wpdb->get_row( $wpdb->prepare( 'SELECT id,quotation_id,reservation_id,status FROM ' . $table( 'sales' ) . ' WHERE id=%d', $rollback_payment['sale_id'] ), ARRAY_A );
	$rollback_reservation = $rollback_sale ? $wpdb->get_row( $wpdb->prepare( 'SELECT id,vehicle_id,status FROM ' . $table( 'reservations' ) . ' WHERE id=%d', $rollback_sale['reservation_id'] ), ARRAY_A ) : null;
	$rollback_quote = $rollback_sale ? $wpdb->get_row( $wpdb->prepare( 'SELECT id,vehicle_id,status FROM ' . $table( 'quotations' ) . ' WHERE id=%d', $rollback_sale['quotation_id'] ), ARRAY_A ) : null;
	$rollback_vehicle = $find_vehicle( 'DEMO-2026-02' );
	if ( 'rejected' !== $rollback_payment['status'] || ! $rollback_sale || 'pending_approval' !== $rollback_sale['status'] || ! $rollback_reservation || 'converted_to_sale' !== $rollback_reservation['status'] || ! $rollback_quote || 'approved' !== $rollback_quote['status'] || (int) $rollback_quote['vehicle_id'] !== (int) $rollback_reservation['vehicle_id'] || (int) $rollback_reservation['vehicle_id'] !== (int) $rollback_vehicle['id'] || 'reserved' !== $rollback_vehicle['status'] ) {
		fwrite( STDERR, "Copied transactional write is inconsistent.\n" );
		exit( 5 );
	}
	$summary['rollback_write'] = array( 'quote_id' => (int) $rollback_quote['id'], 'reservation_id' => (int) $rollback_reservation['id'], 'sale_id' => (int) $rollback_sale['id'], 'payment_id' => (int) $rollback_payment['id'], 'payment_status' => $rollback_payment['status'], 'vehicle_status' => $rollback_vehicle['status'] );
}
if ( '--write' === $mode ) {
	$vehicle = $find_vehicle( 'DEMO-2026-02' );
	$customer_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table( 'customers' ) . ' WHERE email=%s', 'customer01@example.invalid' ) );
	$actor = get_user_by( 'login', 'adc_demo_sales_ryd' );
	if ( ! $vehicle || 'available' !== $vehicle['status'] || ! $customer_id || ! $actor ) { fwrite( STDERR, "Missing rollback write prerequisites.\n" ); exit( 5 ); }
	wp_set_current_user( (int) $actor->ID );
	$unwrap = static function ( $result, string $step ): array {
		if ( is_wp_error( $result ) ) { throw new RuntimeException( $step . ':' . $result->get_error_code() ); }
		if ( ! is_array( $result ) || empty( $result['id'] ) ) { throw new RuntimeException( $step . ':unexpected_result' ); }
		return $result;
	};
	try {
		$quote = $unwrap( SalesService::create_quote( $customer_id, (int) $vehicle['id'], gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) ), 'quote' );
		$reservation = $unwrap( ReservationService::create( array( 'vehicle_id' => (int) $vehicle['id'], 'customer_id' => $customer_id, 'idempotency_key' => wp_generate_uuid4() ) ), 'reservation' );
		$replay = $unwrap( ReservationService::create( array( 'vehicle_id' => (int) $vehicle['id'], 'customer_id' => $customer_id, 'idempotency_key' => $wpdb->get_var( $wpdb->prepare( 'SELECT idempotency_key FROM ' . $table( 'reservations' ) . ' WHERE id=%d', $reservation['id'] ) ) ) ), 'reservation_replay' );
		if ( (int) $replay['id'] !== (int) $reservation['id'] ) { throw new RuntimeException( 'reservation_replay:mismatch' ); }
		$sale = $unwrap( SalesService::create_sale( (int) $quote['id'], (int) $reservation['id'] ), 'sale' );
		$finance = get_user_by( 'login', 'adc_demo_finance_ryd_a' );
		$reviewer = get_user_by( 'login', 'adc_demo_finance_ryd_b' );
		if ( ! $finance || ! $reviewer ) { throw new RuntimeException( 'missing_finance_staff' ); }
		wp_set_current_user( (int) $finance->ID );
		$payment = $unwrap( PaymentService::record( (int) $sale['id'], 100, 'bank_transfer', 'DEMO-ROLLBACK-NO-FUNDS' ), 'payment' );
		$payment_replay = $unwrap( PaymentService::record( (int) $sale['id'], 100, 'bank_transfer', 'DEMO-ROLLBACK-NO-FUNDS' ), 'payment_replay' );
		if ( (int) $payment_replay['id'] !== (int) $payment['id'] ) { throw new RuntimeException( 'payment_replay:mismatch' ); }
		wp_set_current_user( (int) $reviewer->ID );
		$rejected = $unwrap( PaymentService::decide( (int) $payment['id'], false, 'Copied database rehearsal; no funds were received.' ), 'payment_rejection' );
		if ( 'rejected' !== $rejected['status'] ) { throw new RuntimeException( 'payment_rejection:wrong_status' ); }
		$summary['write'] = array( 'quote_id' => (int) $quote['id'], 'reservation_id' => (int) $reservation['id'], 'sale_id' => (int) $sale['id'], 'payment_id' => (int) $payment['id'], 'payment_status' => $rejected['status'], 'vehicle_status' => $find_vehicle( 'DEMO-2026-02' )['status'] );
	} catch ( Throwable $error ) { fwrite( STDERR, "Copied transactional write failed: " . $error->getMessage() . "\n" ); exit( 6 ); }
}
echo wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
