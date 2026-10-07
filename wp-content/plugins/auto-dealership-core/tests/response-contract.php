<?php
/** Offline regression using WordPress classes and functions; no boot or database connection. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
define( 'WPINC', 'wp-includes' );
foreach ( array( 'plugin.php', 'load.php', 'formatting.php', 'class-wp-error.php', 'class-wp-http-response.php', 'rest-api/class-wp-rest-response.php', 'rest-api/class-wp-rest-request.php', 'rest-api/class-wp-rest-server.php', 'rest-api.php' ) as $file ) {
    require_once ABSPATH . WPINC . '/' . $file;
}
require_once __DIR__ . '/../src/API/ResponseContract.php';
$server = ( new ReflectionClass( WP_REST_Server::class ) )->newInstanceWithoutConstructor();
$checks = 0;
$check = static function ( bool $condition, string $message ) use ( &$checks ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
foreach ( array( 1, 2 ) as $version ) {
    $request = new WP_REST_Request( 'GET', '/auto-dealership/v' . $version . '/fixture' );
    $request->set_header( 'X-Request-ID', '12345678-1234-4123-8123-123456789abc' );
    AutoDealership\API\ResponseContract::begin( null, $server, $request );
    $result = AutoDealership\API\ResponseContract::format( new WP_Error( 'fixture_denied', 'Synthetic denied request', array( 'status'=>403, 'reason'=>'fixture' ) ), $server, $request );
    $check( $result instanceof WP_REST_Response && 403 === $result->get_status(), 'Error must preserve HTTP status.' );
    $check( '12345678-1234-4123-8123-123456789abc' === $result->get_headers()['X-Request-ID'], 'Error must retain correlation header.' );
    $data = $result->get_data();
    $check( 1 === $version ? 'fixture_denied' === $data['code'] : false === $data['success'] && 'fixture_denied' === $data['error']['code'] && 'fixture' === $data['error']['details']['reason'] && ! isset( $data['error']['details']['status'] ), 'Error must preserve the versioned contract.' );
    $success = AutoDealership\API\ResponseContract::format( new WP_REST_Response( array( 'id'=>7 ), 201 ), $server, $request );
    $check( 201 === $success->get_status() && ( 1 === $version ? array( 'id'=>7 ) === $success->get_data() : true === $success->get_data()['success'] && 7 === $success->get_data()['data']['id'] ), 'Successful responses must retain their contract.' );
}
$foreign = new WP_Error( 'foreign', 'Untouched foreign route' );
$check( $foreign === AutoDealership\API\ResponseContract::format( $foreign, $server, new WP_REST_Request( 'GET', '/other/v1/fixture' ) ), 'Unrelated routes must pass through unchanged.' );
echo "PASS: $checks response contract checks.\n";
