<?php
/** Restore a locally generated snapshot on an empty disposable MariaDB server, then remove it. */
if ( PHP_SAPI !== 'cli' || '--verify' !== ( $argv[1] ?? '' ) || 4 !== $argc ) { fwrite( STDERR, "Usage: php tools/verify-local-backup.php --verify BACKUP_FILE ISOLATED_PORT\n" ); exit( 1 ); }
$path = realpath( $argv[2] ); $port = filter_var( $argv[3], FILTER_VALIDATE_INT, array( 'options'=>array( 'min_range'=>1025, 'max_range'=>65535 ) ) );
if ( ! $path || ! $port || in_array( $port, array( 3306, 3307 ), true ) || ! preg_match( '/\Aadc-workspace-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}\.sql\z/', basename( $path ) ) ) { exit( 2 ); }
$sql = file_get_contents( $path ); if ( ! $sql || ! str_starts_with( $sql, '-- Local workspace database snapshot ' ) || preg_match( '/\b(?:DROP|USE|GRANT|OUTFILE|LOAD\s+DATA|CREATE\s+DATABASE)\b/i', $sql ) ) { fwrite( STDERR, "Unrecognized local snapshot format.\n" ); exit( 3 ); }
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$connection = new mysqli( '127.0.0.1', 'root', '', '', $port ); $connection->set_charset( 'utf8mb4' );
$databases = $connection->query( 'SHOW DATABASES' )->fetch_all( MYSQLI_NUM );
foreach ( $databases as $row ) {
	// MariaDB's installer may create an empty test database; it is never modified.
	if ( 'test' === $row[0] && 0 === (int) $connection->query( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='test'" )->fetch_row()[0] ) { continue; }
	if ( ! in_array( $row[0], array( 'information_schema','mysql','performance_schema','sys' ), true ) ) { fwrite( STDERR, "Refusing a server containing a non-system database.\n" ); exit( 4 ); }
}
$database = 'adc_restore_verify_' . bin2hex( random_bytes( 8 ) );
if ( ! preg_match( '/\Aadc_restore_verify_[a-f0-9]{16}\z/', $database ) ) { exit( 5 ); }
$connection->query( "CREATE DATABASE `$database` CHARACTER SET utf8mb4" );
try {
	$connection->select_db( $database ); $connection->multi_query( $sql );
	do { if ( $result = $connection->store_result() ) { $result->free(); } } while ( $connection->more_results() && $connection->next_result() );
	preg_match_all( '/^CREATE TABLE `([a-zA-Z0-9_]+)`/m', $sql, $definitions ); $total_rows = 0;
	foreach ( $definitions[1] as $table ) {
		$expected = preg_match_all( '/^INSERT INTO `' . preg_quote( $table, '/' ) . '` /m', $sql );
		$actual = (int) $connection->query( "SELECT COUNT(*) FROM `$table`" )->fetch_row()[0];
		if ( $expected !== $actual ) { throw new RuntimeException( 'Restored row count differs from the snapshot.' ); } $total_rows += $actual;
	}
	echo json_encode( array( 'verified'=>true, 'artifact_sha256'=>hash_file( 'sha256', $path ), 'tables'=>count( $definitions[1] ), 'rows'=>$total_rows, 'isolated_port'=>$port, 'offsite_verified'=>false ), JSON_PRETTY_PRINT ) . "\n";
} finally { $connection->query( "DROP DATABASE `$database`" ); $connection->close(); }
