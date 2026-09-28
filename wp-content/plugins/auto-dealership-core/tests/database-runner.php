<?php
/** CLI-only tests in a fresh database. Source WordPress is booted with SHORTINIT for credentials only. */
if ( PHP_SAPI !== 'cli' ) { exit; }
if ( '--worker' === ( $argv[1] ?? '' ) ) {
	$connection = json_decode( stream_get_contents( STDIN ), true );
	if ( ! is_array( $connection ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $connection['database'] ?? '' ) || ( $connection['database'] ?? '' ) === ( $connection['source_database'] ?? '' ) ) {
		fwrite( STDERR, "Invalid isolated database configuration.\n" );
		exit( 1 );
	}
	define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
	define( 'DB_NAME', $connection['database'] );
	define( 'DB_USER', $connection['user'] );
	define( 'DB_PASSWORD', $connection['password'] );
	define( 'DB_HOST', $connection['host'] );
	define( 'DB_CHARSET', 'utf8mb4' );
	define( 'DB_COLLATE', '' );
	define( 'ADC_TEST_HTTP_CONFIG', base64_encode( json_encode( array(
		'database' => $connection['database'],
		'host'     => $connection['host'],
		'user'     => $connection['user'],
		'password' => $connection['password'],
	), JSON_THROW_ON_ERROR ) ) );
	$test_secret = hash( 'sha512', ADC_TEST_HTTP_CONFIG . '|wordpress-isolated-http' );
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $secret_name ) {
		define( $secret_name, hash( 'sha512', $test_secret . '|' . $secret_name ) );
	}
	unset( $test_secret, $secret_name );
	$scenario = $connection['scenario'] ?? 'suite';
	$actor = (int) ( $connection['actor'] ?? 0 );
	$input = $connection['input'] ?? array();
	$barrier = $connection['barrier'] ?? '';
	unset( $connection );
	define( 'WP_INSTALLING', true );
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
	define( 'DISABLE_WP_CRON', true );
	define( 'WP_DEBUG', false );
	define( 'WP_CONTENT_DIR', __DIR__ . '/isolated-content' );
	define( 'WP_CONTENT_URL', 'http://adc-verification.invalid/content' );
	define( 'WP_SITEURL', 'http://adc-verification.invalid' );
	define( 'WP_HOME', WP_SITEURL );
	$table_prefix = 'test_';
	$_SERVER['HTTP_HOST'] = 'adc-verification.invalid';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	require ABSPATH . 'wp-settings.php';
	add_filter( 'pre_wp_mail', '__return_true' );
	if ( in_array( $scenario, array( 'reserve', 'migrate', 'crm_intake', 'crm_request', 'crm_merge', 'crm_quote', 'crm_erase' ), true ) && $actor >= 0 && is_array( $input ) && preg_match( '/\Aadc_gate_[a-f0-9]{16}\z/', $barrier ) ) {
		require __DIR__ . '/../auto-dealership-core.php';
		wp_set_current_user( $actor );
		global $wpdb;
		$wpdb->suppress_errors( true );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $barrier ) ) ) { exit( 2 ); }
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $barrier ) );
		if ( 'migrate' === $scenario ) {
			require __DIR__ . '/cli-double.php';
			( new \AutoDealership\Core\VehicleMigrationCommand() )( array(), $input );
			$message = WP_CLI::$messages[0];
			$result = json_decode( substr( $message, strpos( $message, '{' ) ), true, 512, JSON_THROW_ON_ERROR );
		} elseif ( 'crm_intake' === $scenario ) {
			$result = \AutoDealership\Leads\PublicIntake::submit( $input, 'message' );
		} elseif ( 'crm_request' === $scenario ) {
			$result = \AutoDealership\Leads\RequestWorkflow::update( (int) $input['lead_id'], $input );
		} elseif ( 'crm_merge' === $scenario ) {
			$result = \AutoDealership\Leads\CustomerIdentity::merge( (int) $input['source_id'], (int) $input['target_id'], $input['revision'], 'CONCURRENT-REVIEW', true );
		} elseif ( 'crm_quote' === $scenario ) {
			$result = \AutoDealership\Sales\SalesService::create_quote( (int) $input['customer_id'], (int) $input['vehicle_id'], $input['valid_until'] );
		} elseif ( 'crm_erase' === $scenario ) {
			$result = \AutoDealership\Privacy\PrivacyTools::erase( $input['email'] );
		} else {
			$result = \AutoDealership\Reservations\ReservationService::create( $input );
		}
		echo wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : $result );
		exit;
	}
	if ( 'suite' !== $scenario ) { exit( 2 ); }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$install = wp_install( 'Isolated verification', 'verify_admin', 'verify@example.invalid', false, '', wp_generate_password( 32, true, true ) );
	wp_set_current_user( (int) $install['user_id'] );
	require __DIR__ . '/../auto-dealership-core.php';
	\AutoDealership\Core\Capabilities::activate();
	wp_set_current_user( 0 );
	wp_set_current_user( (int) $install['user_id'] );
	\AutoDealership\Database\Schema::install();
	if ( '1' === getenv( 'ADC_JOURNEY_ONLY' ) ) { require __DIR__ . '/account-journey-fixture.php'; exit; }
	require __DIR__ . '/database-scenarios.php';
	exit;
}

