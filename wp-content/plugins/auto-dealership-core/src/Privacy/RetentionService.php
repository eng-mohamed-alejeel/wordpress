<?php
namespace AutoDealership\Privacy;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Scheduled, conservative anonymization of inactive customer identity. */
final class RetentionService {
	public const OPTION = 'adc_privacy_retention_days';

	public static function run(): array {
		global $wpdb;
		$days = absint( get_option( self::OPTION, 0 ) );
		if ( $days < 30 || $days > 3650 || ! Schema::is_ready() ) {
			return array( 'processed' => 0, 'disabled' => true );
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'customers' ) . " WHERE updated_at < %s AND (email <> '' OR mobile <> '' OR full_name NOT IN ('Retained customer','Erased customer')) ORDER BY id ASC LIMIT 100", $cutoff ) );
		$processed = 0;
		foreach ( $ids as $id ) {
			if ( self::anonymize_if_due( (int) $id, $cutoff, $days ) ) { ++$processed; }
		}
		return array( 'processed' => $processed, 'disabled' => false );
	}

	private static function anonymize_if_due( int $customer_id, string $cutoff, int $days ): bool {
		global $wpdb;
		if ( ! Transaction::begin() ) { return false; }
		$customer = $wpdb->get_row( $wpdb->prepare( 'SELECT id,updated_at FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d FOR UPDATE', $customer_id ), ARRAY_A );
		if ( ! $customer || $customer['updated_at'] >= $cutoff || self::has_protected_activity( $customer_id, $cutoff ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$lead_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'leads' ) . ' WHERE customer_id=%d', $customer_id ) );
		if ( $lead_ids ) {
			$in = implode( ',', array_map( 'absint', $lead_ids ) );
			if ( false === $wpdb->query( "UPDATE " . Schema::table( 'activities' ) . " SET notes='[Retention policy anonymized]',next_action_at=NULL WHERE lead_id IN ($in)" ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		}
		if ( false === $wpdb->update( Schema::table( 'leads' ), array( 'lost_reason' => '', 'next_action_at' => null ), array( 'customer_id' => $customer_id ), array( '%s', null ), array( '%d' ) )
			|| false === $wpdb->update( Schema::table( 'quotation_versions' ), array( 'customer_name' => 'Retained customer' ), array( 'customer_id' => $customer_id ), array( '%s' ), array( '%d' ) )
			|| false === $wpdb->update( Schema::table( 'customers' ), array( 'full_name' => 'Retained customer', 'mobile' => '', 'email' => '', 'city' => '', 'consent_marketing' => 0, 'consent_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $customer_id ), array( '%s', '%s', '%s', '%s', '%d', null, '%s' ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' ); return false;
		}
		return Transaction::commit( static fn() => AuditLog::record( 'privacy.retention_anonymized', 'customer', $customer_id, 'Configured retention period elapsed', null, array( 'retention_days' => $days ) ) );
	}

	private static function has_protected_activity( int $customer_id, string $cutoff ): bool {
		global $wpdb;
		$l = Schema::table( 'leads' ); $r = Schema::table( 'reservations' ); $q = Schema::table( 'quotations' );
		$s = Schema::table( 'sales' ); $f = Schema::table( 'finance_requests' ); $p = Schema::table( 'payment_confirmations' ); $d = Schema::table( 'deliveries' );
		$sql = "SELECT 1 FROM $l WHERE customer_id=%d AND (stage NOT IN ('won','lost') OR updated_at >= %s) UNION ALL
			SELECT 1 FROM $r WHERE customer_id=%d AND (status='confirmed' OR updated_at >= %s) UNION ALL
			SELECT 1 FROM $q WHERE customer_id=%d AND (status IN ('draft','pending_discount') OR (status='approved' AND valid_until >= %s) OR created_at >= %s) UNION ALL
			SELECT 1 FROM $s WHERE customer_id=%d AND (status NOT IN ('delivered','cancelled','rejected') OR updated_at >= %s) UNION ALL
			SELECT 1 FROM $f INNER JOIN $s sx ON sx.id=$f.sale_id WHERE sx.customer_id=%d AND ($f.status IN ('submitted','under_review') OR $f.updated_at >= %s) UNION ALL
			SELECT 1 FROM $p INNER JOIN $s sy ON sy.id=$p.sale_id WHERE sy.customer_id=%d AND ($p.status='pending' OR $p.created_at >= %s) UNION ALL
			SELECT 1 FROM $d INNER JOIN $s sz ON sz.id=$d.sale_id WHERE sz.customer_id=%d AND ($d.status<>'delivered' OR $d.updated_at >= %s) LIMIT 1";
		$args = array( $customer_id, $cutoff, $customer_id, $cutoff, $customer_id, gmdate( 'Y-m-d' ), $cutoff, $customer_id, $cutoff, $customer_id, $cutoff, $customer_id, $cutoff, $customer_id, $cutoff );
		return null !== $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}
}
