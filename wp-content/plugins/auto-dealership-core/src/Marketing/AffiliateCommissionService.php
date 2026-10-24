<?php
namespace AutoDealership\Marketing;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Pricing\Money;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

final class AffiliateCommissionService {
	private const MAX_AMOUNT = 1000000000;

	public static function configured_amount(): int {
		return Money::parse( get_option( 'adc_affiliate_commission_amount', 0 ) ) ?? 0;
	}

	public static function set_configured_amount( int $amount ) {
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_commission_forbidden', __( 'لا تملك صلاحية إعداد العمولات.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		if ( $amount < 0 || $amount > self::MAX_AMOUNT ) {
			return new \WP_Error( 'adc_commission_amount_invalid', __( 'قيمة العمولة يجب أن تكون بين صفر و10,000,000 ريال.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		$before = self::configured_amount();
		if ( ! update_option( 'adc_affiliate_commission_amount', $amount, false ) && $before !== $amount ) {
			return new \WP_Error( 'adc_commission_setting_failed', __( 'تعذر حفظ قيمة العمولة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		if ( ! AuditLog::record( 'affiliate_commission.rate_changed', 'affiliate_commission', 0, '', array( 'amount'=>$before ), array( 'amount'=>$amount, 'currency'=>'SAR' ) ) ) {
			update_option( 'adc_affiliate_commission_amount', $before, false );
			if ( self::configured_amount() !== $before ) {
				error_log( 'Auto Dealership Core: failed to restore affiliate commission rate after audit failure.' );
				return new \WP_Error( 'adc_commission_setting_rollback_failed', __( 'تعذر توثيق الإعداد أو استعادة القيمة السابقة. أوقفوا معالجة العمولات وافحصوا سجل قاعدة البيانات.', 'auto-dealership-core' ), array( 'status'=>500 ) );
			}
			return new \WP_Error( 'adc_commission_setting_audit_failed', __( 'تعذر توثيق الإعداد؛ أُعيدت القيمة السابقة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return array( 'amount'=>$amount, 'currency'=>'SAR' );
	}

	/** Called inside the delivery release transaction; null means the sale is not attributed. */
	public static function record_delivery( int $sale_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT s.id,s.status,s.vehicle_id,v.branch_id,q.id quotation_id,q.marketing_lead_id,q.affiliate_user_id,q.affiliate_commission_amount FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.id=%d FOR UPDATE',
			$sale_id
		), ARRAY_A );
		if ( ! $row || 'delivered' !== $row['status'] ) { return false; }
		$lead_id = (int) $row['marketing_lead_id'];
		$affiliate_id = (int) $row['affiliate_user_id'];
		$amount = (int) $row['affiliate_commission_amount'];
		if ( 0 === $lead_id && 0 === $affiliate_id && 0 === $amount ) { return null; }
		if ( $lead_id < 1 || $affiliate_id < 1 || $amount < 1 || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'affiliate_commissions' ) . ' WHERE sale_id=%d', $sale_id ) ) ) { return false; }
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( Schema::table( 'affiliate_commissions' ), array(
			'sale_id'=>$sale_id,
			'quotation_id'=>(int) $row['quotation_id'],
			'lead_id'=>$lead_id,
			'affiliate_user_id'=>$affiliate_id,
			'branch_id'=>(int) $row['branch_id'],
			'amount'=>$amount,
			'status'=>'pending_review',
			'created_at'=>$now,
			'updated_at'=>$now,
		), array( '%d','%d','%d','%d','%d','%d','%s','%s','%s' ) );
		if ( 1 !== $inserted ) { return false; }
		return array( 'id'=>(int) $wpdb->insert_id, 'sale_id'=>$sale_id, 'affiliate_user_id'=>$affiliate_id, 'amount'=>$amount );
	}

	/** Keep returned sales from remaining payable; paid records are flagged for financial reconciliation. */
	public static function record_return( int $sale_id ) {
		global $wpdb;
		$table = Schema::table( 'affiliate_commissions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,status FROM $table WHERE sale_id=%d FOR UPDATE", $sale_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return false; }
		if ( ! $row || ! in_array( $row['status'], array( 'pending_review', 'approved', 'paid' ), true ) ) { return null; }
		$next = 'paid' === $row['status'] ? 'paid_sale_returned' : 'void';
		$now = current_time( 'mysql', true );
		$changes = array( 'status'=>$next, 'updated_at'=>$now );
		$formats = array( '%s','%s' );
		if ( 'void' === $next ) { $changes['reviewed_by'] = get_current_user_id(); $changes['reviewed_at'] = $now; $formats[] = '%d'; $formats[] = '%s'; }
		$updated = $wpdb->update( $table, $changes, array( 'id'=>(int) $row['id'], 'status'=>$row['status'] ), $formats, array( '%d','%s' ) );
		return 1 === $updated ? array( 'id'=>(int) $row['id'], 'before'=>$row['status'], 'after'=>$next ) : false;
	}

	public static function transition( int $commission_id, string $target, string $reason = '', string $payment_reference = '' ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_commission_forbidden', __( 'لا تملك صلاحية مراجعة العمولات.', 'auto-dealership-core' ), array( 'status'=>403 ) );
		}
		$target = sanitize_key( $target );
		$reason = sanitize_textarea_field( $reason );
		$payment_reference = sanitize_text_field( $payment_reference );
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
		if ( 'paid' === $target && (int) $row['reviewed_by'] === get_current_user_id() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_commission_separation_denied', __( 'يجب أن يسجل الصرف موظف مختلف عن معتمد الاستحقاق.', 'auto-dealership-core' ), array( 'status'=>409 ) );
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
		if ( 'paid' === $target ) { $changes['paid_by'] = get_current_user_id(); $changes['paid_at'] = $now; $changes['payment_reference'] = $payment_reference; $formats[] = '%d'; $formats[] = '%s'; $formats[] = '%s'; }
		$updated = $wpdb->update( $table, $changes, array( 'id'=>$commission_id, 'status'=>$expected ), $formats, array( '%d','%s' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_commission_update_failed', __( 'تعذر حفظ قرار العمولة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		if ( ! Transaction::commit( static fn(): bool => AuditLog::record( 'affiliate_commission.' . $target, 'affiliate_commission', $commission_id, $reason, array( 'status'=>$expected ), array( 'status'=>$target, 'payment_reference'=>'paid' === $target ? $payment_reference : '' ) ) ) ) {
			return new \WP_Error( 'adc_commission_update_failed', __( 'تعذر حفظ أو توثيق قرار العمولة.', 'auto-dealership-core' ), array( 'status'=>500 ) );
		}
		return array( 'id'=>$commission_id, 'status'=>$target );
	}

	public static function own_sales(): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_own_affiliate_commissions' ) ) { return array(); }
		list( $scope, $args ) = BranchScope::predicate( 'q.branch_id' );
		$sql = 'SELECT q.id quotation_id,q.quote_number,q.status quote_status,q.created_at,s.id sale_id,s.status sale_status,c.status commission_status,c.amount commission_amount FROM ' . Schema::table( 'quotations' ) . ' q LEFT JOIN ' . Schema::table( 'sales' ) . ' s ON s.quotation_id=q.id LEFT JOIN ' . Schema::table( 'affiliate_commissions' ) . ' c ON c.sale_id=s.id WHERE q.affiliate_user_id=%d AND ' . $scope . ' ORDER BY q.id DESC LIMIT 200';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( get_current_user_id() ), $args ) ), ARRAY_A );
		return $wpdb->last_error ? new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل صفقاتك وعمولاتك.', 'auto-dealership-core' ), array( 'status'=>500 ) ) : ( $rows ?: array() );
	}

	public static function all_commissions(): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) { return array(); }
		list( $scope, $args ) = BranchScope::predicate( 'c.branch_id' );
		$sql = 'SELECT c.id,c.sale_id,c.quotation_id,c.lead_id,c.affiliate_user_id,c.branch_id,c.amount,c.status,c.payment_reference,c.created_at,u.display_name FROM ' . Schema::table( 'affiliate_commissions' ) . ' c LEFT JOIN ' . $wpdb->users . ' u ON u.ID=c.affiliate_user_id WHERE ' . $scope . ' ORDER BY c.id DESC LIMIT 200';
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		return $wpdb->last_error ? new \WP_Error( 'adc_commission_query_failed', __( 'تعذر تحميل سجل العمولات.', 'auto-dealership-core' ), array( 'status'=>500 ) ) : ( $rows ?: array() );
	}
}
