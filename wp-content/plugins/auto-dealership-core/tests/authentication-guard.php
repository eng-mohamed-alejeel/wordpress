<?php
/** Offline real-hook/real-limiter regression. No WordPress bootstrap or database. */
namespace AutoDealership\Database {
	final class Schema {
		public static bool $ready = true;
		public static function is_ready(): bool { return self::$ready; }
		public static function table( string $name ): string { return 'test_adc_' . $name; }
	}
}
namespace AutoDealership\Security {
	function time(): int { return $GLOBALS['test_clock']; }
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit; }
	define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	require ABSPATH . 'wp-includes/plugin.php';
	require ABSPATH . 'wp-includes/class-wp-error.php';
	function __( $text, $domain = '' ) { return $text; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_salt( $scheme ) { return 'offline-test-salt-only'; }
	function get_current_user_id() { throw new RuntimeException( 'Authentication limiter must not resolve current user recursively.' ); }
	function status_header( $status ) { $GLOBALS['response_status'] = $status; }
	function nocache_headers() {}
	class WP_User { public int $ID = 7; }
	function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
	function sanitize_email( $email ) { return filter_var( $email, FILTER_SANITIZE_EMAIL ); }
	function get_user_by( $field, $login ) { return in_array( strtolower( $login ), array( 'user', 'user@example.invalid' ), true ) ? new WP_User() : false; }
	function wp_is_application_passwords_available() { return $GLOBALS['application_available']; }
	function validator( $user, $username, $password ) {
		++$GLOBALS['hash_calls'];
		if ( $user instanceof WP_User ) { return $user; }
		return 'correct' === $password ? new WP_User() : new WP_Error( 'incorrect_password', 'Invalid credentials' );
	}
	function wp_authenticate_username_password( $user, $username, $password ) { return validator( $user, $username, $password ); }
	function wp_authenticate_email_password( $user, $username, $password ) { return validator( $user, $username, $password ); }
	function wp_authenticate_application_password( $user, $username, $password ) { return validator( $user, $username, $password ); }
	function wp_validate_application_password( $user ) {
		$result = wp_authenticate_application_password( null, $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		return $result instanceof WP_User ? $result->ID : $user;
	}
	final class AtomicLimitRecorder {
		public array $buckets = array();
		public int $insert_id = 0;
		public bool $fail = false;
		public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
		public function query( $prepared ) {
			if ( $this->fail ) { return false; }
			list( $sql, $args ) = $prepared;
			if ( ! str_contains( $sql, 'ON DUPLICATE KEY UPDATE attempts=LAST_INSERT_ID(attempts+1)' ) ) { throw new RuntimeException( 'Expected atomic limiter statement.' ); }
			list( $key, $policy, $started, $expires ) = $args;
			$attempt = 1 + ( $this->buckets[$key]['attempts'] ?? 0 );
			$this->buckets[$key] = array( 'policy'=>$policy, 'attempts'=>$attempt );
			$this->insert_id = $attempt;
			return 1 === $attempt ? 1 : 2;
		}
	}
	$GLOBALS['wpdb'] = new AtomicLimitRecorder();
	$GLOBALS['test_clock'] = 1800000000;
	$GLOBALS['hash_calls'] = 0;
	$GLOBALS['application_available'] = true;
	$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	$base = dirname( __DIR__ ) . '/src/Security/';
	require $base . 'ClientAddress.php';
	require $base . 'PublicRequestGuard.php';
	require $base . 'AuthenticationGuard.php';
	use AutoDealership\Security\AuthenticationGuard as Guard;
	use AutoDealership\Security\PublicRequestGuard as Limits;
	use AutoDealership\Database\Schema;
	foreach ( array( 'wp_authenticate_username_password', 'wp_authenticate_email_password', 'wp_authenticate_application_password' ) as $callback ) { add_filter( 'authenticate', $callback, 20, 3 ); }
	add_filter( 'determine_current_user', 'wp_validate_application_password', 20 );
	Guard::boot(); Guard::boot();
	$checks = 0;
	$check = static function ( $ok, $message ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$checks; };
	$auth = static fn( $username, $password ) => apply_filters( 'authenticate', null, $username, $password );
	$limited = static fn( $value ) => is_wp_error( $value ) && 'adc_rate_limited' === $value->get_error_code();
	for ( $i = 0; $i < 10; ++$i ) { $check( ! $limited( $auth( 'user', 'wrong' ) ), 'First ten attempts reach core validators.' ); }
	$before = $GLOBALS['hash_calls'];
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.99';
	$error = $auth( 'USER@example.invalid', 'correct' );
	$check( $limited( $error ), 'Account aliases and untrusted forwarded headers do not bypass a blocked account/address.' );
	$check( $GLOBALS['hash_calls'] === $before, 'Blocked attempts never reach any core password hasher.' );
	$check( 429 === $error->get_error_data()['status'] && $error->get_error_data()['retry_after'] > 0, 'Throttle includes status and retry metadata.' );
	$check( 11 === array_values( $GLOBALS['wpdb']->buckets )[0]['attempts'], 'One charge per attempt, not per native validator or duplicate boot.' );
	$check( $auth( 'other@example.invalid', 'correct' ) instanceof WP_User, 'Another account on the SAME address remains usable.' );
	$check( 11 === array_values( $GLOBALS['wpdb']->buckets )[1]['attempts'], 'Blocked narrow attempts do not spend the shared network allowance.' );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
	$check( $auth( 'same-victim', 'correct' ) instanceof WP_User, 'Another address can still authenticate; no global victim-account lockout.' );
	$GLOBALS['test_clock'] += 900;
	$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	$check( $auth( 'user', 'correct' ) instanceof WP_User, 'Window expiry permits authentication again.' );
	// Each invocation inside one PHP request needs its own decision (XML-RPC multicall).
	for ( $i = 1; $i < 10; ++$i ) { $auth( 'user', 'wrong' ); }
	$check( $limited( $auth( 'user', 'correct' ) ), 'Repeated calls in one process cannot reuse an earlier allowed decision.' );
	$override = static fn() => new WP_User();
	add_filter( 'authenticate', $override, 99 );
	$check( $limited( $auth( 'user', 'correct' ) ), 'Later ordinary authentication filters cannot overwrite a throttle error.' );
	remove_filter( 'authenticate', $override, 99 );
	$before = count( $GLOBALS['wpdb']->buckets );
	$auth( '', '' );
	$check( count( $GLOBALS['wpdb']->buckets ) === $before, 'Empty credentials do not consume a bucket.' );
	// Native cookie sessions skip Basic authentication and do not consume quotas.
	$_SERVER['PHP_AUTH_USER'] = 'integration'; $_SERVER['PHP_AUTH_PW'] = 'correct';
	$check( 44 === apply_filters( 'determine_current_user', 44 ), 'Existing cookie session remains intact.' );
	$check( count( $GLOBALS['wpdb']->buckets ) === $before, 'Cookie requests do not spend the Basic budget.' );
	$check( 7 === apply_filters( 'determine_current_user', false ), 'Application-password authentication has an independent operational budget.' );
	for ( $i = 1; $i < 120; ++$i ) { apply_filters( 'determine_current_user', false ); }
	$before_hash = $GLOBALS['hash_calls'];
	$check( false === apply_filters( 'determine_current_user', false ), 'Application-password request beyond its limit remains anonymous.' );
	$check( $GLOBALS['hash_calls'] === $before_hash, 'Basic throttle stops before application-password hashing.' );
	$check( $limited( apply_filters( 'rest_authentication_errors', null ) ), 'REST receives the structured 429 error.' );
	$_SERVER['PHP_AUTH_USER'] = 'another-integration';
	$check( 7 === apply_filters( 'determine_current_user', false ), 'Another API account on the same network remains usable.' );
	$_SERVER['PHP_AUTH_USER'] = 'integration';
	$check( false === apply_filters( 'determine_current_user', false ), 'Original blocked API account remains limited.' );
	$GLOBALS['test_clock'] += 60;
	$check( 7 === apply_filters( 'determine_current_user', false ), 'Application budget expires independently.' );
	$check( null === apply_filters( 'rest_authentication_errors', null ), 'A new permitted determination clears the previous REST error.' );
	$GLOBALS['application_available'] = false;
	$before = count( $GLOBALS['wpdb']->buckets );
	$check( false === apply_filters( 'determine_current_user', false ), 'Disabled application passwords stay disabled.' );
	$check( count( $GLOBALS['wpdb']->buckets ) === $before, 'Disabled authentication does not create buckets.' );
	$GLOBALS['application_available'] = true;
	Schema::$ready = false;
	$error = $auth( 'user', 'correct' );
	$check( is_wp_error( $error ) && 'adc_rate_unavailable' === $error->get_error_code() && 503 === $error->get_error_data()['status'], 'Missing schema fails closed with service-unavailable error.' );
	Schema::$ready = true; $GLOBALS['wpdb']->fail = true;
	$error = $auth( 'user', 'correct' );
	$check( is_wp_error( $error ) && 'adc_rate_unavailable' === $error->get_error_code(), 'Storage failure cannot bypass authentication protection.' );
	$check( false === apply_filters( 'determine_current_user', false ) && 'adc_rate_unavailable' === apply_filters( 'rest_authentication_errors', null )->get_error_code(), 'Storage failure also blocks REST Basic authentication.' );
	$GLOBALS['wpdb']->fail = false;
	// Independent registrations and a shared-network ceiling for username rotation.
	$GLOBALS['test_clock'] += 3600;
	for ( $i = 0; $i < 5; ++$i ) { $check( null === Guard::registration( 'first@example.invalid' ), 'Registration budget permits first five submissions.' ); }
	$check( $limited( Guard::registration( 'FIRST@example.invalid' ) ), 'Repeated registration email is throttled case-insensitively.' );
	$check( null === Guard::registration( 'second@example.invalid' ), 'Different registration email remains usable on the same address.' );
	$check( $auth( 'user', 'correct' ) instanceof WP_User, 'Registration does not consume sign-in budget.' );
	$GLOBALS['wpdb']->buckets = array();
	for ( $i = 0; $i < 300; ++$i ) { $result = $auth( 'rotating-' . $i, 'wrong' ); if ( $limited( $result ) ) { throw new RuntimeException( 'Network ceiling reached too soon.' ); } }
	$check( $limited( $auth( 'next-rotating-user', 'correct' ) ), 'Rotating accounts cannot bypass the generous network safety ceiling.' );
	$private_keys = array_filter( array_keys( $GLOBALS['wpdb']->buckets ), static fn( $key ) => 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $key ) );
	$check( array() === $private_keys, 'Only HMAC bucket identities are persisted.' );
	echo "PASS: $checks authentication guard checks; no database was used.\n";
}
