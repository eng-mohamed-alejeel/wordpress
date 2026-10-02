<?php
/** Public boundary and atomic request protection acceptance for 1.27. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Admin\SecurityPage;
use AutoDealership\API\Routes;
use AutoDealership\Database\Schema;
use AutoDealership\Operations\OutboxService;
use AutoDealership\Security\ClientAddress;
use AutoDealership\Security\PublicRequestGuard;

$security_previous_user = get_current_user_id();
$security_previous_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$security_previous_forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$security_limits = Schema::table( 'request_limits' );
$security_outbox = Schema::table( 'outbox' );
$security_receipts = Schema::table( 'integration_receipts' );
$security_fixture_event = null;

try {
	// Prove that this additive table can be repaired around existing integration records.
	$security_fixture_event = OutboxService::enqueue( 'test.security_upgrade', array( 'subject_type'=>'security', 'subject_id'=>127001 ), 'security-upgrade-127001' );
	$wpdb->insert( $security_receipts, array(
		'outbox_id'=>$security_fixture_event['id'], 'adapter_id'=>'security-upgrade', 'event_key'=>'test.security_upgrade',
		'remote_reference'=>'opaque-security-upgrade', 'reference_hash'=>hash( 'sha256', 'opaque-security-upgrade' ), 'status'=>'pending',
		'created_at'=>current_time( 'mysql', true ), 'updated_at'=>current_time( 'mysql', true ),
	) );
	$wpdb->query( "DROP TABLE `$security_limits`" );
	update_option( 'adc_db_version', '1.15.0', false );
	Schema::install();
	adc_check( Schema::is_ready() && array() === Schema::verify() && (int) $security_fixture_event['id'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT outbox_id FROM $security_receipts WHERE adapter_id=%s", 'security-upgrade' ) ), 'The additive request-limit upgrade preserves existing outbox and provider receipt records.' );

	wp_set_current_user( 0 );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
	adc_check( '198.51.100.10' === ClientAddress::resolve(), 'Forwarded client headers are ignored unless the immediate proxy is explicitly trusted.' );

	$trusted = static fn() => array( '198.51.100.0/24', '2001:db8::/32' );
	add_filter( 'adc_trusted_proxy_cidrs', $trusted );
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.44, 198.51.100.9';
	adc_check( '192.0.2.44' === ClientAddress::resolve(), 'A trusted proxy chain resolves the first untrusted client from right to left.' );
	$_SERVER['HTTP_X_FORWARDED_FOR'] = 'malformed, 198.51.100.9';
	adc_check( '198.51.100.10' === ClientAddress::resolve(), 'A malformed forwarded chain safely falls back to the direct peer.' );
	remove_filter( 'adc_trusted_proxy_cidrs', $trusted );

	$wpdb->query( "DELETE FROM $security_limits" );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.177';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	$first = PublicRequestGuard::consume( 'intake' );
	$stored = $wpdb->get_row( "SELECT * FROM $security_limits LIMIT 1", ARRAY_A );
	adc_check( is_array( $first ) && 64 === strlen( $stored['bucket_key'] ?? '' ) && ! str_contains( wp_json_encode( $stored ), '203.0.113.177' ), 'Request protection persists an HMAC bucket and never a raw client address.' );

	$wpdb->query( "DELETE FROM $security_limits" );
	for ( $security_attempt = 1; $security_attempt <= 8; ++$security_attempt ) { $security_allowed = PublicRequestGuard::consume( 'intake' ); }
	$security_denied = PublicRequestGuard::consume( 'intake' );
	adc_check( is_array( $security_allowed ) && is_wp_error( $security_denied ) && 'adc_rate_limited' === $security_denied->get_error_code() && 429 === $security_denied->get_error_data()['status'], 'The intake policy permits eight attempts and returns a retry-aware 429 on the ninth.' );

	$wpdb->query( "DELETE FROM $security_limits" );
	$anonymous = PublicRequestGuard::consume( 'public_read' );
	wp_set_current_user( $admin );
	$authenticated = PublicRequestGuard::consume( 'public_read' );
	adc_check( is_array( $anonymous ) && is_array( $authenticated ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $security_limits WHERE policy_key='public_read'" ), 'Authenticated and anonymous callers use distinct privacy-preserving request buckets.' );

	wp_set_current_user( 0 );
	$wpdb->query( "DELETE FROM $security_limits" );
	$rate_failure = $with_sql_failure( static fn( $query ) => str_starts_with( $query, "INSERT INTO $security_limits" ), static fn() => PublicRequestGuard::consume( 'intake' ) );
	adc_check( is_wp_error( $rate_failure ) && 'adc_rate_unavailable' === $rate_failure->get_error_code(), 'Public request protection fails closed when its atomic storage write fails.' );

	$expired_key = hash( 'sha256', 'expired-security-bucket' );
	$wpdb->insert( $security_limits, array( 'bucket_key'=>$expired_key, 'policy_key'=>'intake', 'attempts'=>1, 'window_started'=>'2000-01-01 00:00:00', 'expires_at'=>'2000-01-02 00:00:00' ) );
	PublicRequestGuard::prune();
	$prune_health = get_option( 'adc_rate_limit_health', array() );
	adc_check( null === $wpdb->get_var( $wpdb->prepare( "SELECT bucket_key FROM $security_limits WHERE bucket_key=%s", $expired_key ) ) && empty( $prune_health['error'] ) && ! empty( $prune_health['finished_at'] ), 'Bounded cleanup removes expired request buckets and records non-sensitive health metadata.' );

	// Validate the declared anonymous inventory against every registered core endpoint.
	wp_set_current_user( 0 );
	$wpdb->query( "DELETE FROM $security_limits" );
	$server = rest_get_server();
	$anonymous_routes = array();
	foreach ( $server->get_routes() as $route => $handlers ) {
		if ( ! str_starts_with( $route, '/auto-dealership/v1/' ) ) { continue; }
		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || empty( $handler['permission_callback'] ) ) { continue; }
			$methods = array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) );
			foreach ( $methods as $method ) {
				$request = new WP_REST_Request( $method, $route );
				try { $permission = call_user_func( $handler['permission_callback'], $request ); }
				catch ( Throwable $error ) { $permission = false; }
				if ( true === $permission ) { $anonymous_routes[] = $method . ' ' . substr( $route, strlen( '/auto-dealership/v1' ) ); }
			}
		}
	}
	$anonymous_routes = array_values( array_unique( $anonymous_routes ) ); sort( $anonymous_routes );
	$declared_routes = array_keys( Routes::public_endpoints() ); sort( $declared_routes );
	adc_check( $declared_routes === $anonymous_routes, 'The complete REST inventory exposes exactly the three declared anonymous method-route pairs.' );

	wp_set_current_user( $admin );
	$summary = PublicRequestGuard::summary();
	ob_start(); SecurityPage::render(); $security_html = ob_get_clean();
	adc_check( is_array( $summary ) && array_keys( PublicRequestGuard::POLICIES ) === array_keys( $summary['policies'] ) && str_contains( $security_html, 'GET /vehicles' ) && ! str_contains( $security_html, '203.0.113.177' ) && ! str_contains( $security_html, $expired_key ), 'The restricted security page shows the public inventory and all configured aggregate policies without addresses or bucket fingerprints.' );
	adc_check( has_action( 'adc_prune_request_limits', array( PublicRequestGuard::class, 'prune' ) ) !== false, 'The bounded request-limit cleanup handler is registered for scheduled execution.' );

	// Independent connections must receive exact atomic attempt numbers.
	wp_set_current_user( 0 );
	$wpdb->query( "DELETE FROM $security_limits" );
	$barrier = 'adc_gate_' . bin2hex( random_bytes( 8 ) );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $barrier ) ) ) { throw new RuntimeException( 'Cannot acquire request-limit barrier.' ); }
	$jobs = array();
	try {
		for ( $worker_index = 0; $worker_index < 12; ++$worker_index ) {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/database-runner.php', '--worker' ), array( 0=>array('pipe','r'), 1=>array('pipe','w'), 2=>STDERR ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start request-limit worker.' ); }
			fwrite( $pipes[0], json_encode( array( 'database'=>DB_NAME, 'source_database'=>'', 'host'=>DB_HOST, 'user'=>DB_USER, 'password'=>DB_PASSWORD, 'scenario'=>'rate_limit', 'actor'=>0, 'input'=>array( 'address'=>'192.0.2.127', 'policy'=>'intake' ), 'barrier'=>$barrier ), JSON_THROW_ON_ERROR ) );
			fclose( $pipes[0] ); $jobs[] = array( $process, $pipes[1] );
		}
		usleep( 500000 );
	} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $barrier ) ); }
	$worker_results = array();
	foreach ( $jobs as list( $process, $output ) ) {
		$result = json_decode( stream_get_contents( $output ), true ); fclose( $output );
		if ( 0 !== proc_close( $process ) || ! is_array( $result ) ) { throw new RuntimeException( 'Request-limit worker failed.' ); }
		$worker_results[] = $result;
	}
	$accepted = count( array_filter( $worker_results, static fn( $result ) => ! isset( $result['error'] ) ) );
	$rejected = count( array_filter( $worker_results, static fn( $result ) => 'adc_rate_limited' === ( $result['error'] ?? '' ) ) );
	adc_check( 8 === $accepted && 4 === $rejected && 12 === (int) $wpdb->get_var( "SELECT attempts FROM $security_limits WHERE policy_key='intake' LIMIT 1" ), 'Twelve simultaneous independent requests atomically allow eight, reject four and retain all attempts.' );
} finally {
	wp_set_current_user( $security_previous_user );
	if ( null === $security_previous_remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $security_previous_remote; }
	if ( null === $security_previous_forwarded ) { unset( $_SERVER['HTTP_X_FORWARDED_FOR'] ); } else { $_SERVER['HTTP_X_FORWARDED_FOR'] = $security_previous_forwarded; }
	$wpdb->query( "DELETE FROM $security_limits" );
	$wpdb->delete( $security_receipts, array( 'adapter_id'=>'security-upgrade' ), array( '%s' ) );
	$wpdb->delete( $security_outbox, array( 'event_key'=>'test.security_upgrade' ), array( '%s' ) );
}
