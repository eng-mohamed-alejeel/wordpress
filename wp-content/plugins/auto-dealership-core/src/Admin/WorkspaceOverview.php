<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Read-only operational queues. Counts and drill-downs share the same predicates. */
final class WorkspaceOverview {
	public const PAGE_SIZE = 20;
	public static function t( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }

	private static function field( string $name, string $default = '' ): string {
		return isset( $_GET[$name] ) && is_scalar( $_GET[$name] ) ? wp_unslash( (string) $_GET[$name] ) : $default;
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page'=>'adc-workspace' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function scope( string $column, int $branch, string $owner = '', bool $finance_access = false ): array {
		list( $where, $args ) = BranchScope::predicate( $column );
		if ( $branch ) { $where .= " AND $column=%d"; $args[] = $branch; }
		if ( $owner && ! BranchScope::is_global() && ! current_user_can( 'adc_view_branch_leads' ) && ! ( $finance_access && current_user_can( 'adc_view_finance' ) ) ) {
			$where .= " AND $owner=%d"; $args[] = get_current_user_id();
		}
		return array( $where, $args );
	}

	private static function definitions( int $branch, int $days ): array {
		$queues = array(); $user = get_current_user_id();
		$now = current_time( 'mysql', true );
		$day = current_datetime()->setTime( 0, 0 );
		$from = $day->modify( '-' . ( $days - 1 ) . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$until = $day->modify( '+1 day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$t = static fn( $name ) => Schema::table( $name );
		$add = static function ( $key, $ar, $en, $from_sql, $where, $args, $id, $label, $date, $target, $target_key, $extra = array() ) use ( &$queues ): void {
			$queues[$key] = array( 'title'=>self::t( $ar, $en ), 'from'=>$from_sql, 'where'=>$where, 'args'=>$args, 'id'=>$id, 'label'=>$label, 'date'=>$date, 'target'=>$target, 'target_key'=>$target_key, 'extra'=>$extra );
		};
		if ( current_user_can( 'manage_options' ) || current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) ) {
			list( $scope, $args ) = self::scope( 'l.branch_id', $branch, 'l.owner_user_id' );
			$from_sql = "{$t('leads')} l INNER JOIN {$t('customers')} c ON c.id=l.customer_id";
			$add( 'overdue', 'متابعات مستحقة', 'Due follow-ups', $from_sql, "$scope AND l.stage NOT IN ('won','lost') AND l.next_action_at<=%s", array_merge( $args, array( $now ) ), 'l.id', 'c.full_name', 'l.next_action_at', 'adc-crm', 'lead_id' );
			$add( 'new_leads', 'فرص جديدة خلال الفترة', 'New leads in period', $from_sql, "$scope AND l.stage='new' AND l.created_at>=%s AND l.created_at<%s", array_merge( $args, array( $from, $until ) ), 'l.id', 'c.full_name', 'l.created_at', 'adc-crm', 'lead_id' );
			foreach ( array( 'overdue', 'new_leads' ) as $key ) {
				$queues[$key]['context'] = 'l.owner_user_id owner_id,l.customer_id customer_id';
				$queues[$key]['has_owner'] = true;
			}
		}
		if ( current_user_can( 'adc_review_discounts' ) ) {
			list( $scope, $args ) = self::scope( 'v.branch_id', $branch );
			$where = "$scope AND d.status='pending' AND q.status='pending_discount' AND q.valid_until>=%s AND d.requester_user_id<>%d AND NOT EXISTS (SELECT 1 FROM {$t('sales')} sold WHERE sold.quotation_id=q.id)";
			$args = array_merge( $args, array( gmdate( 'Y-m-d' ), $user ) );
			$where .= current_user_can( 'adc_approve_high_discounts' ) ? " AND d.approval_tier IN ('sales_manager','general_manager')" : " AND d.approval_tier='sales_manager'";
			$add( 'discounts', 'خصومات تنتظر اعتمادك', 'Discounts awaiting your approval', "{$t('discount_requests')} d INNER JOIN {$t('quotations')} q ON q.id=d.quotation_id INNER JOIN {$t('vehicles')} v ON v.id=q.vehicle_id INNER JOIN {$t('customers')} c ON c.id=q.customer_id", $where, $args, 'd.id', 'c.full_name', 'd.created_at', 'adc-quotes', 'quote_id' );
			$queues['discounts']['target'] = 'adc-approvals';
			$queues['discounts']['target_key'] = 'discount_id';
			$queues['discounts']['extra'] = array( 'section'=>'discounts' );
		}
		if ( current_user_can( 'adc_approve_sales' ) ) {
			list( $scope, $args ) = self::scope( 'v.branch_id', $branch );
			$add( 'sales', 'مبيعات تنتظر مراجعتك', 'Sales awaiting your review', "{$t('sales')} s INNER JOIN {$t('vehicles')} v ON v.id=s.vehicle_id INNER JOIN {$t('customers')} c ON c.id=s.customer_id", "$scope AND s.status='pending_approval' AND s.owner_user_id<>%d", array_merge( $args, array( $user ) ), 's.id', 'c.full_name', 's.created_at', 'adc-onyx-documents', 'sale_id' );
		}
		if ( isset( $queues['sales'] ) ) { $queues['sales']['target'] = 'adc-approvals'; $queues['sales']['extra'] = array( 'section'=>'sales' ); }
		if ( \AutoDealership\Pricing\QuoteHistory::can_read() ) {
			list( $scope, $args ) = self::scope( 'r.branch_id', $branch, 'r.owner_user_id', true );
			$add( 'reservations', 'حجوزات تنتهي خلال 6 ساعات', 'Reservations expiring within 6 hours', "{$t('reservations')} r INNER JOIN {$t('customers')} c ON c.id=r.customer_id", "$scope AND r.status='confirmed' AND r.expires_at>%s AND r.expires_at<=%s", array_merge( $args, array( $now, gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ) ) ), 'r.id', 'c.full_name', 'r.expires_at', 'adc-sales-journey', 'reservation_id', array( 'section'=>'reservations' ) );
			$add( 'expired_reservations', 'حجوزات منتهية تنتظر المعالجة', 'Expired reservations awaiting processing', "{$t('reservations')} r INNER JOIN {$t('customers')} c ON c.id=r.customer_id", "$scope AND r.status='confirmed' AND r.expires_at<=%s", array_merge( $args, array( $now ) ), 'r.id', 'c.full_name', 'r.expires_at', 'adc-sales-journey', 'reservation_id', array( 'section'=>'reservations' ) );
		}
		if ( current_user_can( 'adc_view_finance' ) && current_user_can( 'adc_verify_payments' ) ) {
			list( $scope, $args ) = self::scope( 'v.branch_id', $branch );
			$add( 'payments', 'دفعات تنتظر مراجعتك', 'Payments awaiting your review', "{$t('payment_confirmations')} p INNER JOIN {$t('sales')} s ON s.id=p.sale_id INNER JOIN {$t('vehicles')} v ON v.id=s.vehicle_id INNER JOIN {$t('customers')} c ON c.id=s.customer_id", "$scope AND p.status='pending' AND s.status IN ('pending_approval','approved') AND p.recorded_by<>%d AND s.owner_user_id<>%d", array_merge( $args, array( $user, $user ) ), 'p.id', 'c.full_name', 'p.created_at', 'adc-payments', 'payment_id' );
			list( $scope, $args ) = self::scope( 'r.branch_id', $branch );
			$add( 'deposits', 'عربون ينتظر مراجعتك', 'Deposits awaiting your review', "{$t('reservation_deposits')} p INNER JOIN {$t('reservations')} r ON r.id=p.reservation_id INNER JOIN {$t('vehicles')} v ON v.id=r.vehicle_id INNER JOIN {$t('customers')} c ON c.id=r.customer_id", "$scope AND p.status='pending' AND r.status='confirmed' AND r.expires_at>%s AND p.recorded_by<>%d AND r.owner_user_id<>%d", array_merge( $args, array( $now, $user, $user ) ), 'p.id', 'c.full_name', 'p.created_at', 'adc-payments', 'reservation_id' );
			$queues['deposits']['target_key'] = 'deposit_id';
		}
		if ( current_user_can( 'adc_view_finance' ) && current_user_can( 'adc_manage_finance' ) ) {
			list( $scope, $args ) = self::scope( 'v.branch_id', $branch );
			$add( 'finance', 'تمويل ينتظر قرارك', 'Finance awaiting your decision', "{$t('finance_requests')} f INNER JOIN {$t('sales')} s ON s.id=f.sale_id INNER JOIN {$t('vehicles')} v ON v.id=s.vehicle_id INNER JOIN {$t('customers')} c ON c.id=s.customer_id", "$scope AND f.status IN ('submitted','under_review') AND f.requested_by<>%d", array_merge( $args, array( $user ) ), 'f.id', 'c.full_name', 'f.created_at', 'adc-finance', 'finance_id' );
		}
		if ( current_user_can( 'adc_approve_delivery' ) || current_user_can( 'adc_confirm_vehicle_vin' ) ) {
			list( $scope, $args ) = self::scope( 'v.branch_id', $branch );
			$add( 'delivery', 'تسليمات قيد التجهيز', 'Deliveries in progress', "{$t('deliveries')} d INNER JOIN {$t('sales')} s ON s.id=d.sale_id INNER JOIN {$t('vehicles')} v ON v.id=s.vehicle_id INNER JOIN {$t('customers')} c ON c.id=s.customer_id", "$scope AND s.status='approved' AND d.status IN ('preparing','vin_confirmed','approved')", $args, 'd.id', 'c.full_name', 'd.created_at', 'adc-delivery', 'delivery_id' );
		}
		return $queues;
	}

