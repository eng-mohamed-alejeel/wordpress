<?php
/** Actual WordPress validators and MariaDB, disposable database only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Security\AuthenticationGuard;
use AutoDealership\Security\PublicRequestGuard;
use AutoDealership\Security\AccessPolicy;
use AutoDealership\Accounts\EmailVerification;

$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	++$checks;
};
$table = PublicRequestGuard::table();
$reset = static function () use ( $wpdb, $table ): void { $wpdb->query( "DELETE FROM $table" ); wp_set_current_user( 0 ); };
$denied = static fn( $result ) => is_wp_error( $result ) && 'adc_rate_limited' === $result->get_error_code();
$password = wp_generate_password( 32 );
$id = wp_insert_user( array( 'user_login'=>'authentication_fixture', 'user_email'=>'authentication@example.invalid', 'user_pass'=>$password, 'role'=>'subscriber' ) );
$check( ! is_wp_error( $id ), 'Synthetic user created in disposable database.' );
$other_id = wp_insert_user( array( 'user_login'=>'other_authentication_fixture', 'user_email'=>'other-authentication@example.invalid', 'user_pass'=>$password, 'role'=>'subscriber' ) );
$check( ! is_wp_error( $other_id ), 'Second synthetic network user created.' );
AccessPolicy::boot();
$_SERVER['REMOTE_ADDR'] = '192.0.2.50';
$reset();
$hashes = 0;
$counter = static function ( $valid ) use ( &$hashes ) { ++$hashes; return $valid; };
add_filter( 'check_password', $counter );
try {
	$check( wp_authenticate( 'authentication_fixture', $password ) instanceof WP_User, 'Real username validator permits correct credentials.' );
	$check( wp_authenticate( 'authentication@example.invalid', $password ) instanceof WP_User, 'Real email validator permits correct credentials.' );
	$check( 2 === (int) $wpdb->get_var( "SELECT attempts FROM $table WHERE policy_key='account_auth'" ), 'Exactly one charge per authentication, shared by username and email.' );
	for ( $i = 2; $i < 10; ++$i ) { wp_authenticate( 'authentication_fixture', 'incorrect' ); }
	$before = $hashes;
	$check( $denied( wp_authenticate( 'authentication@example.invalid', $password ) ), 'Correct password cannot bypass an exhausted native authentication quota.' );
	$check( $before === $hashes, 'No real native password hash check after limit.' );
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.60';
	$check( $denied( wp_signon( array( 'user_login'=>'authentication_fixture', 'user_password'=>$password ), false ) ), 'Theme wp_signon shares native budget; spoofed proxy header does not help.' );
	$check( wp_authenticate( 'other_authentication_fixture', $password ) instanceof WP_User, 'Another real user on the SAME network remains able to sign in.' );
	$check( 11 === (int) $wpdb->get_var( "SELECT attempts FROM $table WHERE policy_key='auth_network'" ), 'Blocked account requests do not drain the shared network budget.' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.51';
	$check( wp_authenticate( 'authentication_fixture', $password ) instanceof WP_User, 'Victim remains able to log in from another address.' );
	$reset();
	update_user_meta( $id, 'adc_suspended', '1' );
	$blocked = wp_authenticate( 'authentication_fixture', $password );
	$check( is_wp_error( $blocked ) && 'adc_account_suspended' === $blocked->get_error_code(), 'Existing suspension guard remains effective.' );
	delete_user_meta( $id, 'adc_suspended' );
	update_user_meta( $id, 'adc_email_verification_status', 'pending' );
	$blocked = wp_authenticate( 'authentication_fixture', $password );
	$check( is_wp_error( $blocked ) && 'adc_email_pending' === $blocked->get_error_code(), 'Existing email verification guard remains effective.' );
	delete_user_meta( $id, 'adc_email_verification_status' );
	$reset();
	for ( $i = 0; $i < 5; ++$i ) { AuthenticationGuard::registration( 'busy@example.invalid' ); }
	$check( $denied( AuthenticationGuard::registration( 'busy@example.invalid' ) ), 'One repeated registration identity is limited.' );
	$check( null === AuthenticationGuard::registration( 'new-customer@example.invalid' ), 'A new signup on the same address remains allowed.' );
	$check( wp_authenticate( 'authentication_fixture', $password ) instanceof WP_User, 'Registration traffic cannot exhaust sign-in quota.' );
	EmailVerification::boot();
	$register = new ReflectionMethod( \AutoDealership\Accounts\CustomerAccount::class, 'register' ); $register->setAccessible( true );
	$_POST = array( 'display_name'=>'Network signup', 'email'=>'new-customer@example.invalid', 'password'=>'StrongAcceptance123!abcd', 'password_confirm'=>'StrongAcceptance123!abcd', 'phone'=>'+966505555551', 'privacy_consent'=>'1' );
	$signup = $register->invoke( null );
	$check( is_int( $signup ) && $signup > 0, 'Actual signup service creates an account while another email on the network is limited.' );
	$_POST['email'] = 'second-customer@example.invalid'; $_POST['phone'] = '+966505555552';
	$check( null === AuthenticationGuard::registration( $_POST['email'] ), 'Second new signup has an independent allowance.' );
	$signup2 = $register->invoke( null );
	$check( is_int( $signup2 ) && $signup2 > 0 && $signup2 !== $signup, 'Second real signup from the shared network succeeds.' );
	$_POST = array();
	// REST resolves Basic authentication directly, outside wp_authenticate.
	$enable = static fn() => true;
	add_filter( 'wp_is_application_passwords_available', $enable );
	add_filter( 'application_password_is_api_request', $enable );
	$app = WP_Application_Passwords::create_new_application_password( $id, array( 'name'=>'Isolated authentication acceptance' ) );
	$check( ! is_wp_error( $app ), 'Synthetic application password created.' );
	$_SERVER['PHP_AUTH_USER'] = 'authentication_fixture'; $_SERVER['PHP_AUTH_PW'] = $app[0];
	$reset();
	$check( $id === AuthenticationGuard::validate_application( false ), 'Real application password authenticates before its quota.' );
	$wpdb->query( "UPDATE $table SET attempts=120 WHERE policy_key='application_auth'" );
	$check( false === AuthenticationGuard::validate_application( false ), 'Real Basic authentication stops at exhausted application quota.' );
	$error = AuthenticationGuard::rest_error( null );
	$check( $denied( $error ) && 429 === $error->get_error_data()['status'], 'REST error exposes 429 and retry metadata.' );
	$reset();
	$check( $id === AuthenticationGuard::validate_application( false ), 'Application authentication resumes after clearing the synthetic bucket.' );
	unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
	// Exercise WordPress XML-RPC's actual login method, without dispatching a request.
	require_once ABSPATH . WPINC . '/class-IXR.php';
	require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
	$xmlrpc = new wp_xmlrpc_server();
	$reset();
	$check( $xmlrpc->login( 'authentication_fixture', $password ) instanceof WP_User, 'Real XML-RPC login delegates through the protected validators.' );
	$wpdb->query( "UPDATE $table SET attempts=10 WHERE policy_key='account_auth'" );
	$before = $hashes;
	$check( false === $xmlrpc->login( 'authentication_fixture', $password ), 'XML-RPC cannot authenticate beyond the shared quota.' );
	$check( $before === $hashes, 'XML-RPC blocked before native hash check.' );
	$stored = $wpdb->get_results( "SELECT bucket_key,policy_key FROM $table", ARRAY_A );
	$check( ! str_contains( wp_json_encode( $stored ), '192.0.2.' ) && ! str_contains( wp_json_encode( $stored ), 'authentication_fixture' ), 'Limiter rows contain no plaintext username or address.' );
	// Fault injection is confined to this disposable database.
	$wpdb->query( "RENAME TABLE $table TO {$table}_unavailable" );
	$wpdb->suppress_errors( true );
	try {
		$error = wp_authenticate( 'authentication_fixture', $password );
		$check( is_wp_error( $error ) && 'adc_rate_unavailable' === $error->get_error_code(), 'Actual database table outage fails closed.' );
	} finally { $wpdb->query( "RENAME TABLE {$table}_unavailable TO $table" ); $wpdb->suppress_errors( false ); }
	// Independent HTTP requests prove persistent quotas and real response headers.
	$reset();
	update_option( 'active_plugins', array( 'auto-dealership-core/auto-dealership-core.php' ) );
	$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	if ( ! $socket ) { throw new RuntimeException( 'Cannot allocate test HTTP port.' ); }
	$address = stream_socket_get_name( $socket, false ); fclose( $socket );
	$log = tempnam( sys_get_temp_dir(), 'adc-auth-http-' );
	$environment = getenv(); $environment['ADC_HTTP_TEST_CONFIG'] = ADC_TEST_HTTP_CONFIG;
	$server = proc_open( array( PHP_BINARY, '-S', $address, __DIR__ . '/authentication-http-router.php' ), array( 0=>array( 'pipe', 'r' ), 1=>array( 'file', $log, 'a' ), 2=>array( 'file', $log, 'a' ) ), $pipes, ABSPATH, $environment, array( 'bypass_shell'=>true ) );
	if ( ! is_resource( $server ) ) { throw new RuntimeException( 'Cannot start isolated HTTP server.' ); }
	fclose( $pipes[0] );
	try {
		for ( $i = 0; $i < 50; ++$i ) { $probe = @stream_socket_client( 'tcp://' . $address, $errno, $errstr, 0.1 ); if ( $probe ) { fclose( $probe ); break; } usleep( 100000 ); }
		$request = static function ( string $path, array $options = array() ) use ( $address ): array {
			$options = array_replace( array( 'ignore_errors'=>true, 'timeout'=>10 ), $options );
			$body = file_get_contents( 'http://' . $address . $path, false, stream_context_create( array( 'http'=>$options ) ) );
			return array( 'headers'=>$http_response_header ?? array(), 'body'=>json_decode( (string) $body, true ) );
		};
		$post = static fn( $pass ) => array( 'method'=>'POST', 'header'=>'Content-Type: application/x-www-form-urlencoded', 'content'=>http_build_query( array( 'login'=>'authentication_fixture', 'password'=>$pass ) ) );
		for ( $i = 0; $i < 10; ++$i ) { $reply = $request( '/login', $post( 'incorrect' ) ); }
		$reply = $request( '/login', $post( $password ) );
		$headers = implode( "\n", $reply['headers'] );
		$check( str_contains( $headers, '429' ) && 'adc_rate_limited' === ( $reply['body']['error'] ?? '' ), 'HTTP password login returns 429 on request eleven, even with correct credentials.' );
		$check( 1 === preg_match( '/Retry-After: [1-9][0-9]*/i', $headers ) && str_contains( strtolower( $headers ), 'no-cache' ), 'HTTP throttle sends Retry-After and non-cacheable headers.' );
		$reply = $request( '/login', array( 'method'=>'POST', 'header'=>'Content-Type: application/x-www-form-urlencoded', 'content'=>http_build_query( array( 'login'=>'other_authentication_fixture', 'password'=>$password ) ) ) );
		$check( $other_id === ( $reply['body']['id'] ?? 0 ), 'A different user on the same HTTP network can log in after the first user is throttled.' );
		$reset();
		$basic = array( 'header'=>'Authorization: Basic ' . base64_encode( 'authentication_fixture:' . $app[0] ) );
		$reply = $request( '/rest', $basic );
		$check( $id === ( $reply['body']['id'] ?? 0 ), 'Real HTTP REST Basic authentication returns its synthetic user.' );
		$wpdb->query( "UPDATE $table SET attempts=120 WHERE policy_key='application_auth'" );
		$reply = $request( '/rest', $basic );
		$check( str_contains( implode( "\n", $reply['headers'] ), '429' ) && 'adc_rate_limited' === ( $reply['body']['code'] ?? '' ), 'Real HTTP REST authentication returns structured 429 beyond its budget.' );
	} finally { proc_terminate( $server ); proc_close( $server ); if ( is_file( $log ) ) { unlink( $log ); } }
} finally {
	remove_filter( 'check_password', $counter );
	unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
}
echo "PASS: $checks real WordPress/MariaDB authentication checks.\n";