// Dedicated disposable server mode does not load wp-config.php or contact the source database.
if ( '--isolated' === ( $argv[1] ?? '' ) ) {
	$port = filter_var( $argv[2] ?? '', FILTER_VALIDATE_INT );
	if ( ! $port || $port < 1024 || 3306 === $port || $port > 65535 ) { fwrite( STDERR, "Use a dedicated localhost test port (not 3306).\n" ); exit( 1 ); }
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	$database = 'adc_verify_' . bin2hex( random_bytes( 8 ) );
	$created = false;
	$code = 1;
	try {
		$db = new mysqli( '127.0.0.1', 'root', '', '', $port );
		// Refuse any server with application databases, including an existing test run.
		foreach ( $db->query( 'SHOW DATABASES' )->fetch_all() as $row ) {
			if ( ! in_array( $row[0], array( 'information_schema', 'mysql', 'performance_schema', 'sys', 'test' ), true ) ) { throw new RuntimeException( 'Server is not empty; refusing isolated run.' ); }
		}
		$db->query( "CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
		$created = true;
		$worker = proc_open( array( PHP_BINARY, __FILE__, '--worker' ), array( 0 => array( 'pipe', 'r' ), 1 => STDOUT, 2 => STDERR ), $pipes );
		if ( ! is_resource( $worker ) ) { throw new RuntimeException( 'Cannot start isolated worker.' ); }
		fwrite( $pipes[0], json_encode( array( 'database' => $database, 'source_database' => '', 'host' => '127.0.0.1:' . $port, 'user' => 'root', 'password' => '' ), JSON_THROW_ON_ERROR ) );
		fclose( $pipes[0] );
		$code = proc_close( $worker );
	} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); }
	finally {
		if ( $created ) {
			try { $db->query( "DROP DATABASE `$database`" ); echo "Disposable test database removed. Source database was not contacted.\n"; }
			catch ( Throwable $error ) { fwrite( STDERR, "Cleanup failed for $database.\n" ); $code = 1; }
		}
	}
	exit( $code );
}

define( 'SHORTINIT', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
global $wpdb, $wp_version;
$wpdb->suppress_errors( true );
if ( '--inspect' === ( $argv[1] ?? '' ) ) {
	$options = $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ('active_plugins','stylesheet','adc_db_version','adc_roles_version')", ARRAY_A );
	echo json_encode( array( 'php' => PHP_VERSION, 'wordpress' => $wp_version, 'database_server' => $wpdb->get_var( 'SELECT VERSION()' ), 'runtime_options' => $options ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit;
}
if ( '--run' !== ( $argv[1] ?? '' ) ) {
	echo "Usage: php database-runner.php --inspect | --run\n";
	exit( 1 );
}

$test_database = 'adc_verify_' . bin2hex( random_bytes( 8 ) );
$created = false;
$exit_code = 1;
try {
	if ( false === $wpdb->query( "CREATE DATABASE `$test_database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
		throw new RuntimeException( 'Cannot create an isolated test database. Source data was not changed.' );
	}
	$created = true;
	$process = proc_open( array( PHP_BINARY, __FILE__, '--worker' ), array( 0 => array( 'pipe', 'r' ), 1 => STDOUT, 2 => STDERR ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start isolated test worker.' ); }
	$payload = json_encode( array( 'database' => $test_database, 'source_database' => DB_NAME, 'host' => DB_HOST, 'user' => DB_USER, 'password' => DB_PASSWORD ), JSON_THROW_ON_ERROR );
	fwrite( $pipes[0], $payload );
	fclose( $pipes[0] );
	unset( $payload );
	$exit_code = proc_close( $process );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
} finally {
	// Drop only the exact random database successfully created by this process.
	if ( $created && $test_database !== DB_NAME && preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $test_database ) ) {
		if ( false === $wpdb->query( "DROP DATABASE `$test_database`" ) ) {
			fwrite( STDERR, "Isolated database cleanup failed: $test_database\n" );
			$exit_code = 1;
		} else {
			echo "Isolated database removed; source database unchanged by test scenarios.\n";
		}
	}
}
exit( $exit_code );
