<?php
namespace AutoDealership\Leads;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

final class LeadService {
	private const SOURCES = array( 'website', 'whatsapp', 'phone', 'walk_in', 'instagram', 'tiktok', 'snapchat', 'google', 'referral', 'campaign', 'other' );
	private const INTAKE_TYPES = array( 'contact', 'finance', 'finance_request', 'price_request', 'offer_request', 'test_drive', 'purchase', 'message', 'booking', 'marketing' );
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

	public static function inventory_options( int $branch_id ): array {
		global $wpdb;
		if ( $branch_id < 1 ) { return array(); }
		return $wpdb->get_results( $wpdb->prepare( "SELECT id,stock_number,brand,model,model_year,status FROM " . Schema::table( 'vehicles' ) . " WHERE branch_id=%d AND status IN ('available','reserved') ORDER BY stock_number LIMIT 100", $branch_id ), ARRAY_A ) ?: array();
	}

	public static function stage_keys(): array {
		return self::STAGE_TRANSITIONS;
	}

	/** Sales employees see new unassigned leads from every branch, and their own leads inside their branches. */
	private static function is_sales_only_viewer(): bool {
		return current_user_can( 'adc_manage_own_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'manage_options' );
	}

	private static function append_marketing_visibility_scope( string &$scope, array &$args ): void {
		if ( self::is_sales_only_viewer() ) {
			$scope = "(($scope) OR (l.owner_user_id=0 AND l.stage='new'))";
			return;
		}
		if ( ! current_user_can( 'adc_view_marketing_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ) ) { return; }
		if ( current_user_can( 'adc_view_marketing_team_leads' ) ) {
			$scope .= ' AND l.created_by>0';
			return;
		}
		$scope .= ' AND l.created_by=%d';
		$args[] = get_current_user_id();
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
		$input['request_kind'] = 'marketing';
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
		if ( 'manual' !== self::assignment_mode() ) { return new \WP_Error( 'adc_claim_disabled', __( 'الاستلام الذاتي غير مفعّل؛ تُسند الفرص عبر مدير المبيعات أو التوزيع الآلي.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$daily_cap = self::daily_cap();
		if ( $daily_cap > 0 && self::leads_received_today( get_current_user_id() ) >= $daily_cap ) { return new \WP_Error( 'adc_daily_cap_reached', __( 'بلغت الحد اليومي للفرص الجديدة.', 'auto-dealership-core' ), array( 'status' => 429 ) ); }
		$user_id = get_current_user_id();
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'dealership_sales', (array) $user->roles, true ) ) { return new \WP_Error( 'adc_claim_forbidden', __( 'يجب أن يكون المستخدم موظف مبيعات نشطًا لاستلام الفرص.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$claim_branch = 0;
		foreach ( BranchScope::assigned_branches( $user_id ) as $candidate_branch ) {
			if ( BranchScope::is_active( $candidate_branch ) ) { $claim_branch = $candidate_branch; break; }
		}
		if ( $claim_branch < 1 ) { return new \WP_Error( 'adc_claim_forbidden', __( 'لا يوجد فرع نشط مرتبط بحسابك لاستلام الفرص.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$table = Schema::table( 'leads' );
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) ); }
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage FROM $table WHERE id=%d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || 'new' !== $lead['stage'] || 0 !== (int) $lead['owner_user_id'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_claim_unavailable', __( 'الفرصة لم تعد متاحة للاستلام.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		// New leads are open to every branch; the claim moves the lead into the claiming employee's branch.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE $table SET owner_user_id=%d,branch_id=%d,stage='assigned',claimed_at=%s,updated_at=%s WHERE id=%d AND branch_id=%d AND owner_user_id=0 AND stage='new'", $user_id, $claim_branch, $now, $now, $lead_id, (int) $lead['branch_id'] ) );
		$history = 1 === $updated && self::record_stage_transition( $lead_id, $claim_branch, $user_id, $user_id, 'new', 'assigned', $now );
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

	private static function round_robin_assignment( int $branch_id, string $now, bool $any_branch = false ) {
		global $wpdb;
		if ( $branch_id < 1 || 'round_robin' !== get_option( 'adc_lead_assignment_mode', 'manual' ) ) {
			return array( 'owner_user_id'=>0, 'stage'=>'new', 'claimed_at'=>null );
		}

		$branch = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE id=%d AND active=1 FOR UPDATE', $branch_id ), ARRAY_A );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: round-robin branch lock failed: ' . $wpdb->last_error );
			return new \WP_Error( 'adc_assignment_unavailable', __( 'تعذر التحقق من موظفي الفرع لتوزيع الطلب.', 'auto-dealership-core' ), array( 'status'=>503 ) );
		}
		if ( ! $branch && ! $any_branch ) {
			return new \WP_Error( 'adc_assignment_branch_unavailable', __( 'الفرع غير نشط ولا يمكن توزيع الطلب عليه.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}

		$users = get_users( array( 'role'=>'dealership_sales', 'fields'=>'ID', 'number'=>-1, 'orderby'=>'ID', 'order'=>'ASC' ) );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: round-robin staff lookup failed: ' . $wpdb->last_error );
			return new \WP_Error( 'adc_assignment_unavailable', __( 'تعذر التحقق من موظفي الفرع لتوزيع الطلب.', 'auto-dealership-core' ), array( 'status'=>503 ) );
		}
		$staff = array();
		$staff_branches = array();
		foreach ( $users as $user_id ) {
			$user_id = absint( $user_id );
			if ( ! $user_id || ! user_can( $user_id, 'adc_manage_own_leads' ) || user_can( $user_id, 'manage_options' ) ) { continue; }
			if ( $any_branch ) {
				foreach ( BranchScope::assigned_branches( $user_id ) as $candidate_branch ) {
					if ( BranchScope::is_active( $candidate_branch ) ) { $staff[] = $user_id; $staff_branches[$user_id] = $candidate_branch; break; }
				}
			} elseif ( BranchScope::allows( $branch_id, $user_id ) ) {
				$staff[] = $user_id;
				$staff_branches[$user_id] = $branch_id;
			}
		}
		if ( ! $staff ) {
			return array( 'owner_user_id'=>0, 'stage'=>'new', 'claimed_at'=>null );
		}

		$day_start = self::day_start_utc();
		$placeholders = implode( ',', array_fill( 0, count( $staff ), '%d' ) );
		$sql = 'SELECT owner_user_id, COUNT(*) AS total, MAX(claimed_at) AS last_claimed FROM ' . Schema::table( 'leads' ) . " WHERE owner_user_id IN ($placeholders) AND claimed_at>=%s GROUP BY owner_user_id FOR UPDATE";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $staff, array( $day_start ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: lead workload lookup failed: ' . $wpdb->last_error );
			return new \WP_Error( 'adc_assignment_unavailable', __( 'تعذر التحقق من سجل توزيع الطلبات.', 'auto-dealership-core' ), array( 'status'=>503 ) );
		}
		$loads = array();
		foreach ( (array) $rows as $row ) { $loads[(int) $row['owner_user_id']] = array( (int) $row['total'], (string) $row['last_claimed'] ); }
		$cap = self::daily_cap();
		$candidates = array();
		foreach ( $staff as $user_id ) {
			$total = $loads[$user_id][0] ?? 0;
			if ( $cap > 0 && $total >= $cap ) { continue; }
			$candidates[] = array( $total, $loads[$user_id][1] ?? '', $user_id );
		}
		if ( ! $candidates ) {
			return array( 'owner_user_id'=>0, 'stage'=>'new', 'claimed_at'=>null );
		}
		// Fewest new leads today first; ties go to whoever received a lead longest ago.
		usort( $candidates, static fn( $a, $b ) => array( $a[0], $a[1], $a[2] ) <=> array( $b[0], $b[1], $b[2] ) );
		return array( 'owner_user_id'=>$candidates[0][2], 'stage'=>'assigned', 'claimed_at'=>$now, 'branch_id'=>$staff_branches[$candidates[0][2]] ?? $branch_id );
			}
	public static function assignment_mode(): string {
		$mode = sanitize_key( (string) get_option( 'adc_lead_assignment_mode', 'manual' ) );
		return in_array( $mode, array( 'manual', 'manager', 'round_robin' ), true ) ? $mode : 'manual';
	}

	/** Maximum new leads one sales employee may receive per site-local day; 0 means unlimited. */
	public static function daily_cap(): int {
		return min( 500, absint( get_option( 'adc_lead_daily_cap', 0 ) ) );
	}

	private static function day_start_utc(): string {
		$start = new \DateTimeImmutable( 'today', wp_timezone() );
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function leads_received_today( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'leads' ) . ' WHERE owner_user_id=%d AND claimed_at>=%s', $user_id, self::day_start_utc() ) );
	}

	/** Distributes unassigned branch leads in automatic mode once staff capacity is available. */
	public static function distribute_pending(): int {
		global $wpdb;
		if ( 'round_robin' !== self::assignment_mode() ) { return 0; }
		$table = Schema::table( 'leads' );
		$ids = $wpdb->get_col( "SELECT id FROM $table WHERE stage='new' AND owner_user_id=0 AND branch_id>0 ORDER BY created_at ASC,id ASC LIMIT 50" );
		if ( $wpdb->last_error ) {
			error_log( 'Auto Dealership Core: pending lead scan failed: ' . $wpdb->last_error );
			return 0;
		}
		$distributed = 0;
		foreach ( (array) $ids as $lead_id ) {
			if ( ! Transaction::begin() ) { break; }
			$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage FROM $table WHERE id=%d FOR UPDATE", (int) $lead_id ), ARRAY_A );
			if ( ! $lead || 'new' !== $lead['stage'] || 0 !== (int) $lead['owner_user_id'] ) { $wpdb->query( 'ROLLBACK' ); continue; }
			$now = current_time( 'mysql', true );
			$pick = self::round_robin_assignment( (int) $lead['branch_id'], $now );
			if ( is_wp_error( $pick ) || 0 === (int) $pick['owner_user_id'] ) {
				// Branch staff are full or unavailable: the lead is open to every branch.
				$pick = self::round_robin_assignment( (int) $lead['branch_id'], $now, true );
			}
			if ( is_wp_error( $pick ) || 0 === (int) $pick['owner_user_id'] ) {
				$wpdb->query( 'ROLLBACK' );
				continue;
			}
			$owner = (int) $pick['owner_user_id'];
			$new_branch = (int) ( $pick['branch_id'] ?? $lead['branch_id'] );
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE $table SET owner_user_id=%d,branch_id=%d,stage='assigned',claimed_at=%s,updated_at=%s WHERE id=%d AND owner_user_id=0 AND stage='new'", $owner, $new_branch, $now, $now, (int) $lead['id'] ) );
			$history = 1 === $updated && self::record_stage_transition( (int) $lead['id'], $new_branch, $owner, 0, 'new', 'assigned', $now );
			$activity = 1 === $updated ? $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>(int) $lead['id'], 'actor_user_id'=>0, 'type'=>'assignment', 'notes'=>'Automatic distribution by daily workload', 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) ) : false;
			if ( 1 !== $updated || ! $history || 1 !== $activity || ! Transaction::commit( static fn() => AuditLog::record( 'lead.auto_assigned', 'lead', (int) $lead['id'], 'Automatic distribution', array( 'stage'=>'new','owner_user_id'=>0 ), array( 'stage'=>'assigned','owner_user_id'=>$owner ) ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				error_log( 'Auto Dealership Core: automatic lead distribution failed.' );
				continue;
			}
			$distributed++;
		}
		return $distributed;
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
		$campaign = sanitize_text_field( (string) ( $input['campaign'] ?? $input['utm_campaign'] ?? '' ) );
		$platform = sanitize_text_field( (string) ( $input['platform'] ?? $input['utm_source'] ?? '' ) );
		$ad_url = '';
		$intake_type = sanitize_key( (string) ( $input['request_kind'] ?? ( $compatibility_type ?: 'contact' ) ) );
		$intake_type = '' !== $intake_type ? substr( $intake_type, 0, 32 ) : 'contact';
		$requested_car_id = absint( $input['car_id'] ?? 0 );
		$external_id = sanitize_text_field( (string) ( $input['external_id'] ?? '' ) );
		$utm_source = sanitize_text_field( (string) ( $input['utm_source'] ?? '' ) );
		$utm_medium = sanitize_text_field( (string) ( $input['utm_medium'] ?? '' ) );
		$utm_content = sanitize_text_field( (string) ( $input['utm_content'] ?? '' ) );
		$landing_page = esc_url_raw( (string) ( $input['landing_page'] ?? '' ) );
		$referrer_url = esc_url_raw( (string) ( $input['referrer_url'] ?? '' ) );
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
		// A contact alone is not enough to merge two genuine enquiries. Explicit external IDs opt into safe cross-request deduplication; the idempotency key still protects exact retries.
		// Public enquiries are deduplicated by customer, type and vehicle for a short window.
		// An explicit external ID remains supported for integrations, while normal website
		// submissions are protected from repeated clicks/forms without merging old enquiries.
		// Authenticated compatibility forms intentionally create a new opportunity
		// while reusing the locked customer account; guest intake uses short-window
		// deduplication to absorb repeated submissions.
		$dedupe_enabled = ! $marketing && ! empty( $input['public_intake'] ) && !( $compatibility_type && get_current_user_id() );
		$payload_hash = $request_key ? hash_hmac( 'sha256', wp_json_encode( array( $identity, $branch_id, $legacy_ref, $initial_activity_notes, $compatibility_type, $input['request_kind'] ?? '', $requested_car_id, $input['date'] ?? '', $input['time'] ?? '', $external_id, $utm_source, $utm_medium, $utm_content, $landing_page, $referrer_url, $marketing ) ), wp_salt( 'auth' ) ) : null;
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
		// Serialize intake decisions per branch. This closes the small race between
		// duplicate detection and compatibility-row creation for simultaneous retries.
		if ( $branch_id ) {
			$locked_branch = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE id=%d FOR UPDATE', (int) $branch_id ) );
			if ( $wpdb->last_error || ! $locked_branch ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_assignment_unavailable', __( 'Unable to verify the branch.', 'auto-dealership-core' ), array( 'status'=>503 ) );
			}
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
		} elseif ( $dedupe_enabled ) {
			$customer_id = self::reusable_public_customer( $mobile, $email, (int) $branch_id, $intake_type, $requested_car_id, $external_id, $compatibility_type );
			if ( $customer_id < 0 ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_lead_failed', 'Unable to check the existing customer safely.', array( 'status'=>503 ) ); }
			if ( ! $customer_id ) {
				$ok = $wpdb->insert( Schema::table( 'customers' ), array( 'full_name'=>$name, 'mobile'=>$mobile, 'email'=>$email, 'city'=>$identity['city'], 'consent_marketing'=>$identity['consent_marketing'] ? 1 : 0, 'consent_at'=>$identity['consent_marketing'] ? $now : null, 'created_at'=>$now, 'updated_at'=>$now ) );
				if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
				$customer_id = (int) $wpdb->insert_id;
			}
		} else {
			$ok = $wpdb->insert( Schema::table( 'customers' ), array( 'full_name'=>$name, 'mobile'=>$mobile, 'email'=>$email, 'city'=>$identity['city'], 'consent_marketing'=>$identity['consent_marketing'] ? 1 : 0, 'consent_at'=>$identity['consent_marketing'] ? $now : null, 'created_at'=>$now, 'updated_at'=>$now ) );
			if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_lead_failed', __( 'تعذر حفظ الطلب. حاول لاحقًا.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
			$customer_id = (int) $wpdb->insert_id;
		}
		$duplicate = $dedupe_enabled ? self::find_open_duplicate( $customer_id, $intake_type, $requested_car_id, (int) $branch_id, $external_id, $compatibility_type ) : null;
		if ( is_wp_error( $duplicate ) ) { $wpdb->query( 'ROLLBACK' ); return $duplicate; }
		if ( $duplicate ) {
			$note = trim( 'Repeated public enquiry was attached to this open opportunity.' . ( $initial_activity_notes ? "\n" . $initial_activity_notes : '' ) );
			$activity = $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>(int) $duplicate['id'], 'actor_user_id'=>0, 'type'=>'note', 'notes'=>sanitize_textarea_field( $note ), 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) );
			$updated = false !== $wpdb->update( Schema::table( 'leads' ), array( 'updated_at'=>$now ), array( 'id'=>(int) $duplicate['id'] ), array( '%s' ), array( '%d' ) );
			$audited = $activity && $updated && Transaction::commit( static fn() => AuditLog::record( 'lead.duplicate_intake', 'lead', (int) $duplicate['id'], 'Repeated public enquiry attached to an open opportunity', null, array( 'intake_type'=>$intake_type, 'requested_car_id'=>$requested_car_id ) ) );
			if ( ! $audited ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_lead_failed', 'Unable to record the repeated enquiry safely.', array( 'status'=>500 ) ); }
			$result = array( 'id'=>(int) $duplicate['id'], 'status'=>'duplicate', 'duplicate'=>true, 'reused'=>true );
			if ( $compatibility_type && $compatibility_type === (string) $duplicate['legacy_request_type'] ) { $result['legacy_request_id'] = (int) $duplicate['legacy_request_id']; }
			return $result;
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
		// A concurrent public retry can pass the first duplicate check before the
		// winning transaction commits. Re-check after the legacy row is inserted so
		// the losing request cannot leave an orphan compatibility record.
		$race_duplicate = $dedupe_enabled ? self::find_open_duplicate( $customer_id, $intake_type, $requested_car_id, (int) $branch_id, $external_id, $compatibility_type ) : null;
		if ( is_wp_error( $race_duplicate ) ) { $wpdb->query( 'ROLLBACK' ); return $race_duplicate; }
		if ( $race_duplicate ) {
			$legacy_deleted = ! $compatibility_table || ( 1 === $wpdb->delete( $compatibility_table, array( 'id'=>(int) $legacy_ref['id'] ), array( '%d' ) ) );
			$note = trim( 'Repeated public enquiry was attached to this open opportunity.' . ( $initial_activity_notes ? "\n" . $initial_activity_notes : '' ) );
			$activity = $legacy_deleted && $wpdb->insert( Schema::table( 'activities' ), array( 'lead_id'=>(int) $race_duplicate['id'], 'actor_user_id'=>0, 'type'=>'note', 'notes'=>sanitize_textarea_field( $note ), 'created_at'=>$now ), array( '%d','%d','%s','%s','%s' ) );
			$updated = false !== $wpdb->update( Schema::table( 'leads' ), array( 'updated_at'=>$now ), array( 'id'=>(int) $race_duplicate['id'] ), array( '%s' ), array( '%d' ) );
			$audited = $activity && $updated && Transaction::commit( static fn() => AuditLog::record( 'lead.duplicate_intake', 'lead', (int) $race_duplicate['id'], 'Repeated public enquiry attached to an open opportunity', null, array( 'intake_type'=>$intake_type, 'requested_car_id'=>$requested_car_id ) ) );
			if ( ! $audited ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_lead_failed', 'Unable to record the repeated enquiry safely.', array( 'status'=>500 ) ); }
			$result = array( 'id'=>(int) $race_duplicate['id'], 'status'=>'duplicate', 'duplicate'=>true, 'reused'=>true );
			if ( $compatibility_type === (string) $race_duplicate['legacy_request_type'] ) { $result['legacy_request_id'] = (int) $race_duplicate['legacy_request_id']; }
			return $result;
		}
		$legacy_type = isset( $legacy_ref['type'] ) && in_array( $legacy_ref['type'], array( 'message', 'booking' ), true ) ? $legacy_ref['type'] : null;
		$legacy_id = $legacy_type && ! empty( $legacy_ref['id'] ) ? absint( $legacy_ref['id'] ) : null;
		$lead_data = array( 'customer_id'=>$customer_id, 'branch_id'=>$branch_id, 'owner_user_id'=>$assignment['owner_user_id'], 'source'=>$source, 'intake_type'=>$intake_type, 'requested_car_id'=>$requested_car_id, 'external_id'=>$external_id, 'utm_source'=>$utm_source, 'utm_medium'=>$utm_medium, 'utm_content'=>$utm_content, 'landing_page'=>$landing_page, 'referrer_url'=>$referrer_url, 'stage'=>$assignment['stage'], 'lost_reason'=>'', 'legacy_request_type'=>$legacy_type, 'legacy_request_id'=>$legacy_id, 'claimed_at'=>$assignment['claimed_at'], 'created_at'=>$now, 'updated_at'=>$now, 'public_request_key'=>$request_key, 'public_payload_hash'=>$payload_hash );
		$lead_formats = array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );
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
		// Keep the public-intake notification inside the intake transaction.
		if ( ! empty( $input['public_intake'] ) && ! \AutoDealership\Operations\FollowUpNotifications::queue_lead_created( $lead_id, (int) $branch_id, (int) $assignment['owner_user_id'], $now ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_failed', __( 'Unable to record the opportunity notification.', 'auto-dealership-core' ), array( 'status' => 500 ) );
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

	private static function reusable_public_customer( string $mobile, string $email, int $branch_id, string $intake_type, int $requested_car_id, string $external_id, string $compatibility_type ): int {
		global $wpdb;
		$customer_table = Schema::table( 'customers' );
		$lead_table = Schema::table( 'leads' );
		$match = '(c.mobile=%s';
		$args = array( $mobile );
		if ( '' !== $email ) { $match .= ' OR c.email=%s'; $args[] = $email; }
		$match .= ')';
		$conditions = array( $match, '(c.account_user_id=0 OR c.account_user_id IS NULL)', 'c.merged_into_id IS NULL', 'l.branch_id=%d', 'l.intake_type=%s', $compatibility_type ? 'l.legacy_request_type=%s' : 'l.legacy_request_type IS NULL', 'l.stage NOT IN ("won","lost")', 'l.archived_at IS NULL' );
		$args[] = $branch_id;
		$args[] = $intake_type;
		if ( $compatibility_type ) { $args[] = $compatibility_type; }
		if ( '' !== $external_id ) { $conditions[] = 'l.external_id=%s'; $args[] = $external_id; }
		else { $conditions[] = 'l.created_at>=%s'; $args[] = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ); }
		if ( $requested_car_id ) { $conditions[] = 'l.requested_car_id=%d'; $args[] = $requested_car_id; }
		else { $conditions[] = 'l.requested_car_id=0'; $conditions[] = 'l.created_at>=%s'; $args[] = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT c.id FROM ' . $customer_table . ' c INNER JOIN ' . $lead_table . ' l ON l.customer_id=c.id WHERE ' . implode( ' AND ', $conditions ) . ' ORDER BY l.id DESC LIMIT 1 FOR UPDATE', $args ), ARRAY_A );
		if ( $wpdb->last_error ) { return -1; }
		return $row ? (int) $row['id'] : 0;
	}

	private static function find_open_duplicate( int $customer_id, string $intake_type, int $requested_car_id, int $branch_id, string $external_id, string $compatibility_type ) {
		global $wpdb;
		$conditions = array( 'customer_id=%d', 'branch_id=%d', 'intake_type=%s', $compatibility_type ? 'legacy_request_type=%s' : 'legacy_request_type IS NULL', 'stage NOT IN ("won","lost")', 'archived_at IS NULL' );
		$args = array( $customer_id, $branch_id, $intake_type );
		if ( $compatibility_type ) { $args[] = $compatibility_type; }
		if ( '' !== $external_id ) { $conditions[] = 'external_id=%s'; $args[] = $external_id; }
		else { $conditions[] = 'created_at>=%s'; $args[] = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ); }
		if ( $requested_car_id ) {
			$conditions[] = 'requested_car_id=%d';
			$args[] = $requested_car_id;
		} else {
			$conditions[] = 'requested_car_id=0';
			$conditions[] = 'created_at>=%s';
			$args[] = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,legacy_request_type,legacy_request_id FROM ' . Schema::table( 'leads' ) . ' WHERE ' . implode( ' AND ', $conditions ) . ' ORDER BY id DESC LIMIT 1 FOR UPDATE', $args ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_lead_failed', 'Unable to check duplicate opportunities safely.', array( 'status'=>503 ) ); }
		return $row ?: null;
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
		self::append_marketing_visibility_scope( $scope, $args );
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
		$intake_type = sanitize_key( (string) ( $filters['intake_type'] ?? '' ) );
		if ( '' !== $intake_type && in_array( $intake_type, self::INTAKE_TYPES, true ) ) { $where .= ' AND l.intake_type=%s'; $args[] = $intake_type; }
		$utm_source = sanitize_text_field( (string) ( $filters['utm_source'] ?? '' ) );
		if ( '' !== $utm_source ) { $where .= ' AND l.utm_source LIKE %s'; $args[] = '%' . $wpdb->esc_like( $utm_source ) . '%'; }
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
		self::append_lifecycle_filters( $where, $args, $filters );
		if ( ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) {
			if ( ! current_user_can( 'adc_view_branch_leads' ) ) {
				$where .= " AND (l.owner_user_id=%d OR (l.owner_user_id=0 AND l.stage='new'))";
				$args[] = get_current_user_id();
			}
		}
		$args[] = $per_page;
		$args[] = ( $page - 1 ) * $per_page;
		$sql = 'SELECT l.id,l.customer_id,l.branch_id,l.owner_user_id,l.source,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.intake_type END intake_type,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.campaign END campaign,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.platform END platform,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN 0 ELSE l.created_by END created_by,l.referred_at,l.stage,l.next_action_at,l.claimed_at,l.first_contact_at,l.priority,l.created_at,c.full_name,c.mobile,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.email END email,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.city END city FROM ' . $lead_table . ' l INNER JOIN ' . $customer_table . ' c ON c.id=l.customer_id' . $where . ' ORDER BY FIELD(l.priority,"urgent","high","normal","low"),l.created_at ASC LIMIT %d OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public static function count_for_current_user( array $filters = array() ): int {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) { return 0; }
		$lead_table = Schema::table( 'leads' ); $customer_table = Schema::table( 'customers' );
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' ); self::append_marketing_visibility_scope( $scope, $args ); $where = ' WHERE ' . $scope;
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
		$intake_type = sanitize_key( (string) ( $filters['intake_type'] ?? '' ) );
		if ( '' !== $intake_type && in_array( $intake_type, self::INTAKE_TYPES, true ) ) { $where .= ' AND l.intake_type=%s'; $args[] = $intake_type; }
		$utm_source = sanitize_text_field( (string) ( $filters['utm_source'] ?? '' ) );
		if ( '' !== $utm_source ) { $where .= ' AND l.utm_source LIKE %s'; $args[] = '%' . $wpdb->esc_like( $utm_source ) . '%'; }
		$campaign = trim( (string) ( $filters['campaign'] ?? '' ) ); if ( '' !== $campaign ) { $where .= ' AND l.campaign LIKE %s'; $args[] = '%' . $wpdb->esc_like( $campaign ) . '%'; }
		$platform = sanitize_text_field( (string) ( $filters['platform'] ?? '' ) ); if ( '' !== $platform ) { $where .= ' AND l.platform=%s'; $args[] = $platform; }
		if ( ! empty( $filters['created_by'] ) ) { $where .= ' AND l.created_by=%d'; $args[] = absint( $filters['created_by'] ); }
		if ( ! empty( $filters['overdue'] ) ) { $where .= " AND l.next_action_at IS NOT NULL AND l.next_action_at<=%s AND l.stage NOT IN ('won','lost')"; $args[] = current_time( 'mysql', true ); }
		if ( array_key_exists( 'owner', $filters ) ) { $where .= ' AND l.owner_user_id=%d'; $args[] = absint( $filters['owner'] ); }
		self::append_lifecycle_filters( $where, $args, $filters );
		if ( ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) { $where .= " AND (l.owner_user_id=%d OR (l.owner_user_id=0 AND l.stage='new'))"; $args[] = get_current_user_id(); }
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $lead_table . ' l INNER JOIN ' . $customer_table . ' c ON c.id=l.customer_id' . $where, $args ) );
	}

	private static function append_lifecycle_filters( string &$where, array &$args, array $filters ): void {
		global $wpdb;
		$from = isset( $filters['from'] ) && is_scalar( $filters['from'] ) ? (string) $filters['from'] : ''; $to = isset( $filters['to'] ) && is_scalar( $filters['to'] ) ? (string) $filters['to'] : '';
		if ( preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $from ) ) { $where .= ' AND l.created_at>=%s'; $args[] = $from . ' 00:00:00'; }
		if ( preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $to ) ) { $where .= ' AND l.created_at<%s'; $args[] = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' +1 day' ) ); }
		$archived = isset( $filters['archived'] ) && is_scalar( $filters['archived'] ) ? strtolower( (string) $filters['archived'] ) : ''; $days = max( 0, (int) get_option( 'adc_lead_archive_days', 30 ) );
		if ( 'all' !== $archived ) {
			if ( '1' === $archived ) { $where .= ' AND (l.archived_at IS NOT NULL'; if ( $days > 0 ) { $where .= " OR (l.stage IN ('won','lost') AND l.closed_at IS NOT NULL AND l.closed_at<%s)"; $args[] = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ); } $where .= ')'; }
			else { $where .= ' AND l.archived_at IS NULL'; if ( $days > 0 ) { $where .= " AND NOT (l.stage IN ('won','lost') AND l.closed_at IS NOT NULL AND l.closed_at<%s)"; $args[] = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ); } }
		}
	}

	public static function pipeline_summary(): array {
		global $wpdb;
		$base = array();
		$total = self::count_for_current_user();
		$base['total'] = $total;
		$base['new'] = self::count_for_current_user( array( 'stage' => 'new' ) );
		$base['overdue'] = self::count_for_current_user( array( 'overdue' => true ) );
		$base['unassigned'] = self::count_for_current_user( array( 'owner' => 0 ) );
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		self::append_marketing_visibility_scope( $scope, $args );
		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$metric_where = ' WHERE ' . $scope; $metric_args = $args; self::append_lifecycle_filters( $metric_where, $metric_args, array() );
		$metrics = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN l.stage='won' AND l.closed_at>=%s THEN l.sale_amount ELSE 0 END),0) month_sales_amount,COALESCE(SUM(CASE WHEN l.stage NOT IN ('won','lost') THEN l.sale_amount ELSE 0 END),0) open_value,SUM(l.stage='won') won_count,SUM(l.stage IN ('won','lost')) closed_count FROM " . Schema::table( 'leads' ) . " l$metric_where", array_merge( array( $month_start ), $metric_args ) ), ARRAY_A );
		$base['month_sales_amount'] = (int) ( $metrics['month_sales_amount'] ?? 0 );
		$base['open_value'] = (int) ( $metrics['open_value'] ?? 0 );
		$base['conversion_percent'] = ( (int) ( $metrics['closed_count'] ?? 0 ) > 0 ) ? round( 100 * (int) ( $metrics['won_count'] ?? 0 ) / (int) $metrics['closed_count'], 1 ) : 0;
		return $base;
	}

	/** Monthly sales goal and achieved value for a sales employee. */
	public static function monthly_goal_progress( int $user_id = 0 ): array {
		global $wpdb;
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$goal = absint( get_user_meta( $user_id, 'adc_monthly_sales_goal', true ) );
		$start = gmdate( 'Y-m-01 00:00:00' );
		$end = gmdate( 'Y-m-01 00:00:00', strtotime( '+1 month', strtotime( $start ) ) );
		$achieved = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(sale_amount),0) FROM ' . Schema::table( 'leads' ) . " WHERE owner_user_id=%d AND stage='won' AND closed_at>=%s AND closed_at<%s", $user_id, $start, $end ) );
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'leads' ) . " WHERE owner_user_id=%d AND stage='won' AND closed_at>=%s AND closed_at<%s", $user_id, $start, $end ) );
		return array( 'goal' => $goal, 'achieved' => $achieved, 'won_count' => $count, 'percent' => $goal > 0 ? min( 100, round( 100 * $achieved / $goal, 1 ) ) : 0 );
	}

	/** Persist the archive state so old closed leads are not evaluated on every CRM request. */
	public static function archive_closed_leads(): int {
		global $wpdb;
		$days = absint( get_option( 'adc_lead_archive_days', 30 ) );
		if ( $days < 1 ) { return 0; }
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return max( 0, (int) $wpdb->query( $wpdb->prepare( "UPDATE " . Schema::table( 'leads' ) . " SET archived_at=%s,updated_at=%s WHERE archived_at IS NULL AND stage IN ('won','lost') AND closed_at IS NOT NULL AND closed_at<%s", current_time( 'mysql', true ), current_time( 'mysql', true ), $cutoff ) ) );
	}

	public static function find_for_current_user( int $lead_id ) {
		global $wpdb;
		if ( $lead_id < 1 || ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) ) { return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) ); }
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		self::append_marketing_visibility_scope( $scope, $args );
		if ( ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) { $scope .= " AND (l.owner_user_id=%d OR (l.owner_user_id=0 AND l.stage='new'))"; $args[] = get_current_user_id(); }
		$args[] = $lead_id;
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT l.id,l.customer_id,l.branch_id,l.owner_user_id,l.source,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.intake_type END intake_type,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.external_id END external_id,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.utm_source END utm_source,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.utm_medium END utm_medium,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.utm_content END utm_content,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.landing_page END landing_page,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.referrer_url END referrer_url,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.campaign END campaign,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE l.platform END platform,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN 0 ELSE l.created_by END created_by,l.referred_at,l.stage,l.next_action_at,l.claimed_at,l.first_contact_at,l.priority,l.created_at,l.updated_at,c.full_name,c.mobile,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.email END email,CASE WHEN l.owner_user_id=0 AND l.stage="new" THEN "" ELSE c.city END city FROM ' . Schema::table( 'leads' ) . ' l INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=l.customer_id WHERE ' . $scope . ' AND l.id=%d', $args ), ARRAY_A );
		return $row ?: new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) );
	}

	/** Activity content uses the same read scope as the lead list. */
	public static function activity_history( int $lead_id, int $page = 1, int $per_page = 50 ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_leads' ) && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية عرض الفرص.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		self::append_marketing_visibility_scope( $scope, $args );
		$visible_scope = $scope;
		$visible_args = $args;
		if ( ! current_user_can( 'adc_view_marketing_leads' ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) {
			$scope .= ' AND l.owner_user_id=%d'; $args[] = get_current_user_id();
			// Unassigned new leads can be opened, but their history is only for the assigned employee.
			$visible_scope .= " AND (l.owner_user_id=%d OR (l.owner_user_id=0 AND l.stage='new'))"; $visible_args[] = get_current_user_id();
		}
		$args[] = $lead_id;
		$visible_args[] = $lead_id;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT l.id FROM ' . Schema::table( 'leads' ) . " l WHERE $visible_scope AND l.id=%d", $visible_args ) ) ) {
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

	public static function update_stage( int $lead_id, string $to, string $reason = '', int $sale_amount = 0, int $vehicle_id = 0, string $payment_method = '', string $invoice_reference = '', string $next_action_at = '', string $lost_competitor = '', int $lost_comparative_price = 0 ) {
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
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,customer_id,branch_id,owner_user_id,stage,first_contact_at,lost_reason,legacy_request_type,legacy_request_id FROM $table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || ! BranchScope::can_manage_lead( $lead ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status' => 404 ) );
		}
		$vehicle_id = absint( $vehicle_id );
		$payment_method = sanitize_key( $payment_method );
		$invoice_reference = sanitize_text_field( $invoice_reference );
		$lost_competitor = sanitize_text_field( $lost_competitor );
		$lost_comparative_price = absint( $lost_comparative_price );
		$recontact_at = null;
		if ( 'lost' === $to && '' !== trim( $next_action_at ) ) {
			$recontact_timestamp = strtotime( sanitize_text_field( $next_action_at ) );
			if ( false === $recontact_timestamp || $recontact_timestamp <= current_time( 'timestamp', true ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_invalid_recontact_date', __( 'يجب أن يكون موعد إعادة التواصل في المستقبل.', 'auto-dealership-core' ), array( 'status' => 400 ) );
			}
			$recontact_at = gmdate( 'Y-m-d H:i:s', $recontact_timestamp );
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
		if ( 'won' === $to ) {
			if ( $sale_amount < 1 || ! in_array( $payment_method, array( 'cash', 'bank', 'card', 'finance', 'other' ), true ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_sale_details_required', __( 'مبلغ البيع وطريقة الدفع والمركبة مطلوبة لإغلاق الفرصة.', 'auto-dealership-core' ), array( 'status' => 400 ) );
			}
			$legacy_table = $wpdb->prefix . 'car_dealer_' . ( 'booking' === (string) $lead['legacy_request_type'] ? 'bookings' : 'messages' );
			$legacy_vehicle = ( $lead['legacy_request_id'] && in_array( (string) $lead['legacy_request_type'], array( 'booking', 'message' ), true ) ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT car_id FROM $legacy_table WHERE id=%d", (int) $lead['legacy_request_id'] ) ) : 0;
			if ( ! $vehicle_id && $legacy_vehicle ) { $vehicle_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id=%d', $legacy_vehicle ) ); }
			$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d FOR UPDATE', $vehicle_id ), ARRAY_A );
			if ( ! $vehicle || (int) $vehicle['branch_id'] !== (int) $lead['branch_id'] || ! in_array( $vehicle['status'], array( 'available', 'reserved' ), true ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_sale_vehicle_required', __( 'اختر مركبة متاحة من مخزون فرع الفرصة.', 'auto-dealership-core' ), array( 'status' => 409 ) );
			}
			$active_reservation = $wpdb->get_row( $wpdb->prepare( "SELECT id,customer_id,deposit_amount,payment_reference FROM " . Schema::table( 'reservations' ) . " WHERE vehicle_id=%d AND status='confirmed' ORDER BY id DESC LIMIT 1 FOR UPDATE", $vehicle_id ), ARRAY_A );
			if ( ( 'reserved' === $vehicle['status'] && ! $active_reservation ) || ( $active_reservation && (int) $active_reservation['customer_id'] !== (int) $lead['customer_id'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_sale_vehicle_reserved', __( 'المركبة محجوزة لعميل آخر أو لا يملك النظام حجزًا صالحًا لها.', 'auto-dealership-core' ), array( 'status' => 409 ) );
			}
			$now = current_time( 'mysql', true );
			if ( $active_reservation ) {
				$wpdb->update( Schema::table( 'reservations' ), array( 'status'=>'cancelled', 'deposit_refund_status'=>( (int) $active_reservation['deposit_amount'] > 0 ? 'pending_refund' : 'no_refund_due' ), 'updated_at'=>$now ), array( 'id'=>(int) $active_reservation['id'], 'status'=>'confirmed' ), array( '%s','%s','%s' ), array( '%d','%s' ) );
			}
			$vehicle_changed = $wpdb->update( Schema::table( 'vehicles' ), array( 'status'=>'sold', 'updated_at'=>$now ), array( 'id'=>$vehicle_id, 'status'=>$vehicle['status'] ), array( '%s','%s' ), array( '%d','%s' ) );
			$movement = 1 === $vehicle_changed ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id'=>$vehicle_id, 'from_branch_id'=>(int) $lead['branch_id'], 'to_branch_id'=>(int) $lead['branch_id'], 'from_status'=>$vehicle['status'], 'to_status'=>'sold', 'actor_user_id'=>$user_id, 'reason'=>'Lead #' . $lead_id . ' won', 'created_at'=>$now ), array( '%d','%d','%d','%s','%s','%d','%s','%s' ) ) : false;
			$commission_type = sanitize_key( (string) get_option( 'adc_employee_commission_type', 'none' ) );
			$commission_value = absint( get_option( 'adc_employee_commission_value', 0 ) );
			$employee_commission_amount = 'fixed' === $commission_type ? $commission_value : ( 'percentage' === $commission_type ? (int) round( $sale_amount * min( 10000, $commission_value ) / 10000 ) : 0 );
			$employee_commission_status = $employee_commission_amount > 0 ? 'pending_review' : 'disabled';
			$sale = $wpdb->insert( Schema::table( 'sales' ), array( 'quotation_id'=>0, 'reservation_id'=>(int) ( $active_reservation['id'] ?? 0 ), 'customer_id'=>(int) $lead['customer_id'], 'vehicle_id'=>$vehicle_id, 'status'=>'approved', 'owner_user_id'=>(int) $lead['owner_user_id'], 'lead_id'=>$lead_id, 'sale_amount'=>$sale_amount, 'payment_method'=>$payment_method, 'employee_commission_amount'=>$employee_commission_amount, 'employee_commission_status'=>$employee_commission_status, 'invoice_reference'=>$invoice_reference, 'approved_by'=>$user_id, 'created_at'=>$now, 'updated_at'=>$now ), array( '%d','%d','%d','%d','%s','%d','%d','%d','%s','%d','%s','%s','%d','%s','%s' ) );
			if ( 1 !== $vehicle_changed || false === $movement || 1 !== $sale ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_sale_creation_failed', __( 'تعذر إنشاء سجل البيع وتحديث المخزون.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		}
		$now = current_time( 'mysql', true );
		$changes = array( 'stage'=>$to, 'updated_at'=>$now );
		$formats = array( '%s', '%s' );
		if ( 'lost' === $to ) { $changes['lost_reason'] = $reason; $formats[] = '%s'; $changes['lost_competitor'] = $lost_competitor; $formats[] = '%s'; $changes['lost_comparative_price'] = $lost_comparative_price; $formats[] = '%d'; $changes['next_action_at'] = $recontact_at; $formats[] = '%s'; }
		if ( in_array( $to, array( 'won', 'lost' ), true ) ) { $changes['closed_at'] = $now; $formats[] = '%s'; }
		if ( 'won' === $to ) { $changes['next_action_at'] = null; $formats[] = '%s'; }
		if ( 'won' === $to ) { $changes['sale_amount'] = max( 0, $sale_amount ); $formats[] = '%d'; }
		if ( 'new' === $to ) { $changes['closed_at'] = null; $formats[] = '%s'; $changes['next_action_at'] = null; $formats[] = '%s'; }
		if ( 'new' === $to ) { $changes['owner_user_id'] = 0; $changes['claimed_at'] = null; $formats[] = '%d'; $formats[] = '%s'; }
		if ( 'lost' === $to ) {
			$release = $wpdb->get_row( $wpdb->prepare( "SELECT id,vehicle_id,deposit_amount,payment_reference FROM " . Schema::table( 'reservations' ) . " WHERE vehicle_id IN (SELECT id FROM " . Schema::table( 'vehicles' ) . " WHERE branch_id=%d AND status='reserved') AND customer_id=%d AND status='confirmed' ORDER BY id DESC LIMIT 1 FOR UPDATE", (int) $lead['branch_id'], (int) $lead['customer_id'] ), ARRAY_A );
			if ( $release ) {
				$release_status = ( (int) $release['deposit_amount'] > 0 || '' !== $release['payment_reference'] ) ? 'hold' : 'available';
				$wpdb->update( Schema::table( 'reservations' ), array( 'status'=>'cancelled', 'deposit_refund_status'=>( $release_status === 'hold' ? 'pending_refund' : 'no_refund_due' ), 'updated_at'=>$now ), array( 'id'=>(int) $release['id'], 'status'=>'confirmed' ), array( '%s','%s','%s' ), array( '%d','%s' ) );
				$wpdb->update( Schema::table( 'vehicles' ), array( 'status'=>$release_status, 'updated_at'=>$now ), array( 'id'=>(int) $release['vehicle_id'], 'status'=>'reserved' ), array( '%s','%s' ), array( '%d','%s' ) );
			}
		}
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
		if ( in_array( $to, array( 'won', 'lost' ), true ) && ! \AutoDealership\Operations\FollowUpNotifications::queue_lead_closed( $lead_id, (int) $lead['branch_id'], (int) $lead['owner_user_id'], $to, $now ) ) { error_log( 'Auto Dealership Core: failed to queue lead close notification #' . $lead_id ); }
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
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage,source,created_by FROM $lead_table WHERE id = %d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( $lead && ! $legacy_assignment_access && (int) $lead['created_by'] < 1 ) {
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
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,owner_user_id,stage,first_contact_at,source,created_by FROM $table WHERE id=%d FOR UPDATE", $lead_id ), ARRAY_A );
		if ( ! $lead || ! BranchScope::allows( (int) $lead['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_lead_not_found', __( 'الفرصة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status'=>404 ) );
		}
		if ( ! $legacy_assignment_access && (int) $lead['created_by'] < 1 ) {
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
		$created_by = $lead ? (int) ( $lead['created_by'] ?? 0 ) : 0;
		$created_by_current_user = $lead && get_current_user_id() === $created_by;
		$created_by_marketing_team = $lead && current_user_can( 'adc_view_marketing_team_leads' ) && $created_by > 0;
		$can_add_to_marketing_lead = $marketing_activity_only && $lead && ( $created_by_current_user || $created_by_marketing_team ) && BranchScope::allows( (int) $lead['branch_id'] );
		if ( ! $lead || ( ! $can_add_to_marketing_lead && ! BranchScope::can_manage_lead( $lead ) ) ) {
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