	public static function snapshot() {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_workspace' ) ) { return self::error( 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 503 ); }
		list( $where, $args ) = BranchScope::predicate( 'id' );
		$sql = 'SELECT id,name FROM ' . Schema::table( 'branches' ) . " WHERE active=1 AND $where ORDER BY name,id";
		$branches = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 503 ); }
		$branch = filter_var( self::field( 'workspace_branch', '0' ), FILTER_VALIDATE_INT, array( 'options'=>array( 'min_range'=>0 ) ) );
		if ( false === $branch || ( $branch && ! in_array( $branch, array_map( 'intval', array_column( $branches, 'id' ) ), true ) ) ) { return self::error( 403 ); }
		$period = self::field( 'workspace_period', 'today' );
		$days = array( 'today'=>1, 'week'=>7, 'month'=>30 );
		if ( ! isset( $days[$period] ) ) { $period = 'today'; }
		$queues = self::definitions( $branch, $days[$period] );
		$search = mb_substr( sanitize_text_field( self::field( 'workspace_search' ) ), 0, 120 );
		if ( '' !== $search ) {
			foreach ( $queues as &$queue ) {
				$queue['where'] .= " AND {$queue['label']} LIKE %s";
				$queue['args'][] = '%' . $wpdb->esc_like( $search ) . '%';
			} unset( $queue );
		}
		$selected = sanitize_key( self::field( 'workspace_task' ) );
		if ( $selected && ! isset( $queues[$selected] ) ) { return self::error( 403 ); }
		foreach ( $queues as &$queue ) {
			$sql = "SELECT COUNT(*) FROM {$queue['from']} WHERE {$queue['where']}";
			$count = $wpdb->get_var( $queue['args'] ? $wpdb->prepare( $sql, $queue['args'] ) : $sql );
			$queue['count'] = $wpdb->last_error || null === $count ? null : (int) $count;
		} unset( $queue );
		if ( ! $selected ) {
			foreach ( $queues as $key=>$queue ) { if ( $queue['count'] > 0 ) { $selected = $key; break; } }
			$selected = $selected ?: (string) array_key_first( $queues );
		}
		$count = $queues[$selected]['count'] ?? null;
		$pages = max( 1, (int) ceil( ( $count ?? 0 ) / self::PAGE_SIZE ) );
		$page = min( $pages, max( 1, (int) self::field( 'workspace_page', '1' ) ) );
		$rows = array(); $error = false;
		if ( null !== $count && $count > 0 ) {
			$q = $queues[$selected]; $target = $q['target_id'] ?? $q['id'];
			$context = $q['context'] ?? '0 owner_id,0 customer_id';
			$sql = "SELECT {$q['id']} id,{$q['label']} label,{$q['date']} event_at,$target target_id,$context FROM {$q['from']} WHERE {$q['where']} ORDER BY {$q['date']} ASC,{$q['id']} ASC LIMIT %d OFFSET %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $q['args'], array( self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ) ) ), ARRAY_A ) ?: array();
			$error = (bool) $wpdb->last_error;
		}
		// Keep SQL and authorization predicates server-side, outside the view model.
		foreach ( $queues as &$queue ) { $queue = array_intersect_key( $queue, array_flip( array( 'title','count','target','target_key','extra','has_owner' ) ) ); } unset( $queue );
		return compact( 'branches','branch','period','search','queues','selected','rows','error','page','pages' );
	}

	private static function error( int $status ): \WP_Error {
		return new \WP_Error( 'adc_workspace_unavailable', self::t( 'تعذر تحميل الملخص ضمن الصلاحيات والإعدادات الحالية.', 'The overview could not be loaded within your current permissions and configuration.' ), array( 'status'=>$status ) );
	}
}
