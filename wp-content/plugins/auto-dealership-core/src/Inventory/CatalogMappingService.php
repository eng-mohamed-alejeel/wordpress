<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Administrator-controlled, audited mapping between operational vehicles and editorial car posts. */
final class CatalogMappingService {
	public static function assign( int $vehicle_id, int $post_id, string $reason ) {
		global $wpdb;
		$reason = sanitize_textarea_field( $reason );
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_catalog_mapping_forbidden', __( 'Administrator access is required.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		if ( get_option( 'adc_db_version' ) !== Schema::VERSION ) {
			return new \WP_Error( 'adc_schema_unavailable', __( 'The verified dealership schema is required.', 'auto-dealership-core' ), array( 'status'=>503 ) );
		}
		if ( $vehicle_id < 1 || '' === trim( $reason ) || mb_strlen( $reason ) > 500 ) {
			return new \WP_Error( 'adc_invalid_catalog_mapping', __( 'Choose a vehicle and provide a reason of up to 500 characters.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || 'car' !== $post->post_type || in_array( $post->post_status, array( 'trash','auto-draft' ), true ) ) {
				return new \WP_Error( 'adc_invalid_catalog_post', __( 'Choose a valid vehicle post that is not trashed.', 'auto-dealership-core' ), array( 'status'=>400 ) );
			}
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', '', array( 'status'=>500 ) );
		}
		$table = Schema::table( 'vehicles' );
		$vehicle = $wpdb->get_row( $wpdb->prepare( "SELECT id,public_post_id FROM $table WHERE id=%d FOR UPDATE", $vehicle_id ), ARRAY_A );
		if ( ! $vehicle ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vehicle_not_found', __( 'Vehicle not found.', 'auto-dealership-core' ), array( 'status'=>404 ) );
		}
		if ( $post_id > 0 ) {
			$conflict = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE public_post_id=%d AND id<>%d LIMIT 1 FOR UPDATE", $post_id, $vehicle_id ) );
			if ( $conflict > 0 ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_catalog_post_conflict', __( 'This post is already mapped to another operational vehicle.', 'auto-dealership-core' ), array( 'status'=>409, 'vehicle_id'=>$conflict ) );
			}
		}
		$before = (int) $vehicle['public_post_id'];
		if ( $before === $post_id ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'vehicle_id'=>$vehicle_id, 'public_post_id'=>$post_id, 'updated'=>false );
		}
		$updated = $wpdb->update( $table, array( 'public_post_id'=>$post_id, 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>$vehicle_id, 'public_post_id'=>$before ), array( '%d','%s' ), array( '%d','%d' ) );
		if ( 1 !== $updated || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.catalog_mapping_changed', 'vehicle', $vehicle_id, $reason, array( 'public_post_id'=>$before ), array( 'public_post_id'=>$post_id ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_catalog_mapping_failed', __( 'The catalog mapping could not be saved and audited.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return array( 'vehicle_id'=>$vehicle_id, 'public_post_id'=>$post_id, 'updated'=>true );
	}

	/** Detailed, bounded reconciliation data without VIN, costs, customers or financial fields. */
	public static function report( int $limit = 100 ): array {
		global $wpdb;
		$limit = min( 200, max( 1, $limit ) );
		$readiness = PublicCatalog::readiness();
		$empty = array( 'readiness'=>$readiness, 'setup'=>array(), 'issue_totals'=>array( 'unmapped_posts'=>0, 'unmapped_vehicles'=>0, 'duplicate_mappings'=>0, 'invalid_mappings'=>0 ), 'unmapped_posts'=>array(), 'unmapped_vehicles'=>array(), 'duplicate_mappings'=>array(), 'invalid_mappings'=>array(), 'fingerprint'=>'' );
		if ( ! $readiness['schema_ready'] ) { return $empty; }
		$vehicles = Schema::table( 'vehicles' );
		$branches = Schema::table( 'branches' );
		$brands = Schema::table( 'brands' );
		$locations = Schema::table( 'locations' );
		$setup = array(
			'active_branches'=>(int) $wpdb->get_var( "SELECT COUNT(*) FROM $branches WHERE active=1" ),
			'active_brands'=>(int) $wpdb->get_var( "SELECT COUNT(*) FROM $brands WHERE active=1" ),
			'active_locations'=>(int) $wpdb->get_var( "SELECT COUNT(*) FROM $locations WHERE active=1" ),
		);
		$unmapped_posts = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID post_id,p.post_title,p.post_status FROM {$wpdb->posts} p WHERE p.post_type='car' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM $vehicles v WHERE v.public_post_id=p.ID) ORDER BY p.ID ASC LIMIT %d", $limit ), ARRAY_A ) ?: array();
		$unmapped_vehicles = $wpdb->get_results( $wpdb->prepare( "SELECT v.id vehicle_id,v.stock_number,v.brand,v.model,v.status,b.name branch_name FROM $vehicles v LEFT JOIN $branches b ON b.id=v.branch_id WHERE v.public_post_id=0 ORDER BY v.id ASC LIMIT %d", $limit ), ARRAY_A ) ?: array();
		$duplicates = $wpdb->get_results( $wpdb->prepare( "SELECT v.public_post_id post_id,p.post_title,COUNT(*) mapping_count,GROUP_CONCAT(v.id ORDER BY v.id ASC) vehicle_ids,GROUP_CONCAT(v.stock_number ORDER BY v.id ASC SEPARATOR ', ') stock_numbers FROM $vehicles v LEFT JOIN {$wpdb->posts} p ON p.ID=v.public_post_id WHERE v.public_post_id>0 GROUP BY v.public_post_id,p.post_title HAVING COUNT(*)>1 ORDER BY v.public_post_id ASC LIMIT %d", $limit ), ARRAY_A ) ?: array();
		$invalid = $wpdb->get_results( $wpdb->prepare( "SELECT v.id vehicle_id,v.stock_number,v.public_post_id post_id,COALESCE(p.post_title,'') post_title,COALESCE(p.post_type,'missing') post_type,COALESCE(p.post_status,'missing') post_status FROM $vehicles v LEFT JOIN {$wpdb->posts} p ON p.ID=v.public_post_id WHERE v.public_post_id>0 AND (p.ID IS NULL OR p.post_type<>'car' OR p.post_status<>'publish') ORDER BY v.id ASC LIMIT %d", $limit ), ARRAY_A ) ?: array();
		$issue_totals = array(
			'unmapped_posts'=>(int) $readiness['unmapped_published_posts'],
			'unmapped_vehicles'=>(int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles WHERE public_post_id=0" ),
			'duplicate_mappings'=>(int) $readiness['duplicate_mappings'],
			'invalid_mappings'=>(int) $readiness['invalid_mappings'],
		);
		$vehicle_state = $wpdb->get_row( "SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',id,public_post_id,status,branch_id,updated_at))),0) checksum_sum,COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,public_post_id,status,branch_id,updated_at))),0) checksum_xor,COALESCE(MAX(updated_at),'') latest_update FROM $vehicles", ARRAY_A ) ?: array();
		$post_state = $wpdb->get_row( "SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',ID,post_status,post_modified_gmt))),0) checksum_sum,COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',ID,post_status,post_modified_gmt))),0) checksum_xor,COALESCE(MAX(post_modified_gmt),'') latest_update FROM {$wpdb->posts} WHERE post_type='car'", ARRAY_A ) ?: array();
		$summary = array( 'readiness'=>$readiness, 'setup'=>$setup, 'issue_totals'=>$issue_totals, 'vehicle_state'=>$vehicle_state, 'post_state'=>$post_state );
		return array( 'readiness'=>$readiness, 'setup'=>$setup, 'issue_totals'=>$issue_totals, 'unmapped_posts'=>$unmapped_posts, 'unmapped_vehicles'=>$unmapped_vehicles, 'duplicate_mappings'=>$duplicates, 'invalid_mappings'=>$invalid, 'fingerprint'=>hash( 'sha256', wp_json_encode( $summary ) ) );
	}

	public static function vehicles( int $limit = 200 ): array {
		global $wpdb;
		$limit = min( 500, max( 1, $limit ) );
		$table = Schema::table( 'vehicles' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT v.id,v.stock_number,v.brand,v.model,v.model_year,v.status,v.public_post_id,b.name branch_name,p.post_title,p.post_status FROM $table v LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=v.branch_id LEFT JOIN {$wpdb->posts} p ON p.ID=v.public_post_id ORDER BY v.id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array();
	}

	public static function posts( int $limit = 500 ): array {
		global $wpdb;
		$limit = min( 500, max( 1, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_title,post_status FROM {$wpdb->posts} WHERE post_type='car' AND post_status NOT IN ('trash','auto-draft') ORDER BY post_title ASC,ID ASC LIMIT %d", $limit ), ARRAY_A ) ?: array();
	}
}
