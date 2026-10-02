<?php
/** Empty-site theme-switch acceptance on a disposable database only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) || ! defined( 'ADC_TEST_HTTP_CONFIG' ) ) { exit( 1 ); }

if ( ! is_file( __DIR__ . '/isolated-content/themes/twentytwentyfive/style.css' ) ) { throw new RuntimeException( 'Install the official Twenty Twenty-Five test fixture in isolated-content/themes first.' ); }
update_option( 'active_plugins', array( 'auto-dealership-core/auto-dealership-core.php' ) );
$page_ids = array();
foreach ( array( 'about'=>'About', 'contact'=>'Contact', 'privacy'=>'Privacy', 'terms'=>'Terms' ) as $slug=>$title ) {
	$id = wp_insert_post( array( 'post_type'=>'page', 'post_status'=>'publish', 'post_name'=>$slug, 'post_title'=>$title, 'post_content'=>'' ), true );
	if ( is_wp_error( $id ) || $id < 1 ) { throw new RuntimeException( 'Cannot prepare isolated editorial page ' . $slug ); }
	$page_ids[$slug] = (int) $id;
}
$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: $message\n";
};
$request = static function ( int $port, string $path, ?array $form = null ): array {
	$curl = curl_init( 'http://127.0.0.1:' . $port . $path );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>12 ) );
	if ( null !== $form ) { curl_setopt_array( $curl, array( CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query( $form ) ) ); }
	$body = curl_exec( $curl ); $status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ); $error = curl_error( $curl ); curl_close( $curl );
	if ( false === $body ) { throw new RuntimeException( 'Local theme request failed: ' . $error ); }
	return array( $status, $body );
};
$inventory = array();
$pages = array();
foreach ( array( 'twentytwentyfive', 'car-dealer' ) as $slug ) {
	$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	if ( ! $socket ) { throw new RuntimeException( 'Cannot allocate isolated theme server: ' . $errstr ); }
	$address = stream_socket_get_name( $socket, false ); fclose( $socket );
	$port = (int) substr( $address, strrpos( $address, ':' ) + 1 );
	$log = tempnam( sys_get_temp_dir(), 'adc-theme-cutover-' );
	$server = null;
	try {
		$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/http-router.php' ), array( 0=>array( 'pipe','r' ), 1=>array( 'file',$log,'a' ), 2=>array( 'file',$log,'a' ) ), $pipes, ABSPATH, array_merge( getenv(), array( 'ADC_HTTP_TEST_CONFIG'=>ADC_TEST_HTTP_CONFIG, 'ADC_THEME_TEST'=>'1', 'ADC_THEME_SLUG'=>$slug ) ) );
		if ( ! is_resource( $server ) ) { throw new RuntimeException( 'Cannot launch isolated theme server.' ); }
		fclose( $pipes[0] );
		$ready = false;
		for ( $i = 0; $i < 50; ++$i ) { $probe = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 ); if ( $probe ) { fclose( $probe ); $ready = true; break; } usleep( 100000 ); }
		if ( ! $ready ) { throw new RuntimeException( 'Isolated theme server did not start.' ); }
		$key = hash( 'sha256', ADC_TEST_HTTP_CONFIG . '|session' );
		list( $status, $body ) = $request( $port, '/adc-test-inventory?key=' . rawurlencode( $key ) );
		$state = json_decode( $body, true );
		$check( 200 === $status && is_array( $state ) && $slug === ( $state['theme'] ?? '' ), $slug . ' loads the selected theme and inventory endpoint.' );
		$check( ADC_VERSION === ( $state['plugin'] ?? '' ) && array( true,true,true ) === ( $state['post_types'] ?? null ) && array( true,true ) === ( $state['taxonomies'] ?? null ), $slug . ' keeps the plugin content registry active.' );
		$check( ! empty( $state['privacy_exporter'] ) && ! empty( $state['privacy_eraser'] ) && ! in_array( false, $state['cron'] ?? array( false ), true ) && ! empty( $state['administrator_workspace'] ), $slug . ' retains privacy, cron and administrator capability registrations.' );
		$check( 1 === ( $state['ajax_contact_handlers'] ?? 0 ) && 1 === ( $state['ajax_booking_handlers'] ?? 0 ), $slug . ' has exactly one plugin contact and booking write handler.' );
		$check( 1 === ( $state['anonymous_contact_handlers'] ?? 0 ) && 1 === ( $state['anonymous_booking_handlers'] ?? 0 ), $slug . ' has exactly one anonymous contact and booking write handler.' );
		$check( isset( $state['shortcodes']['car_dealer_cars'], $state['shortcodes']['car_dealer_contact_form'] ) && ! array_filter( $state['shortcodes'], static fn( $owner ) => ! str_starts_with( $owner, 'AutoDealership\\' ) ), $slug . ' uses plugin-owned dealership shortcode callbacks.' );
		$check( ( $state['admin_menu_hooks'] ?? 0 ) > 0 && count( $state['rest_routes'] ?? array() ) > 0 && count( $state['shortcodes'] ?? array() ) >= 5, $slug . ' retains admin, REST and shortcode registrations.' );
		$check( array_sum( $state['counts'] ?? array( 1 ) ) === 0, $slug . ' has no vehicle, offer, customer, lead or sale records.' );
		foreach ( array( '/', '/?post_type=car', '/?post_type=car_offer' ) as $path ) {
			list( $page_status, $html ) = $request( $port, $path );
			$check( 200 === $page_status && str_contains( strtolower( $html ), '</html>' ), $slug . ' renders empty public route ' . $path . '.' );
			$pages[$slug][$path] = $html;
		}
		foreach ( $page_ids as $page_slug=>$page_id ) {
			list( $page_status, $html ) = $request( $port, '/?page_id=' . $page_id );
			$check( 200 === $page_status && str_contains( strtolower( $html ), '</html>' ), $slug . ' renders the isolated ' . $page_slug . ' editorial page.' );
		}
		$asset = '/wp-content/themes/' . $slug . '/style.css';
		list( $asset_status, $asset_body ) = $request( $port, $asset );
		$check( 200 === $asset_status && str_contains( $asset_body, 'Theme Name:' ), $slug . ' serves its stylesheet.' );
		foreach ( array( 'car_dealer_contact', 'car_dealer_booking' ) as $action ) {
			list( $bad_status ) = $request( $port, '/wp-admin/admin-ajax.php', array( 'action'=>$action, 'nonce'=>'invalid' ) );
			$check( 403 === $bad_status, $slug . ' rejects an anonymous ' . $action . ' write with an invalid nonce.' );
		}
		$inventory[$slug] = $state;
	} catch ( Throwable $error ) {
		throw new RuntimeException( $error->getMessage() . "\nServer log:\n" . substr( (string) @file_get_contents( $log ), -3000 ), 0, $error );
	} finally {
		if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); }
		if ( is_file( $log ) ) { unlink( $log ); }
	}
}
$stock = $inventory['twentytwentyfive']; $dealer = $inventory['car-dealer'];
$check( ! str_contains( $pages['car-dealer']['/'], '<article class="car-card"' ) && ! str_contains( $pages['car-dealer']['/'], 'class="offer-card' ), 'Empty dealership home does not fabricate vehicle or offer cards.' );
$check( str_contains( $pages['car-dealer']['/?post_type=car'], 'empty-state' ) && ! str_contains( $pages['car-dealer']['/?post_type=car'], '<article class="car-card"' ), 'Empty dealership vehicle archive presents an empty state.' );
$check( str_contains( $pages['car-dealer']['/?post_type=car_offer'], 'empty-state' ) && ! str_contains( $pages['car-dealer']['/?post_type=car_offer'], 'class="offer-card' ), 'Empty dealership offer archive presents an empty state.' );
foreach ( array( 'post_types','taxonomies','shortcodes','rest_routes','privacy_exporter','privacy_eraser','ajax_contact_handlers','ajax_booking_handlers','anonymous_contact_handlers','anonymous_booking_handlers','admin_menu_hooks','cron','administrator_workspace','counts' ) as $field ) {
	$check( $stock[$field] === $dealer[$field], 'Theme switch preserves ' . $field . '.' );
}
echo "Empty-site stock-theme/dealership-theme cutover completed.\n";
