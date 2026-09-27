<?php
namespace AutoDealership\Reservations;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Security\CustomerScope;
use AutoDealership\Database\Transaction;

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
		$vehicle = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,status FROM $vehicle_table WHERE id = %d FOR UPDATE", $vehicle_id ), ARRAY_A );
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
		$now = current_time( 'mysql', true );
		$duration = min( 168, max( 1, absint( get_option( 'adc_reservation_hours', 24 ) ) ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( $duration * HOUR_IN_SECONDS ) );
		$inserted = $wpdb->insert( Schema::table( 'reservations' ), array( 'vehicle_id' => $vehicle_id, 'customer_id' => $customer_id, 'branch_id' => (int) $vehicle['branch_id'], 'owner_user_id' => get_current_user_id(), 'status' => 'confirmed', 'deposit_amount' => $deposit, 'payment_reference' => '', 'expires_at' => $expires, 'idempotency_key' => $key, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
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
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'reservation.confirmed', 'reservation', $reservation_id, 'Reservation created', null, array( 'vehicle_id' => $vehicle_id, 'customer_id' => $customer_id, 'expires_at' => $expires ) ) ) ) {
			return new \WP_Error( 'adc_reservation_failed', __( 'تعذر توثيق الحجز وحفظه.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $reservation_id, 'vehicle_id' => $vehicle_id, 'status' => 'confirmed', 'expires_at' => $expires );
	}

	private static function find_key( string $key, bool $lock = false ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id,vehicle_id,customer_id,branch_id,owner_user_id,status,deposit_amount,expires_at FROM ' . Schema::table( 'reservations' ) . ' WHERE idempotency_key = %s' . ( $lock ? ' FOR UPDATE' : '' ), $key ), ARRAY_A ) ?: null;
	}

	private static function replay( array $row, int $vehicle_id, int $customer_id, int $deposit ) {
		if ( (int) $row['owner_user_id'] !== get_current_user_id() || (int) $row['vehicle_id'] !== $vehicle_id || (int) $row['customer_id'] !== $customer_id || (int) $row['deposit_amount'] !== $deposit || ! CustomerScope::allows( $customer_id, (int) $row['branch_id'] ) ) {
			return new \WP_Error( 'adc_idempotency_conflict', __( 'مفتاح الطلب غير متاح لهذه العملية.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		return array( 'id' => (int) $row['id'], 'vehicle_id' => (int) $row['vehicle_id'], 'status' => $row['status'], 'expires_at' => $row['expires_at'] );
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
			if ( ! $vehicle || 'reserved' !== $vehicle['status'] || '' !== $reservation['payment_reference'] || (int) $reservation['deposit_amount'] > 0 || $active_sale ) {
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
		$now = current_time( 'mysql', true );
		$vehicle_status = ( (int) $reservation['deposit_amount'] > 0 || '' !== $reservation['payment_reference'] ) ? 'hold' : 'available';
		$reservation_update = $wpdb->update( $reservations, array( 'status' => 'cancelled', 'updated_at' => $now ), array( 'id' => $reservation_id, 'status' => 'confirmed' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$vehicle_update = $wpdb->update( Schema::table( 'vehicles' ), array( 'status' => $vehicle_status, 'updated_at' => $now ), array( 'id' => (int) $reservation['vehicle_id'], 'status' => 'reserved' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$movement = 1 === $vehicle_update ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => (int) $reservation['vehicle_id'], 'from_branch_id' => (int) $reservation['branch_id'], 'to_branch_id' => (int) $reservation['branch_id'], 'from_status' => 'reserved', 'to_status' => $vehicle_status, 'actor_user_id' => get_current_user_id(), 'reason' => sanitize_textarea_field( $reason ), 'created_at' => $now ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) ) : false;
		if ( 1 !== $reservation_update || 1 !== $vehicle_update || false === $movement ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_reservation_cancel_failed', __( 'تعذر إلغاء الحجز.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'reservation.cancelled', 'reservation', $reservation_id, $reason, array( 'status' => 'confirmed' ), array( 'status' => 'cancelled', 'vehicle_status' => $vehicle_status ) ) ) ) {
			return new \WP_Error( 'adc_reservation_cancel_failed', __( 'تعذر توثيق إلغاء الحجز.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $reservation_id, 'status' => 'cancelled', 'vehicle_status' => $vehicle_status );
	}
}
