<?php
namespace AutoDealership\Delivery;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Database\Transaction;
use AutoDealership\Integrations\DomainEventPublisher;

defined( 'ABSPATH' ) || exit;

/** Delivery release requires a VIN check and two distinct authorized staff members. */
final class DeliveryService {
	private const DOCUMENTS = array(
		'invoice' => 'Invoice', 'customer_identity' => 'Customer identity',
		'vehicle_registration' => 'Vehicle registration', 'insurance' => 'Insurance',
		'handover_form' => 'Signed handover form', 'finance_clearance' => 'Finance clearance',
	);

	public static function required_documents(): array {
		$keys = array_values( array_intersect( array_keys( self::DOCUMENTS ), (array) get_option( 'adc_delivery_required_documents', array() ) ) );
		return array_map( static fn( $key )=>array( 'key'=>$key, 'label'=>self::DOCUMENTS[ $key ] ), $keys );
	}

	public static function checklist( int $delivery_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_approve_delivery' ) && ! current_user_can( 'adc_confirm_vehicle_vin' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'Delivery checklist permission is required.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		$delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT d.id,d.status,v.branch_id FROM ' . Schema::table( 'deliveries' ) . ' d INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=d.vehicle_id WHERE d.id=%d', $delivery_id ), ARRAY_A );
		if ( ! $delivery || ! VehicleService::user_can_access_branch( (int) $delivery['branch_id'] ) ) {
			return new \WP_Error( 'adc_delivery_not_found', __( 'Delivery is unavailable in your branch scope.', 'auto-dealership-core' ), array( 'status'=>404 ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT document_key,reference,confirmed_by,confirmed_at FROM ' . Schema::table( 'delivery_documents' ) . ' WHERE delivery_id=%d ORDER BY document_key', $delivery_id ), ARRAY_A ) ?: array();
		$recorded = array_column( $rows, null, 'document_key' );
		$items = array();
		foreach ( self::required_documents() as $document ) {
			$items[] = array_merge( $document, array( 'complete'=>isset( $recorded[ $document['key'] ] ), 'evidence'=>$recorded[ $document['key'] ] ?? null ) );
		}
		return array( 'delivery_id'=>$delivery_id, 'status'=>$delivery['status'], 'complete'=>! array_filter( $items, static fn( $item )=>! $item['complete'] ), 'items'=>$items );
	}

	public static function record_document( int $delivery_id, string $document_key, string $reference ) {
		global $wpdb;
		$document_key = sanitize_key( $document_key );
		$reference = sanitize_text_field( $reference );
		if ( ! current_user_can( 'adc_approve_delivery' ) && ! current_user_can( 'adc_confirm_vehicle_vin' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'Delivery document permission is required.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		if ( ! isset( self::DOCUMENTS[ $document_key ] ) || '' === $reference ) {
			return new \WP_Error( 'adc_document_invalid', __( 'A supported document type and reference are required.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'Could not start the operation.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		$delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT d.id,d.status,v.branch_id FROM ' . Schema::table( 'deliveries' ) . ' d INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=d.vehicle_id WHERE d.id=%d FOR UPDATE', $delivery_id ), ARRAY_A );
		if ( ! $delivery || ! in_array( $delivery['status'], array( 'preparing','vin_confirmed' ), true ) || ! VehicleService::user_can_access_branch( (int) $delivery['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_document_state', __( 'Delivery documents are locked in this state.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		$table = Schema::table( 'delivery_documents' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,reference FROM $table WHERE delivery_id=%d AND document_key=%s FOR UPDATE", $delivery_id, $document_key ), ARRAY_A );
		$now = current_time( 'mysql', true );
		if ( $existing && hash_equals( (string) $existing['reference'], $reference ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::checklist( $delivery_id );
		}
		$ok = $existing ? $wpdb->update( $table, array( 'reference'=>$reference, 'confirmed_by'=>get_current_user_id(), 'confirmed_at'=>$now ), array( 'id'=>(int) $existing['id'] ) ) : $wpdb->insert( $table, array( 'delivery_id'=>$delivery_id, 'document_key'=>$document_key, 'reference'=>$reference, 'confirmed_by'=>get_current_user_id(), 'confirmed_at'=>$now ) );
		$id = $existing ? (int) $existing['id'] : (int) $wpdb->insert_id;
		if ( false === $ok || ( $existing && 1 !== $ok ) || ! Transaction::commit( static fn()=>AuditLog::record( 'delivery.document_recorded', 'delivery_document', $id, '', $existing ? array( 'reference'=>$existing['reference'] ) : null, array( 'delivery_id'=>$delivery_id, 'document_key'=>$document_key, 'reference'=>$reference, 'confirmed_by'=>get_current_user_id() ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_document_failed', __( 'Could not save and audit the delivery document.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return self::checklist( $delivery_id );
	}

	private static function documents_complete( int $delivery_id ): bool {
		global $wpdb;
		$required = array_column( self::required_documents(), 'key' );
		if ( ! $required ) { return true; }
		$present = $wpdb->get_col( $wpdb->prepare( 'SELECT document_key FROM ' . Schema::table( 'delivery_documents' ) . ' WHERE delivery_id=%d FOR UPDATE', $delivery_id ) ) ?: array();
		return ! array_diff( $required, $present );
	}

	public static function prepare( int $sale_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_approve_delivery' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية بدء تجهيز التسليم.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$sales = Schema::table( 'sales' );
		$sale = $wpdb->get_row( $wpdb->prepare( "SELECT s.id,s.vehicle_id,s.status,v.branch_id FROM $sales s INNER JOIN " . Schema::table( 'vehicles' ) . " v ON v.id=s.vehicle_id WHERE s.id = %d FOR UPDATE", $sale_id ), ARRAY_A );
		if ( ! $sale || 'approved' !== $sale['status'] || ! VehicleService::user_can_access_branch( (int) $sale['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_sale_state', __( 'البيع غير معتمد أو خارج نطاق فرعك.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( ! PaymentService::is_settled( $sale_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_payment_unverified', __( 'يجب التحقق من سداد كامل قيمة البيع قبل تجهيز التسليم.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( Schema::table( 'deliveries' ), array( 'sale_id' => $sale_id, 'vehicle_id' => (int) $sale['vehicle_id'], 'status' => 'preparing', 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		$updated = $wpdb->update( $sales, array( 'status' => 'ready_for_delivery', 'updated_at' => $now ), array( 'id' => $sale_id, 'status' => 'approved' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$vehicle = $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => 'ready_for_delivery', 'updated_at' => $now ), array( 'id' => (int) $sale['vehicle_id'], 'status' => 'sold' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( false === $ok || 1 !== $updated || 1 !== $vehicle ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_prepare_failed', __( 'تعذر بدء تجهيز التسليم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$movement = $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $sale['vehicle_id'], 'from_branch_id' => (int) $sale['branch_id'], 'to_branch_id' => (int) $sale['branch_id'], 'from_status' => 'sold', 'to_status' => 'ready_for_delivery', 'actor_user_id' => get_current_user_id(), 'reason' => 'Delivery preparation #' . $id, 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $movement ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_prepare_failed', __( 'تعذر تسجيل حركة المركبة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'delivery.prepared', 'delivery', $id, '', null, array( 'sale_id' => $sale_id ) ) ) ) {
			return new \WP_Error( 'adc_delivery_prepare_failed', __( 'تعذر توثيق تجهيز التسليم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $id, 'sale_id' => $sale_id, 'status' => 'preparing' );
	}

	public static function confirm_vin( int $delivery_id, string $vin ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_confirm_vehicle_vin' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية تأكيد رقم الهيكل.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$table = Schema::table( 'deliveries' );
		$delivery = $wpdb->get_row( $wpdb->prepare( "SELECT d.id,d.vehicle_id,d.status,v.vin,v.branch_id FROM $table d INNER JOIN " . Schema::table( 'vehicles' ) . " v ON v.id=d.vehicle_id WHERE d.id = %d FOR UPDATE", $delivery_id ), ARRAY_A );
		$vin = strtoupper( sanitize_text_field( $vin ) );
		if ( ! $delivery || 'preparing' !== $delivery['status'] || ! VehicleService::user_can_access_branch( (int) $delivery['branch_id'] ) || ! hash_equals( (string) $delivery['vin'], $vin ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vin_mismatch', __( 'رقم الهيكل غير مطابق أو أن التسليم غير متاح.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$updated = $wpdb->update( $table, array( 'status' => 'vin_confirmed', 'vin_confirmed_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $delivery_id, 'status' => 'preparing' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vin_update_failed', __( 'تعذر حفظ تأكيد رقم الهيكل.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'delivery.vin_confirmed', 'delivery', $delivery_id, 'VIN visually confirmed against vehicle', array( 'status' => 'preparing' ), array( 'status' => 'vin_confirmed', 'confirmed_by' => get_current_user_id() ) ) ) ) {
			return new \WP_Error( 'adc_vin_update_failed', __( 'تعذر توثيق مطابقة رقم الهيكل.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $delivery_id, 'status' => 'vin_confirmed' );
	}

	public static function approve( int $delivery_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_approve_delivery' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية اعتماد التسليم.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$table = Schema::table( 'deliveries' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT d.id,d.status,d.vin_confirmed_by,v.branch_id FROM $table d INNER JOIN " . Schema::table( 'vehicles' ) . " v ON v.id=d.vehicle_id WHERE d.id = %d FOR UPDATE", $delivery_id ), ARRAY_A );
		if ( ! $row || 'vin_confirmed' !== $row['status'] || (int) $row['vin_confirmed_by'] < 1 || (int) $row['vin_confirmed_by'] === get_current_user_id() || ! VehicleService::user_can_access_branch( (int) $row['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_approval_denied', __( 'يجب أن يعتمد التسليم موظف مختلف عن مؤكد رقم الهيكل.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( ! self::documents_complete( $delivery_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_documents_incomplete', __( 'Every required delivery document must be recorded before approval.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		$updated = $wpdb->update( $table, array( 'status' => 'approved', 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $delivery_id, 'status' => 'vin_confirmed' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_approval_failed', __( 'تعذر حفظ اعتماد التسليم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'delivery.approved', 'delivery', $delivery_id, '', array( 'status' => 'vin_confirmed' ), array( 'status' => 'approved', 'approver' => get_current_user_id() ) ) ) ) {
			return new \WP_Error( 'adc_delivery_approval_failed', __( 'تعذر توثيق اعتماد التسليم.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $delivery_id, 'status' => 'approved' );
	}

	public static function release( int $delivery_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_approve_delivery' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية تسليم المركبة.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$table = Schema::table( 'deliveries' );
		$sale_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT sale_id FROM $table WHERE id = %d", $delivery_id ) );
		$sale = $wpdb->get_row( $wpdb->prepare( 'SELECT id,status,owner_user_id,approved_by,invoice_reference FROM ' . Schema::table( 'sales' ) . ' WHERE id = %d FOR UPDATE', $sale_id ), ARRAY_A );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT d.id,d.sale_id,d.vehicle_id,d.status,d.vin_confirmed_by,d.approved_by,v.branch_id FROM $table d INNER JOIN " . Schema::table( 'vehicles' ) . " v ON v.id=d.vehicle_id WHERE d.id = %d FOR UPDATE", $delivery_id ), ARRAY_A );
		if ( ! $row || ! $sale || (int) $row['sale_id'] !== $sale_id || 'ready_for_delivery' !== $sale['status'] || (int) $sale['approved_by'] < 1 || '' === trim( $sale['invoice_reference'] ) || (int) $sale['owner_user_id'] === get_current_user_id() || 'approved' !== $row['status'] || (int) $row['vin_confirmed_by'] < 1 || (int) $row['approved_by'] < 1 || (int) $row['vin_confirmed_by'] === (int) $row['approved_by'] || (int) $row['vin_confirmed_by'] === get_current_user_id() || ! VehicleService::user_can_access_branch( (int) $row['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_release_denied', __( 'لا تستوفي عملية التسليم شروط الإطلاق أو فصل المهام.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( ! PaymentService::is_settled( $sale_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_payment_unverified', __( 'لم يتم التحقق من سداد كامل قيمة البيع.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( ! self::documents_complete( $delivery_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_documents_incomplete', __( 'Required delivery documents are incomplete.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		$now = current_time( 'mysql', true );
		$delivery_update = $wpdb->update( $table, array( 'status' => 'delivered', 'delivered_at' => $now, 'updated_at' => $now ), array( 'id' => $delivery_id, 'status' => 'approved' ), array( '%s', '%s', '%s' ), array( '%d', '%s' ) );
		$vehicle_update = $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => 'delivered', 'updated_at' => $now ), array( 'id' => (int) $row['vehicle_id'], 'status' => 'ready_for_delivery' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$sale_update = $wpdb->update( Schema::table( 'sales' ), array( 'status' => 'delivered', 'updated_at' => $now ), array( 'id' => (int) $row['sale_id'], 'status' => 'ready_for_delivery' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$movement = 1 === $vehicle_update ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $row['vehicle_id'], 'from_branch_id' => (int) $row['branch_id'], 'to_branch_id' => (int) $row['branch_id'], 'from_status' => 'ready_for_delivery', 'to_status' => 'delivered', 'actor_user_id' => get_current_user_id(), 'reason' => 'Delivery released #' . $delivery_id, 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) ) : false;
		if ( 1 !== $delivery_update || 1 !== $vehicle_update || 1 !== $sale_update || false === $movement ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_delivery_release_failed', __( 'تعذر تسجيل تسليم المركبة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'delivery.released', 'delivery', $delivery_id, '', array( 'status' => 'approved' ), array( 'status' => 'delivered', 'released_by' => get_current_user_id() ) ) && AuditLog::record( 'vehicle.status_changed', 'vehicle', (int) $row['vehicle_id'], 'Delivery released', array( 'status' => 'ready_for_delivery' ), array( 'status' => 'delivered' ) ) && DomainEventPublisher::commit( 'delivery.released', $delivery_id, (int) $row['branch_id'], 'delivered' ) ) ) {
			return new \WP_Error( 'adc_delivery_release_failed', __( 'تعذر توثيق تسليم المركبة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $delivery_id, 'status' => 'delivered', 'delivered_at' => $now );
	}
}
