<?php
/** Audited catalog mapping and cutover reconciliation acceptance for 1.22. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Admin\CatalogCutoverPage;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\CatalogMappingService;
use AutoDealership\Inventory\PublicCatalog;

$mapping_posts = array();
$mapping_vehicles = array();
$mapping_original_posts = $wpdb->get_results( "SELECT ID,post_status FROM {$wpdb->posts} WHERE post_type='car'", ARRAY_A ) ?: array();
$mapping_table = Schema::table( 'vehicles' );
$mapping_original_mappings = $wpdb->get_results( "SELECT id,public_post_id FROM $mapping_table WHERE public_post_id>0", ARRAY_A ) ?: array();
$mapping_previous_user = get_current_user_id();

try {
	foreach ( $mapping_original_mappings as $mapping ) {
		$wpdb->update( $mapping_table, array( 'public_post_id'=>0 ), array( 'id'=>(int) $mapping['id'] ), array( '%d' ), array( '%d' ) );
	}
	foreach ( $mapping_original_posts as $post ) {
		$wpdb->update( $wpdb->posts, array( 'post_status'=>'draft' ), array( 'ID'=>(int) $post['ID'] ), array( '%s' ), array( '%d' ) );
		clean_post_cache( (int) $post['ID'] );
	}
	$empty_readiness = PublicCatalog::readiness();
	adc_check( 0 === $empty_readiness['published_posts'] && ! $empty_readiness['ready'], 'Catalog activation rejects an empty published catalog even when no mapping conflicts exist.' );

	if ( ! post_type_exists( 'car' ) ) { register_post_type( 'car', array( 'public'=>true, 'has_archive'=>true ) ); }
	foreach ( array( '<script>alert(1)</script> Cutover One', 'Cutover Two' ) as $title ) {
		$post_id = wp_insert_post( array( 'post_type'=>'car', 'post_status'=>'publish', 'post_title'=>$title ) );
		if ( is_wp_error( $post_id ) || $post_id < 1 ) { throw new RuntimeException( 'Catalog mapping post fixture failed.' ); }
		$mapping_posts[] = (int) $post_id;
	}
	$invalid_post = wp_insert_post( array( 'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'Not a vehicle post' ) );
	if ( is_wp_error( $invalid_post ) || $invalid_post < 1 ) { throw new RuntimeException( 'Invalid mapping post fixture failed.' ); }
	$mapping_posts[] = (int) $invalid_post;

	$now = current_time( 'mysql', true );
	foreach ( array(
		array( 'vin'=>'MAP12200000000001', 'stock_number'=>'MAP-122-ONE', 'model'=>'One' ),
		array( 'vin'=>'MAP12200000000002', 'stock_number'=>'MAP-122-TWO', 'model'=>'Two' ),
		array( 'vin'=>'MAP12200000000003', 'stock_number'=>'MAP-122-BAD', 'model'=>'Invalid' ),
	) as $index=>$identity ) {
		$row = array_merge( $identity, array( 'brand'=>'Mapping', 'model_year'=>2026, 'condition_key'=>'new', 'branch_id'=>$branch_a['id'], 'status'=>'available', 'retail_price'=>10000000 + ( $index * 10000 ), 'currency'=>'SAR', 'public_post_id'=>2 === $index ? (int) $invalid_post : 0, 'created_at'=>$now, 'updated_at'=>$now ) );
		if ( false === $wpdb->insert( $mapping_table, $row ) ) { throw new RuntimeException( 'Catalog mapping vehicle fixture failed: ' . $wpdb->last_error ); }
		$mapping_vehicles[] = (int) $wpdb->insert_id;
	}

	$initial_report = CatalogMappingService::report();
	adc_check( in_array( $mapping_posts[0], array_map( 'intval', wp_list_pluck( $initial_report['unmapped_posts'], 'post_id' ) ), true ) && in_array( $mapping_vehicles[0], array_map( 'intval', wp_list_pluck( $initial_report['unmapped_vehicles'], 'vehicle_id' ) ), true ) && in_array( $mapping_vehicles[2], array_map( 'intval', wp_list_pluck( $initial_report['invalid_mappings'], 'vehicle_id' ) ), true ), 'Cutover report identifies unmapped posts, unmapped inventory and invalid post types.' );
	adc_check( 64 === strlen( $initial_report['fingerprint'] ) && $initial_report['setup']['active_branches'] > 0 && $initial_report['issue_totals']['unmapped_vehicles'] >= 2, 'Cutover report produces a stable review fingerprint, complete issue totals and setup prerequisites.' );

	wp_set_current_user( $sales_a );
	$forbidden = CatalogMappingService::assign( $mapping_vehicles[0], $mapping_posts[0], 'Unauthorized mapping' );
	adc_check( is_wp_error( $forbidden ) && 'adc_catalog_mapping_forbidden' === $forbidden->get_error_code(), 'Non-administrator cannot change the catalog mapping.' );

	wp_set_current_user( $admin );
	$invalid = CatalogMappingService::assign( $mapping_vehicles[0], $invalid_post, 'Invalid post type' );
	adc_check( is_wp_error( $invalid ) && 'adc_invalid_catalog_post' === $invalid->get_error_code(), 'Catalog mapping accepts only vehicle posts.' );
	$cleared_invalid = CatalogMappingService::assign( $mapping_vehicles[2], 0, 'Clear invalid legacy catalog target' );
	adc_check( is_array( $cleared_invalid ) && $cleared_invalid['updated'] && 0 === PublicCatalog::readiness()['invalid_mappings'], 'Administrator can remove an invalid legacy target before cutover.' );

	$audit_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'vehicle.catalog_mapping_changed' ) );
	$mapped_one = CatalogMappingService::assign( $mapping_vehicles[0], $mapping_posts[0], 'Approved catalog link one' );
	$audit_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'vehicle.catalog_mapping_changed' ) );
	adc_check( is_array( $mapped_one ) && $mapped_one['updated'] && $audit_after === $audit_before + 1, 'Administrator creates an audited vehicle-to-post catalog mapping.' );

	add_filter( 'query', $break_audit );
	$failed_mapping = CatalogMappingService::assign( $mapping_vehicles[1], $mapping_posts[1], 'Mapping audit rollback' );
	remove_filter( 'query', $break_audit );
	$failed_mapping_post = (int) $wpdb->get_var( $wpdb->prepare( "SELECT public_post_id FROM $mapping_table WHERE id=%d", $mapping_vehicles[1] ) );
	adc_check( is_wp_error( $failed_mapping ) && 0 === $failed_mapping_post, 'Audit failure rolls the catalog mapping back.' );

	$mapped_two = CatalogMappingService::assign( $mapping_vehicles[1], $mapping_posts[1], 'Approved catalog link two' );
	$conflict = CatalogMappingService::assign( $mapping_vehicles[1], $mapping_posts[0], 'Attempt duplicate mapping' );
	$current_two = (int) $wpdb->get_var( $wpdb->prepare( "SELECT public_post_id FROM $mapping_table WHERE id=%d", $mapping_vehicles[1] ) );
	adc_check( is_array( $mapped_two ) && is_wp_error( $conflict ) && 'adc_catalog_post_conflict' === $conflict->get_error_code() && $mapping_posts[1] === $current_two, 'A post cannot be assigned to two operational vehicles.' );

	$idempotent_audit = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'vehicle.catalog_mapping_changed' ) );
	$idempotent = CatalogMappingService::assign( $mapping_vehicles[0], $mapping_posts[0], 'No change retry' );
	$idempotent_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'vehicle.catalog_mapping_changed' ) );
	adc_check( is_array( $idempotent ) && ! $idempotent['updated'] && $idempotent_audit === $idempotent_after, 'Identical catalog mapping retry is idempotent.' );

	$ready = PublicCatalog::readiness();
	$reconciled = CatalogMappingService::report();
	adc_check( $ready['ready'] && 0 === $ready['unmapped_published_posts'] && $initial_report['fingerprint'] !== $reconciled['fingerprint'], 'Completed published mappings satisfy the cutover gate and change the reconciliation fingerprint.' );
	$wpdb->query( $wpdb->prepare( "UPDATE $mapping_table SET status='maintenance' WHERE id IN (%d,%d)", $mapping_vehicles[0], $mapping_vehicles[1] ) );
	$no_eligible = PublicCatalog::readiness();
	adc_check( 0 === $no_eligible['eligible'] && ! $no_eligible['ready'], 'Authoritative activation rejects mappings that would produce an empty public catalog.' );
	$wpdb->query( $wpdb->prepare( "UPDATE $mapping_table SET status='available' WHERE id IN (%d,%d)", $mapping_vehicles[0], $mapping_vehicles[1] ) );

	ob_start(); CatalogCutoverPage::render(); $mapping_html = ob_get_clean();
	adc_check( str_contains( $mapping_html, 'adc_save_catalog_mapping' ) && str_contains( $mapping_html, 'adc-catalog-post-' . $mapping_vehicles[0] ) && str_contains( $mapping_html, 'MAP-122-ONE' ) && ! str_contains( $mapping_html, '<script>alert(1)</script>' ), 'Catalog cutover screen renders labelled protected mapping controls and escapes post titles.' );

	$unmapped = CatalogMappingService::assign( $mapping_vehicles[0], 0, 'Remove link for editorial correction' );
	adc_check( is_array( $unmapped ) && $unmapped['updated'] && ! PublicCatalog::readiness()['ready'], 'Audited unmapping immediately closes the authoritative activation gate.' );
} finally {
	wp_set_current_user( $mapping_previous_user );
	if ( $mapping_vehicles ) { $wpdb->query( 'DELETE FROM ' . $mapping_table . ' WHERE id IN (' . implode( ',', array_map( 'absint', $mapping_vehicles ) ) . ')' ); }
	foreach ( $mapping_posts as $post_id ) { wp_delete_post( $post_id, true ); }
	foreach ( $mapping_original_posts as $post ) {
		$wpdb->update( $wpdb->posts, array( 'post_status'=>$post['post_status'] ), array( 'ID'=>(int) $post['ID'] ), array( '%s' ), array( '%d' ) );
		clean_post_cache( (int) $post['ID'] );
	}
	foreach ( $mapping_original_mappings as $mapping ) {
		$wpdb->update( $mapping_table, array( 'public_post_id'=>(int) $mapping['public_post_id'] ), array( 'id'=>(int) $mapping['id'] ), array( '%d' ), array( '%d' ) );
	}
}
