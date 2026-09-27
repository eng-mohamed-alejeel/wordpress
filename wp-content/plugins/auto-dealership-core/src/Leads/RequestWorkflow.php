<?php
namespace AutoDealership\Leads;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Audited updates of the account-visible request attached to a core lead. */
final class RequestWorkflow {
	public static function statuses( string $type ): array {
		return 'booking' === $type ? array( 'pending'=>'قيد الانتظار', 'confirmed'=>'مؤكد', 'completed'=>'مكتمل', 'cancelled'=>'ملغى' ) : array( 'new'=>'جديد', 'read'=>'قيد المتابعة', 'completed'=>'مكتمل', 'cancelled'=>'ملغى' );
	}

	private static function table( string $type ): string {
		global $wpdb;
		return in_array( $type, array( 'message', 'booking' ), true ) ? $wpdb->prefix . 'car_dealer_' . ( 'booking' === $type ? 'bookings' : 'messages' ) : '';
	}

	/** Null means genuinely unmapped; errors must never fall back to legacy writes. */
	public static function linked_lead( string $type, int $id ) {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return self::error( 'adc_schema_unavailable', 503 ); }
		if ( ! self::table( $type ) || $id < 1 ) { return self::error( 'adc_request_invalid', 400 ); }
		$lead = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'leads' ) . ' WHERE legacy_request_type=%s AND legacy_request_id=%d', $type, $id ) );
		return $wpdb->last_error ? self::error( 'adc_request_unavailable', 503 ) : ( $lead ? (int) $lead : null );
	}

	/** Prepared predicate for code-owned request ID columns, including legacy lists/counts. */
	public static function staff_predicate( string $type, string $column ): string {
		global $wpdb;
		if ( ! preg_match( '/\A[a-z_][a-z0-9_]*\.id\z/i', $column ) || ! self::table( $type ) ) { throw new \InvalidArgumentException( 'Invalid request scope column.' ); }
		if ( ! Schema::is_ready() ) { return '1=0'; }
		if ( current_user_can( 'manage_options' ) ) { return '1=1'; }
		if ( ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_own_leads' ) ) { return '1=0'; }
		list( $scope, $args ) = BranchScope::predicate( 'request_lead.branch_id' );
		if ( ! current_user_can( 'adc_view_branch_leads' ) ) { $scope .= ' AND request_lead.owner_user_id=%d'; $args[] = get_current_user_id(); }
		$args[] = $type;
		return $wpdb->prepare( 'EXISTS (SELECT 1 FROM ' . Schema::table( 'leads' ) . " request_lead WHERE $scope AND request_lead.legacy_request_type=%s AND request_lead.legacy_request_id=$column)", $args );
	}

	public static function read( int $lead_id ) {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return self::error( 'adc_schema_unavailable', 503 ); }
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', $lead_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_request_unavailable', 503 ); }
		if ( ! $lead || ! BranchScope::can_view_lead( $lead ) ) { return self::error( 'adc_request_not_found', 404 ); }
		$type = (string) $lead['legacy_request_type']; $table = self::table( $type );
		if ( ! $table || ! $lead['legacy_request_id'] ) { return self::error( 'adc_request_not_found', 404 ); }
		$scope = self::staff_predicate( $type, 'r.id' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT r.* FROM $table r WHERE r.id=%d AND $scope", $lead['legacy_request_id'] ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_request_unavailable', 503 ); }
		if ( ! $row ) { return self::error( 'adc_request_not_found', 404 ); }
		$revision = self::revision( $lead_id, $type, $row );
		if ( is_wp_error( $revision ) ) { return $revision; }
		return array( 'lead_id'=>$lead_id, 'request_id'=>(int) $row['id'], 'type'=>$type, 'status'=>$row['status'], 'customer_reply'=>$row['customer_reply'], 'requested_date'=>$row['requested_date'] ?? '', 'requested_time'=>$row['requested_time'] ?? '', 'revision'=>$revision, 'can_edit'=>BranchScope::can_manage_lead( $lead ) );
	}

	private static function revision( int $lead_id, string $type, array $row ) {
		global $wpdb;
		$activity = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . Schema::table( 'activities' ) . ' WHERE lead_id=%d', $lead_id ) );
		if ( $wpdb->last_error ) { return self::error( 'adc_request_unavailable', 503 ); }
		return hash_hmac( 'sha256', wp_json_encode( array( $lead_id, $type, $row, (int) $activity ) ), wp_salt( 'auth' ) );
	}

	public static function update( int $lead_id, array $input, bool $customer_cancel = false ) {
		global $wpdb;
		if ( ! is_user_logged_in() ) { return self::error( 'adc_request_forbidden', 403 ); }
		foreach ( array( 'status'=>20, 'customer_reply'=>4000, 'requested_date'=>10, 'requested_time'=>5, 'revision'=>64 ) as $key => $max ) {
			if ( array_key_exists( $key, $input ) && ( ! is_string( $input[$key] ) || mb_strlen( $input[$key] ) > $max ) ) { return self::error( 'adc_request_invalid', 400 ); }
		}
		if ( ! Transaction::begin() ) { return self::error( 'adc_request_unavailable', 503 ); }
		// Match retention's customer -> lead -> compatibility lock order.
		$customer_id = $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', $lead_id ) );
		if ( $wpdb->last_error ) { return self::rollback( 'adc_request_unavailable', 503 ); }
		$customer = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d FOR UPDATE', (int) $customer_id ) );
		if ( $wpdb->last_error ) { return self::rollback( 'adc_request_unavailable', 503 ); }
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d FOR UPDATE', $lead_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::rollback( 'adc_request_unavailable', 503 ); }
		if ( ! $customer || ! $lead || (int) $lead['customer_id'] !== (int) $customer_id || ( ! $customer_cancel && ! BranchScope::can_manage_lead( $lead ) ) ) { return self::rollback( 'adc_request_not_found', 404 ); }
		$type = (string) $lead['legacy_request_type']; $table = self::table( $type );
		if ( ! $table || ! $lead['legacy_request_id'] ) { return self::rollback( 'adc_request_not_found', 404 ); }
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( 'InnoDB' !== $engine ) { return self::rollback( 'adc_request_unavailable', 503 ); }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d FOR UPDATE", $lead['legacy_request_id'] ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::rollback( 'adc_request_unavailable', 503 ); }
		if ( ! $row || ( $customer_cancel && ( 'booking' !== $type || (int) $row['user_id'] !== get_current_user_id() ) ) ) { return self::rollback( 'adc_request_not_found', 404 ); }
		if ( $customer_cancel ) {
			if ( 'cancelled' === $row['status'] ) { $wpdb->query( 'ROLLBACK' ); return array( 'updated'=>false, 'status'=>'cancelled' ); }
			if ( ! in_array( $row['status'], array( 'pending', 'confirmed' ), true ) ) { return self::rollback( 'adc_request_transition', 409 ); }
			$changes = array( 'status'=>'cancelled' );
		} else {
			$revision = self::revision( $lead_id, $type, $row );
			if ( is_wp_error( $revision ) ) { return self::rollback( 'adc_request_unavailable', 503 ); }
			if ( ! isset( $input['revision'] ) || ! hash_equals( $revision, $input['revision'] ) ) { return self::rollback( 'adc_request_stale', 409 ); }
			$to = $input['status'] ?? $row['status'];
			$transitions = 'booking' === $type ? array( 'pending'=>array( 'confirmed','cancelled' ), 'confirmed'=>array( 'pending','completed','cancelled' ) ) : array( 'new'=>array( 'read','completed','cancelled' ), 'read'=>array( 'completed','cancelled' ) );
			if ( ! isset( self::statuses( $type )[$to] ) || ( $to !== $row['status'] && ! in_array( $to, $transitions[$row['status']] ?? array(), true ) ) ) { return self::rollback( 'adc_request_transition', 409 ); }
			$changes = array( 'status'=>$to, 'customer_reply'=>array_key_exists( 'customer_reply', $input ) ? sanitize_textarea_field( $input['customer_reply'] ) : $row['customer_reply'] );
			if ( 'booking' === $type ) {
				$date = $input['requested_date'] ?? (string) $row['requested_date']; $time = $input['requested_time'] ?? (string) $row['requested_time'];
				$rescheduled = $date !== (string) $row['requested_date'] || $time !== (string) $row['requested_time'];
				$entering_active = $to !== $row['status'] && in_array( $to, array( 'pending', 'confirmed' ), true );
				if ( $rescheduled && ( in_array( $row['status'], array( 'completed','cancelled' ), true ) || in_array( $to, array( 'completed','cancelled' ), true ) ) ) { return self::rollback( 'adc_request_transition', 409 ); }
				if ( ( $rescheduled || $entering_active ) && ! self::future_appointment( $date, $time ) ) { return self::rollback( 'adc_request_date', 400 ); }
				if ( $rescheduled || ( 'confirmed' === $to && $to !== $row['status'] ) ) {
					if ( ! self::vehicle_available( (int) $row['car_id'], (int) $lead['branch_id'] ) ) { return self::rollback( 'adc_request_vehicle', 409 ); }
				}
				if ( $rescheduled ) { $changes['requested_date'] = $date; $changes['requested_time'] = $time; }
			}
		}
		$changed = array();
		foreach ( $changes as $key => $value ) { if ( (string) $row[$key] !== (string) $value ) { $changed[$key] = $value; } }
		if ( ! $changed ) { $wpdb->query( 'ROLLBACK' ); return array( 'updated'=>false, 'status'=>$row['status'] ); }
		$changed['updated_at'] = current_time( 'mysql' );
		if ( 1 !== $wpdb->update( $table, $changed, array( 'id'=>(int) $row['id'] ) ) ) { return self::rollback( 'adc_request_failed', 500 ); }
		$notes = ( $customer_cancel ? 'Customer cancellation' : 'Staff request update' ) . ': ' . $type . ' #' . $row['id'] . "\nStatus: " . $row['status'] . ' -> ' . $changes['status'];
		if ( isset( $changed['requested_date'] ) ) { $notes .= "\nAppointment: " . $changed['requested_date'] . ' ' . $changed['requested_time'] . ' (' . wp_timezone_string() . ')'; }
		if ( array_key_exists( 'customer_reply', $changed ) ) { $notes .= "\nCustomer reply: " . $changed['customer_reply']; }
		$now = current_time( 'mysql', true );
		if ( 1 !== $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>$lead_id, 'actor_user_id'=>get_current_user_id(), 'type'=>'note', 'notes'=>$notes, 'created_at'=>$now ) ) ) { return self::rollback( 'adc_request_failed', 500 ); }
		$activity_id = (int) $wpdb->insert_id;
		if ( false === $wpdb->update( Schema::table( 'leads' ), array( 'updated_at'=>$now ), array( 'id'=>$lead_id ) ) ) { return self::rollback( 'adc_request_failed', 500 ); }
		// Free text and appointment details stay in erasable activity notes, not audit JSON.
		$audit = array( 'request_type'=>$type, 'request_id'=>(int) $row['id'], 'status'=>$changes['status'], 'activity_id'=>$activity_id, 'fields'=>array_values( array_diff( array_keys( $changed ), array( 'updated_at' ) ) ), 'customer_cancel'=>$customer_cancel );
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.request_updated', 'lead', $lead_id, '', array( 'status'=>$row['status'] ), $audit ) ) ) { return self::error( 'adc_request_failed', 500 ); }
		return array( 'updated'=>true, 'status'=>$changes['status'] );
	}

	private static function future_appointment( string $date, string $time ): bool {
		if ( ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date ) || ! preg_match( '/\A[0-9]{2}:[0-9]{2}\z/', $time ) ) { return false; }
		$when = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', "$date $time", wp_timezone() );
		return $when && $when->format( 'Y-m-d H:i' ) === "$date $time" && $when->getTimestamp() > time();
	}

	private static function vehicle_available( int $post_id, int $branch_id ): bool {
		global $wpdb;
		if ( 'car' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) { return false; }
		$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT branch_id,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id=%d FOR UPDATE', $post_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return false; }
		return $vehicle ? ( 'available' === $vehicle['status'] && (int) $vehicle['branch_id'] === $branch_id && VehicleService::branch_exists( $branch_id ) ) : 'available' === get_post_meta( $post_id, '_car_inventory_status', true );
	}

	private static function rollback( string $code, int $status ): \WP_Error { global $wpdb; $wpdb->query( 'ROLLBACK' ); return self::error( $code, $status ); }
	private static function error( string $code, int $status ): \WP_Error {
		$messages = array( 'adc_request_not_found'=>'الطلب غير موجود أو خارج نطاق صلاحيتك.', 'adc_request_stale'=>'تغير الطلب منذ فتحه. أعد تحميله قبل الحفظ.', 'adc_request_transition'=>'لا يمكن الانتقال إلى هذه الحالة أو تعديل موعد طلب مغلق.', 'adc_request_date'=>'اختر موعدًا صحيحًا في المستقبل حسب توقيت الموقع.', 'adc_request_vehicle'=>'السيارة غير متاحة أو لم تعد في فرع الطلب.' );
		return new \WP_Error( $code, __( $messages[$code] ?? 'تعذر تحديث الطلب. تحقق من البيانات والصلاحيات وحاول مجددًا.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
