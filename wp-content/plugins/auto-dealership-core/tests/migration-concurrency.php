<?php
/** Two independent WordPress connections import the same synthetic source post. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
$import_barrier = 'adc_gate_' . bin2hex( random_bytes( 8 ) );
if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $import_barrier ) ) ) { throw new RuntimeException( 'Cannot acquire migration test barrier.' ); }
$import_jobs = array();
try {
	for ( $i = 0; $i < 2; ++$i ) {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/database-runner.php', '--worker' ), array( 0=>array( 'pipe','r' ), 1=>array( 'pipe','w' ), 2=>STDERR ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start migration worker.' ); }
		fwrite( $pipes[0], json_encode( array( 'database'=>DB_NAME, 'source_database'=>'', 'host'=>DB_HOST, 'user'=>DB_USER, 'password'=>DB_PASSWORD, 'scenario'=>'migrate', 'actor'=>$admin, 'input'=>array( 'post-id'=>$parallel_post ), 'barrier'=>$import_barrier ), JSON_THROW_ON_ERROR ) );
		fclose( $pipes[0] );
		$import_jobs[] = array( $process, $pipes[1] );
	}
	usleep( 500000 );
} finally {
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $import_barrier ) );
}
$import_results = array();
$import_error = false;
foreach ( $import_jobs as list( $process, $output ) ) {
	$result = json_decode( stream_get_contents( $output ), true );
	fclose( $output );
	$exit_code = proc_close( $process );
	if ( 0 !== $exit_code || ! is_array( $result ) ) { $import_error = true; } else { $import_results[] = $result; }
}
adc_check( ! $import_error && 2 === count( $import_results ) && 1 === array_sum( array_column( $import_results, 'imported' ) ) && 1 === array_sum( array_column( $import_results, 'already_imported' ) ) && 0 === array_sum( array_column( $import_results, 'conflict' ) ), 'Concurrent migration workers produce one import and one idempotent skip.' );
$parallel_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $vehicles WHERE public_post_id=%d", $parallel_post ) );
adc_check( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $vehicles WHERE public_post_id=%d", $parallel_post ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \AutoDealership\Database\Schema::table( 'vehicle_movements' ) . ' WHERE vehicle_id=%d', $parallel_id ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $audit WHERE event_key='vehicle.legacy_imported' AND subject_id=%d", $parallel_id ) ), 'Concurrent import leaves exactly one vehicle, movement and import audit.' );
