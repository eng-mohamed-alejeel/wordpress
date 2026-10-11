<?php
namespace AutoDealership\Purchasing;

use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Security\BranchScope;
use AutoDealership\Pricing\Money;
defined( 'ABSPATH' ) || exit;

/** One VIN-specific vehicle per order; physical intake remains a separate mandatory workflow. */
final class PurchaseOrderService {
	public static function policy(): array { return (array) get_option( 'adc_purchase_policy', array() ); }
	public static function configure( array $input, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 403 ); }
		$manager = Money::from_sar( $input['manager_limit'] ?? '' );
		$general = Money::from_sar( $input['general_limit'] ?? '' );
		$basis = $input['cost_basis'] ?? '';
		$cancellation = $input['approved_cancellation'] ?? '';
		if ( null === $manager || null === $general || $general < 1 || $general < $manager || ! in_array( $basis, array( 'net', 'gross' ), true ) || ! in_array( $cancellation, array( 'manager_only', 'authorised_staff' ), true ) || ! trim( $reason ) || mb_strlen( $reason ) > 2000 ) { return self::error( 'invalid_policy', 400 ); }
		add_option( 'adc_purchase_policy', array(), '', false );
		if ( ! Transaction::begin() ) { return self::error( 'database', 500 ); }
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", 'adc_purchase_policy' ) );
		$before = (array) maybe_unserialize( $stored );
		$policy = array( 'manager_limit'=>$manager, 'general_limit'=>$general, 'cost_basis'=>$basis, 'approved_cancellation'=>$cancellation, 'version'=>wp_generate_uuid4(), 'approved_by'=>get_current_user_id(), 'approved_at'=>current_time( 'mysql', true ) );
		if ( false === $wpdb->update( $wpdb->options, array( 'option_value'=>maybe_serialize( $policy ) ), array( 'option_name'=>'adc_purchase_policy' ) ) ) { return self::rollback( 'database', 500 ); }
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'purchase.policy_approved', 'purchase_policy', 0, $reason, $before, $policy ) ) ) { wp_cache_delete( 'adc_purchase_policy', 'options' ); return self::error( 'database', 500 ); }
		wp_cache_delete( 'adc_purchase_policy', 'options' );
		return $policy;
	}
	public static function get( int $id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_purchase_orders' ) ) { return self::error( 'forbidden', 403 ); }
		list( $scope, $args ) = BranchScope::predicate( 'branch_id' );
		array_unshift( $args, $id );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'purchase_orders' ) . ' WHERE id=%d AND ' . $scope, $args ), ARRAY_A ) ?: self::error( 'not_found', 404 );
	}
	public static function create( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_create_purchase_orders' ) ) { return self::error( 'forbidden', 403 ); }
		$purchase = Money::from_sar( $input['purchase_amount'] ?? '' ); $extra = Money::from_sar( $input['additional_amount'] ?? '0' );
		$rate = filter_var( $input['tax_rate_bps'] ?? '', FILTER_VALIDATE_INT, array( 'options'=>array( 'min_range'=>0, 'max_range'=>10000 ) ) );
		$key = (string) ( $input['request_key'] ?? '' ); $reason = sanitize_textarea_field( $input['reason'] ?? '' );
		if ( ! $purchase || null === $extra || false === $rate || $purchase > PHP_INT_MAX - $extra || ! preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i', $key ) || ! trim( $reason ) || mb_strlen( $reason ) > 2000 ) { return self::error( 'invalid_input', 400 ); }
		try { $amounts = Money::calculate( $purchase + $extra, 0, $rate ); } catch ( \Throwable ) { return self::error( 'invalid_amount', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'database', 500 ); }
		$table = Schema::table( 'purchase_orders' );
		$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d FOR UPDATE', absint( $input['vehicle_id'] ?? 0 ) ), ARRAY_A );
		if ( ! $vehicle || ! BranchScope::allows( (int) $vehicle['branch_id'] ) ) { return self::rollback( 'not_found', 404 ); }
		$number = 'PO-' . str_replace( '-', '', strtolower( $key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_number=%s", $number ), ARRAY_A );
		if ( $existing ) {
			$match = (int) $existing['requester_user_id'] === get_current_user_id() && (int) $existing['vehicle_id'] === (int) $vehicle['id'] && (int) $existing['supplier_id'] === absint( $input['supplier_id'] ?? 0 ) && (int) $existing['purchase_amount'] === $purchase && (int) $existing['additional_amount'] === $extra && (int) $existing['tax_rate_bps'] === $rate;
			$wpdb->query( 'ROLLBACK' ); return $match ? $existing : self::error( 'key_conflict', 409 );
		}
		if ( ! in_array( $vehicle['status'], array( 'ordered', 'in_transit', 'received', 'inspection' ), true ) || ! SupplierService::active( absint( $input['supplier_id'] ?? 0 ) ) || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE vehicle_id=%d AND status IN ('draft','pending','approved','received')", $vehicle['id'] ) ) ) { return self::rollback( 'vehicle_or_supplier_conflict', 409 ); }
		$data = array( 'order_number'=>$number, 'vehicle_id'=>$vehicle['id'], 'branch_id'=>$vehicle['branch_id'], 'supplier_id'=>absint( $input['supplier_id'] ), 'requester_user_id'=>get_current_user_id(), 'status'=>'draft', 'version'=>1, 'purchase_amount'=>$purchase, 'additional_amount'=>$extra, 'tax_rate_bps'=>$rate, 'tax_amount'=>$amounts['tax_amount'], 'total_amount'=>$amounts['final_amount'], 'reason'=>$reason, 'created_at'=>current_time( 'mysql', true ), 'updated_at'=>current_time( 'mysql', true ) );
		if ( 1 !== $wpdb->insert( $table, $data ) ) { return self::rollback( 'database', 500 ); }
		$id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'purchase.created', 'purchase_order', $id, $reason, null, $data ) ) ) { return self::error( 'database', 500 ); }
		return array( 'id'=>$id ) + $data;
	}
	public static function transition( int $id, int $version, string $action, string $reason, string $reference = '' ) {
		global $wpdb;
		$caps = array( 'submit'=>'adc_create_purchase_orders', 'approve'=>'adc_approve_purchase_orders', 'reject'=>'adc_approve_purchase_orders', 'cancel'=>'adc_cancel_purchase_orders', 'receive'=>'adc_receive_purchase_orders' );
		if ( ! isset( $caps[$action] ) || ! current_user_can( $caps[$action] ) ) { return self::error( 'forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason ); $reference = sanitize_text_field( $reference );
		if ( ! trim( $reason ) || mb_strlen( $reason ) > 2000 || strlen( $reference ) > 190 ) { return self::error( 'invalid_input', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'database', 500 ); }
		$table = Schema::table( 'purchase_orders' );
		// Lock the vehicle before the order, consistently with creation and inventory services.
		$vehicle_id = $wpdb->get_var( $wpdb->prepare( "SELECT vehicle_id FROM $table WHERE id=%d", $id ) );
		$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d FOR UPDATE', $vehicle_id ), ARRAY_A );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || ! $vehicle || ! BranchScope::allows( (int) $row['branch_id'] ) || (int) $vehicle['branch_id'] !== (int) $row['branch_id'] ) { return self::rollback( 'not_found', 404 ); }
		if ( (int) $row['version'] !== $version ) { return self::rollback( 'stale_version', 409 ); }
		$states = array( 'submit'=>array( 'draft' ), 'approve'=>array( 'pending' ), 'reject'=>array( 'pending' ), 'cancel'=>array( 'draft', 'pending', 'approved' ), 'receive'=>array( 'approved' ) );
		if ( ! in_array( $row['status'], $states[$action], true ) ) { return self::rollback( 'invalid_state', 409 ); }
		$data = array( 'version'=>$version+1, 'updated_at'=>current_time( 'mysql', true ), 'reason'=>$reason );
		$policy = json_decode( $row['policy_snapshot'] ?? '', true ) ?: array();
		if ( 'submit' === $action ) {
			$policy = self::policy();
			if ( (int) $row['requester_user_id'] !== get_current_user_id() || empty( $policy['version'] ) || (int) $row['total_amount'] > (int) $policy['general_limit'] || ! SupplierService::active( (int) $row['supplier_id'] ) ) { return self::rollback( 'policy_or_requester', 409 ); }
			$data['policy_snapshot'] = wp_json_encode( $policy ); $data['status'] = 'pending';
		} elseif ( in_array( $action, array( 'approve', 'reject' ), true ) ) {
			if ( (int) $row['requester_user_id'] === get_current_user_id() || empty( $policy['version'] ) || ( (int) $row['total_amount'] > (int) $policy['manager_limit'] && ! current_user_can( 'adc_approve_high_purchase_orders' ) ) ) { return self::rollback( 'separation_or_limit', 403 ); }
			$data['status'] = 'approve' === $action ? 'approved' : 'rejected'; $data['approver_user_id'] = get_current_user_id();
		} elseif ( 'cancel' === $action ) {
			if ( 'approved' === $row['status'] && ( empty( $policy['approved_cancellation'] ) || ( 'manager_only' === $policy['approved_cancellation'] && ! current_user_can( 'adc_approve_high_purchase_orders' ) ) ) ) { return self::rollback( 'cancellation_policy', 403 ); }
			$data['status'] = 'cancelled';
		}
		else {
			if ( get_current_user_id() === (int) $row['requester_user_id'] || get_current_user_id() === (int) $row['approver_user_id'] || ! $reference || empty( $policy['cost_basis'] ) || ! in_array( $vehicle['status'], array( 'ordered', 'in_transit', 'received', 'inspection' ), true ) ) { return self::rollback( 'receipt_or_separation', 409 ); }
			$cost = (int) $row['purchase_amount']; $extra = (int) $row['additional_amount'];
			if ( 'gross' === $policy['cost_basis'] ) { $extra += (int) $row['tax_amount']; }
			$acquisition = array( 'supplier_id'=>(int) $row['supplier_id'], 'purchase_cost'=>$cost, 'additional_cost'=>$extra, 'total_cost'=>$cost+$extra, 'updated_at'=>current_time( 'mysql', true ) );
			if ( false === $wpdb->update( Schema::table( 'vehicles' ), $acquisition, array( 'id'=>$vehicle['id'] ) ) || ! AuditLog::record( 'vehicle.purchase_received', 'vehicle', (int) $vehicle['id'], $reason, array_intersect_key( $vehicle, $acquisition ), $acquisition ) ) { return self::rollback( 'database', 500 ); }
			$data['status'] = 'received'; $data['receipt_reference'] = $reference; $data['receiver_user_id'] = get_current_user_id();
		}
		if ( 1 !== $wpdb->update( $table, $data, array( 'id'=>$id, 'version'=>$version ) ) || ! Transaction::commit( static fn() => AuditLog::record( 'purchase.' . $action, 'purchase_order', $id, $reason, $row, array_merge( $row, $data ) ) ) ) { return self::rollback( 'database', 500 ); }
		return array_merge( $row, $data );
	}
	private static function rollback( string $code, int $status ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return self::error( $code, $status ); }
	private static function error( string $code, int $status ) { return new \WP_Error( 'adc_purchase_' . $code, $code, array( 'status'=>$status ) ); }
}
