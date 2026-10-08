<?php
/** Aggregate-only, read-only audit. No personal identifiers appear in its output. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'SHORTINIT', true );
require dirname( __DIR__ ) . '/wp-load.php';
global $wpdb;
$tables = array();
foreach ( array( 'customers', 'brands', 'vehicles' ) as $name ) {
	$table = $wpdb->prefix . 'adc_' . $name;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { throw new RuntimeException( 'Required audit table is missing: ' . $name ); }
	$tables[$name] = $table;
}
$count = static function( string $sql ) use ( $wpdb ): int {
	$value = $wpdb->get_var( $sql );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Read-only audit query failed.' ); }
	return (int) $value;
};
$metrics = array();
foreach ( array( 'mobile', 'email' ) as $field ) {
	// Equality after trimming/case normalization flags review candidates, not a
	// license to merge: shared family/company contacts may be legitimate.
	$metrics['customer_' . $field . '_duplicate_groups'] = $count( "SELECT COUNT(*) FROM (SELECT LOWER(TRIM($field)) k FROM {$tables['customers']} WHERE merged_into_id IS NULL AND TRIM($field)<>'' GROUP BY k HAVING COUNT(*)>1) candidates" );
}
$metrics['brand_name_ar_duplicate_groups'] = $count( "SELECT COUNT(*) FROM (SELECT TRIM(name_ar) k FROM {$tables['brands']} WHERE active=1 AND TRIM(name_ar)<>'' GROUP BY k HAVING COUNT(*)>1) candidates" );
$metrics['brand_name_en_duplicate_groups'] = $count( "SELECT COUNT(*) FROM (SELECT LOWER(TRIM(name_en)) k FROM {$tables['brands']} WHERE active=1 AND TRIM(name_en)<>'' GROUP BY k HAVING COUNT(*)>1) candidates" );
$metrics['vehicle_post_duplicate_groups'] = $count( "SELECT COUNT(*) FROM (SELECT public_post_id FROM {$tables['vehicles']} WHERE public_post_id>0 GROUP BY public_post_id HAVING COUNT(*)>1) candidates" );
$metrics['vehicle_post_invalid_links'] = $count( "SELECT COUNT(*) FROM {$tables['vehicles']} v LEFT JOIN {$wpdb->posts} p ON p.ID=v.public_post_id WHERE v.public_post_id>0 AND (p.ID IS NULL OR p.post_type<>'car')" );
$metrics['published_car_unmapped_count'] = $count( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$tables['vehicles']} v ON v.public_post_id=p.ID WHERE p.post_type='car' AND p.post_status='publish' AND v.id IS NULL" );
$metrics['operational_vehicle_count'] = $count( "SELECT COUNT(*) FROM {$tables['vehicles']}" );
$metrics['customer_count'] = $count( "SELECT COUNT(*) FROM {$tables['customers']} WHERE merged_into_id IS NULL" );
$metrics['active_brand_count'] = $count( "SELECT COUNT(*) FROM {$tables['brands']} WHERE active=1" );
echo json_encode( array( 'read_only'=>true, 'metrics'=>$metrics, 'limits'=>array( 'Customer matching uses existing stored contacts, trimming and email case normalization; it does not equate local/international phone formats.', 'Equal brand names and contact values require human review; no records were merged.', 'Public content can exist legitimately without operational inventory in compatibility mode.' ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
