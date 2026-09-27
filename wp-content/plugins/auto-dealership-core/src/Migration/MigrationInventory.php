<?php
namespace AutoDealership\Migration;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Read-only source inventory and reconciliation report for staged migration. */
final class MigrationInventory {
	public static function report( int $fallback_branch = 0 ): array {
		global $wpdb;
		$branches = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE active=1' ) );
		if ( $fallback_branch && ! in_array( $fallback_branch, $branches, true ) ) {
			return array( 'error' => 'invalid_fallback_branch' );
		}
		return array(
			'generated_at_utc' => gmdate( 'c' ),
			'vehicles' => self::vehicles( $fallback_branch ),
			'crm' => self::crm(),
			'offers' => self::offers(),
			'sources' => self::sources(),
		);
	}

	private static function vehicles( int $fallback_branch ): array {
		global $wpdb;
		$counts = array_fill_keys( array( 'source_total','mapped','matched','drifted','eligible_unmapped','invalid_identity','workflow_blocked','unresolved_branch','vin_conflict','stock_conflict' ), 0 );
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='car' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID" );
		$counts['source_total'] = count( $ids );
		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			$expected = LegacyVehicleMapper::map( $post_id );
			$mapped = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id=%d LIMIT 1', $post_id ), ARRAY_A );
			if ( $mapped ) {
				++$counts['mapped'];
				$matches = ! is_wp_error( $expected );
				if ( $matches ) {
					foreach ( $expected as $field => $value ) {
						if ( (string) $value !== (string) ( $mapped[$field] ?? '' ) ) { $matches = false; break; }
					}
				}
				$matches ? ++$counts['matched'] : ++$counts['drifted'];
				continue;
			}
			if ( is_wp_error( $expected ) ) { ++$counts['invalid_identity']; continue; }
			$vin = $expected['vin']; $stock = $expected['stock_number'];
			if ( null === LegacyVehicleMapper::status( $post_id ) ) { ++$counts['workflow_blocked']; continue; }
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . ' WHERE vin=%s LIMIT 1', $vin ) ) ) { ++$counts['vin_conflict']; continue; }
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . ' WHERE stock_number=%s LIMIT 1', $stock ) ) ) { ++$counts['stock_conflict']; continue; }
			$location = sanitize_text_field( (string) get_post_meta( $post_id, '_car_location', true ) );
			$branch_id = $fallback_branch;
			if ( ! $branch_id && $location ) {
				$matches = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE active=1 AND name=%s LIMIT 2', $location ) );
				if ( 1 === count( $matches ) ) { $branch_id = (int) $matches[0]; }
			}
			$branch_id ? ++$counts['eligible_unmapped'] : ++$counts['unresolved_branch'];
		}
		return $counts;
	}

	private static function crm(): array {
		global $wpdb;
		$result = array();
		foreach ( array( 'message' => 'car_dealer_messages', 'booking' => 'car_dealer_bookings' ) as $type => $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
			$total = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) : 0;
			$imported = $exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'leads' ) . ' WHERE legacy_request_type=%s', $type ) ) : 0;
			$invalid = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE TRIM(COALESCE(name,''))='' OR TRIM(COALESCE(phone,''))=''" ) : 0;
			$result[ $type ] = array( 'source_exists' => $exists, 'source_total' => $total, 'imported' => min( $total, $imported ), 'unmapped' => max( 0, $total - $imported ), 'obviously_invalid' => $invalid );
		}
		$result['legacy_crm_posts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='cd_crm' AND post_status NOT IN ('trash','auto-draft')" );
		return $result;
	}

	private static function offers(): array {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='car_offer' AND post_status NOT IN ('trash','auto-draft')" );
		$linked = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_car_id' INNER JOIN {$wpdb->posts} car ON car.ID=CAST(pm.meta_value AS UNSIGNED) AND car.post_type='car' WHERE p.post_type='car_offer' AND p.post_status NOT IN ('trash','auto-draft')" );
		return array( 'source_total' => $total, 'linked_to_vehicle' => $linked, 'unlinked_or_orphaned' => max( 0, $total - $linked ), 'target' => 'pending_domain_model' );
	}

	private static function sources(): array {
		return array(
			'post_types' => array( 'car' => 'vehicle inventory', 'car_offer' => 'offers pending target model', 'cd_crm' => 'legacy CRM compatibility' ),
			'tables' => array( 'car_dealer_messages' => 'CRM lead intake', 'car_dealer_bookings' => 'test-drive lead intake', 'car_dealer_subscribers' => 'separate marketing-consent purpose' ),
			'write_paths' => array( 'car post editor', 'car_offer post editor', 'car_dealer_contact AJAX', 'car_dealer_booking AJAX', 'car_dealer_subscribe AJAX', 'car_dealer_crm admin actions' ),
		);
	}
}
