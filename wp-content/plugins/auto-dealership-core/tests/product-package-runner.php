<?php
/** Fresh WordPress installation using ZIP contents only, on a dedicated empty test server. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = dirname( __DIR__, 4 );
if ( '--worker' === ( $argv[1] ?? '' ) ) {
	$config = json_decode( (string) file_get_contents( $argv[2] ), true );
	if ( ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $config['database'] ?? '' ) ) { exit( 2 ); }
	define( 'ABSPATH', $root . '/' );
	define( 'DB_NAME', $config['database'] ); define( 'DB_HOST', '127.0.0.1:' . $config['port'] ); define( 'DB_USER', 'root' ); define( 'DB_PASSWORD', '' ); define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
	define( 'WP_CONTENT_DIR', $config['content'] ); define( 'WP_CONTENT_URL', 'http://product.invalid/wp-content' ); define( 'WP_HOME', 'http://product.invalid' ); define( 'WP_SITEURL', WP_HOME );
	define( 'WP_INSTALLING', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'DISABLE_WP_CRON', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'WP_DEBUG', false );
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) { define( $key, hash( 'sha512', $config['token'] . $key ) ); }
	$table_prefix = 'test_'; $_SERVER['HTTP_HOST'] = 'product.invalid'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	require ABSPATH . 'wp-includes/plugin.php'; add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	require ABSPATH . 'wp-settings.php'; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$install = wp_install( 'Example Motors', 'product_admin', 'qa@example.invalid', false, '', wp_generate_password( 32, true ) );
	wp_set_current_user( $install['user_id'] );
	$result = activate_plugin( 'auto-dealership-core/auto-dealership-core.php' ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	switch_theme( 'car-dealer' );
	\AutoDealership\Leads\NewsletterService::boot();
	$result = \AutoDealership\Core\ProductSetup::apply( array( 'profile'=>array( 'brand_ar'=>'معرض الاختبار', 'brand_en'=>'Example Motors', 'language'=>'ar', 'timezone'=>'Asia/Riyadh' ) ) );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	$pages = \AutoDealership\Core\ProductSetup::create_pages(); if ( is_wp_error( $pages ) ) { throw new RuntimeException( $pages->get_error_message() ); }
	$config['admin'] = $install['user_id']; file_put_contents( $argv[2], json_encode( $config ) );
	global $wpdb;
	foreach ( array( 'vehicles', 'onyx_stock', 'customers', 'leads' ) as $table ) { if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \AutoDealership\Database\Schema::table( $table ) ) ) { throw new RuntimeException( 'Unexpected business data.' ); } }
	if ( is_dir( WP_CONTENT_DIR . '/mu-plugins' ) || ! function_exists( 'autobrands_mail_config' ) || 'disabled' !== autobrands_mail_config()['mode'] ) { throw new RuntimeException( 'Packages must provide mail without MU or local credentials.' ); }
	echo "PASS Package: clean installation and activation from ZIPs; mail disabled; no MU dependencies or demo business data.\n"; exit;
}
if ( '--isolated' !== ( $argv[1] ?? '' ) || empty( $argv[3] ) ) { fwrite( STDERR, "Usage: php product-package-runner.php --isolated PORT PACKAGE_DIRECTORY\n" ); exit( 1 ); }
$port = (int) $argv[2]; $package = realpath( $argv[3] );
if ( $port < 1024 || in_array( $port, array( 3306, 3307 ), true ) || ! $package ) { exit( 2 ); }
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$db = new mysqli( '127.0.0.1', 'root', '', '', $port );
foreach ( $db->query( 'SHOW DATABASES' )->fetch_all() as $row ) { if ( ! in_array( $row[0], array( 'information_schema', 'mysql', 'performance_schema', 'sys', 'test' ), true ) ) { throw new RuntimeException( 'Dedicated test server must be empty.' ); } }
$manifest = json_decode( file_get_contents( $package . '/manifest.json' ), true );
if ( ! is_array( $manifest ) || empty( $manifest['artifacts'] ) ) { throw new RuntimeException( 'Invalid package manifest.' ); }
$directory = sys_get_temp_dir() . '/adc-product-' . bin2hex( random_bytes( 12 ) ); mkdir( $directory, 0700 ); mkdir( $directory . '/content', 0700 );
$database = 'adc_verify_' . bin2hex( random_bytes( 8 ) ); $server = null;
$remove = static function ( string $dir ) use ( &$remove, $directory ): void {
	$real = realpath( $dir ); $base = realpath( $directory );
	if ( ! $real || ! $base || ( $real !== $base && ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) || is_link( $dir ) ) { throw new RuntimeException( 'Unsafe cleanup target.' ); }
	foreach ( new DirectoryIterator( $dir ) as $item ) { if ( $item->isDot() ) { continue; } $item->isDir() && ! $item->isLink() ? $remove( $item->getPathname() ) : unlink( $item->getPathname() ); } rmdir( $dir );
};
try {
	foreach ( $manifest['artifacts'] as $artifact ) {
		$zipPath = $package . '/' . basename( $artifact['name'] ); if ( ! hash_equals( $artifact['sha256'], hash_file( 'sha256', $zipPath ) ) ) { throw new RuntimeException( 'Package hash mismatch.' ); }
		$zip = new ZipArchive(); if ( true !== $zip->open( $zipPath ) ) { throw new RuntimeException( 'Cannot open package.' ); }
		$plugin = str_starts_with( $artifact['name'], 'auto-dealership-core-' ); $prefix = $plugin ? 'auto-dealership-core/' : 'car-dealer/';
		$target = $directory . '/content/' . ( $plugin ? 'plugins' : 'themes' ); if ( ! is_dir( $target ) ) { mkdir( $target, 0700 ); }
		for ( $i=0; $i<$zip->numFiles; ++$i ) { $name = $zip->getNameIndex( $i ); if ( ! str_starts_with( $name, $prefix ) || str_contains( $name, '..' ) || str_contains( $name, '\\' ) ) { throw new RuntimeException( 'Unsafe archive entry.' ); } }
		$zip->extractTo( $target ); $zip->close();
		foreach ( $artifact['files'] as $item ) { if ( ! hash_equals( $item['sha256'], hash_file( 'sha256', $target . '/' . $item['path'] ) ) ) { throw new RuntimeException( 'Extracted file mismatch.' ); } }
	}
	$db->query( "CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
	$configFile = $directory . '/configuration.json';
	$config = array( 'database'=>$database, 'port'=>$port, 'content'=>$directory . '/content', 'token'=>bin2hex( random_bytes( 32 ) ) ); file_put_contents( $configFile, json_encode( $config ) ); chmod( $configFile, 0600 );
	$worker = proc_open( array( PHP_BINARY, __FILE__, '--worker', $configFile ), array( 0=>array( 'pipe','r' ), 1=>STDOUT, 2=>STDERR ), $pipes ); fclose( $pipes[0] ); if ( 0 !== proc_close( $worker ) ) { throw new RuntimeException( 'Package installation failed.' ); }
	$socket = stream_socket_server( 'tcp://127.0.0.1:0' ); $address = stream_socket_get_name( $socket, false ); fclose( $socket ); $httpPort = (int) substr( $address, strrpos( $address, ':' ) + 1 );
	$environment = getenv(); $environment['ADC_PRODUCT_TEST_CONFIG'] = $configFile;
	$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, __DIR__ . '/product-package-router.php' ), array( 0=>array( 'pipe','r' ), 1=>array( 'file',$directory . '/http.log','a' ), 2=>array( 'file',$directory . '/http.log','a' ) ), $pipes, $root, $environment ); fclose( $pipes[0] );
	for ( $i=0; $i<50; ++$i ) { $probe = @fsockopen( '127.0.0.1', $httpPort, $errno, $errstr, 0.1 ); if ( $probe ) { fclose( $probe ); break; } usleep( 100000 ); }
	$browser = proc_open( array( 'node', __DIR__ . '/product-package-browser.cjs' ), array( 0=>array( 'pipe','r' ), 1=>STDOUT, 2=>STDERR ), $pipes, $root );
	fwrite( $pipes[0], json_encode( array( 'root'=>$root, 'base'=>'http://127.0.0.1:' . $httpPort, 'key'=>$config['token'] ) ) ); fclose( $pipes[0] );
	if ( 0 !== proc_close( $browser ) ) { echo file_get_contents( $directory . '/http.log' ); throw new RuntimeException( 'Package browser acceptance failed.' ); }
	echo "PASS Package: extracted files match manifests and clean WordPress runs on the two ZIPs only.\n";
} finally {
	if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); }
	$db->query( "DROP DATABASE IF EXISTS `$database`" ); $db->close(); $remove( $directory );
	echo "Disposable package database and extracted files removed. Source database was not contacted.\n";
}
