<?php
namespace AutoDealership\Reservations;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Security\CustomerScope;
use AutoDealership\Database\Transaction;
use AutoDealership\Pricing\PricingPolicy;
use AutoDealership\Security\BranchScope;
use AutoDealership\Integrations\DomainEventPublisher;

defined( 'ABSPATH' ) || exit;

/** Atomic reservation creation and safe expiry. */
final class ReservationService {
	public static function create( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_create_reservations' ) && ! current_user_can( 'adc_manage_reservations' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إنشاء الحجوزات.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$vehicle_id = filter_var( $input['vehicle_id'] ?? 0, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		$customer_id = filter_var( $input['customer_id'] ?? 0, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		$deposit = filter_var( $input['deposit_amount'] ?? 0, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		$key = isset( $input['idempotency_key'] ) && is_string( $input['idempotency_key'] ) ? strtolower( trim( $input['idempotency_key'] ) ) : '';
		if ( ! wp_is_uuid( $key ) || ! $vehicle_id || ! $customer_id || false === $deposit ) {
			return new \WP_Error( 'adc_invalid_reservation', __( 'بيانات الحجز غير صالحة.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$existing = self::find_key( $key );
		if ( $existing ) {
			return self::replay( $existing, $vehicle_id, $customer_id, $deposit );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$vehicle_table = Schema::table( 'vehicles' );
		$vehicle = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,status,retail_price FROM $vehicle_table WHERE id = %d FOR UPDATE", $vehicle_id ), ARRAY_A );
		if ( ! $vehicle || ! VehicleService::user_can_access_branch( (int) $vehicle['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vehicle_unavailable', __( 'السيارة غير متاحة للحجز أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		// The vehicle lock serializes retries that arrived before the first request committed.
		$existing = self::find_key( $key, true );
		if ( $existing ) {
			$wpdb->query( 'ROLLBACK' );
			return self::replay( $existing, $vehicle_id, $customer_id, $deposit );
		}
		if ( 'available' !== $vehicle['status'] || ! CustomerScope::allows( $customer_id, (int) $vehicle['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_reservation_scope', __( 'العميل أو السيارة غير متاحين لهذه العملية.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		try {
			$policy = PricingPolicy::reservation_deposit( (int) $vehicle['retail_price'] );
		} catch ( \InvalidArgumentException | \OverflowException ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_deposit_policy_invalid', __( 'Reservation deposit policy is invalid.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( $deposit > 0 && $deposit !== $policy['required_amount'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_deposit_amount_invalid', __( 'The declared deposit does not match the reservation policy.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$duration = min( 168, max( 1, absint( get_option( 'adc_reservation_hours', 24 ) ) ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( $duration * HOUR_IN_SECONDS ) );
		$inserted = $wpdb->insert( Schema::table( 'reservations' ), array( 'vehicle_id' => $vehicle_id, 'customer_id' => $customer_id, 'branch_id' => (int) $vehicle['branch_id'], 'owner_user_id' => get_current_user_id(), 'status' => 'confirmed', 'deposit_policy' => wp_json_encode( array( 'type' => $policy['type'], 'value' => $policy['value'], 'declared_amount' => $deposit ) ), 'deposit_required_amount' => $policy['required_amount'], 'deposit_amount' => 0, 'payment_reference' => '', 'expires_at' => $expires, 'idempotency_key' => $key, 'created_at' => $now, 'updated_at' => $now ) );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = self::find_key( $key );
			if ( $existing ) { return self::replay( $existing, $vehicle_id, $customer_id, $deposit ); }
			return new \WP_Error( 'adc_reservation_failed', __( 'تعذر إنشاء الحجز.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$reservation_id = (int) $wpdb->insert_id;
		$changed = $wpdb->update( $vehicle_table, array( 'status' => 'reserved', 'updated_at' => $now ), array( 'id' => $vehicle_id, 'status' => 'available' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $changed ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vehicle_race', __( 'تم حجز السيارة من مستخدم آخر. حدّث الصفحة وحاول مجددًا.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$movement = $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => $vehicle_id, 'from_branch_id' => (int) $vehicle['branch_id'], 'to_branch_id' => (int) $vehicle['branch_id'], 'from_status' => 'available', 'to_status' => 'reserved', 'actor_user_id' => get_current_user_id(), 'reason' => 'Reservation #' . $reservation_id, 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $movement ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_reservation_failed', __( 'تعذر تسجيل حركة المخزون.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'reservation.confirmed', 'reservation', $reservation_id, 'Reservation created', null, array( 'vehicle_id' => $vehicle_id, 'customer_id' => $customer_id, 'expires_at' => $expires, 'deposit_policy' => $policy, 'deposit_receipt_verified' => false ) ) && DomainEventPublisher::commit( 'reservation.confirmed', $reservation_id, (int) $vehicle['branch_id'], 'confirmed' ) ) ) {
			return new \WP_Error( 'adc_reservation_failed', __( 'تعذر توثيق الحجز وحفظه.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $reservation_id, 'vehicle_id' => $vehicle_id, 'status' => 'confirmed', 'expires_at' => $expires, 'deposit_policy' => $policy['type'], 'deposit_required_amount' => $policy['required_amount'], 'deposit_verified_amount' => 0, 'currency' => 'SAR' );
	}

	private static function find_key( string $key, bool $lock = false ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id,vehicle_id,customer_id,branch_id,owner_user_id,status,deposit_policy,deposit_required_amount,deposit_amount,expires_at FROM ' . Schema::table( 'reservations' ) . ' WHERE idempotency_key = %s' . ( $lock ? ' FOR UPDATE' : '' ), $key ), ARRAY_A ) ?: null;
	}

	private static function replay( array $row, int $vehicle_id, int $customer_id, int $deposit ) {
		$policy = json_decode( (string) $row['deposit_policy'], true );
		$declared = is_array( $policy ) ? (int) ( $policy['declared_amount'] ?? 0 ) : (int) $row['deposit_amount'];
		if ( (int) $row['owner_user_id'] !== get_current_user_id() || (int) $row['vehicle_id'] !== $vehicle_id || (int) $row['customer_id'] !== $customer_id || $declared !== $deposit || ! CustomerScope::allows( $customer_id, (int) $row['branch_id'] ) ) {
			return new \WP_Error( 'adc_idempotency_conflict', __( 'مفتاح الطلب غير متاح لهذه العملية.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		return array( 'id' => (int) $row['id'], 'vehicle_id' => (int) $row['vehicle_id'], 'status' => $row['status'], 'expires_at' => $row['expires_at'], 'deposit_policy' => $policy['type'] ?? 'none', 'deposit_required_amount' => (int) $row['deposit_required_amount'], 'deposit_verified_amount' => (int) $row['deposit_amount'], 'currency' => 'SAR' );
	}

	/** Record deposit evidence; only an independent reviewer can make it effective. */
	public static function record_deposit( int $reservation_id, int $amount, string $source, string $reference ) {
		global $wpdb;
		$source = sanitize_key( $source );
		$reference = sanitize_text_field( $reference );
		if ( ! current_user_can( 'adc_record_payments' ) || $amount < 1 || ! in_array( $source, array( 'bank_transfer', 'cash', 'card', 'finance' ), true ) || '' === $reference || strlen( $reference ) > 100 ) {
			return new \WP_Error( 'adc_deposit_invalid', __( 'Valid deposit evidence and payment recording permission are required.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'Could not start the operation.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$reservation = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,owner_user_id,status,expires_at,deposit_required_amount,deposit_amount FROM ' . Schema::table( 'reservations' ) . ' WHERE id=%d FOR UPDATE', $reservation_id ), ARRAY_A );
		if ( ! $reservation || 'confirmed' !== $reservation['status'] || $reservation['expires_at'] <= current_time( 'mysql', true ) || $amount !== (int) $reservation['deposit_required_amount'] || (int) $reservation['deposit_amount'] > 0 || ! VehicleService::user_can_access_branch( (int) $reservation['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_deposit_state', __( 'The reservation cannot accept this deposit evidence.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$table = Schema::table( 'reservation_deposits' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE reservation_id=%d FOR UPDATE", $reservation_id ), ARRAY_A );
		$now = current_time( 'mysql', true );
		if ( $existing && 'rejected' !== $existing['status'] ) {
			$wpdb->query( 'ROLLBACK' );
			if ( (int) $existing['amount'] === $amount && hash_equals( (string) $existing['source'], $source ) && hash_equals( (string) $existing['reference'], $reference ) && (int) $existing['recorded_by'] === get_current_user_id() ) {
				return array( 'id'=>(int) $existing['id'], 'reservation_id'=>$reservation_id, 'status'=>$existing['status'] );
			}
			return new \WP_Error( 'adc_deposit_exists', __( 'Deposit evidence is already pending or verified.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$data = array( 'amount'=>$amount, 'currency'=>'SAR', 'source'=>$source, 'reference'=>$reference, 'status'=>'pending', 'recorded_by'=>get_current_user_id(), 'decided_by'=>0, 'decision_reason'=>'', 'created_at'=>$now, 'decided_at'=>null );
		$ok = $existing ? $wpdb->update( $table, $data, array( 'id'=>(int) $existing['id'], 'status'=>'rejected' ) ) : $wpdb->insert( $table, array_merge( array( 'reservation_id'=>$reservation_id ), $data ) );
		$id = $existing ? (int) $existing['id'] : (int) $wpdb->insert_id;
		if ( false === $ok || ( $existing && 1 !== $ok ) || ! Transaction::commit( static fn()=>AuditLog::record( 'reservation.deposit_recorded', 'reservation_deposit', $id, '', null, array( 'reservation_id'=>$reservation_id, 'amount'=>$amount, 'currency'=>'SAR', 'source'=>$source, 'reference'=>$reference, 'status'=>'pending' ) ) ) ) {
			if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); }
			return new \WP_Error( 'adc_deposit_failed', __( 'Could not save and audit the deposit evidence.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id'=>$id, 'reservation_id'=>$reservation_id, 'status'=>'pending' );
	}

	public static function decide_deposit( int $deposit_id, bool $approve, string $reason = '' ) {
		global $wpdb;
		$reason = sanitize_textarea_field( $reason );
		if ( ! current_user_can( 'adc_verify_payments' ) || ( ! $approve && '' === $reason ) ) {
			return new \WP_Error( 'adc_deposit_decision_forbidden', __( 'Deposit verification permission and a rejection reason are required.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'Could not start the operation.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$table = Schema::table( 'reservation_deposits' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT d.*,r.branch_id,r.owner_user_id,r.status AS reservation_status,r.deposit_required_amount FROM ' . $table . ' d INNER JOIN ' . Schema::table( 'reservations' ) . ' r ON r.id=d.reservation_id WHERE d.id=%d FOR UPDATE', $deposit_id ), ARRAY_A );
		if ( ! $row || 'pending' !== $row['status'] || (int) $row['recorded_by'] === get_current_user_id() || (int) $row['owner_user_id'] === get_current_user_id() || 'confirmed' !== $row['reservation_status'] || (int) $row['amount'] !== (int) $row['deposit_required_amount'] || ! VehicleService::user_can_access_branch( (int) $row['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_deposit_decision_state', __( 'The deposit evidence cannot be decided by this user or in this state.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$status = $approve ? 'verified' : 'rejected';
		$now = current_time( 'mysql', true );
		$updated = $wpdb->update( $table, array( 'status'=>$status, 'decided_by'=>get_current_user_id(), 'decision_reason'=>$reason, 'decided_at'=>$now ), array( 'id'=>$deposit_id, 'status'=>'pending' ) );
		$reservation_update = 1;
		if ( $approve ) {
			$reservation_update = $wpdb->update( Schema::table( 'reservations' ), array( 'deposit_amount'=>(int) $row['amount'], 'payment_reference'=>$row['reference'], 'updated_at'=>$now ), array( 'id'=>(int) $row['reservation_id'], 'status'=>'confirmed', 'deposit_amount'=>0 ) );
		}
		if ( 1 !== $updated || 1 !== $reservation_update || ! Transaction::commit( static fn()=>AuditLog::record( 'reservation.deposit_' . $status, 'reservation_deposit', $deposit_id, $reason, array( 'status'=>'pending' ), array( 'status'=>$status, 'reservation_id'=>(int) $row['reservation_id'], 'amount'=>(int) $row['amount'], 'decided_by'=>get_current_user_id() ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_deposit_decision_failed', __( 'Could not save and audit the deposit decision.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id'=>$deposit_id, 'reservation_id'=>(int) $row['reservation_id'], 'status'=>$status );
	}

	public static function deposit_queue(): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_finance' ) && ! current_user_can( 'adc_record_payments' ) && ! current_user_can( 'adc_verify_payments' ) ) { return array(); }
		list( $scope, $args ) = BranchScope::predicate( 'r.branch_id' );
		$sql = 'SELECT d.id,d.reservation_id,d.amount,d.currency,d.source,d.reference,d.status,d.recorded_by,d.decided_by,d.decision_reason,d.created_at,d.decided_at,r.owner_user_id,r.expires_at,v.stock_number FROM ' . Schema::table( 'reservation_deposits' ) . ' d INNER JOIN ' . Schema::table( 'reservations' ) . ' r ON r.id=d.reservation_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=r.vehicle_id WHERE ' . $scope . ' ORDER BY d.id DESC LIMIT 100';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	public static function expire_due(): int {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return 0; }
		$table = Schema::table( 'reservations' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE status = 'confirmed' AND expires_at <= %s ORDER BY expires_at LIMIT 100", current_time( 'mysql', true ) ) );
		$expired = 0;
		foreach ( $ids ?: array() as $id ) {
			if ( ! Transaction::begin() ) { continue; }
			$reservation = $wpdb->get_row( $wpdb->prepare( "SELECT id,vehicle_id,branch_id,status,expires_at,payment_reference,deposit_amount FROM $table WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
			if ( ! $reservation || 'confirmed' !== $reservation['status'] || $reservation['expires_at'] > current_time( 'mysql', true ) ) {
				$wpdb->query( 'ROLLBACK' );
				continue;
			}
			$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT id,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id = %d FOR UPDATE', (int) $reservation['vehicle_id'] ), ARRAY_A );
			$active_sale = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'sales' ) . " WHERE reservation_id = %d AND status NOT IN ('cancelled','rejected','delivered') LIMIT 1", (int) $id ) );
			$deposit_evidence = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'reservation_deposits' ) . " WHERE reservation_id=%d AND status IN ('pending','verified') LIMIT 1", (int) $id ) );
			if ( ! $vehicle || 'reserved' !== $vehicle['status'] || '' !== $reservation['payment_reference'] || (int) $reservation['deposit_amount'] > 0 || $deposit_evidence || $active_sale ) {
				$wpdb->query( 'ROLLBACK' );
				continue;
			}
			$reservation_update = $wpdb->update( $table, array( 'status' => 'expired', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id, 'status' => 'confirmed' ), array( '%s', '%s' ), array( '%d', '%s' ) );
			$vehicle_update = $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => 'available', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $reservation['vehicle_id'], 'status' => 'reserved' ), array( '%s', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== $reservation_update || 1 !== $vehicle_update ) {
				$wpdb->query( 'ROLLBACK' );
				continue;
			}
			$movement = $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $reservation['vehicle_id'], 'from_branch_id' => (int) $reservation['branch_id'], 'to_branch_id' => (int) $reservation['branch_id'], 'from_status' => 'reserved', 'to_status' => 'available', 'actor_user_id' => 0, 'reason' => 'Reservation expired #' . (int) $id, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) );
			if ( false === $movement ) {
				$wpdb->query( 'ROLLBACK' );
				continue;
			}
			if ( ! Transaction::commit( static fn() => AuditLog::record( 'reservation.expired', 'reservation', (int) $id, 'Reservation expired', array( 'status' => 'confirmed' ), array( 'status' => 'expired' ) ) ) ) { continue; }
			++$expired;
		}
		return $expired;
	}

	public static function cancel( int $reservation_id, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_reservations' ) || '' === trim( $reason ) ) {
			return new \WP_Error( 'adc_reservation_forbidden', __( 'صلاحية إدارة الحجز وسبب الإلغاء مطلوبان.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$reservations = Schema::table( 'reservations' );
		$reservation = $wpdb->get_row( $wpdb->prepare( "SELECT id,vehicle_id,status,branch_id,deposit_amount,payment_reference FROM $reservations WHERE id = %d FOR UPDATE", $reservation_id ), ARRAY_A );
		if ( ! $reservation || 'confirmed' !== $reservation['status'] || ! VehicleService::user_can_access_branch( (int) $reservation['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_reservation_state', __( 'الحجز غير موجود أو لم يعد قابلًا للإلغاء.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id = %d FOR UPDATE', (int) $reservation['vehicle_id'] ), ARRAY_A );
		if ( ! $vehicle || 'reserved' !== $vehicle['status'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vehicle_state', __( 'حالة السيارة لا تسمح بإلغاء الحجز.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$active_sale = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'sales' ) . " WHERE reservation_id = %d AND status NOT IN ('cancelled','rejected') LIMIT 1", $reservation_id ) );
		if ( $active_sale ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_active_sale', __( 'يجب تسوية عملية البيع المرتبطة قبل إلغاء الحجز.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$pending_deposit = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'reservation_deposits' ) . " WHERE reservation_id=%d AND status='pending' FOR UPDATE", $reservation_id ) );
		if ( $pending_deposit ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_deposit_decision_pending', __( 'Pending deposit evidence must be verified or rejected before cancelling the reservation.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$vehicle_status = ( (int) $reservation['deposit_amount'] > 0 || '' !== $reservation['payment_reference'] ) ? 'hold' : 'available';
		$refund_status = (int) $reservation['deposit_amount'] > 0 ? 'pending_refund' : 'no_refund_due';
		$reservation_update = $wpdb->update( $reservations, array( 'status' => 'cancelled', 'deposit_refund_status' => $refund_status, 'updated_at' => $now ), array( 'id' => $reservation_id, 'status' => 'confirmed' ), array( '%s', '%s', '%s' ), array( '%d', '%s' ) );
		$vehicle_update = $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => $vehicle_status, 'updated_at' => $now ), array( 'id' => (int) $reservation['vehicle_id'], 'status' => 'reserved' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$movement = 1 === $vehicle_update ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $reservation['vehicle_id'], 'from_branch_id' => (int) $reservation['branch_id'], 'to_branch_id' => (int) $reservation['branch_id'], 'from_status' => 'reserved', 'to_status' => $vehicle_status, 'actor_user_id' => get_current_user_id(), 'reason' => sanitize_textarea_field( $reason ), 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) ) : false;
		if ( 1 !== $reservation_update || 1 !== $vehicle_update || false === $movement ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_reservation_cancel_failed', __( 'تعذر إلغاء الحجز.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'reservation.cancelled', 'reservation', $reservation_id, $reason, array( 'status' => 'confirmed' ), array( 'status' => 'cancelled', 'vehicle_status' => $vehicle_status, 'deposit_refund_status' => $refund_status ) ) ) ) {
			return new \WP_Error( 'adc_reservation_cancel_failed', __( 'تعذر توثيق إلغاء الحجز.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $reservation_id, 'status' => 'cancelled', 'vehicle_status' => $vehicle_status, 'deposit_refund_status' => $refund_status );
	}
}
