<?php
namespace AutoDealership\Marketing;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Core\CurrencySettings;
use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Durable, read-only integrity scan for the affiliate commission ledger. */
final class AffiliateCommissionReconciliation {
	private const OPTION = 'adc_affiliate_commission_reconciliation';
	public const HOOK = 'adc_affiliate_commission_reconciliation';

	public static function boot(): void { add_action( self::HOOK, array( self::class, 'run_scheduled' ) ); }

	public static function status(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), array(
			'status'=>'never_run', 'finished_at'=>'', 'error'=>'', 'total'=>0,
			'commission_without_ledger'=>0, 'invalid_commission'=>0, 'migration_issues'=>0,
			'orphan_ledger_entries'=>0, 'inconsistent_entries'=>0, 'duplicate_payment_references'=>0,
		) );
	}

	public static function run_now() {
		if ( ! current_user_can( 'adc_manage_affiliate_commissions' ) && ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_affiliate_reconciliation_forbidden', 'Affiliate reconciliation permission is required.', array( 'status'=>403 ) ); }
		return self::scan();
	}

	public static function run_scheduled(): void {
		$result = self::scan();
		if ( is_wp_error( $result ) ) { \AutoDealership\Operations\AdministrativeAlert::reconciliation_failed( $result->get_error_code(), self::status() ); }
	}

	private static function scan() {
		global $wpdb;
		if ( ! Schema::is_ready() ) { $state = self::failed( 'adc_affiliate_reconciliation_schema' ); return new \WP_Error( 'adc_affiliate_reconciliation_schema', 'Affiliate reconciliation requires a verified database schema.' ); }
		$commissions = Schema::table( 'affiliate_commissions' ); $entries = Schema::table( 'affiliate_commission_entries' ); $issues = Schema::table( 'affiliate_commission_migration_issues' );
		$currency = CurrencySettings::base_code();
		if ( '' === $currency ) { return self::failed_result( 'adc_currency_required' ); }
		$queries = array(
			'commission_without_ledger'=>"SELECT COUNT(*) FROM $commissions c LEFT JOIN $entries e ON e.commission_id=c.id WHERE e.id IS NULL",
			'invalid_commission'=>$wpdb->prepare("SELECT COUNT(*) FROM $commissions c WHERE c.currency_code<>%s OR c.status NOT IN ('pending_review','approved','paid','void','paid_sale_returned') OR c.amount<0 OR (c.amount<1 AND c.status NOT IN ('void','paid_sale_returned'))", $currency),
			'migration_issues'=>"SELECT COUNT(*) FROM $issues WHERE status='open'",
			'orphan_ledger_entries'=>"SELECT COUNT(*) FROM $entries e LEFT JOIN $commissions c ON c.id=e.commission_id WHERE c.id IS NULL",
			'inconsistent_entries'=>$wpdb->prepare("SELECT COUNT(*) FROM $entries e INNER JOIN $commissions c ON c.id=e.commission_id WHERE e.sale_id<>c.sale_id OR e.affiliate_user_id<>c.affiliate_user_id OR e.branch_id<>c.branch_id OR e.currency_code<>%s OR e.entry_type NOT IN ('accrual','legacy_opening','void_reversal','return_reversal') OR (e.entry_type IN ('accrual','legacy_opening') AND e.amount<0) OR (e.entry_type IN ('void_reversal','return_reversal') AND (e.amount>=0 OR e.reverses_entry_id=0 OR NOT EXISTS (SELECT 1 FROM $entries source WHERE source.id=e.reverses_entry_id AND source.commission_id=e.commission_id AND source.amount>0)))", $currency),
			'duplicate_payment_references'=>"SELECT COUNT(*) FROM (SELECT payment_reference FROM $commissions WHERE payment_reference<>'' GROUP BY payment_reference HAVING COUNT(*)>1) duplicate_references",
		);
		$counts = array(); foreach ( $queries as $key=>$sql ) { $counts[ $key ] = (int) $wpdb->get_var( $sql ); if ( $wpdb->last_error ) { return self::failed_result( 'adc_affiliate_reconciliation_query' ); } }
		$counts['total'] = array_sum( $counts ); $state = array_merge( array( 'status'=>$counts['total'] ? 'attention' : 'healthy', 'finished_at'=>current_time( 'mysql', true ), 'error'=>'' ), $counts );
		if ( ! AuditLog::record( 'affiliate_commission.reconciliation_completed', 'affiliate_commission_reconciliation', 0, 'Affiliate commission ledger integrity scan', null, array( 'status'=>$state['status'], 'total'=>$state['total'], 'counts'=>$counts ) ) ) { return self::failed_result( 'adc_affiliate_reconciliation_audit' ); }
		update_option( self::OPTION, $state, false );
		return $state;
	}

	private static function failed( string $error ): array { $state = array_merge( self::status(), array( 'status'=>'failed', 'error'=>$error, 'finished_at'=>current_time( 'mysql', true ) ) ); update_option( self::OPTION, $state, false ); return $state; }
	private static function failed_result( string $error ) { self::failed( $error ); return new \WP_Error( $error, 'Unable to complete affiliate commission reconciliation.', array( 'status'=>500 ) ); }
}
