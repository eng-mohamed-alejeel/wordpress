<?php
namespace AutoDealership\Marketing;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Small, read-only EXPLAIN probe for the high-volume affiliate report paths. */
final class AffiliateCommissionPerformance {
	public static function snapshot(): array {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return array( 'ok' => false, 'value' => 'schema_not_ready', 'missing_indexes' => array(), 'plans' => array() ); }
		$commissions = Schema::table( 'affiliate_commissions' ); $entries = Schema::table( 'affiliate_commission_entries' ); $issues = Schema::table( 'affiliate_commission_migration_issues' );
		$required = array( 'report_created_status_branch', 'report_affiliate_created_status', 'report_branch_created_status' ); $actual = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM $commissions", ARRAY_A ) as $row ) { $actual[ (string) ( $row['Key_name'] ?? '' ) ] = true; }
		$missing = array_values( array_diff( $required, array_keys( $actual ) ) );
		$queries = array(
			'commission_date' => "SELECT c.id FROM $commissions c WHERE c.created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY ORDER BY c.created_at DESC LIMIT 50",
			'ledger_join' => "SELECT e.commission_id FROM $entries e INNER JOIN $commissions c ON c.id=e.commission_id WHERE c.created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY e.commission_id LIMIT 50",
			'migration_queue' => "SELECT i.id FROM $issues i WHERE i.status='open' ORDER BY i.id DESC LIMIT 50",
		);
		$plans = array();
		foreach ( $queries as $name => $query ) {
			$wpdb->last_error = '';
			$plan = $wpdb->get_results( 'EXPLAIN ' . $query, ARRAY_A );
			$plans[ $name ] = array( 'ok' => ! $wpdb->last_error && is_array( $plan ), 'rows' => is_array( $plan ) ? count( $plan ) : 0, 'keys' => self::keys( $plan ) );
		}
		$plan_ok = true; foreach ( $plans as $plan ) { if ( ! $plan['ok'] ) { $plan_ok = false; break; } }
		return array( 'ok' => ! $missing && $plan_ok, 'value' => $missing ? 'missing indexes: ' . implode( ', ', $missing ) : ( $plan_ok ? 'indexes and EXPLAIN probes healthy' : 'EXPLAIN probe failed' ), 'missing_indexes' => $missing, 'plans' => $plans );
	}

	private static function keys( array $plan ): array {
		$keys = array(); foreach ( $plan as $row ) { $key = (string) ( $row['key'] ?? '' ); if ( '' !== $key ) { $keys[] = $key; } } return array_values( array_unique( $keys ) );
	}
}
