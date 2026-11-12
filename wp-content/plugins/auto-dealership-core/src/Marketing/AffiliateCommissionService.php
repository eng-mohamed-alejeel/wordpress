<?php
namespace AutoDealership\Marketing;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Core\CurrencySettings;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Pricing\Money;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

final class AffiliateCommissionService {
	private const MAX_AMOUNT = 1000000000;
	private const ENTRY_ACCRUAL = 'accrual';
	private const ENTRY_VOID_REVERSAL = 'void_reversal';
	private const ENTRY_RETURN_REVERSAL = 'return_reversal';
	private const STATUSES = array( 'pending_review','approved','paid','void','paid_sale_returned' );
	private const FINGERPRINT_OPTION = 'adc_affiliate_entry_fingerprint_enabled';
	private const METADATA_BATCH = 200;

	public static function boot(): void {
		add_action( 'adc_affiliate_commission_entry_metadata_backfill', array( self::class, 'run_metadata_backfill' ) );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && Schema::is_ready() && ! wp_next_scheduled( 'adc_affiliate_commission_entry_metadata_backfill' ) ) {
			wp_schedule_single_event( time() + 30, 'adc_affiliate_commission_entry_metadata_backfill' );
		}
	}

	public static function statuses(): array { return self::STATUSES; }
	public static function currency(): string { return CurrencySettings::base_code(); }
	public static function is_supported_currency( string $currency ): bool { return self::currency() === strtoupper( trim( $currency ) ); }

	public static function fingerprint_enabled(): bool {
		return true;
	}

	public static function set_fingerprint_enabled( bool $enabled ) {
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_commission_forbidden', __( 'لا تملك صلاحية تعديل إعدادات الحركات المالية.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! $enabled ) {
			return new \WP_Error( 'adc_commission_fingerprint_required', __( 'لا يمكن تعطيل بصمة الحركات المالية؛ فهي جزء من سلامة السجل المالي.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( ! update_option( self::FINGERPRINT_OPTION, true, false ) && ! self::fingerprint_enabled() ) {
			return new \WP_Error( 'adc_commission_fingerprint_setting_failed', __( 'تعذر حفظ إعداد بصمة الحركات المالية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return $enabled;
	}

	public static function configured_amount(): int {
		return Money::parse( get_option( 'adc_affiliate_commission_amount', 0 ) ) ?? 0;
	}

	public static function set_configured_amount( int $amount ) {
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_commission_forbidden', __( 'لا تملك صلاحية إعداد العمولات.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		if ( $amount < 0 || $amount > self::MAX_AMOUNT ) {
			return new \WP_Error( 'adc_commission_amount_invalid', sprintf( __( 'قيمة العمولة يجب أن تكون بين صفر و10,000,000 %s.', 'auto-dealership-core' ), self::currency() ), array( 'status'=>400 ) );
		}
		$before = self::configured_amount();
		if ( ! update_option( 'adc_affiliate_commission_amount', $amount, false ) && $before !== $amount ) {
			return new \WP_Error( 'adc_commission_setting_failed', __( 'تعذر حفظ قيمة العمولة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		if ( ! AuditLog::record( 'affiliate_commission.rate_changed', 'affiliate_commission', 0, '', array( 'amount'=>$before, 'currency'=>self::currency() ), array( 'amount'=>$amount, 'currency'=>self::currency() ) ) ) {
			update_option( 'adc_affiliate_commission_amount', $before, false );
			if ( self::configured_amount() !== $before ) {
				error_log( 'Auto Dealership Core: failed to restore affiliate commission rate after audit failure.' );
				return new \WP_Error( 'adc_commission_setting_rollback_failed', __( 'تعذر توثيق الإعداد أو استعادة القيمة السابقة. أوقفوا معالجة العمولات وافحصوا سجل قاعدة البيانات.', 'auto-dealership-core' ), array( 'status'=>500 ) );
			}
			return new \WP_Error( 'adc_commission_setting_audit_failed', __( 'تعذر توثيق الإعداد؛ أُعيدت القيمة السابقة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return array( 'amount'=>$amount, 'currency'=>self::currency() );
	}

	/** Called inside the delivery release transaction; null means the sale is not attributed. */
	public static function record_delivery( int $sale_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT s.id,s.status,s.vehicle_id,v.branch_id,q.id quotation_id,q.marketing_lead_id,q.affiliate_user_id,q.affiliate_commission_amount,q.currency_code FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.id=%d FOR UPDATE',
			$sale_id
		), ARRAY_A );
		if ( ! $row || 'delivered' !== $row['status'] ) { return false; }
		$lead_id = (int) $row['marketing_lead_id'];
		$affiliate_id = (int) $row['affiliate_user_id'];
		$amount = (int) $row['affiliate_commission_amount'];
		$currency = strtoupper( (string) ( $row['currency_code'] ?: self::currency() ) );
		if ( 0 === $lead_id && 0 === $affiliate_id && 0 === $amount ) { return null; }
		if ( $lead_id < 1 || $affiliate_id < 1 || $amount < 1 || ! self::is_supported_currency( $currency ) || ! PaymentService::is_settled( $sale_id ) || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'affiliate_commissions' ) . ' WHERE sale_id=%d', $sale_id ) ) ) { return false; }
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( Schema::table( 'affiliate_commissions' ), array(
			'sale_id'=>$sale_id,
			'quotation_id'=>(int) $row['quotation_id'],
			'lead_id'=>$lead_id,
			'affiliate_user_id'=>$affiliate_id,
			'branch_id'=>(int) $row['branch_id'],
			'amount'=>$amount,
			'currency_code'=>$currency,
			'status'=>'pending_review',
			'created_at'=>$now,
			'updated_at'=>$now,
		), array( '%d','%d','%d','%d','%d','%d','%s','%s','%s','%s' ) );
		if ( 1 !== $inserted ) { return false; }
		$commission_id = (int) $wpdb->insert_id;
		if ( ! self::insert_entry( array(
			'commission_id'=>$commission_id,
			'sale_id'=>$sale_id,
			'affiliate_user_id'=>$affiliate_id,
			'branch_id'=>(int) $row['branch_id'],
			'entry_type'=>self::ENTRY_ACCRUAL,
			'amount'=>$amount,
			'currency_code'=>$currency,
			'event_key'=>hash( 'sha256', 'accrual:' . $sale_id ),
			'reason'=>'Commission accrued on delivered and settled sale.',
		) ) ) { return false; }
		if ( ! \AutoDealership\Operations\FollowUpNotifications::queue_affiliate_commission( $commission_id, 'created' ) ) { return false; }
		return array( 'id'=>$commission_id, 'sale_id'=>$sale_id, 'affiliate_user_id'=>$affiliate_id, 'amount'=>$amount, 'currency'=>$currency );
	}

	/** Keep returned sales from remaining payable; paid records are flagged for financial reconciliation. */
	public static function record_return( int $sale_id ) {
		global $wpdb;
		$table = Schema::table( 'affiliate_commissions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,sale_id,affiliate_user_id,branch_id,amount,currency_code,status FROM $table WHERE sale_id=%d FOR UPDATE", $sale_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return false; }
		if ( ! $row || ! in_array( $row['status'], array( 'pending_review', 'approved', 'paid' ), true ) ) { return null; }
		if ( ! self::is_supported_currency( (string) ( $row['currency_code'] ?: self::currency() ) ) || (int) $row['amount'] < 1 ) { return false; }
		$source_entry_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'affiliate_commission_entries' ) . " WHERE commission_id=%d AND entry_type IN ('accrual','legacy_opening') AND amount>0 ORDER BY id ASC LIMIT 1", (int) $row['id'] ) );
		if ( $source_entry_id < 1 ) { return false; }
		$next = 'paid' === $row['status'] ? 'paid_sale_returned' : 'void';
		$now = current_time( 'mysql', true );
		$changes = array( 'status'=>$next, 'updated_at'=>$now );
		$formats = array( '%s','%s' );
		if ( 'void' === $next ) { $changes['reviewed_by'] = get_current_user_id(); $changes['reviewed_at'] = $now; $formats[] = '%d'; $formats[] = '%s'; }
		$updated = $wpdb->update( $table, $changes, array( 'id'=>(int) $row['id'], 'status'=>$row['status'] ), $formats, array( '%d','%s' ) );
		if ( 1 !== $updated ) { return false; }
		if ( ! self::insert_entry( array(
			'commission_id'=>(int) $row['id'],
			'sale_id'=>(int) $row['sale_id'],
			'affiliate_user_id'=>(int) $row['affiliate_user_id'],
			'branch_id'=>(int) $row['branch_id'],
			'entry_type'=>self::ENTRY_RETURN_REVERSAL,
			'amount'=>-(int) $row['amount'],
			'currency_code'=>(string) ( $row['currency_code'] ?: self::currency() ),
			'reverses_entry_id'=>$source_entry_id,
			'event_key'=>hash( 'sha256', 'return:' . $sale_id ),
			'reason'=>'Commission reversed because the delivered sale was returned.',
		) ) ) { return false; }
		if ( ! \AutoDealership\Operations\FollowUpNotifications::queue_affiliate_commission( (int) $row['id'], 'returned' ) ) { return false; }
		return array( 'id'=>(int) $row['id'], 'before'=>$row['status'], 'after'=>$next, 'amount'=>(int) $row['amount'], 'currency'=>(string) ( $row['currency_code'] ?: self::currency() ) );
	}

	public static function transition( int $commission_id, string $target, string $reason = '', string $payment_reference = '' ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_commission_forbidden', __( 'لا تملك صلاحية مراجعة العمولات.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		$target = sanitize_key( $target );
		$reason = sanitize_textarea_field( $reason );
		$payment_reference = preg_replace( '/\s+/u', ' ', trim( sanitize_text_field( $payment_reference ) ) );
		if ( ! in_array( $target, array( 'approved', 'paid', 'void' ), true ) || ( 'paid' === $target && '' === $payment_reference ) || ( 'void' === $target && '' === $reason ) ) {
			return new \WP_Error( 'adc_commission_action_invalid', __( 'بيانات قرار العمولة غير مكتملة.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		$table = Schema::table( 'affiliate_commissions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d FOR UPDATE", $commission_id ), ARRAY_A );
		$expected = 'approved' === $target || 'void' === $target ? 'pending_review' : 'approved';
		if ( 'void' === $target && 'approved' === ( $row['status'] ?? '' ) ) { $expected = 'approved'; }
		if ( ! $row || $row['status'] !== $expected || (int) $row['affiliate_user_id'] === get_current_user_id() || ! BranchScope::allows( (int) $row['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_commission_transition_denied', __( 'حالة العمولة لا تسمح بهذا القرار أو لا يجوز اعتماد عمولتك بنفسك.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		if ( ! self::is_supported_currency( (string) ( $row['currency_code'] ?: self::currency() ) ) || (int) $row['amount'] < 1 || ! in_array( $row['status'], self::STATUSES, true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_commission_data_invalid', 'The commission contains an unsupported currency, amount or status.', array( 'status'=>409 ) );
		}
		if ( 'paid' === $target && (int) $row['reviewed_by'] === get_current_user_id() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_commission_separation_denied', __( 'يجب أن يسجل الصرف موظف مختلف عن معتمد الاستحقاق.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		if ( 'paid' === $target ) {
			$payment_reference_hash = hash( 'sha256', $payment_reference );
			$duplicate_reference = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id<>%d AND payment_reference=%s AND payment_reference<>'' LIMIT 1", $commission_id, $payment_reference ) );
			$duplicate_hash = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id<>%d AND payment_reference_hash=%s LIMIT 1", $commission_id, $payment_reference_hash ) );
			if ( $duplicate_reference || $duplicate_hash ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_commission_payment_reference_duplicate', 'Payment reference is already used by another commission.', array( 'status'=>409 ) );
			}
		}
		if ( in_array( $target, array( 'approved', 'paid' ), true ) ) {
			$sale_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'sales' ) . ' WHERE id=%d', (int) $row['sale_id'] ) );
			if ( 'delivered' !== $sale_status || ! PaymentService::is_settled( (int) $row['sale_id'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_commission_sale_unsettled', __( 'لا يمكن اعتماد أو صرف عمولة لصفقة غير مسلّمة أو غير مسددة بالكامل.', 'auto-dealership-core' ), array( 'status'=>409 ) );
			}
		}
		$now = current_time( 'mysql', true );
		$changes = array( 'status'=>$target, 'updated_at'=>$now );
		$formats = array( '%s','%s' );
		if ( 'approved' === $target || 'void' === $target ) { $changes['reviewed_by'] = get_current_user_id(); $changes['reviewed_at'] = $now; $formats[] = '%d'; $formats[] = '%s'; }
		if ( 'paid' === $target ) { $changes['paid_by'] = get_current_user_id(); $changes['paid_at'] = $now; $changes['payment_reference'] = $payment_reference; $changes['payment_reference_hash'] = hash( 'sha256', $payment_reference ); $formats[] = '%d'; $formats[] = '%s'; $formats[] = '%s'; $formats[] = '%s'; }
		$updated = $wpdb->update( $table, $changes, array( 'id'=>$commission_id, 'status'=>$expected ), $formats, array( '%d','%s' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			if ( 'paid' === $target && self::is_duplicate_key_error( (string) $wpdb->last_error ) ) {
				return new \WP_Error( 'adc_commission_payment_reference_duplicate', 'Payment reference is already used by another commission.', array( 'status'=>409 ) );
			}
			return new \WP_Error( 'adc_commission_update_failed', __( 'تعذر حفظ قرار العمولة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		$source_entry_id = 0;
		if ( 'void' === $target ) {
			$source_entry_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'affiliate_commission_entries' ) . " WHERE commission_id=%d AND entry_type IN ('accrual','legacy_opening') AND amount>0 ORDER BY id ASC LIMIT 1", $commission_id ) );
			if ( $source_entry_id < 1 ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_commission_source_entry_missing', 'Cannot reverse a commission without its original accrual entry.', array( 'status'=>409 ) );
			}
		}
		if ( 'void' === $target && ! self::insert_entry( array(
			'commission_id'=>$commission_id,
			'sale_id'=>(int) $row['sale_id'],
			'affiliate_user_id'=>(int) $row['affiliate_user_id'],
			'branch_id'=>(int) $row['branch_id'],
			'entry_type'=>self::ENTRY_VOID_REVERSAL,
			'amount'=>-(int) $row['amount'],
			'currency_code'=>(string) ( $row['currency_code'] ?: self::currency() ),
			'reverses_entry_id'=>$source_entry_id,
			'event_key'=>hash( 'sha256', 'void:' . $commission_id ),
			'reason'=>$reason,
		) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_commission_entry_failed', __( 'تعذر تسجيل التسوية المالية للعمولة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		if ( ! \AutoDealership\Operations\FollowUpNotifications::queue_affiliate_commission( $commission_id, 'status_' . $target ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_commission_notification_failed', 'Unable to queue the commission notification.', array( 'status'=>500 ) );
		}
		if ( ! Transaction::commit( static fn(): bool => AuditLog::record( 'affiliate_commission.' . $target, 'affiliate_commission', $commission_id, $reason, array( 'status'=>$expected, 'amount'=>(int) $row['amount'], 'currency'=>(string) ( $row['currency_code'] ?: self::currency() ) ), array( 'status'=>$target, 'amount'=>(int) $row['amount'], 'currency'=>(string) ( $row['currency_code'] ?: self::currency() ), 'payment_reference'=>'paid' === $target ? $payment_reference : '' ) ) ) ) {
			return new \WP_Error( 'adc_commission_update_failed', __( 'تعذر حفظ أو توثيق قرار العمولة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return array( 'id'=>$commission_id, 'status'=>$target );
	}

	public static function own_sales() {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_affiliate_commissions' ) ) { return array(); }
		list( $scope, $args ) = BranchScope::predicate( 'q.branch_id' );
		$sql = 'SELECT q.id quotation_id,q.quote_number,q.status quote_status,q.currency_code,q.created_at,s.id sale_id,s.status sale_status,c.status commission_status,c.amount commission_amount,c.currency_code commission_currency FROM ' . Schema::table( 'quotations' ) . ' q LEFT JOIN ' . Schema::table( 'sales' ) . ' s ON s.quotation_id=q.id LEFT JOIN ' . Schema::table( 'affiliate_commissions' ) . ' c ON c.sale_id=s.id WHERE q.affiliate_user_id=%d AND ' . $scope . ' ORDER BY q.id DESC LIMIT 200';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( get_current_user_id() ), $args ) ), ARRAY_A );
		return $wpdb->last_error ? new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل صفقاتك وعمولاتك.', 'auto-dealership-core' ), array( 'status'=>500 ) ) : ( $rows ?: array() );
	}

	/** Paginated referred-sale history for the affiliate's own administration view. */
	public static function own_sales_page( array $filters = array() ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_affiliate_commissions' ) ) { return new \WP_Error( 'adc_commission_forbidden', 'Affiliate commission permission is required.', array( 'status'=>403 ) ); }
		$page = max( 1, absint( $filters['page'] ?? 1 ) ); $per_page = min( 100, max( 1, absint( $filters['per_page'] ?? 25 ) ) );
		list( $scope, $scope_args ) = BranchScope::predicate( 'q.branch_id' ); $where = 'q.affiliate_user_id=%d AND ' . $scope; $args = array_merge( array( get_current_user_id() ), $scope_args );
		$quotes = Schema::table( 'quotations' ); $sales = Schema::table( 'sales' ); $commissions = Schema::table( 'affiliate_commissions' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $quotes q WHERE $where", $args ) );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_query_failed', 'Unable to load referred sales.', array( 'status'=>500 ) ); }
		$sql = "SELECT q.id quotation_id,q.quote_number,q.status quote_status,q.currency_code,q.created_at,s.id sale_id,s.status sale_status,c.status commission_status,c.amount commission_amount,c.currency_code commission_currency FROM $quotes q LEFT JOIN $sales s ON s.quotation_id=q.id LEFT JOIN $commissions c ON c.sale_id=s.id WHERE $where ORDER BY q.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_query_failed', 'Unable to load referred sales.', array( 'status'=>500 ) ); }
		return array( 'items'=>$rows ?: array(), 'page'=>$page, 'per_page'=>$per_page, 'total'=>$total, 'pages'=>(int) ceil( $total / $per_page ), 'currency'=>self::currency() );
	}

	public static function all_commissions() {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) { return array(); }
		list( $scope, $args ) = BranchScope::predicate( 'c.branch_id' );
		$sql = 'SELECT c.id,c.sale_id,c.quotation_id,c.lead_id,c.affiliate_user_id,c.branch_id,c.amount,c.currency_code,c.status,c.payment_reference,c.created_at,u.display_name FROM ' . Schema::table( 'affiliate_commissions' ) . ' c LEFT JOIN ' . $wpdb->users . ' u ON u.ID=c.affiliate_user_id WHERE ' . $scope . ' ORDER BY c.id DESC LIMIT 200';
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		return $wpdb->last_error ? new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل سجل العمولات.', 'auto-dealership-core' ), array( 'status'=>500 ) ) : ( $rows ?: array() );
	}

	/** Return the current user's financial summary without exposing actor-controlled IDs. */
	public static function account_summary( int $affiliate_user_id = 0 ) {
		global $wpdb;
		$can_manage = current_user_can( 'adc_manage_affiliate_commissions' ) || current_user_can( 'manage_options' );
		if ( ! $can_manage ) { $affiliate_user_id = get_current_user_id(); }
		if ( ( $affiliate_user_id < 1 && ! $can_manage ) || ( ! $can_manage && ! current_user_can( 'adc_view_own_affiliate_commissions' ) ) ) {
			return new \WP_Error( 'adc_commission_forbidden', __( 'لا تملك صلاحية عرض ملخص العمولات.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		list( $scope, $args ) = BranchScope::predicate( 'c.branch_id' );
		$affiliate_where = '1=1';
		if ( $affiliate_user_id > 0 ) { $affiliate_where = 'c.affiliate_user_id=%d'; array_unshift( $args, $affiliate_user_id ); }
		$commissions = Schema::table( 'affiliate_commissions' );
		$currency_sql = "SELECT DISTINCT c.currency_code FROM $commissions c WHERE $affiliate_where AND $scope";
		$currency_rows = $wpdb->get_col( $args ? $wpdb->prepare( $currency_sql, $args ) : $currency_sql );
		$entries = Schema::table( 'affiliate_commission_entries' );
		$entry_currency_sql = "SELECT DISTINCT e.currency_code FROM $entries e INNER JOIN $commissions c ON c.id=e.commission_id WHERE $affiliate_where AND $scope";
		$entry_currency_rows = $wpdb->get_col( $args ? $wpdb->prepare( $entry_currency_sql, $args ) : $entry_currency_sql );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_query_failed', 'Unable to validate affiliate ledger currencies.', array( 'status'=>500 ) ); }
		$currency_rows = array_values( array_unique( array_merge( array_map( 'strtoupper', $currency_rows ), array_map( 'strtoupper', $entry_currency_rows ?: array() ) ) ) );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_query_failed', __( 'تعذر التحقق من عملة ملخص العمولات.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		if ( count( $currency_rows ) > 1 || ( $currency_rows && self::currency() !== strtoupper( (string) $currency_rows[0] ) ) ) { return new \WP_Error( 'adc_commission_currency_mixed', sprintf( __( 'لا يمكن عرض ملخص %s لأن بيانات الحساب تحتوي على عملات مختلطة أو غير مدعومة.', 'auto-dealership-core' ), self::currency() ), array( 'status'=>409 ) ); }
		$sql = "SELECT
			COALESCE(SUM(CASE WHEN c.status='pending_review' THEN c.amount ELSE 0 END),0) pending_review,
			COALESCE(SUM(CASE WHEN c.status='approved' THEN c.amount ELSE 0 END),0) approved,
			COALESCE(SUM(CASE WHEN c.status='paid' THEN c.amount ELSE 0 END),0) paid,
			COALESCE(SUM(CASE WHEN c.status='void' THEN c.amount ELSE 0 END),0) voided,
			COALESCE(SUM(CASE WHEN c.status='paid_sale_returned' THEN c.amount ELSE 0 END),0) returned,
			COUNT(c.id) commission_count
			FROM $commissions c
			WHERE $affiliate_where AND $scope";
		$row = $wpdb->get_row( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		if ( $wpdb->last_error || ! $row ) { return new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل ملخص العمولات.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		$entries = Schema::table( 'affiliate_commission_entries' );
		$entry_sql = "SELECT COALESCE(SUM(e.amount),0) net_ledger FROM $entries e INNER JOIN $commissions c ON c.id=e.commission_id WHERE $affiliate_where AND $scope";
		$entry = $wpdb->get_row( $args ? $wpdb->prepare( $entry_sql, $args ) : $entry_sql, ARRAY_A );
		if ( $wpdb->last_error || ! $entry ) { return new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل حركات العمولات.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		$integrity_sql = "SELECT COUNT(*) FROM $commissions c WHERE $affiliate_where AND $scope AND (
			c.currency_code<>%s OR c.status NOT IN ('pending_review','approved','paid','void','paid_sale_returned') OR c.amount<0 OR (c.amount<1 AND c.status NOT IN ('void','paid_sale_returned'))
			OR NOT EXISTS (SELECT 1 FROM $entries missing WHERE missing.commission_id=c.id)
			OR EXISTS (SELECT 1 FROM $entries e WHERE e.commission_id=c.id AND (e.sale_id<>c.sale_id OR e.affiliate_user_id<>c.affiliate_user_id OR e.branch_id<>c.branch_id OR e.currency_code<>%s OR e.entry_type NOT IN ('accrual','legacy_opening','void_reversal','return_reversal') OR (e.entry_type IN ('accrual','legacy_opening') AND (e.amount<0 OR (e.amount<1 AND c.status NOT IN ('void','paid_sale_returned')))) OR (e.entry_type IN ('void_reversal','return_reversal') AND (e.amount>=0 OR e.reverses_entry_id=0 OR NOT EXISTS (SELECT 1 FROM $entries src WHERE src.id=e.reverses_entry_id AND src.commission_id=e.commission_id AND src.entry_type IN ('accrual','legacy_opening') AND src.amount>0)))))
			OR (c.payment_reference<>'' AND EXISTS (SELECT 1 FROM $commissions dupe WHERE dupe.id<>c.id AND dupe.payment_reference=c.payment_reference))
		)";
		$integrity_args = array_merge( $args, array( self::currency(), self::currency() ) );
		$integrity_issues = (int) $wpdb->get_var( $wpdb->prepare( $integrity_sql, $integrity_args ) );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_query_failed', 'Unable to validate affiliate ledger integrity.', array( 'status'=>500 ) ); }
		$summary = array( 'currency'=>self::currency(), 'commission_count'=>(int) $row['commission_count'], 'pending_review'=>(int) $row['pending_review'], 'approved'=>(int) $row['approved'], 'paid'=>(int) $row['paid'], 'voided'=>(int) $row['voided'], 'returned'=>(int) $row['returned'], 'net_ledger'=>(int) $entry['net_ledger'], 'integrity_issues'=>$integrity_issues, 'payout_ready'=>0 === $integrity_issues );
		foreach ( array( 'pending_review','approved','paid','voided','returned','net_ledger' ) as $field ) { $summary[ $field . '_amount' ] = Money::decimal( $summary[ $field ] ); }
		return $summary;
	}

	/** Paginated commission ledger for REST consumers; scope is always server-derived. */
	public static function list_commissions( array $filters = array() ) {
		global $wpdb;
		$can_manage = current_user_can( 'adc_manage_affiliate_commissions' ) || current_user_can( 'manage_options' );
		if ( ! $can_manage && ! current_user_can( 'adc_view_own_affiliate_commissions' ) ) {
			return new \WP_Error( 'adc_commission_forbidden', __( 'لا تملك صلاحية عرض العمولات.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		$page = max( 1, absint( $filters['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $filters['per_page'] ?? 25 ) ) );
		$from_date = sanitize_text_field( (string) ( $filters['from'] ?? '' ) );
		$to_date = sanitize_text_field( (string) ( $filters['to'] ?? '' ) );
		$timezone = wp_timezone();
		$utc = new \DateTimeZone( 'UTC' );
		$from_utc = '';
		$to_utc = '';
		foreach ( array( $from_date, $to_date ) as $date ) {
			if ( '' !== $date ) {
				$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
				if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) { return new \WP_Error( 'adc_commission_date_invalid', __( 'تواريخ تصفية العمولات غير صالحة.', 'auto-dealership-core' ), array( 'status'=>400 ) ); }
			}
		}
		if ( '' !== $from_date && '' !== $to_date && $from_date > $to_date ) { return new \WP_Error( 'adc_commission_date_range', __( 'تاريخ البداية يجب أن يسبق تاريخ النهاية.', 'auto-dealership-core' ), array( 'status'=>400 ) ); }
		if ( '' !== $from_date ) { $from_utc = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from_date, $timezone )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ); }
		if ( '' !== $to_date ) { $to_utc = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to_date, $timezone )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ); }
		$where = array(); $args = array();
		if ( ! $can_manage || ! empty( $filters['affiliate_user_id'] ) ) { $where[] = 'c.affiliate_user_id=%d'; $args[] = $can_manage ? absint( $filters['affiliate_user_id'] ) : get_current_user_id(); }
		if ( ! empty( $filters['status'] ) ) {
			$status = sanitize_key( (string) $filters['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) { return new \WP_Error( 'adc_commission_status_invalid', __( 'حالة العمولة غير معروفة.', 'auto-dealership-core' ), array( 'status'=>400 ) ); }
			$where[] = 'c.status=%s'; $args[] = $status;
		}
		if ( '' !== $from_utc ) { $where[] = 'c.created_at>=%s'; $args[] = $from_utc; }
		if ( '' !== $to_utc ) { $where[] = 'c.created_at<%s'; $args[] = $to_utc; }
		list( $scope, $scope_args ) = BranchScope::predicate( 'c.branch_id' ); $where[] = $scope; $args = array_merge( $args, $scope_args );
		$from = Schema::table( 'affiliate_commissions' );
		$count_sql = 'SELECT COUNT(*) FROM ' . $from . ' c WHERE ' . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل سجل العمولات.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		$limit = $per_page; $offset = ( $page - 1 ) * $per_page;
		$entries = Schema::table( 'affiliate_commission_entries' );
		$sql = "SELECT c.id,c.sale_id,c.quotation_id,c.lead_id,c.affiliate_user_id,c.branch_id,c.amount,c.currency_code,c.status,c.payment_reference,c.reviewed_by,c.reviewed_at,c.paid_by,c.paid_at,c.created_at,u.display_name,(SELECT GROUP_CONCAT(e.entry_reference ORDER BY e.id ASC SEPARATOR ', ') FROM $entries e WHERE e.commission_id=c.id AND e.entry_reference<>'') entry_references FROM $from c LEFT JOIN {$wpdb->users} u ON u.ID=c.affiliate_user_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY c.id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل سجل العمولات.', 'auto-dealership-core' ), array( 'status'=>500 ) ); }
		return array( 'items'=>$rows ?: array(), 'page'=>$page, 'per_page'=>$per_page, 'total'=>$total, 'pages'=>(int) ceil( $total / $per_page ), 'currency'=>self::currency(), 'timezone'=>$timezone->getName() );
	}

	public static function run_metadata_backfill(): void {
		self::backfill_entry_metadata( self::METADATA_BATCH );
	}

	/** Internal migration hook; only metadata is written, never the financial movement itself. */
	public static function stamp_entry_metadata( int $entry_id, array $entry ): bool {
		return self::write_entry_metadata( $entry_id, $entry );
	}

	/** Backfills visible references and optional fingerprints without changing financial amounts. */
	public static function backfill_entry_metadata( int $limit = self::METADATA_BATCH ): int {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return 0; }
		$table = Schema::table( 'affiliate_commission_entries' );
		$condition = 'fingerprint IS NULL';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,commission_id,sale_id,affiliate_user_id,branch_id,entry_type,amount,currency_code,event_key,reverses_entry_id,reason,actor_user_id,created_at,entry_reference,fingerprint FROM $table WHERE entry_reference='' OR ($condition) ORDER BY id ASC LIMIT %d", min( 1000, max( 1, $limit ) ) ), ARRAY_A );
		if ( $wpdb->last_error || ! $rows ) { return 0; }
		$count = 0;
		foreach ( $rows as $row ) {
			if ( self::write_entry_metadata( (int) $row['id'], $row ) ) { ++$count; }
		}
		if ( count( $rows ) >= $limit && function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'adc_affiliate_commission_entry_metadata_backfill' ) ) {
			wp_schedule_single_event( time() + 30, 'adc_affiliate_commission_entry_metadata_backfill' );
		}
		return $count;
	}

	private static function write_entry_metadata( int $entry_id, array $entry ): bool {
		global $wpdb;
		$table = Schema::table( 'affiliate_commission_entries' );
		$reference = 'ADC-LE-' . str_pad( (string) $entry_id, 12, '0', STR_PAD_LEFT );
		$data = array( 'entry_reference' => $reference );
		$formats = array( '%s' );
		$data['fingerprint'] = hash( 'sha256', (string) wp_json_encode( array(
				'id'=>(int) $entry_id, 'commission_id'=>(int) ( $entry['commission_id'] ?? 0 ), 'sale_id'=>(int) ( $entry['sale_id'] ?? 0 ),
				'affiliate_user_id'=>(int) ( $entry['affiliate_user_id'] ?? 0 ), 'branch_id'=>(int) ( $entry['branch_id'] ?? 0 ),
				'entry_type'=>(string) ( $entry['entry_type'] ?? '' ), 'amount'=>(int) ( $entry['amount'] ?? 0 ),
				'reverses_entry_id'=>(int) ( $entry['reverses_entry_id'] ?? 0 ),
				'currency_code'=>(string) ( $entry['currency_code'] ?? self::currency() ), 'event_key'=>(string) ( $entry['event_key'] ?? '' ),
				'reason'=>(string) ( $entry['reason'] ?? '' ), 'actor_user_id'=>(int) ( $entry['actor_user_id'] ?? 0 ), 'created_at'=>(string) ( $entry['created_at'] ?? '' ),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$formats[] = '%s';
		return 1 === $wpdb->update( $table, $data, array( 'id' => $entry_id ), $formats, array( '%d' ) );
	}

	private static function insert_entry( array $entry ): bool {
		global $wpdb;
		$entry_type = (string) ( $entry['entry_type'] ?? '' );
		$amount = (int) ( $entry['amount'] ?? 0 );
		$currency = strtoupper( (string) ( $entry['currency_code'] ?? self::currency() ) );
		$valid_type = in_array( $entry_type, array( self::ENTRY_ACCRUAL, self::ENTRY_VOID_REVERSAL, self::ENTRY_RETURN_REVERSAL ), true );
		$valid_sign = self::ENTRY_ACCRUAL === $entry_type ? $amount > 0 : $amount < 0;
		$reverses_entry_id = (int) ( $entry['reverses_entry_id'] ?? 0 );
		$event_key = (string) ( $entry['event_key'] ?? '' );
		if ( ! $valid_type || ! self::is_supported_currency( $currency ) || ! $valid_sign || ( self::ENTRY_ACCRUAL !== $entry_type && $reverses_entry_id < 1 ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $event_key ) ) { return false; }
		$table = Schema::table( 'affiliate_commission_entries' );
		if ( self::ENTRY_ACCRUAL !== $entry_type ) {
			$source = $wpdb->get_row( $wpdb->prepare( "SELECT commission_id,entry_type,amount FROM $table WHERE id=%d", $reverses_entry_id ), ARRAY_A );
			if ( ! $source || (int) $source['commission_id'] !== (int) $entry['commission_id'] || ! in_array( (string) $source['entry_type'], array( self::ENTRY_ACCRUAL, 'legacy_opening' ), true ) || (int) $source['amount'] < 1 ) { return false; }
		}
		$created_at = current_time( 'mysql', true );
		$inserted = $wpdb->insert( $table, array(
			'commission_id'=>(int) $entry['commission_id'], 'sale_id'=>(int) $entry['sale_id'], 'affiliate_user_id'=>(int) $entry['affiliate_user_id'], 'branch_id'=>(int) $entry['branch_id'], 'entry_type'=>$entry_type, 'amount'=>$amount, 'currency_code'=>$currency, 'event_key'=>$event_key, 'reverses_entry_id'=>$reverses_entry_id, 'reason'=>(string) ( $entry['reason'] ?? '' ), 'actor_user_id'=>get_current_user_id(), 'created_at'=>$created_at,
		), array( '%d','%d','%d','%d','%s','%d','%s','%s','%d','%s','%d','%s' ) );
		if ( 1 === $inserted ) {
			$entry_id = (int) $wpdb->insert_id;
			return self::write_entry_metadata( $entry_id, array_merge( $entry, array( 'id' => $entry_id, 'currency_code' => $currency, 'actor_user_id' => get_current_user_id(), 'created_at' => $created_at ) ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,commission_id,sale_id,affiliate_user_id,branch_id,entry_type,amount,currency_code,reverses_entry_id FROM $table WHERE event_key=%s", $event_key ), ARRAY_A );
		if ( ! $existing ) { return false; }
		$matches = (int) $existing['commission_id'] === (int) $entry['commission_id'] && (int) $existing['sale_id'] === (int) $entry['sale_id'] && (int) $existing['affiliate_user_id'] === (int) $entry['affiliate_user_id'] && (int) $existing['branch_id'] === (int) $entry['branch_id'] && (string) $existing['entry_type'] === $entry_type && (int) $existing['amount'] === $amount && strtoupper( (string) $existing['currency_code'] ) === $currency && (int) $existing['reverses_entry_id'] === $reverses_entry_id;
		if ( ! $matches ) { error_log( 'Auto Dealership Core: affiliate ledger event key collision detected.' ); }
		return $matches;
	}

	private static function is_duplicate_key_error( string $error ): bool {
		return false !== stripos( $error, 'duplicate' ) || false !== stripos( $error, '1062' );
	}
}
