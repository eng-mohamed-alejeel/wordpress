<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Reference\ReferenceService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Receives a delivered vehicle back into controlled inventory without claiming a financial refund. */
final class VehicleReturnService {
	private const CONDITIONS = array( 'good', 'damaged', 'incomplete' );

	public static function receive( int $delivery_id, array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_process_returns' ) ) {
			return self::error( 'adc_return_forbidden', 403 );
		}

		$reason = sanitize_textarea_field( $input['reason'] ?? '' );
		$condition = sanitize_key( $input['condition'] ?? '' );
		$document_reference = sanitize_text_field( $input['document_reference'] ?? '' );
		$location_id = absint( $input['location_id'] ?? 0 );
		$odometer = absint( $input['odometer'] ?? 0 );
		if ( '' === trim( $reason ) || '' === $document_reference || ! in_array( $condition, self::CONDITIONS, true ) || $location_id < 1 ) {
			return self::error( 'adc_invalid_return', 400 );
		}
		if ( ! Transaction::begin() ) {
			return self::error( 'adc_transaction_failed', 500 );
		}

		$deliveries = Schema::table( 'deliveries' );
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT d.id delivery_id,d.sale_id,d.vehicle_id,d.status delivery_status,s.status sale_status,v.status vehicle_status,v.branch_id,v.location_id old_location_id FROM ' . $deliveries . ' d INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=d.sale_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=d.vehicle_id WHERE d.id=%d FOR UPDATE',
			$delivery_id
		), ARRAY_A );
		if ( ! $row || 'delivered' !== $row['delivery_status'] || 'delivered' !== $row['sale_status'] || 'delivered' !== $row['vehicle_status'] || ! VehicleService::user_can_access_branch( (int) $row['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_return_state', 409 );
		}
		if ( ! ReferenceService::active_location( $location_id, (int) $row['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_return_location', 409 );
		}

		$now = current_time( 'mysql', true );
		$inspection_baseline_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM ' . Schema::table( 'vehicle_inspections' ) . ' WHERE vehicle_id=%d', (int) $row['vehicle_id'] ) );
		$inserted = $wpdb->insert( Schema::table( 'vehicle_returns' ), array(
			'sale_id' => (int) $row['sale_id'], 'delivery_id' => $delivery_id, 'vehicle_id' => (int) $row['vehicle_id'],
			'branch_id' => (int) $row['branch_id'], 'location_id' => $location_id, 'inspection_baseline_id' => $inspection_baseline_id, 'condition_key' => $condition,
			'odometer' => $odometer, 'document_reference' => $document_reference, 'reason' => $reason,
			'financial_status' => 'pending_refund', 'received_by' => get_current_user_id(), 'created_at' => $now,
		), array( '%d','%d','%d','%d','%d','%d','%s','%d','%s','%s','%s','%d','%s' ) );
		$return_id = (int) $wpdb->insert_id;
		$delivery_updated = 1 === $inserted ? $wpdb->update( $deliveries, array( 'status' => 'returned', 'updated_at' => $now ), array( 'id' => $delivery_id, 'status' => 'delivered' ), array( '%s','%s' ), array( '%d','%s' ) ) : false;
		$sale_updated = 1 === $delivery_updated ? $wpdb->update( Schema::table( 'sales' ), array( 'status' => 'returned', 'updated_at' => $now ), array( 'id' => (int) $row['sale_id'], 'status' => 'delivered' ), array( '%s','%s' ), array( '%d','%s' ) ) : false;
		$vehicle_updated = 1 === $sale_updated ? $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => 'returned', 'location_id' => $location_id, 'updated_at' => $now ), array( 'id' => (int) $row['vehicle_id'], 'status' => 'delivered' ), array( '%s','%d','%s' ), array( '%d','%s' ) ) : false;
		$movement = 1 === $vehicle_updated ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array(
			'vehicle_id' => (int) $row['vehicle_id'], 'from_branch_id' => (int) $row['branch_id'], 'to_branch_id' => (int) $row['branch_id'],
			'from_location_id' => (int) $row['old_location_id'], 'to_location_id' => $location_id, 'from_status' => 'delivered', 'to_status' => 'returned',
			'actor_user_id' => get_current_user_id(), 'reason' => $reason, 'created_at' => $now,
		), array( '%d','%d','%d','%d','%d','%s','%s','%d','%s','%s' ) ) : false;

		$audit = static fn() => AuditLog::record( 'vehicle.return_received', 'vehicle_return', $return_id, $reason, null, array(
			'sale_id' => (int) $row['sale_id'], 'delivery_id' => $delivery_id, 'vehicle_id' => (int) $row['vehicle_id'],
			'condition' => $condition, 'location_id' => $location_id, 'financial_status' => 'pending_refund',
		) ) && AuditLog::record( 'vehicle.status_changed', 'vehicle', (int) $row['vehicle_id'], $reason, array( 'status' => 'delivered' ), array( 'status' => 'returned' ) );
		if ( 1 !== $inserted || 1 !== $delivery_updated || 1 !== $sale_updated || 1 !== $vehicle_updated || 1 !== $movement || ! Transaction::commit( $audit ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_return_failed', 500 );
		}
		return array( 'id' => $return_id, 'sale_id' => (int) $row['sale_id'], 'delivery_id' => $delivery_id, 'vehicle_id' => (int) $row['vehicle_id'], 'status' => 'returned', 'financial_status' => 'pending_refund' );
	}

	public static function list_for_current_user(): array {
		global $wpdb;
		list( $scope, $args ) = BranchScope::predicate( 'r.branch_id' );
		$sql = 'SELECT r.id,r.sale_id,r.delivery_id,r.vehicle_id,r.branch_id,r.location_id,r.condition_key,r.odometer,r.document_reference,r.reason,r.financial_status,r.received_by,r.created_at,v.stock_number FROM ' . Schema::table( 'vehicle_returns' ) . ' r INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=r.vehicle_id WHERE ' . $scope . ' ORDER BY r.created_at DESC LIMIT 200';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	public static function eligible_deliveries(): array {
		global $wpdb;
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		$sql = "SELECT d.id,d.sale_id,d.vehicle_id,v.branch_id,v.stock_number,v.brand,v.model FROM " . Schema::table( 'deliveries' ) . " d INNER JOIN " . Schema::table( 'vehicles' ) . " v ON v.id=d.vehicle_id INNER JOIN " . Schema::table( 'sales' ) . " s ON s.id=d.sale_id WHERE d.status='delivered' AND s.status='delivered' AND v.status='delivered' AND " . $scope . ' ORDER BY d.delivered_at DESC LIMIT 200';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	public static function eligible_locations(): array {
		global $wpdb;
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		$sql = 'SELECT l.id,l.branch_id,l.name,l.location_type FROM ' . Schema::table( 'locations' ) . ' l WHERE l.active=1 AND ' . $scope . ' ORDER BY l.name ASC LIMIT 200';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Vehicle return could not be completed.', 'auto-dealership-core' ), array( 'status' => $status ) );
	}
}
