<?php
/** Read-only verification of the phase/type queries against the local schema. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
$ids = get_users( array( 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ) );
wp_set_current_user( (int) $ids[0] );
global $wpdb;
foreach ( array( '', 'received', 'inspection' ) as $phase ) {
 $rows = \AutoDealership\Inventory\VehicleService::list_for_current_user( 1, $phase );
 if ( $wpdb->last_error ) { throw new RuntimeException( 'Vehicle phase query failed.' ); }
 foreach ( $rows as $row ) {
  if ( ! array_key_exists( 'location_id', $row ) || ( $phase && $row['status'] !== $phase ) ) { throw new RuntimeException( 'Wrong vehicle phase or missing location.' ); }
 }
 echo 'PASS vehicle phase ' . ( $phase ?: 'all' ) . ': ' . count( $rows ) . " records, valid query.\n";
}
foreach ( array( 'hold', 'maintenance' ) as $type ) {
 $rows = \AutoDealership\Inventory\VehicleIssueService::list_open( $type );
 if ( $wpdb->last_error ) { throw new RuntimeException( 'Issue type query failed.' ); }
 foreach ( $rows as $row ) { if ( $row['issue_type'] !== $type ) { throw new RuntimeException( 'Mixed issue types.' ); } }
 echo "PASS issue type $type: " . count( $rows ) . " records, valid query.\n";
}
