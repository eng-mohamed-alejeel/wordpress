<?php
/** Read-only application inspection of an independently restored local snapshot. */
if ( PHP_SAPI !== 'cli' || 3 !== $argc ) {
	fwrite( STDERR, "Usage: php tools/independent-restore-inspect.php PORT adc-restore-<32 hex> directory\n" );
	exit( 1 );
}
$port = filter_var( $argv[1], FILTER_VALIDATE_INT );
$root = realpath( $argv[2] );
$normalize = static fn( string $path ): string => strtolower( str_replace( '\\', '/', rtrim( $path, '/\\' ) ) );
$temp = $normalize( sys_get_temp_dir() );
if ( ! $port || $port < 1024 || 3306 === $port || $port > 65535 || ! $root || ! preg_match( '#^' . preg_quote( $temp, '#' ) . '/adc-restore-[a-f0-9]{32}$#', $normalize( $root ) ) ) {
	fwrite( STDERR, "Refusing a non-isolated restore target.\n" );
	exit( 2 );
}
$content = $root . '/files/wp-content';
if ( ! is_file( $content . '/plugins/auto-dealership-core/auto-dealership-core.php' ) || ! is_file( $content . '/themes/car-dealer/style.css' ) || ! is_file( $root . '/files/wp-config.php' ) ) {
	fwrite( STDERR, "Restored release files are incomplete.\n" );
	exit( 2 );
}
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$guard = new mysqli( '127.0.0.1', 'root', '', '', $port );
$state = $guard->query( 'SELECT @@port port,@@datadir datadir,@@innodb_force_recovery recovery,@@event_scheduler scheduler' )->fetch_assoc();
$guard->close();
if ( (int) $state['port'] !== $port || $normalize( $state['datadir'] ) !== $normalize( $root . '/mariadb' ) || '0' !== (string) $state['recovery'] || 'OFF' !== $state['scheduler'] ) {
	fwrite( STDERR, "Independent server identity check failed.\n" );
	exit( 2 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'DB_NAME', 'wp-autobrands' );
define( 'DB_HOST', '127.0.0.1:' . $port );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_CONTENT_URL', 'http://adc-rehearsal.invalid/content' );
define( 'WP_HOME', 'http://adc-rehearsal.invalid' );
define( 'WP_SITEURL', WP_HOME );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_DEBUG', false );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) {
	define( $name, hash( 'sha512', $root . '|' . $name . '|restored' ) );
}
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = 'adc-rehearsal.invalid';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require ABSPATH . 'wp-settings.php';
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'rehearsal_network_disabled' ) );

use AutoDealership\Database\Schema;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Content\PublicOfferView;

global $wpdb;
if ( ! class_exists( Schema::class ) || ! Schema::is_ready() || array() !== Schema::verify() || 'car-dealer' !== get_stylesheet() ) {
	fwrite( STDERR, "Restored application or schema did not load.\n" );
	exit( 3 );
}
$table = static fn( string $key ): string => Schema::table( $key );
$counts = array();
foreach ( array( 'branches','vehicles','customers','leads','quotations','reservations','sales','finance_requests','payment_confirmations','deliveries' ) as $key ) {
	$counts[$key] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table( $key ) );
}
$counts['car_posts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='car' AND post_status='publish'" );
$counts['offer_posts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='car_offer' AND post_status='publish'" );
$catalog = PublicCatalog::catalog( array( 'per_page' => 48 ) );
$offer_ids = get_posts( array( 'post_type' => 'car_offer', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 48, 'suppress_filters' => false ) );
$eligible_offers = 0;
foreach ( $offer_ids as $id ) { if ( PublicOfferView::for_post( (int) $id ) ) { ++$eligible_offers; } }
$sale = $wpdb->get_row( 'SELECT status FROM ' . $table( 'sales' ) . ' ORDER BY id ASC LIMIT 1', ARRAY_A );
$payment = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . $table( 'payment_confirmations' ) . ' WHERE reference=%s', 'DEMO-NO-FUNDS-002' ), ARRAY_A );
$delivery = $wpdb->get_row( 'SELECT status,vin_confirmed_by,approved_by,delivered_at FROM ' . $table( 'deliveries' ) . ' ORDER BY id ASC LIMIT 1', ARRAY_A );
$media = array( 'referenced' => 0, 'present' => 0, 'missing' => 0, 'unsafe_path' => 0 );
$uploads = realpath( $content . '/uploads' );
foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'" ) as $relative ) {
	++$media['referenced'];
	if ( ! is_string( $relative ) || preg_match( '#(?:^|[/\\\\])\.\.(?:[/\\\\]|$)#', $relative ) || preg_match( '#^(?:[A-Za-z]:|[/\\\\])#', $relative ) ) { ++$media['unsafe_path']; continue; }
	$path = realpath( $content . '/uploads/' . $relative );
	if ( $path && $uploads && str_starts_with( $normalize( $path ), $normalize( $uploads ) . '/' ) && is_file( $path ) ) { ++$media['present']; } else { ++$media['missing']; }
}
$routes = 0;
foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) { if ( str_starts_with( $route, '/auto-dealership/' ) ) { ++$routes; } }
$plugin_header = get_file_data( $content . '/plugins/auto-dealership-core/auto-dealership-core.php', array( 'Version' => 'Version' ) );
$summary = array(
	'port' => $port,
	'schema' => Schema::VERSION,
	'plugin_version' => $plugin_header['Version'],
	'theme_version' => wp_get_theme()->get( 'Version' ),
	'counts' => $counts,
	'catalog_mode' => PublicCatalog::mode(),
	'public_catalog_count' => count( $catalog ),
	'eligible_offer_count' => $eligible_offers,
	'original_sale_status' => $sale['status'] ?? null,
	'original_payment_status' => $payment['status'] ?? null,
	'delivery_status' => $delivery['status'] ?? null,
	'delivery_handed_over' => ! empty( $delivery['vin_confirmed_by'] ) || ! empty( $delivery['approved_by'] ) || ! empty( $delivery['delivered_at'] ),
	'media' => $media,
	'rest_routes' => $routes,
);
echo wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( $counts['vehicles'] < 1 || $counts['sales'] < 1 || $counts['finance_requests'] < 1 || $counts['payment_confirmations'] < 1 || $counts['deliveries'] < 1 || count( $catalog ) < 1 || $eligible_offers < 1 || 'compatibility' !== $summary['catalog_mode'] || 'pending_approval' !== $summary['original_sale_status'] || 'verified' !== $summary['original_payment_status'] || 'preparing' !== $summary['delivery_status'] || $summary['delivery_handed_over'] || $media['missing'] || $media['unsafe_path'] || $routes < 1 ) {
	exit( 4 );
}
