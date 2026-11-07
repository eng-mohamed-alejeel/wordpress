<?php
namespace AutoDealership\Marketing;

use AutoDealership\Database\Schema;
use AutoDealership\Operations\JobRuntime;

defined( 'ABSPATH' ) || exit;

/** Read-only pre-release checks for affiliate commission operations. */
final class AffiliateCommissionHealth {
	public static function snapshot(): array {
		$reconciliation = AffiliateCommissionReconciliation::status(); $migration = AffiliateCommissionMigration::status();
		$checks = array(
			'schema'=>array( 'label'=>'Verified database schema', 'ok'=>Schema::is_ready(), 'value'=>Schema::VERSION ),
			'currency'=>array( 'label'=>'Single supported currency', 'ok'=>AffiliateCommissionService::is_supported_currency( AffiliateCommissionService::CURRENCY ), 'value'=>AffiliateCommissionService::CURRENCY ),
			'excel'=>array( 'label'=>'Excel export dependency', 'ok'=>class_exists( 'ZipArchive' ), 'value'=>class_exists( 'ZipArchive' ) ? 'ZIP enabled' : 'ZIP missing' ),
			'admin_email'=>array( 'label'=>'Administrator email', 'ok'=>is_email( get_option( 'admin_email' ) ), 'value'=>is_email( get_option( 'admin_email' ) ) ? 'configured' : 'missing' ),
			'outbox_schedule'=>array( 'label'=>'Outbox schedule', 'ok'=>false !== wp_next_scheduled( 'adc_process_outbox' ), 'value'=>self::scheduled_value( 'adc_process_outbox' ) ),
			'reconciliation_schedule'=>array( 'label'=>'Reconciliation schedule', 'ok'=>false !== wp_next_scheduled( AffiliateCommissionReconciliation::HOOK ), 'value'=>self::scheduled_value( AffiliateCommissionReconciliation::HOOK ) ),
			'ledger_reconciliation'=>array( 'label'=>'Ledger reconciliation', 'ok'=>'healthy' === $reconciliation['status'], 'value'=>$reconciliation['status'] . ' / ' . absint( $reconciliation['total'] ) . ' findings' ),
			'migration'=>array( 'label'=>'Historical migration', 'ok'=>in_array( $migration['status'], array( 'idle','complete' ), true ), 'value'=>$migration['status'] ),
		);
		$ok = true; foreach ( $checks as $check ) { if ( ! $check['ok'] ) { $ok = false; break; } }
		return array( 'status'=>$ok ? 'ready' : 'attention', 'checks'=>$checks, 'job_runtime'=>JobRuntime::status() );
	}

	private static function scheduled_value( string $hook ): string { $next = wp_next_scheduled( $hook ); return false === $next ? 'missing' : wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ); }
}
