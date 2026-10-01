<?php
namespace AutoDealership\Migration;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Read-only retirement decision and aggregate readiness for legacy projections. */
final class CompatibilityRetirement {
	public const POLICY_VERSION = '1.0.0';

	/**
	 * No table or record is changed here. Removal stays blocked while account,
	 * request workflow or privacy behavior still depends on the projection.
	 */
	public static function report(): array {
		$message = self::request_table( 'message', 'car_dealer_messages' );
		$booking = self::request_table( 'booking', 'car_dealer_bookings' );
		$subscribers = self::subscriber_table();
		$crm = self::legacy_crm();
		$dependencies = array(
			'account_request_history' => true,
			'request_status_and_booking_updates' => true,
			'privacy_export_and_erasure' => true,
			'retention_anonymization' => true,
			'legacy_migration_source' => true,
		);

		return array(
			'policy_version' => self::POLICY_VERSION,
			'decision' => 'retain_controlled_compatibility',
			'can_drop_request_tables' => false,
			'reason' => 'native_request_projection_not_complete',
			'request_tables' => array( 'message'=>$message, 'booking'=>$booking ),
			'subscribers' => $subscribers,
			'legacy_crm' => $crm,
			'code_dependencies' => $dependencies,
			'exit_requirements' => array(
				'native account request history is active',
				'native request status, reply and appointment updates are active',
				'privacy export, erasure and retention use only native request storage',
				'all eligible historical rows are reconciled and aggregate counts match',
				'backup restore and rollback rehearsal are accepted',
			),
		);
	}

	private static function request_table( string $type, string $suffix ): array {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		$exists = self::table_exists( $table );
		$result = array(
			'storage' => $suffix,
			'role' => 'required_request_projection',
			'exists' => $exists,
			'engine' => $exists ? self::engine( $table ) : '',
			'total' => 0,
			'core_linked' => 0,
			'unmapped' => 0,
			'active' => 0,
			'decision' => 'retain_read_write_projection',
			'can_drop' => false,
		);
		if ( ! $exists ) {
			$result['decision'] = 'restore_required_projection';
			return $result;
		}

		$result['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$result['active'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status NOT IN ('completed','cancelled')" );
		if ( Schema::is_ready() ) {
			$result['core_linked'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'leads' ) . ' WHERE legacy_request_type=%s AND legacy_request_id IS NOT NULL', $type ) );
		}
		$result['core_linked'] = min( $result['total'], $result['core_linked'] );
		$result['unmapped'] = max( 0, $result['total'] - $result['core_linked'] );
		return $result;
	}

	private static function subscriber_table(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'car_dealer_subscribers';
		$exists = self::table_exists( $table );
		return array(
			'storage' => 'car_dealer_subscribers',
			'role' => 'canonical_marketing_consent_store',
			'exists' => $exists,
			'engine' => $exists ? self::engine( $table ) : '',
			'total' => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) : 0,
			'active' => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='active'" ) : 0,
			'decision' => $exists ? 'retain_canonical_store' : 'restore_canonical_store',
			'can_drop' => false,
		);
	}

	private static function legacy_crm(): array {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='cd_crm' AND post_status='private'" );
		$retired = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key IN ('_crm_retired_core_customer_id','_crm_privacy_erased') WHERE p.post_type='cd_crm' AND p.post_status='private'" );
		return array(
			'storage' => 'cd_crm',
			'role' => 'private_read_only_history',
			'total' => $total,
			'retired' => min( $total, $retired ),
			'pending_retirement' => max( 0, $total - $retired ),
			'decision' => 'retain_private_read_only_history',
			'can_drop' => false,
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function engine( string $table ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	}
}
