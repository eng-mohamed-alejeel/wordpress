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
		'new' => array( 'lost' ),
		'assigned' => array( 'contacted', 'lost' ),
		'contacted' => array( 'qualified', 'lost' ),
		'qualified' => array( 'quotation', 'finance', 'negotiation', 'lost' ),
		'quotation' => array( 'finance', 'negotiation', 'reserved', 'lost' ),
		'finance' => array( 'negotiation', 'reserved', 'lost' ),
		'negotiation' => array( 'quotation', 'finance', 'reserved', 'won', 'lost' ),
		'reserved' => array( 'won', 'lost' ),
		'won' => array(),
		'lost' => array( 'new' ),
	);

	public static function allowed_stage_transitions( string $from ): array {
		return self::STAGE_TRANSITIONS[ sanitize_key( $from ) ] ?? array();
	}

	public static function stage_keys(): array {
		return self::STAGE_TRANSITIONS;
	}

	public static function create_marketing( array $input, array $campaign ) {
		if ( ! current_user_can( 'adc_create_marketing_leads' ) ) {
			return new \WP_Error( 'adc_marketing_lead_forbidden', __( 'لا تملك صلاحية إنشاء فرص تسويقية.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		if ( empty( $input['consent_marketing'] ) ) {
			return new \WP_Error( 'adc_consent_required', __( 'موافقة التواصل مطلوبة.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		$campaign = array(
			'source'=>sanitize_key( (string) ( $campaign['source'] ?? '' ) ),
			'campaign'=>sanitize_text_field( (string) ( $campaign['campaign'] ?? '' ) ),
			'platform'=>sanitize_text_field( (string) ( $campaign['platform'] ?? '' ) ),
			'ad_url'=>esc_url_raw( (string) ( $campaign['ad_url'] ?? '' ) ),
			'staff_id'=>absint( $campaign['staff_id'] ?? 0 ),
		);
		$notes = trim( implode( "\n", array_filter( array(
			'Marketing campaign: ' . $campaign['campaign'],
			'Platform: ' . $campaign['platform'],
			'Source: ' . $campaign['source'],
			$campaign['ad_url'] ? 'Ad URL: ' . $campaign['ad_url'] : '',
			sanitize_textarea_field( (string) ( $campaign['notes'] ?? '' ) ),
		) ) ) );
		return self::create_public( $input, null, $notes, '', $campaign );
	}

	private static function record_stage_transition( int $lead_id, int $branch_id, int $owner_user_id, int $actor_user_id, string $from, string $to, string $changed_at ): bool {
		global $wpdb;
		$table = Schema::table( 'lead_stage_history' );
		$previous_at = $wpdb->get_var( $wpdb->prepare( "SELECT changed_at FROM $table WHERE lead_id=%d ORDER BY changed_at DESC,id DESC LIMIT 1", $lead_id ) );
		if ( $wpdb->last_error ) { return false; }
		$duration = null;
		if ( null !== $previous_at ) {
			$previous_timestamp = strtotime( $previous_at . ' UTC' );
			$changed_timestamp = strtotime( $changed_at . ' UTC' );
			if ( false === $previous_timestamp || false === $changed_timestamp ) { return false; }
			$duration = max( 0, $changed_timestamp - $previous_timestamp );
		}
		return 1 === $wpdb->insert(
			$table,
			array( 'lead_id'=>$lead_id, 'branch_id'=>$branch_id, 'owner_user_id'=>$owner_user_id, 'actor_user_id'=>$actor_user_id, 'from_stage'=>$from, 'to_stage'=>$to, 'duration_seconds'=>$duration, 'changed_at'=>$changed_at ),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/** Atomically claims an unassigned lead for the current sales employee. */
	public static function claim( int $lead_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_view_marketing_leads' ) ) { return new \WP_Error( 'adc_claim_forbidden', __( 'لا تملك صلاحية استلام الفرص.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$user_id = get_current_user_id();
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'dealership_sales', (array) $user->roles, true ) ) { return new \WP_Error( 'adc_claim_forbidden', __( 'يجب أن يكون المستخدم موظف مبيعات نشطًا لاستلام الفرص.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$table = Schema::table( 'leads' );
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage FROM $table WHERE id=%d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || 'new' !== $lead['stage'] || 0 !== (int) $lead['owner_user_id'] || ! BranchScope::allows( (int) $lead['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_claim_unavailable', __( 'الفرصة لم تعد متاحة ضمن فرعك.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE $table SET owner_user_id=%d,stage='assigned',claimed_at=%s,updated_at=%s WHERE id=%d AND branch_id=%d AND owner_user_id=0 AND stage='new'", $user_id, $now, $now, $lead_id, (int) $lead['branch_id'] ) );
		$history = 1 === $updated && self::record_stage_transition( $lead_id, (int) $lead['branch_id'], $user_id, $user_id, 'new', 'assigned', $now );
		$activity = 1 === $updated ? $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>$lead_id, 'actor_user_id'=>$user_id, 'type'=>'assignment', 'notes'=>'Claimed from shared branch queue', 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) ) : false;
		if ( 1 !== $updated || ! $history || 1 !== $activity ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_claim_failed', __( 'تعذر حفظ استلام الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.claimed', 'lead', $lead_id, 'Sales employee claimed lead', array( 'stage'=>'new','owner_user_id'=>0 ), array( 'stage'=>'assigned','owner_user_id'=>$user_id ) ) ) ) { return new \WP_Error( 'adc_claim_audit_failed', __( 'تعذر توثيق استلام الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		return array( 'id'=>$lead_id, 'stage'=>'assigned', 'owner_user_id'=>$user_id, 'claimed_at'=>$now );
	}

	/** Releases claims with no recorded first contact after the configured window. */
	public static function release_stale_claims(): int {
		global $wpdb;
		$minutes = max( 5, min( 1440, absint( get_option( 'adc_lead_claim_timeout_minutes', 30 ) ) ) );
		$table = Schema::table( 'leads' ); $cutoff = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM $table WHERE stage='assigned' AND claimed_at IS NOT NULL AND claimed_at<=%s AND first_contact_at IS NULL ORDER BY claimed_at ASC LIMIT 100", $cutoff ), ARRAY_A );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: stale lead claim scan failed: ' . $wpdb->last_error );
			return 0;
		}
		$rows = $rows ?: array();
		$released = 0;
		foreach ( $rows as $row ) {
			if ( ! Transaction::begin() ) {
				error_log( 'Auto Dealership Core: unable to start stale lead claim transaction.' );
				break;
			}
			$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage,claimed_at,first_contact_at FROM $table WHERE id=%d FOR UPDATE", (int) $row['id'] ), ARRAY_A );
			if ( ! $lead || 'assigned' !== $lead['stage'] || empty( $lead['claimed_at'] ) || $lead['claimed_at'] > $cutoff || null !== $lead['first_contact_at'] ) {
				$wpdb->query( 'ROLLBACK' );
				continue;
			}
			$now = current_time( 'mysql', true );
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE $table SET owner_user_id=0,stage='new',claimed_at=NULL,updated_at=%s WHERE id=%d AND owner_user_id=%d AND stage='assigned' AND claimed_at=%s AND first_contact_at IS NULL", $now, (int) $lead['id'], (int) $lead['owner_user_id'], $lead['claimed_at'] ) );
			$history = 1 === $updated && self::record_stage_transition( (int) $lead['id'], (int) $lead['branch_id'], (int) $lead['owner_user_id'], 0, 'assigned', 'new', $now );
			$activity = 1 === $updated ? $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>(int) $lead['id'], 'actor_user_id'=>0, 'type'=>'assignment', 'notes'=>'Claim expired before first contact; returned to shared queue', 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) ) : false;
			$notifications = 1 === $updated && 1 === $activity ? \AutoDealership\Operations\FollowUpNotifications::queue_claim_expired( (int) $lead['id'], (int) $lead['branch_id'], (int) $lead['owner_user_id'], $now ) : false;
			if ( 1 !== $updated || ! $history || 1 !== $activity || ! $notifications || ! Transaction::commit( static fn() => AuditLog::record( 'lead.claim_expired', 'lead', (int) $lead['id'], 'No first contact before claim deadline', array( 'owner_user_id'=>(int) $lead['owner_user_id'], 'stage'=>'assigned' ), array( 'owner_user_id'=>0, 'stage'=>'new' ) ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				error_log( 'Auto Dealership Core: stale lead claim release failed.' );
				continue;
			}
			$released++;
			do_action( 'adc_lead_claim_expired', (int) $lead['id'], (int) $lead['owner_user_id'], (int) $lead['branch_id'] );
		}
		return $released;
	}

	private static function round_robin_assignment( int $branch_id, string $now ) {
		global $wpdb;
		if ( $branch_id < 1 || 'round_robin' !== get_option( 'adc_lead_assignment_mode', 'manual' ) ) {
			return array( 'owner_user_id'=>0, 'stage'=>'new', 'claimed_at'=>null );
		}

		$branch = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE id=%d AND active=1 FOR UPDATE', $branch_id ), ARRAY_A );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: round-robin branch lock failed: ' . $wpdb->last_error );
			return new \WP_Error( 'adc_assignment_unavailable', __( 'تعذر التحقق من موظفي الفرع لتوزيع الطلب.', 'auto-dealership-core' ), array( 'status'=>503 ) );
		}
		if ( ! $branch ) {
			return new \WP_Error( 'adc_assignment_branch_unavailable', __( 'الفرع غير نشط ولا يمكن توزيع الطلب عليه.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}

		$users = get_users( array( 'role'=>'dealership_sales', 'fields'=>'ID', 'number'=>-1, 'orderby'=>'ID', 'order'=>'ASC' ) );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: round-robin staff lookup failed: ' . $wpdb->last_error );
			return new \WP_Error( 'adc_assignment_unavailable', __( 'تعذر التحقق من موظفي الفرع لتوزيع الطلب.', 'auto-dealership-core' ), array( 'status'=>503 ) );
		}
		$staff = array();
		foreach ( $users as $user_id ) {
			$user_id = absint( $user_id );
			if ( $user_id && user_can( $user_id, 'adc_manage_own_leads' ) && ! user_can( $user_id, 'manage_options' ) && BranchScope::allows( $branch_id, $user_id ) ) {
				$staff[] = $user_id;
			}
		}
		if ( ! $staff ) {
			return array( 'owner_user_id'=>0, 'stage'=>'new', 'claimed_at'=>null );
		}

		$placeholders = implode( ',', array_fill( 0, count( $staff ), '%d' ) );
		$sql = 'SELECT owner_user_id FROM ' . Schema::table( 'leads' ) . " WHERE branch_id=%d AND owner_user_id IN ($placeholders) AND claimed_at IS NOT NULL ORDER BY claimed_at DESC,id DESC LIMIT 1 FOR UPDATE";
		$last_owner = $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $branch_id ), $staff ) ) );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: round-robin rotation lookup failed: ' . $wpdb->last_error );
			return new \WP_Error( 'adc_assignment_unavailable', __( 'تعذر التحقق من سجل توزيع الطلبات.', 'auto-dealership-core' ), array( 'status'=>503 ) );
		}
		$last_index = false === $last_owner ? false : array_search( (int) $last_owner, $staff, true );
		$next_index = false === $last_index ? 0 : ( ( $last_index + 1 ) % count( $staff ) );
		return array( 'owner_user_id'=>$staff[$next_index], 'stage'=>'assigned', 'claimed_at'=>$now );
	}

	public static function create_public( array $input, ?array $legacy_ref = null, string $initial_activity_notes = '', string $compatibility_type = '', array $marketing = array() ) {
		global $wpdb;
		if ( $marketing && ! current_user_can( 'adc_create_marketing_leads' ) ) {
			return new \WP_Error( 'adc_marketing_lead_forbidden', __( 'لا تملك صلاحية إنشاء فرص تسويقية.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		$identity = ContactIdentity::normalize( $input );
		if ( is_wp_error( $identity ) ) { return $identity; }
		$input = array_merge( $input, $identity );
		$name = $identity['name']; $mobile = $identity['mobile']; $email = $identity['email'];
		$branch_id = \AutoDealership\Pricing\Money::parse( $input['branch_id'] ?? 0 );
		$source = 'website';
		$campaign = '';
		$platform = '';
		$ad_url = '';
		$created_by = 0;
		$referred_at = null;
		$marketing_staff_id = 0;
		if ( $marketing ) {
			$source = sanitize_key( (string) ( $marketing['source'] ?? '' ) );
			$campaign = sanitize_text_field( (string) ( $marketing['campaign'] ?? '' ) );
			$platform = sanitize_text_field( (string) ( $marketing['platform'] ?? '' ) );
			$ad_url = esc_url_raw( (string) ( $marketing['ad_url'] ?? '' ) );
			$marketing_staff_id = absint( $marketing['staff_id'] ?? 0 );
			$campaign_length = function_exists( 'mb_strlen' ) ? mb_strlen( $campaign, 'UTF-8' ) : strlen( $campaign );
			$platform_length = function_exists( 'mb_strlen' ) ? mb_strlen( $platform, 'UTF-8' ) : strlen( $platform );
			if ( ! in_array( $source, array_merge( self::SOURCES, array( 'marketing' ) ), true ) || '' === $campaign || '' === $platform || $campaign_length > 190 || $platform_length > 80 || empty( $identity['consent_marketing'] ) ) {
				return new \WP_Error( 'adc_invalid_marketing_lead', __( 'تحقق من المصدر والحملة والمنصة وموافقة التواصل.', 'auto-dealership-core' ), array( 'status'=>400 ) );
			}
			$created_by = get_current_user_id();
			$referred_at = current_time( 'mysql', true );
		}
		if ( null === $branch_id || ! in_array( $compatibility_type, array( '', 'message','booking' ), true ) || ( $branch_id && ! \AutoDealership\Inventory\VehicleService::branch_exists( $branch_id ) ) ) {
			return new \WP_Error( 'adc_invalid_lead', __( 'تحقق من الاسم ورقم الجوال والبريد والفرع.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( $marketing_staff_id ) {
			$staff = get_userdata( $marketing_staff_id );
			$staff_branch = BranchScope::assigned_branch( $marketing_staff_id );
			if ( ! $staff || ! in_array( 'dealership_sales', (array) $staff->roles, true ) || ! user_can( $marketing_staff_id, 'adc_manage_own_leads' ) || ! $branch_id || $staff_branch !== (int) $branch_id || ( ! current_user_can( 'manage_options' ) && ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $branch_id ) ) ) {
				return new \WP_Error( 'adc_invalid_marketing_assignment', __( 'موظف المبيعات المحدد غير متاح لهذا الفرع.', 'auto-dealership-core' ), array( 'status'=>400 ) );
			}
		}
		$now = current_time( 'mysql', true );
		$raw_key = $input['idempotency_key'] ?? '';
		if ( ! is_string( $raw_key ) || ( '' !== $raw_key && ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $raw_key ) ) ) { return new \WP_Error( 'adc_invalid_intake_key', '', array( 'status'=>400 ) ); }
		$request_key = '' === $raw_key ? null : hash_hmac( 'sha256', get_current_user_id() . '|' . strtolower( $raw_key ), wp_salt( 'auth' ) );
		$payload_hash = $request_key ? hash_hmac( 'sha256', wp_json_encode( array( $identity, $branch_id, $legacy_ref, $initial_activity_notes, $compatibility_type, $input['request_kind'] ?? '', $input['car_id'] ?? 0, $input['date'] ?? '', $input['time'] ?? '', $marketing ) ), wp_salt( 'auth' ) ) : null;
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
		$assignment = $marketing_staff_id
			? array( 'owner_user_id'=>$marketing_staff_id, 'stage'=>'assigned', 'claimed_at'=>$now )
			: self::round_robin_assignment( (int) $branch_id, $now );
		if ( is_wp_error( $assignment ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $assignment;
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
		$lead_data = array( 'customer_id'=>$customer_id, 'branch_id'=>$branch_id, 'owner_user_id'=>$assignment['owner_user_id'], 'source'=>$source, 'stage'=>$assignment['stage'], 'lost_reason'=>'', 'legacy_request_type'=>$legacy_type, 'legacy_request_id'=>$legacy_id, 'claimed_at'=>$assignment['claimed_at'], 'created_at'=>$now, 'updated_at'=>$now, 'public_request_key'=>$request_key, 'public_payload_hash'=>$payload_hash );
		$lead_formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );
		if ( $marketing ) {
			$lead_data = array_merge( $lead_data, array( 'campaign'=>$campaign, 'platform'=>$platform, 'ad_url'=>$ad_url, 'consent_marketing'=>1, 'created_by'=>$created_by, 'referred_at'=>$referred_at ) );
			$lead_formats = array_merge( $lead_formats, array( '%s', '%s', '%s', '%d', '%d', '%s' ) );
		}
		$ok = $wpdb->insert( Schema::table( 'leads' ), $lead_data, $lead_formats );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $request_key ) {
				$replay = self::intake_replay( $request_key, $payload_hash, $compatibility_type );
				if ( null !== $replay ) { return $replay; }
			}
			return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$lead_id = (int) $wpdb->insert_id;
		$history = self::record_stage_transition( $lead_id, (int) $branch_id, 0, 0, '', 'new', $now );
		if ( $history && 'assigned' === $assignment['stage'] ) {
			$history = self::record_stage_transition( $lead_id, (int) $branch_id, (int) $assignment['owner_user_id'], 0, 'new', 'assigned', $now );
		}
		if ( ! $history ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ سجل مراحل الطلب.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		if ( $assignment['owner_user_id'] && 1 !== $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>$lead_id, 'actor_user_id'=>$marketing_staff_id ? get_current_user_id() : 0, 'type'=>'assignment', 'notes'=>$marketing_staff_id ? 'Marketing intake assignment' : 'Automatically assigned by branch round robin', 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ إسناد الطلب.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		$initial_activity_notes = sanitize_textarea_field( $initial_activity_notes );
		if ( '' !== $initial_activity_notes ) {
			$activity = $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id' => $lead_id, 'actor_user_id' => 0, 'type' => 'note', 'notes' => $initial_activity_notes, 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%s' ) );
			if ( 1 !== $activity ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ سياق الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status' => 500 ) );
			}
		}
		if ( ! Transaction::commit( static function () use ( $lead_id, $customer_id, $source, $assignment, $branch_id, $marketing, $campaign, $platform, $created_by, $marketing_staff_id ): bool {
			$details = array( 'customer_id'=>$customer_id, 'source'=>$source, 'stage'=>$assignment['stage'], 'owner_user_id'=>$assignment['owner_user_id'], 'branch_id'=>(int) $branch_id );
			if ( $marketing ) { $details['campaign'] = $campaign; $details['platform'] = $platform; $details['created_by'] = $created_by; }
			$created = AuditLog::record( 'lead.created', 'lead', $lead_id, $marketing ? 'Marketing CRM intake' : 'Public website enquiry', null, $details );
			$assigned = ! $assignment['owner_user_id'] || AuditLog::record( $marketing_staff_id ? 'lead.assigned' : 'lead.auto_assigned', 'lead', $lead_id, $marketing_staff_id ? 'Marketing intake assignment' : 'Branch round-robin assignment', array( 'owner_user_id'=>0, 'stage'=>'new' ), array( 'owner_user_id'=>$assignment['owner_user_id'], 'stage'=>'assigned' ) );
			return $created && $assigned;
		} ) ) {
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
	public static function list_for_current_user( int $page = 1, int $per_page = 20, array $filters = array() ): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$page = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$lead_table = Schema::table( 'leads' );
		$customer_table = Schema::table( 'customers' );
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $scope = '1=1'; $args = array(); }
		$where = ' WHERE ' . $scope;
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (c.full_name LIKE %s OR c.mobile LIKE %s OR c.email LIKE %s OR CAST(l.id AS CHAR) LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$stage = sanitize_key( (string) ( $filters['stage'] ?? '' ) );
		if ( '' !== $stage && array_key_exists( $stage, self::STAGE_TRANSITIONS ) ) {
			$where .= ' AND l.stage=%s';
			$args[] = $stage;
		}
		$priority = sanitize_key( (string) ( $filters['priority'] ?? '' ) );
		if ( '' !== $priority && in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) { $where .= ' AND l.priority=%s'; $args[] = $priority; }
		if ( array_key_exists( 'branch_id', $filters ) && absint( $filters['branch_id'] ) > 0 ) {
			$branch_id = absint( $filters['branch_id'] );
			if ( ! BranchScope::allows( $branch_id ) ) { $where .= ' AND 1=0'; }
			else { $where .= ' AND l.branch_id=%d'; $args[] = $branch_id; }
		}
		$source = sanitize_key( (string) ( $filters['source'] ?? '' ) );
		if ( '' !== $source && in_array( $source, self::SOURCES, true ) ) { $where .= ' AND l.source=%s'; $args[] = $source; }
		$campaign = trim( (string) ( $filters['campaign'] ?? '' ) ); if ( '' !== $campaign ) { $where .= ' AND l.campaign LIKE %s'; $args[] = '%' . $wpdb->esc_like( $campaign ) . '%'; }
		$platform_raw = (string) ( $filters['platform'] ?? '' );
		$platform = function_exists( 'sanitize_text_field' ) ? \sanitize_text_field( $platform_raw ) : preg_replace( '/[^\p{L}\p{N} _.-]/u', '', $platform_raw );
		if ( '' !== $platform ) { $where .= ' AND l.platform=%s'; $args[] = $platform; }
		if ( ! empty( $filters['created_by'] ) ) { $where .= ' AND l.created_by=%d'; $args[] = absint( $filters['created_by'] ); }
		if ( array_key_exists( 'owner', $filters ) ) { $where .= ' AND l.owner_user_id=%d'; $args[] = absint( $filters['owner'] ); }
		if ( ! empty( $filters['overdue'] ) ) {
			$where .= " AND l.next_action_at IS NOT NULL AND l.next_action_at<=%s AND l.stage NOT IN ('won','lost')";
			$args[] = current_time( 'mysql', true );
		}
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $where .= ' AND l.created_by=%d'; $args[] = get_current_user_id(); }
		elseif ( ! current_user_can( 'manage_options' ) ) {
			if ( ! current_user_can( 'adc_view_branch_leads' ) ) {
				$where .= " AND (l.owner_user_id=%d OR (l.owner_user_id=0 AND l.stage='new'))";
				$args[] = get_current_user_id();
			}
		}
		$args[] = $per_page;
		$args[] = ( $page - 1 ) * $per_page;
		$sql = 'SELECT l.id,l.customer_id,l.branch_id,l.owner_user_id,l.source,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.campaign END campaign,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.platform END platform,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN 0 ELSE l.created_by END created_by,l.referred_at,l.stage,l.next_action_at,l.claimed_at,l.first_contact_at,l.priority,l.created_at,c.full_name,c.mobile,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.email END email,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.city END city FROM ' . $lead_table . ' l INNER JOIN ' . $customer_table . ' c ON c.id=l.customer_id' . $where . ' ORDER BY FIELD(l.priority,"urgent","high","normal","low"),l.created_at ASC LIMIT %d OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public static function count_for_current_user( array $filters = array() ): int {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) { return 0; }
		$lead_table = Schema::table( 'leads' ); $customer_table = Schema::table( 'customers' );
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' ); if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $scope = '1=1'; $args = array(); } $where = ' WHERE ' . $scope;
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where .= ' AND (c.full_name LIKE %s OR c.mobile LIKE %s OR c.email LIKE %s OR CAST(l.id AS CHAR) LIKE %s)'; array_push( $args, $like, $like, $like, $like ); }
		$stage = sanitize_key( (string) ( $filters['stage'] ?? '' ) );
		if ( '' !== $stage && array_key_exists( $stage, self::STAGE_TRANSITIONS ) ) { $where .= ' AND l.stage=%s'; $args[] = $stage; }
		$priority = sanitize_key( (string) ( $filters['priority'] ?? '' ) );
		if ( '' !== $priority && in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) { $where .= ' AND l.priority=%s'; $args[] = $priority; }
		if ( array_key_exists( 'branch_id', $filters ) && absint( $filters['branch_id'] ) > 0 ) {
			$branch_id = absint( $filters['branch_id'] );
			if ( ! BranchScope::allows( $branch_id ) ) { $where .= ' AND 1=0'; }
			else { $where .= ' AND l.branch_id=%d'; $args[] = $branch_id; }
		}
		$source = sanitize_key( (string) ( $filters['source'] ?? '' ) );
		if ( '' !== $source && in_array( $source, self::SOURCES, true ) ) { $where .= ' AND l.source=%s'; $args[] = $source; }
		$campaign = trim( (string) ( $filters['campaign'] ?? '' ) ); if ( '' !== $campaign ) { $where .= ' AND l.campaign LIKE %s'; $args[] = '%' . $wpdb->esc_like( $campaign ) . '%'; }
		$platform = sanitize_text_field( (string) ( $filters['platform'] ?? '' ) ); if ( '' !== $platform ) { $where .= ' AND l.platform=%s'; $args[] = $platform; }
		if ( ! empty( $filters['created_by'] ) ) { $where .= ' AND l.created_by=%d'; $args[] = absint( $filters['created_by'] ); }
		if ( ! empty( $filters['overdue'] ) ) { $where .= " AND l.next_action_at IS NOT NULL AND l.next_action_at<=%s AND l.stage NOT IN ('won','lost')"; $args[] = current_time( 'mysql', true ); }
		if ( array_key_exists( 'owner', $filters ) ) { $where .= ' AND l.owner_user_id=%d'; $args[] = absint( $filters['owner'] ); }
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $where .= ' AND l.created_by=%d'; $args[] = get_current_user_id(); }
		elseif ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) { $where .= " AND (l.owner_user_id=%d OR (l.owner_user_id=0 AND l.stage='new'))"; $args[] = get_current_user_id(); }
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $lead_table . ' l INNER JOIN ' . $customer_table . ' c ON c.id=l.customer_id' . $where, $args ) );
	}

	public static function pipeline_summary(): array {
		$base = array();
		$total = self::count_for_current_user();
		$base['total'] = $total;
		$base['new'] = self::count_for_current_user( array( 'stage' => 'new' ) );
		$base['overdue'] = self::count_for_current_user( array( 'overdue' => true ) );
		$base['unassigned'] = self::count_for_current_user( array( 'owner' => 0 ) );
		return $base;
	}

	public static function find_for_current_user( int $lead_id ) {
		global $wpdb;
		if ( $lead_id < 1 || ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) ) { return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) ); }
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' ); if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $scope = '1=1'; $args = array(); }
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $scope .= ' AND l.created_by=%d'; $args[] = get_current_user_id(); }
		elseif ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) { $scope .= ' AND l.owner_user_id=%d'; $args[] = get_current_user_id(); }
		$args[] = $lead_id;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT l.id,l.customer_id,l.branch_id,l.owner_user_id,l.source,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.campaign END campaign,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.platform END platform,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN 0 ELSE l.created_by END created_by,l.referred_at,l.stage,l.next_action_at,l.claimed_at,l.first_contact_at,l.priority,l.created_at,l.updated_at,c.full_name,c.mobile,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.email END email,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.city END city FROM ' . Schema::table( 'leads' ) . ' l INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=l.customer_id WHERE ' . $scope . ' AND l.id=%d', $args ), ARRAY_A );
		return $row ?: new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) );
	}

	/** Activity content uses the same read scope as the lead list. */
	public static function activity_history( int $lead_id, int $page = 1, int $per_page = 50 ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية عرض الفرص.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) { $scope = '1=1'; $args = array(); }
		if ( current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			$scope .= ' AND l.created_by=%d'; $args[] = get_current_user_id();
		} elseif ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) {
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
		$sql = 'SELECT a.id,a.lead_id,a.actor_user_id,a.type,a.notes,a.result,a.occurred_at,a.next_action_at,a.created_at FROM ' . Schema::table( 'activities' ) . ' a INNER JOIN ' . Schema::table( 'leads' ) . " l ON l.id=a.lead_id WHERE $scope AND l.id=%d ORDER BY a.id DESC LIMIT %d OFFSET %d";
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return $wpdb->last_error ? new \WP_Error( 'adc_activity_unavailable', __( 'تعذر تحميل سجل المتابعة.', 'auto-dealership-core' ), array( 'status'=>503 ) ) : ( $items ?: array() );
	}

	/** Records the first real customer contact and advances the lead atomically. */
	public static function record_first_contact( int $lead_id, string $type, string $contacted_at, string $result, string $notes, string $next_action_at = '' ) {
		global $wpdb;
		$type = sanitize_key( $type );
		$result = sanitize_textarea_field( $result );
		$notes = sanitize_textarea_field( $notes );
		if ( ! in_array( $type, array( 'call', 'whatsapp', 'email', 'meeting', 'other' ), true ) || '' === trim( $result ) || '' === trim( $notes ) ) {
			return new \WP_Error( 'adc_invalid_first_contact', __( 'نوع التواصل والنتيجة والملاحظات مطلوبة.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$local_contact = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $contacted_at, wp_timezone() );
		$parse_errors = \DateTimeImmutable::getLastErrors();
		if ( ! $local_contact || ( is_array( $parse_errors ) && ( $parse_errors['warning_count'] || $parse_errors['error_count'] ) ) || $local_contact->format( 'Y-m-d\TH:i' ) !== $contacted_at || $local_contact->getTimestamp() > time() ) {
			return new \WP_Error( 'adc_invalid_contact_time', __( 'تاريخ ووقت التواصل غير صالحين.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$due = null;
		if ( '' !== $next_action_at ) {
			$due_date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $next_action_at, new \DateTimeZone( 'UTC' ) );
			$due_errors = \DateTimeImmutable::getLastErrors();
			$due_timestamp = $due_date && ( ! is_array( $due_errors ) || ( 0 === $due_errors['warning_count'] && 0 === $due_errors['error_count'] ) ) && $due_date->format( 'Y-m-d H:i:s' ) === $next_action_at ? $due_date->getTimestamp() : false;
			if ( false === $due_timestamp || $due_timestamp <= time() ) {
				return new \WP_Error( 'adc_invalid_follow_up', __( 'موعد المتابعة يجب أن يكون في المستقبل.', 'auto-dealership-core' ), array( 'status' => 400 ) );
			}
			$due = gmdate( 'Y-m-d H:i:s', $due_timestamp );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$lead_table = Schema::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage,first_contact_at FROM $lead_table WHERE id=%d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || 'assigned' !== $lead['stage'] || ! BranchScope::can_manage_lead( $lead ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status' => 404 ) );
		}
		if ( null !== $lead['first_contact_at'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_first_contact_exists', __( 'تم تسجيل أول تواصل لهذه الفرصة مسبقًا.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$occurred_at = $local_contact->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			Schema::table( 'activities' ),
			array( 'lead_id'=>$lead_id, 'actor_user_id'=>get_current_user_id(), 'type'=>$type, 'notes'=>$notes, 'result'=>$result, 'occurred_at'=>$occurred_at, 'next_action_at'=>$due, 'created_at'=>$now ),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$activity_id = (int) $wpdb->insert_id;
		$updated = 1 === $inserted ? $wpdb->update(
			$lead_table,
			array( 'stage'=>'contacted', 'first_contact_at'=>$occurred_at, 'first_contact_activity_id'=>$activity_id, 'next_action_at'=>$due, 'updated_at'=>$now ),
			array( 'id'=>$lead_id, 'stage'=>'assigned', 'first_contact_at'=>null ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		) : false;
		$history = 1 === $updated && self::record_stage_transition( $lead_id, (int) $lead['branch_id'], (int) $lead['owner_user_id'], get_current_user_id(), 'assigned', 'contacted', $now );
		if ( 1 !== $inserted || 1 !== $updated || ! $history || ! Transaction::commit( static fn() => AuditLog::record( 'lead.first_contact_recorded', 'lead', $lead_id, '', array( 'stage'=>'assigned' ), array( 'stage'=>'contacted', 'activity_id'=>$activity_id, 'contact_type'=>$type, 'occurred_at'=>$occurred_at ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_first_contact_failed', __( 'تعذر حفظ سجل التواصل الأول.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id'=>$lead_id, 'stage'=>'contacted', 'activity_id'=>$activity_id, 'first_contact_at'=>$occurred_at );
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
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage,first_contact_at,lost_reason FROM $table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || ! BranchScope::can_manage_lead( $lead ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status' => 404 ) );
		}
		$from = $lead['stage'];
		if ( ! in_array( $to, self::STAGE_TRANSITIONS[ $from ] ?? array(), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_transition', __( 'الانتقال بين مراحل الفرصة غير مسموح.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( in_array( $to, array( 'contacted', 'qualified', 'quotation', 'finance', 'negotiation', 'reserved', 'won' ), true ) && empty( $lead['first_contact_at'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_first_contact_required', __( 'سجل أول تواصل فعلي قبل الانتقال إلى هذه المرحلة.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( 'new' === $to && 'lost' === $from && ( '' === $reason || ( ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_reopen_forbidden', __( 'إعادة فتح الفرصة المفقودة تتطلب صلاحية المدير وسببًا موثقًا.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$now = current_time( 'mysql', true );
		$changes = array( 'stage'=>$to, 'updated_at'=>$now );
		$formats = array( '%s', '%s' );
		if ( 'lost' === $to ) { $changes['lost_reason'] = $reason; $formats[] = '%s'; }
		if ( 'new' === $to ) { $changes['owner_user_id'] = 0; $changes['claimed_at'] = null; $formats[] = '%d'; $formats[] = '%s'; }
		$updated = $wpdb->update( $table, $changes, array( 'id' => $lead_id ), $formats, array( '%d' ) );
		$history = 1 === $updated && self::record_stage_transition( $lead_id, (int) $lead['branch_id'], 'new' === $to ? 0 : (int) $lead['owner_user_id'], $user_id, $from, $to, $now );
		$activity = $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id' => $lead_id, 'actor_user_id' => $user_id, 'type' => 'stage_change', 'notes' => $reason, 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%s' ) );
		if ( 1 !== $updated || ! $history || 1 !== $activity ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_update_failed', __( 'تعذر حفظ تحديث الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.stage_changed', 'lead', $lead_id, $reason, array( 'stage' => $from ), array( 'stage' => $to ) ) ) ) {
			return new \WP_Error( 'adc_lead_update_failed', __( 'تعذر توثيق تحديث الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $lead_id, 'stage' => $to );
	}

	public static function assign( int $lead_id, int $staff_id, string $reason = '' ) {
		global $wpdb;
		$reason = sanitize_textarea_field( $reason );
		$legacy_assignment_access = current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' );
		if ( ! current_user_can( 'adc_assign_marketing_leads' ) && ! $legacy_assignment_access ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إسناد الفرص.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$staff = get_userdata( $staff_id );
		$staff_branch = BranchScope::assigned_branch( $staff_id );
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$lead_table = Schema::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage,source FROM $lead_table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( $lead && ! $legacy_assignment_access && 'marketing' !== $lead['source'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_forbidden', __( 'This permission only allows assigning marketing leads.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$assign_branch = $lead && 0 === (int) $lead['branch_id'] && current_user_can( 'manage_options' ) ? $staff_branch : (int) ( $lead['branch_id'] ?? 0 );
		if ( ! $lead || ! $staff || ! in_array( 'dealership_sales', (array) $staff->roles, true ) || ! \AutoDealership\Inventory\VehicleService::user_can_access_branch( (int) $lead['branch_id'] ) || ! \AutoDealership\Inventory\VehicleService::branch_exists( $assign_branch ) || $staff_branch !== $assign_branch || ( (int) $lead['owner_user_id'] > 0 && (int) $lead['owner_user_id'] !== $staff_id && '' === $reason ) || (int) $lead['owner_user_id'] === $staff_id ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_invalid_assignment', __( 'الموظف أو الفرع غير صالح للإسناد.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$changes = array( 'owner_user_id'=>$staff_id, 'branch_id'=>$assign_branch, 'updated_at'=>$now );
		$formats = array( '%d', '%d', '%s' );
		if ( 'new' === $lead['stage'] ) { $changes['stage'] = 'assigned'; $changes['claimed_at'] = $now; $formats[] = '%s'; $formats[] = '%s'; }
		$updated = $wpdb->update( $lead_table, $changes, array( 'id' => $lead_id ), $formats, array( '%d' ) );
		$history = 1 !== $updated || 'new' !== $lead['stage'] || self::record_stage_transition( $lead_id, $assign_branch, $staff_id, get_current_user_id(), 'new', 'assigned', $now );
		$activity = 1 === $updated ? $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>$lead_id, 'actor_user_id'=>get_current_user_id(), 'type'=>'assignment', 'notes'=>'' !== $reason ? $reason : 'Initial manager assignment', 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) ) : false;
		if ( 1 !== $updated || ! $history || 1 !== $activity ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_assignment_failed', __( 'تعذر إسناد الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'lead.assigned', 'lead', $lead_id, $reason, array( 'owner_user_id' => (int) $lead['owner_user_id'], 'branch_id' => (int) $lead['branch_id'], 'stage'=>$lead['stage'] ), array( 'owner_user_id' => $staff_id, 'branch_id' => $assign_branch, 'stage'=> 'new' === $lead['stage'] ? 'assigned' : $lead['stage'] ) ) ) ) {
			return new \WP_Error( 'adc_assignment_failed', __( 'تعذر توثيق إسناد الفرصة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $lead_id, 'owner_user_id' => $staff_id, 'branch_id' => $assign_branch );
	}

	/** Returns an uncontacted claimed lead to its branch queue; managers only. */
	public static function release_assignment( int $lead_id, string $reason ) {
		global $wpdb;
		$reason = sanitize_textarea_field( $reason );
		$legacy_assignment_access = current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' );
		if ( '' === trim( $reason ) ) {
			return new \WP_Error( 'adc_assignment_reason_required', __( 'سبب تحرير الإسناد مطلوب.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		if ( ! current_user_can( 'adc_assign_marketing_leads' ) && ! $legacy_assignment_access ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية تحرير إسناد الفرصة.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		$table = Schema::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage,first_contact_at,source FROM $table WHERE id=%d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || ! BranchScope::allows( (int) $lead['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) );
		}
		if ( ! $legacy_assignment_access && 'marketing' !== $lead['source'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_forbidden', __( 'This permission only allows releasing marketing lead assignments.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( 0 === (int) $lead['owner_user_id'] || 'assigned' !== $lead['stage'] || null !== $lead['first_contact_at'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_assignment_state', __( 'لا يمكن إعادة هذه الفرصة إلى قائمة الاستلام بعد بدء التواصل أو تغير مرحلتها.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE $table SET owner_user_id=0,stage='new',claimed_at=NULL,updated_at=%s WHERE id=%d AND owner_user_id=%d AND stage='assigned' AND first_contact_at IS NULL", $now, $lead_id, (int) $lead['owner_user_id'] ) );
		$history = 1 === $updated && self::record_stage_transition( $lead_id, (int) $lead['branch_id'], 0, get_current_user_id(), 'assigned', 'new', $now );
		$activity = 1 === $updated ? $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>$lead_id, 'actor_user_id'=>get_current_user_id(), 'type'=>'assignment', 'notes'=>$reason, 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) ) : false;
		if ( 1 !== $updated || ! $history || 1 !== $activity || ! Transaction::commit( static fn() => AuditLog::record( 'lead.assignment_released', 'lead', $lead_id, $reason, array( 'owner_user_id'=>(int) $lead['owner_user_id'], 'stage'=>'assigned' ), array( 'owner_user_id'=>0, 'stage'=>'new' ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_assignment_release_failed', __( 'تعذر تحرير إسناد الفرصة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return array( 'id'=>$lead_id, 'owner_user_id'=>0, 'stage'=>'new' );
	}

	public static function set_priority( int $lead_id, string $priority ) {
		global $wpdb;
		$priority = sanitize_key( $priority );
		if ( ! in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) {
			return new \WP_Error( 'adc_invalid_lead_priority', __( 'أولوية الفرصة غير صالحة.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		if ( ! current_user_can( 'adc_manage_own_leads' ) && ! current_user_can( 'adc_manage_branch_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية تغيير أولوية الفرصة.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		$table = Schema::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,priority FROM $table WHERE id=%d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || ! BranchScope::can_manage_lead( $lead ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) );
		}
		if ( $priority === $lead['priority'] ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'id'=>$lead_id, 'priority'=>$priority, 'updated'=>false );
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->update( $table, array( 'priority'=>$priority, 'updated_at'=>$now ), array( 'id'=>$lead_id ), array( '%s', '%s' ), array( '%d' ) );
		$activity = 1 === $updated ? $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>$lead_id, 'actor_user_id'=>get_current_user_id(), 'type'=>'priority_change', 'notes'=>'Priority changed to ' . $priority, 'created_at'=>$now ), array( '%d', '%d', '%s', '%s', '%s' ) ) : false;
		if ( 1 !== $updated || 1 !== $activity || ! Transaction::commit( static fn() => AuditLog::record( 'lead.priority_changed', 'lead', $lead_id, '', array( 'priority'=>$lead['priority'] ), array( 'priority'=>$priority ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_priority_failed', __( 'تعذر حفظ أولوية الفرصة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return array( 'id'=>$lead_id, 'priority'=>$priority, 'updated'=>true );
	}

	public static function add_activity( int $lead_id, string $type, string $notes, string $next_action_at = '' ) {
		global $wpdb;
		$can_manage_leads = current_user_can( 'adc_manage_own_leads' ) || current_user_can( 'adc_manage_branch_leads' ) || current_user_can( 'manage_options' );
		$marketing_activity_only = ! $can_manage_leads && current_user_can( 'adc_add_marketing_activity' );
		if ( ! $can_manage_leads && ! $marketing_activity_only ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إضافة نشاط للفرصة.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		$types = array( 'call', 'whatsapp', 'email', 'meeting', 'test_drive', 'quotation', 'follow_up', 'finance_request', 'reservation', 'sale', 'note' );
		if ( ! in_array( $type, $types, true ) || '' === trim( $notes ) ) {
			return new \WP_Error( 'adc_invalid_activity', __( 'نوع النشاط والملاحظة مطلوبان.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( $marketing_activity_only && ! in_array( $type, array( 'call', 'whatsapp', 'email', 'meeting', 'follow_up', 'note' ), true ) ) {
			return new \WP_Error( 'adc_activity_forbidden', __( 'لا تملك صلاحية إضافة هذا النوع من النشاط.', 'auto-dealership-core' ), array( 'status'=>403 ) );
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
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,source,created_by FROM $lead_table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		$can_add_to_own_marketing_lead = $marketing_activity_only && $lead && 'marketing' === $lead['source'] && get_current_user_id() === (int) $lead['created_by'] && BranchScope::allows( (int) $lead['branch_id'] );
		if ( ! $lead || ( ! $can_add_to_own_marketing_lead && ! BranchScope::can_manage_lead( $lead ) ) ) {
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
