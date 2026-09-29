<?php
namespace AutoDealership\Leads;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

final class LeadService {
	private const SOURCES = array( 'website', 'whatsapp', 'phone', 'walk_in', 'instagram', 'tiktok', 'snapchat', 'google', 'referral', 'campaign', 'other' );
	private const STAGE_TRANSITIONS = array(
		'new' => array( 'contacted', 'lost' ),
		'contacted' => array( 'qualified', 'lost' ),
		'qualified' => array( 'quotation', 'finance', 'negotiation', 'lost' ),
		'quotation' => array( 'finance', 'negotiation', 'reserved', 'lost' ),
		'finance' => array( 'negotiation', 'reserved', 'lost' ),
		'negotiation' => array( 'quotation', 'finance', 'reserved', 'won', 'lost' ),
		'reserved' => array( 'won', 'lost' ),
		'won' => array(),
		'lost' => array( 'new' ),
	);

	public static function create_public( array $input, ?array $legacy_ref = null, string $initial_activity_notes = '', string $compatibility_type = '' ) {
		global $wpdb;
		$identity = ContactIdentity::normalize( $input );
		if ( is_wp_error( $identity ) ) { return $identity; }
		$input = array_merge( $input, $identity );
		$name = $identity['name']; $mobile = $identity['mobile']; $email = $identity['email'];
		$branch_id = \AutoDealership\Pricing\Money::parse( $input['branch_id'] ?? 0 );
		$source = 'website';
		if ( null === $branch_id || ! in_array( $compatibility_type, array( '', 'message','booking' ), true ) || ( $branch_id && ! \AutoDealership\Inventory\VehicleService::branch_exists( $branch_id ) ) ) {
			return new \WP_Error( 'adc_invalid_lead', __( 'تحقق من الاسم ورقم الجوال والبريد والفرع.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$raw_key = $input['idempotency_key'] ?? '';
		if ( ! is_string( $raw_key ) || ( '' !== $raw_key && ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $raw_key ) ) ) { return new \WP_Error( 'adc_invalid_intake_key', '', array( 'status'=>400 ) ); }
		$request_key = '' === $raw_key ? null : hash_hmac( 'sha256', get_current_user_id() . '|' . strtolower( $raw_key ), wp_salt( 'auth' ) );
		$payload_hash = $request_key ? hash_hmac( 'sha256', wp_json_encode( array( $identity, $branch_id, $legacy_ref, $initial_activity_notes, $compatibility_type, $input['request_kind'] ?? '', $input['car_id'] ?? 0, $input['date'] ?? '', $input['time'] ?? '' ) ), wp_salt( 'auth' ) ) : null;
		if ( $request_key ) {
			$replay = self::intake_replay( $request_key, $payload_hash, $compatibility_type );
			if ( null !== $replay ) { return $replay; }
		}
		$compatibility_table = '';
		if ( $compatibility_type ) {
			$compatibility_table = $wpdb->prefix . 'car_dealer_' . ( 'booking' === $compatibility_type ? 'bookings' : 'messages' );
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $compatibility_table ) );
			if ( 'InnoDB' !== $engine ) { return new \WP_Error( 'adc_intake_storage_unavailable', __( 'تعذر حفظ الطلب مؤقتًا.', 'auto-dealership-core' ), array( 'status'=>503 ) ); }
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( $compatibility_type && get_current_user_id() ) {
			$customer_id = CustomerIdentity::account_customer( $identity );
			if ( is_wp_error( $customer_id ) ) { $wpdb->query( 'ROLLBACK' ); return $customer_id; }
		} else {
			$ok = $wpdb->insert( Schema::table( 'customers' ), array( 'full_name'=>$name, 'mobile'=>$mobile, 'email'=>$email, 'city'=>$identity['city'], 'consent_marketing'=>$identity['consent_marketing'] ? 1 : 0, 'consent_at'=>$identity['consent_marketing'] ? $now : null, 'created_at'=>$now, 'updated_at'=>$now ) );
			if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
			$customer_id = (int) $wpdb->insert_id;
		}
		if ( $compatibility_table ) {
			$row = array( 'user_id'=>get_current_user_id(), 'car_id'=>(int) ( $input['car_id'] ?? 0 ), 'name'=>$name, 'email'=>$email, 'phone'=>$mobile, 'status'=>'booking' === $compatibility_type ? 'pending' : 'new', 'customer_reply'=>'', 'created_at'=>current_time( 'mysql' ) );
			if ( 'booking' === $compatibility_type ) {
				$row['requested_date'] = $input['date']; $row['requested_time'] = $input['time'];
			} else { $row['lead_type'] = $input['request_kind']; $row['message'] = $input['message']; }
			if ( 1 !== $wpdb->insert( $compatibility_table, $row ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status'=>500 ) );
			}
			$legacy_ref = array( 'type'=>$compatibility_type, 'id'=>(int) $wpdb->insert_id );
		}
		$legacy_type = isset( $legacy_ref['type'] ) && in_array( $legacy_ref['type'], array( 'message', 'booking' ), true ) ? $legacy_ref['type'] : null;
		$legacy_id = $legacy_type && ! empty( $legacy_ref['id'] ) ? absint( $legacy_ref['id'] ) : null;
		$ok = $wpdb->insert( Schema::table( 'leads' ), array( 'customer_id' => $customer_id, 'branch_id' => $branch_id, 'owner_user_id' => 0, 'source' => $source, 'stage' => 'new', 'lost_reason' => '', 'legacy_request_type' => $legacy_type, 'legacy_request_id' => $legacy_id, 'created_at' => $now, 'updated_at' => $now, 'public_request_key'=>$request_key, 'public_payload_hash'=>$payload_hash ), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $request_key ) {
				$replay = self::intake_replay( $request_key, $payload_hash, $compatibility_type );
				if ( null !== $replay ) { return $replay; }
			}
			return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$lead_id = (int) $wpdb->insert_id;
		$initial_activity_notes = sanitize_textarea_field( $initial_activity_notes );
		if ( '' !== $initial_activity_notes ) {
			$activity = $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id' => $lead_id, 'actor_user_id' => 0, 'type' => 'note', 'notes' => $initial_activity_notes, 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%s' ) );
			if ( 1 !== $activity ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ سياق الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status' => 500 ) );
			}
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.created', 'lead', $lead_id, 'Public website enquiry', null, array( 'customer_id' => $customer_id, 'source' => $source ) ) ) ) {
			return new \WP_Error( 'adc_lead_failed', __( 'تعذر توثيق الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( $compatibility_type && get_current_user_id() ) { do_action( 'adc_customer_account_linked', get_current_user_id(), $customer_id ); }
		$result = array( 'id' => $lead_id, 'status' => 'new' );
		if ( $compatibility_type ) { $result['legacy_request_id'] = $legacy_id; }
		return $result;
	}

	private static function intake_replay( string $key, string $hash, string $compatibility_type ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,public_payload_hash,legacy_request_id FROM ' . Schema::table( 'leads' ) . ' WHERE public_request_key=%s', $key ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_intake_unavailable', '', array( 'status'=>503 ) ); }
		if ( ! $row ) { return null; }
		if ( ! hash_equals( (string) $row['public_payload_hash'], $hash ) ) { return new \WP_Error( 'adc_intake_key_conflict', __( 'تغيرت بيانات الطلب. أرسل طلبًا جديدًا.', 'auto-dealership-core' ), array( 'status'=>409 ) ); }
		$result = array( 'id'=>(int) $row['id'], 'status'=>'new' );
		if ( $compatibility_type ) { $result['legacy_request_id'] = (int) $row['legacy_request_id']; }
		return $result;
	}

	/** Imports new requests from the current theme hook without changing source records. */
	public static function capture_theme_request( string $type, int $request_id ): void {
		global $wpdb;
		if ( ! in_array( $type, array( 'message', 'booking' ), true ) || $request_id < 1 ) {
			return;
		}
		$table = $wpdb->prefix . 'car_dealer_' . ( 'booking' === $type ? 'bookings' : 'messages' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $request_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$lead_table = Schema::table( 'leads' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $lead_table WHERE legacy_request_type = %s AND legacy_request_id = %d", $type, $request_id ) );
		if ( $existing ) {
			return;
		}
		$branch_id = 0;
		$car_id = absint( $row['car_id'] ?? 0 );
		if ( $car_id ) {
			$branch_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT branch_id FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id = %d LIMIT 1', $car_id ) );
		}
		if ( ! $branch_id ) {
			$branch_id = absint( get_option( 'adc_default_branch_id', 0 ) );
		}
		if ( $branch_id && ! \AutoDealership\Inventory\VehicleService::branch_exists( $branch_id ) ) {
			$branch_id = 0;
		}
		$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		$mobile = (string) ( $row['phone'] ?? '' );
		if ( '' === $name || '' === preg_replace( '/[^0-9+]/', '', $mobile ) ) {
			return;
		}
		$context = array( 'Legacy request type: ' . sanitize_key( (string) ( $row['lead_type'] ?? $type ) ), 'Legacy status: ' . sanitize_key( (string) ( $row['status'] ?? '' ) ) );
		if ( ! empty( $row['subject'] ) ) { $context[] = 'Subject: ' . sanitize_text_field( (string) $row['subject'] ); }
		if ( ! empty( $row['requested_date'] ) ) { $context[] = 'Requested date: ' . sanitize_text_field( (string) $row['requested_date'] ); }
		if ( ! empty( $row['requested_time'] ) ) { $context[] = 'Requested time: ' . sanitize_text_field( (string) $row['requested_time'] ); }
		$message = sanitize_textarea_field( (string) ( $row['message'] ?? '' ) );
		$reply = sanitize_textarea_field( (string) ( $row['customer_reply'] ?? '' ) );
		if ( $message ) { $context[] = 'Message: ' . $message; }
		if ( $reply ) { $context[] = 'Previous reply: ' . $reply; }
		self::create_public( array( 'name' => $name, 'mobile' => $mobile, 'email' => (string) ( $row['email'] ?? '' ), 'branch_id' => $branch_id ), array( 'type' => $type, 'id' => $request_id ), implode( "\n", $context ) );
	}

	/** Lists only leads within the current user's assigned scope. */
	public static function list_for_current_user( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$page = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$lead_table = Schema::table( 'leads' );
		$customer_table = Schema::table( 'customers' );
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		$where = ' WHERE ' . $scope;
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( ! current_user_can( 'adc_view_branch_leads' ) ) {
				$where .= ' AND l.owner_user_id = %d';
				$args[] = get_current_user_id();
			}
		}
		$args[] = $per_page;
		$args[] = ( $page - 1 ) * $per_page;
		$sql = 'SELECT l.id,l.customer_id,l.branch_id,l.owner_user_id,l.source,l.stage,l.next_action_at,l.created_at,c.full_name,c.mobile,c.email,c.city FROM ' . $lead_table . ' l INNER JOIN ' . $customer_table . ' c ON c.id=l.customer_id' . $where . ' ORDER BY l.created_at DESC LIMIT %d OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	/** Activity content uses the same read scope as the lead list. */
	public static function activity_history( int $lead_id, int $page = 1, int $per_page = 50 ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية عرض الفرص.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) {
			$scope .= ' AND l.owner_user_id=%d'; $args[] = get_current_user_id();
		}
		$args[] = $lead_id;
		$from = Schema::table( 'leads' ) . " l WHERE $scope AND l.id=%d";
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT l.id FROM ' . $from, $args ) ) ) {
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) );
		}
		$per_page = min( 100, max( 1, $per_page ) );
		$args[] = $per_page; $args[] = ( max( 1, $page ) - 1 ) * $per_page;
		// Reapply scope to the content query; reassignment between reads cannot expose notes.
		$sql = 'SELECT a.id,a.lead_id,a.actor_user_id,a.type,a.notes,a.next_action_at,a.created_at FROM ' . Schema::table( 'activities' ) . ' a INNER JOIN ' . Schema::table( 'leads' ) . " l ON l.id=a.lead_id WHERE $scope AND l.id=%d ORDER BY a.id DESC LIMIT %d OFFSET %d";
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return $wpdb->last_error ? new \WP_Error( 'adc_activity_unavailable', __( 'تعذر تحميل سجل المتابعة.', 'auto-dealership-core' ), array( 'status'=>503 ) ) : ( $items ?: array() );
	}

	public static function update_stage( int $lead_id, string $to, string $reason = '' ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$to = sanitize_key( $to );
		$reason = sanitize_textarea_field( $reason );
		if ( ! current_user_can( 'adc_manage_own_leads' ) && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية تحديث الفرصة.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! array_key_exists( $to, self::STAGE_TRANSITIONS ) || ( 'lost' === $to && '' === $reason ) ) {
			return new \WP_Error( 'adc_invalid_lead_stage', __( 'المرحلة غير صالحة أو سبب الخسارة مطلوب.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$table = Schema::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage FROM $table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || ! BranchScope::can_manage_lead( $lead ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status' => 404 ) );
		}
		$from = $lead['stage'];
		if ( ! in_array( $to, self::STAGE_TRANSITIONS[ $from ] ?? array(), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_transition', __( 'الانتقال بين مراحل الفرصة غير مسموح.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->update( $table, array( 'stage' => $to, 'lost_reason' => 'lost' === $to ? $reason : '', 'updated_at' => $now ), array( 'id' => $lead_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$activity = $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id' => $lead_id, 'actor_user_id' => $user_id, 'type' => 'stage_change', 'notes' => $reason, 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%s' ) );
		if ( 1 !== $updated || 1 !== $activity ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_update_failed', __( 'تعذر حفظ تحديث الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.stage_changed', 'lead', $lead_id, $reason, array( 'stage' => $from ), array( 'stage' => $to ) ) ) ) {
			return new \WP_Error( 'adc_lead_update_failed', __( 'تعذر توثيق تحديث الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $lead_id, 'stage' => $to );
	}

	public static function assign( int $lead_id, int $staff_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إسناد الفرص.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$staff = get_userdata( $staff_id );
		$staff_branch = BranchScope::assigned_branch( $staff_id );
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$lead_table = Schema::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id FROM $lead_table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		$assign_branch = $lead && 0 === (int) $lead['branch_id'] && current_user_can( 'manage_options' ) ? $staff_branch : (int) ( $lead['branch_id'] ?? 0 );
		if ( ! $lead || ! $staff || ! in_array( 'dealership_sales', (array) $staff->roles, true ) || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $lead['branch_id'] ) || ! \AutoDealership\Inventory\VehicleService::branch_exists( $assign_branch ) || $staff_branch !== $assign_branch ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_invalid_assignment', __( 'الموظف أو الفرع غير صالح للإسناد.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$updated = $wpdb->update( $lead_table, array( 'owner_user_id' => $staff_id, 'branch_id' => $assign_branch, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $lead_id ), array( '%d', '%d', '%s' ), array( '%d' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_assignment_failed', __( 'تعذر إسناد الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.assigned', 'lead', $lead_id, '', array( 'owner_user_id' => (int) $lead['owner_user_id'], 'branch_id' => (int) $lead['branch_id'] ), array( 'owner_user_id' => $staff_id, 'branch_id' => $assign_branch ) ) ) ) {
			return new \WP_Error( 'adc_assignment_failed', __( 'تعذر توثيق إسناد الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $lead_id, 'owner_user_id' => $staff_id, 'branch_id' => $assign_branch );
	}

	public static function add_activity( int $lead_id, string $type, string $notes, string $next_action_at = '' ) {
		global $wpdb;
		$types = array( 'call', 'whatsapp', 'email', 'meeting', 'test_drive', 'quotation', 'follow_up', 'finance_request', 'reservation', 'sale', 'note' );
		if ( ! in_array( $type, $types, true ) || '' === trim( $notes ) ) {
			return new \WP_Error( 'adc_invalid_activity', __( 'نوع النشاط والملاحظة مطلوبان.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$due = null;
		if ( '' !== $next_action_at ) {
			$timestamp = strtotime( $next_action_at );
			if ( false === $timestamp || $timestamp < time() - DAY_IN_SECONDS ) {
				return new \WP_Error( 'adc_invalid_follow_up', __( 'موعد المتابعة غير صالح.', 'auto-dealership-core' ), array( 'status' => 400 ) );
			}
			$due = gmdate( 'Y-m-d H:i:s', $timestamp );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$lead_table = Schema::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id FROM $lead_table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || ! BranchScope::can_manage_lead( $lead ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status' => 404 ) );
		}
		$ok = $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id' => $lead_id, 'actor_user_id' => get_current_user_id(), 'type' => $type, 'notes' => sanitize_textarea_field( $notes ), 'next_action_at' => $due, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s', '%s', '%s' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_activity_failed', __( 'تعذر حفظ النشاط.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		$changes = array( 'updated_at'=>current_time( 'mysql', true ) );
		if ( null !== $due ) { $changes['next_action_at'] = $due; }
		if ( false === $wpdb->update( $lead_table, $changes, array( 'id'=>$lead_id ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_activity_failed', __( 'تعذر حفظ موعد المتابعة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.activity_added', 'lead', $lead_id, '', null, array( 'activity_id' => $id, 'type' => $type ) ) ) ) {
			return new \WP_Error( 'adc_activity_failed', __( 'تعذر توثيق النشاط.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $id, 'lead_id' => $lead_id, 'type' => $type );
	}
}
