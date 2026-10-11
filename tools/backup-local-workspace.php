<?php
/** Local database snapshot before applying an additive workspace upgrade. Not an off-site backup. */
if ( PHP_SAPI !== 'cli' || '--create' !== ( $argv[1] ?? '' ) ) { fwrite( STDERR, "Usage: php tools/backup-local-workspace.php --create\n" ); exit( 1 ); }
define( 'SHORTINIT', true ); require dirname( __DIR__ ) . '/wp-load.php';
global $wpdb;
$url = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='siteurl'" );
if ( ! in_array( parse_url( $url ?: '', PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { fwrite( STDERR, "This command is restricted to the local workspace.\n" ); exit( 2 ); }
$directory = realpath( sys_get_temp_dir() ); $root = realpath( dirname( __DIR__ ) );
if ( ! $directory || ! $root || str_starts_with( strtolower( $directory . DIRECTORY_SEPARATOR ), strtolower( $root . DIRECTORY_SEPARATOR ) ) ) { exit( 3 ); }
$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
foreach ( $tables ?: array() as $table ) { if ( 'InnoDB' !== $table['Engine'] || ! preg_match( '/\A[a-zA-Z0-9_]+\z/', $table['Name'] ) ) { fwrite( STDERR, "A consistent snapshot requires validated InnoDB base tables.\n" ); exit( 4 ); } }
$path = $directory . DIRECTORY_SEPARATOR . 'adc-workspace-backup-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 6 ) ) . '.sql';
$handle = fopen( $path, 'xb' ); if ( ! $handle ) { exit( 5 ); } chmod( $path, 0600 );
$write = static function ( string $sql ) use ( $handle ): void { if ( strlen( $sql ) !== fwrite( $handle, $sql ) ) { throw new RuntimeException( 'Backup write failed.' ); } };
try {
	if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) ) { throw new RuntimeException( 'Snapshot isolation unavailable.' ); }
	if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) { throw new RuntimeException( 'Snapshot failed.' ); }
	$write( "-- Local workspace database snapshot " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" );
	foreach ( $tables as $table ) {
		$name = $table['Name']; $create = $wpdb->get_row( "SHOW CREATE TABLE `$name`", ARRAY_N );
		if ( ! $create ) { throw new RuntimeException( 'Table definition unavailable.' ); }
		$write( $create[1] . ";\n" ); $offset = 0;
		do {
			$rows = $wpdb->get_results( "SELECT * FROM `$name` LIMIT 250 OFFSET " . $offset, ARRAY_A ); if ( $wpdb->last_error ) { throw new RuntimeException( 'Table read failed.' ); }
			foreach ( $rows ?: array() as $row ) {
				$columns = implode( ',', array_map( static fn( $column ) => '`' . str_replace( '`', '``', $column ) . '`', array_keys( $row ) ) );
				$values = implode( ',', array_map( static fn( $value ) => null === $value ? 'NULL' : ( '' === (string) $value ? "''" : "X'" . bin2hex( (string) $value ) . "'" ), array_values( $row ) ) );
				$write( "INSERT INTO `$name` ($columns) VALUES ($values);\n" );
			}
			$offset += 250;
		} while ( count( $rows ?: array() ) === 250 );
	}
	$write( "SET FOREIGN_KEY_CHECKS=1;\n" ); $wpdb->query( 'ROLLBACK' ); fflush( $handle ); fclose( $handle );
	echo json_encode( array( 'backup_path'=>$path, 'sha256'=>hash_file( 'sha256', $path ), 'tables'=>count( $tables ), 'bytes'=>filesize( $path ), 'offsite_verified'=>false ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) { $wpdb->query( 'ROLLBACK' ); fclose( $handle ); unlink( $path ); fwrite( STDERR, $error->getMessage() . "\n" ); exit( 6 ); }
