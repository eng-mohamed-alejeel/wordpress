<?php
/** Local recovery inspection. Never bootstrap the live WordPress configuration. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }

$mode = $argv[1] ?? '';
$port = filter_var( $argv[2] ?? '', FILTER_VALIDATE_INT );
if ( ! in_array( $mode, array( 'fingerprint','restore','rehearse' ), true ) || ! $port || $port < 1024 || $port > 65535 ) {
	fwrite( STDERR, "Usage: php tools/restore-rehearsal.php fingerprint|restore|rehearse PORT [archive-directory]\n" ); exit( 1 );
}
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$db = new mysqli( '127.0.0.1', 'root', '', '', $port );
$db->set_charset( 'utf8mb4' );
$state = $db->query( 'SELECT @@port port,@@datadir datadir,@@innodb_force_recovery recovery,@@event_scheduler scheduler' )->fetch_assoc();
$site_database = 'wp-autobrands';

// Mutating modes are confined to a freshly initialized temporary clone.
if ( 'fingerprint' !== $mode ) {
	$root = realpath( $argv[3] ?? '' );
	$normalized = static fn( string $path ): string => strtolower( str_replace( '\\', '/', rtrim( $path, '/\\' ) ) );
	$temp = $normalized( sys_get_temp_dir() );
	if ( 3306 === $port || ! $root || ! preg_match( '#^' . preg_quote( $temp, '#' ) . '/adc-restore-[a-f0-9]{32}$#', $normalized( $root ) ) || $normalized( $state['datadir'] ) !== $normalized( $root . '/mariadb' ) || '0' !== $state['recovery'] || 'OFF' !== $state['scheduler'] ) {
		throw new RuntimeException( 'Refusing operation outside the independent recovery clone.' );
	}
}

if ( 'restore' === $mode ) {
	$allowed = array( 'mysql','information_schema','performance_schema','sys','test' );
	foreach ( $db->query( 'SHOW DATABASES' )->fetch_all() as $row ) {
		if ( ! in_array( $row[0], $allowed, true ) ) { throw new RuntimeException( 'Restore target is not empty.' ); }
	}
	$dump = $root . '/site.sql';
	if ( ! is_file( $dump ) || filesize( $dump ) < 1 ) { throw new RuntimeException( 'Missing logical backup.' ); }
	$started = microtime( true );
	$process = proc_open( array( 'C:/xampp/mysql/bin/mysql.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=' . $port, '--user=root', '--binary-mode' ), array( 0=>array( 'file',$dump,'r' ), 1=>STDOUT, 2=>STDERR ), $pipes );
	if ( ! is_resource( $process ) || 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Logical restore failed.' ); }
	echo json_encode( array( 'restored'=>true, 'seconds'=>round( microtime( true ) - $started, 3 ), 'dump_sha256'=>hash_file( 'sha256', $dump ) ), JSON_PRETTY_PRINT ) . "\n";
	exit;
}

$db->select_db( $site_database );
if ( 'fingerprint' === $mode ) {
	// Only metadata and read statements; no source writes or WordPress hooks.
	$tables = array();
	foreach ( $db->query( 'SHOW FULL TABLES WHERE Table_type="BASE TABLE"' )->fetch_all() as $row ) {
		$name = $row[0];
		$quoted = '`' . str_replace( '`', '``', $name ) . '`';
		$count = $db->query( 'SELECT COUNT(*) FROM ' . $quoted )->fetch_row()[0];
		$checksum = $db->query( 'CHECKSUM TABLE ' . $quoted . ' EXTENDED' )->fetch_row()[1];
		if ( null === $checksum ) { throw new RuntimeException( 'Table checksum unavailable.' ); }
		$tables[$name] = array( 'rows'=>(int) $count, 'checksum'=>(string) $checksum );
	}
	ksort( $tables );
	echo json_encode( array( 'database'=>$site_database, 'port'=>$port, 'recovery'=>(int) $state['recovery'], 'tables'=>$tables ), JSON_PRETTY_PRINT ) . "\n";
	exit;
}
$db->close();

// Use restored plugin code, isolate content bootstrap, disable mail/network/cron.
$restored_content = $root . '/files/wp-content';
$plugin_file = $restored_content . '/plugins/auto-dealership-core/auto-dealership-core.php';
if ( ! is_file( $plugin_file ) ) { throw new RuntimeException( 'Restore the files archive before rehearsal.' ); }
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'DB_NAME', $site_database ); define( 'DB_HOST', '127.0.0.1:' . $port );
define( 'DB_USER', 'root' ); define( 'DB_PASSWORD', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'WP_INSTALLING', true ); define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_CONTENT_DIR', $root . '/empty-content' );
define( 'WP_CONTENT_URL', 'http://adc-rehearsal.invalid/content' );
define( 'WP_HOME', 'http://adc-rehearsal.invalid' ); define( 'WP_SITEURL', WP_HOME );
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = 'adc-rehearsal.invalid'; $_SERVER['REQUEST_METHOD'] = 'GET';
require ABSPATH . 'wp-settings.php';
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'rehearsal_network_disabled' ) );
require $plugin_file;

// Command adapter captures aggregate summaries, never customer field values.
final class WP_CLI {
	public static array $messages = array();
	public static function log( string $text ): void { self::$messages[] = $text; }
	public static function warning( string $text ): void { self::$messages[] = $text; }
	public static function error( string $text ): void { throw new RuntimeException( $text ); }
}
$schema = \AutoDealership\Database\Schema::verify();
if ( $schema ) { echo json_encode( array( 'schema_issues'=>$schema ), JSON_PRETTY_PRINT ); exit( 2 ); }
$admins = get_users( array( 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ) );
if ( ! $admins ) { throw new RuntimeException( 'No existing administrator in restored snapshot.' ); }
wp_set_current_user( (int) $admins[0] );
$before = \AutoDealership\Migration\MigrationInventory::report();
$commands = array( 'vehicles'=>new \AutoDealership\Core\VehicleMigrationCommand(), 'leads'=>new \AutoDealership\Core\LegacyLeadMigrationCommand() );
$runs = array();
foreach ( array( 'dry_run','import','retry' ) as $phase ) {
	foreach ( $commands as $name => $command ) {
		WP_CLI::$messages = array();
		$command( array(), 'dry_run' === $phase ? array( 'dry-run'=>true ) : array() );
		$runs[$phase][$name] = WP_CLI::$messages;
	}
}
$after = \AutoDealership\Migration\MigrationInventory::report();
$media = array( 'referenced'=>0, 'present'=>0, 'missing'=>0, 'unsafe_path'=>0 );
foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'" ) as $relative ) {
	++$media['referenced'];
	$path = realpath( $restored_content . '/uploads/' . $relative );
	$uploads = realpath( $restored_content . '/uploads' );
	if ( preg_match( '#(?:^|[/\\\\])\.\.(?:[/\\\\]|$)#', $relative ) || preg_match( '#^(?:[A-Za-z]:|[/\\\\])#', $relative ) ) { ++$media['unsafe_path']; continue; }
	if ( $path && $uploads && str_starts_with( str_replace( '\\','/', $path ), str_replace( '\\','/', $uploads ) . '/' ) && is_file( $path ) ) { ++$media['present']; } else { ++$media['missing']; }
}
echo json_encode( array( 'schema_issues'=>$schema, 'before'=>$before, 'commands'=>$runs, 'after'=>$after, 'media'=>$media ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
