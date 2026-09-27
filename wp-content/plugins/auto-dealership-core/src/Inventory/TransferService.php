<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Staged, branch-scoped transfers with separate request, approval, dispatch and receipt. */
final class TransferService {
	public static function request( int $vehicle_id, int $target_branch_id, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_transfer_inventory' ) ) { return new \WP_Error( 'adc_forbidden', __( 'Transfer permission required.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$reason = sanitize_textarea_field( $reason );
		if ( '' === $reason || ! VehicleService::branch_exists( $target_branch_id ) ) { return new \WP_Error( 'adc_invalid_transfer', __( 'Select an active destination branch and provide a reason.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'Could not start the transfer transaction.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$vehicles = Schema::table( 'vehicles' );
		$vehicle = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,status FROM $vehicles WHERE id = %d FOR UPDATE", $vehicle_id ), ARRAY_A );
		if ( ! $vehicle || ! VehicleService::user_can_access_branch( (int) $vehicle['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_vehicle_scope', __( 'Vehicle is not in your branch scope.', 'auto-dealership-core' ), array( 'status' => 404 ) ); }
		if ( 'available' !== $vehicle['status'] || $target_branch_id === (int) $vehicle['branch_id'] ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_state', __( 'Only available vehicles can be requested for transfer to another branch.', 'auto-dealership-core' ), array( 'status' => 409 ) ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->update( $vehicles, array( 'status' => 'transferred', 'updated_at' => $now ), array( 'id' => $vehicle_id, 'branch_id' => (int) $vehicle['branch_id'], 'status' => 'available' ), array( '%s', '%s' ), array( '%d', '%d', '%s' ) );
		$ok = 1 === $updated ? $wpdb->insert( Schema::table( 'vehicle_transfers' ), array( 'vehicle_id' => $vehicle_id, 'from_branch_id' => (int) $vehicle['branch_id'], 'to_branch_id' => $target_branch_id, 'requested_by' => get_current_user_id(), 'status' => 'requested', 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ) ) : false;
		$transfer_id = 1 === $ok ? (int) $wpdb->insert_id : 0;
		$movement = 1 === $ok ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => $vehicle_id, 'from_branch_id' => (int) $vehicle['branch_id'], 'to_branch_id' => (int) $vehicle['branch_id'], 'from_status' => 'available', 'to_status' => 'transferred', 'actor_user_id' => get_current_user_id(), 'reason' => $reason, 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) ) : false;
		if ( 1 !== $ok || 1 !== $movement ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_failed', __( 'Could not create the transfer request.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.transfer_requested', 'vehicle_transfer', $transfer_id, $reason, null, array( 'vehicle_id' => $vehicle_id, 'from_branch_id' => (int) $vehicle['branch_id'], 'to_branch_id' => $target_branch_id ) ) ) ) { return new \WP_Error( 'adc_transfer_failed', __( 'Could not document the transfer request.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		return array( 'id' => $transfer_id, 'status' => 'requested' );
	}

	public static function decide( int $transfer_id, bool $approve ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_transfer_inventory' ) ) { return new \WP_Error( 'adc_forbidden', __( 'Transfer permission required.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'Could not start the transfer transaction.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$table = Schema::table( 'vehicle_transfers' );
		$transfer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d FOR UPDATE", $transfer_id ), ARRAY_A );
		if ( ! $transfer || 'requested' !== $transfer['status'] || (int) $transfer['requested_by'] === get_current_user_id() || ! VehicleService::user_can_access_branch( (int) $transfer['to_branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_decision_denied', __( 'This transfer is not awaiting an eligible destination-branch decision.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$now = current_time( 'mysql', true );
		$status = $approve ? 'approved' : 'rejected';
		$updated = $wpdb->update( $table, array( 'status' => $status, 'approved_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => $transfer_id, 'status' => 'requested' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
		$vehicle_update = true;
		if ( ! $approve ) {
			$vehicle_update = 1 === $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => 'available', 'updated_at' => $now ), array( 'id' => (int) $transfer['vehicle_id'], 'branch_id' => (int) $transfer['from_branch_id'], 'status' => 'transferred' ), array( '%s', '%s' ), array( '%d', '%d', '%s' ) );
			if ( $vehicle_update ) {
				$vehicle_update = 1 === $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $transfer['vehicle_id'], 'from_branch_id' => (int) $transfer['from_branch_id'], 'to_branch_id' => (int) $transfer['from_branch_id'], 'from_status' => 'transferred', 'to_status' => 'available', 'actor_user_id' => get_current_user_id(), 'reason' => 'Transfer rejected: ' . $transfer['reason'], 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) );
			}
		}
		if ( 1 !== $updated || true !== $vehicle_update ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_decision_failed', __( 'Could not save the transfer decision.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.transfer_' . $status, 'vehicle_transfer', $transfer_id, '', array( 'status' => 'requested' ), array( 'status' => $status, 'decision_by' => get_current_user_id() ) ) ) ) { return new \WP_Error( 'adc_transfer_decision_failed', __( 'Could not document the transfer decision.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		return array( 'id' => $transfer_id, 'status' => $status );
	}

	public static function dispatch( int $transfer_id ) {
		return self::advance( $transfer_id, 'approved', 'dispatched', 'dispatched_by', 'in_transit', false );
	}

	public static function receive( int $transfer_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_transfer_inventory' ) ) { return new \WP_Error( 'adc_forbidden', __( 'Transfer permission required.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'Could not start the transfer transaction.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$table = Schema::table( 'vehicle_transfers' );
		$transfer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d FOR UPDATE", $transfer_id ), ARRAY_A );
		if ( ! $transfer || 'dispatched' !== $transfer['status'] || (int) $transfer['requested_by'] === get_current_user_id() || (int) $transfer['dispatched_by'] === get_current_user_id() || ! VehicleService::user_can_access_branch( (int) $transfer['to_branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_receipt_denied', __( 'This transfer cannot be received by this user or branch.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$now = current_time( 'mysql', true );
		$vehicle_update = $wpdb->update( Schema::table( 'vehicles' ), array( 'branch_id' => (int) $transfer['to_branch_id'], 'status' => 'available', 'updated_at' => $now ), array( 'id' => (int) $transfer['vehicle_id'], 'branch_id' => (int) $transfer['from_branch_id'], 'status' => 'in_transit' ), array( '%d', '%s', '%s' ), array( '%d', '%d', '%s' ) );
		$movement = 1 === $vehicle_update ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $transfer['vehicle_id'], 'from_branch_id' => (int) $transfer['from_branch_id'], 'to_branch_id' => (int) $transfer['to_branch_id'], 'from_status' => 'in_transit', 'to_status' => 'available', 'actor_user_id' => get_current_user_id(), 'reason' => $transfer['reason'], 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) ) : false;
		$transfer_update = false !== $movement ? $wpdb->update( $table, array( 'status' => 'received', 'received_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => $transfer_id, 'status' => 'dispatched' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) ) : false;
		if ( false === $vehicle_update || 1 !== $vehicle_update || false === $movement || false === $transfer_update || 1 !== $transfer_update ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_receipt_failed', __( 'Could not record transfer receipt.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.transfer_received', 'vehicle_transfer', $transfer_id, $transfer['reason'], array( 'branch_id' => (int) $transfer['from_branch_id'] ), array( 'branch_id' => (int) $transfer['to_branch_id'], 'vehicle_id' => (int) $transfer['vehicle_id'] ) ) ) ) { return new \WP_Error( 'adc_transfer_receipt_failed', __( 'Could not document transfer receipt.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		return array( 'id' => $transfer_id, 'status' => 'received' );
	}

	private static function advance( int $transfer_id, string $from_status, string $to_status, string $actor_column, string $vehicle_status, bool $destination_scope ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_transfer_inventory' ) ) { return new \WP_Error( 'adc_forbidden', __( 'Transfer permission required.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'Could not start the transfer transaction.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$table = Schema::table( 'vehicle_transfers' );
		$transfer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d FOR UPDATE", $transfer_id ), ARRAY_A );
		$scope_branch = $transfer ? (int) ( $destination_scope ? $transfer['to_branch_id'] : $transfer['from_branch_id'] ) : 0;
		if ( ! $transfer || $from_status !== $transfer['status'] || (int) $transfer['requested_by'] !== get_current_user_id() || ! VehicleService::user_can_access_branch( $scope_branch ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_dispatch_denied', __( 'This transfer is not ready for dispatch by this branch user.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$now = current_time( 'mysql', true );
		$vehicle_update = $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => $vehicle_status, 'updated_at' => $now ), array( 'id' => (int) $transfer['vehicle_id'], 'branch_id' => (int) $transfer['from_branch_id'], 'status' => 'transferred' ), array( '%s', '%s' ), array( '%d', '%d', '%s' ) );
		$transfer_update = 1 === $vehicle_update ? $wpdb->update( $table, array( 'status' => $to_status, $actor_column => get_current_user_id(), 'updated_at' => $now ), array( 'id' => $transfer_id, 'status' => $from_status ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) ) : false;
		$movement = 1 === $transfer_update ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $transfer['vehicle_id'], 'from_branch_id' => (int) $transfer['from_branch_id'], 'to_branch_id' => (int) $transfer['from_branch_id'], 'from_status' => 'transferred', 'to_status' => 'in_transit', 'actor_user_id' => get_current_user_id(), 'reason' => $transfer['reason'], 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) ) : false;
		if ( false === $vehicle_update || 1 !== $vehicle_update || false === $transfer_update || 1 !== $transfer_update || false === $movement ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_transfer_dispatch_failed', __( 'Could not record transfer dispatch.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.transfer_' . $to_status, 'vehicle_transfer', $transfer_id, '', array( 'status' => $from_status ), array( 'status' => $to_status, 'vehicle_id' => (int) $transfer['vehicle_id'] ) ) ) ) { return new \WP_Error( 'adc_transfer_dispatch_failed', __( 'Could not document transfer dispatch.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		return array( 'id' => $transfer_id, 'status' => $to_status );
	}
}
