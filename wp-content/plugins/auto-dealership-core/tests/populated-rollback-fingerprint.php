<?php
/** Read-only local table fingerprints for a populated rollback copy. */
if ( PHP_SAPI !== 'cli' || ! preg_match( '/\A(?:wp-autobrands|adc_verify_[a-f0-9]{16})\z/', $argv[1] ?? '' ) ) {
	fwrite( STDERR, "Usage: php populated-rollback-fingerprint.php wp-autobrands|adc_verify_<16 hex>\n" );
	exit( 1 );
}
$database = $argv[1];
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$db = new mysqli( '127.0.0.1', 'root', '', $database, 3306 );
$db->set_charset( 'utf8mb4' );
$tables = array();
foreach ( $db->query( 'SHOW TABLES' )->fetch_all( MYSQLI_NUM ) as $row ) {
	$name = $row[0];
	if ( ! preg_match( '/\Awp_[a-zA-Z0-9_]+\z/', $name ) ) { throw new RuntimeException( 'Unexpected table name.' ); }
	$count = (int) $db->query( 'SELECT COUNT(*) FROM `' . $name . '`' )->fetch_row()[0];
	$checksum = $db->query( 'CHECKSUM TABLE `' . $name . '` EXTENDED' )->fetch_assoc()['Checksum'];
	if ( null === $checksum ) { throw new RuntimeException( 'No checksum for ' . $name ); }
	$tables[$name] = array( 'rows' => $count, 'checksum' => (string) $checksum );
}
ksort( $tables );
echo json_encode( array( 'database' => $database, 'table_count' => count( $tables ), 'digest' => hash( 'sha256', json_encode( $tables, JSON_THROW_ON_ERROR ) ), 'tables' => $tables ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
