<?php
namespace AutoDealership\Operations;

use AutoDealership\Accounts\BrandedMail;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Leads\NewsletterService;

defined( 'ABSPATH' ) || exit;

/** Durable, deduplicated email alerts for operational failures requiring an administrator. */
final class AdministrativeAlert {
	private const OPTION = 'adc_administrative_alerts';

	public static function boot(): void { add_action( 'adc_outbox_failed', array( self::class, 'outbox_failed' ) ); }

	public static function outbox_failed( array $event ): void {
		$id = absint( $event['id'] ?? 0 ); $key = 'outbox:' . $id . ':' . sanitize_key( (string) ( $event['error_code'] ?? 'failed' ) );
		self::notify( $key, 'فشل مهمة خلفية', 'Background job failure', '<p>فشلت مهمة خلفية وتحتاج إلى مراجعة إدارية.</p><p>المعرّف: <strong>#' . $id . '</strong><br>الحدث: <strong>' . esc_html( (string) ( $event['event_key'] ?? '' ) ) . '</strong><br>الخطأ: <strong>' . esc_html( (string) ( $event['error_code'] ?? 'unknown' ) ) . '</strong></p>', '<p>A background job failed and requires administrator review.</p><p>ID: <strong>#' . $id . '</strong><br>Event: <strong>' . esc_html( (string) ( $event['event_key'] ?? '' ) ) . '</strong><br>Error: <strong>' . esc_html( (string) ( $event['error_code'] ?? 'unknown' ) ) . '</strong></p>', admin_url( 'admin.php?page=adc-outbox' ), 'adc_outbox_failed' );
	}

	public static function migration_failed( string $error, array $state = array() ): void {
		$key = 'affiliate_migration:' . sanitize_key( $error ) . ':' . gmdate( 'Y-m-d' );
		self::notify( $key, 'فشل ترحيل عمولات المسوقين', 'Affiliate commission migration failed', '<p>توقف ترحيل العمولات التاريخية ويحتاج إلى مراجعة إدارية.</p><p>رمز الخطأ: <strong>' . esc_html( $error ) . '</strong><br>تمت معالجة: <strong>' . absint( $state['processed'] ?? 0 ) . '</strong><br>المعزولة: <strong>' . absint( $state['quarantined'] ?? 0 ) . '</strong></p>', '<p>The historical affiliate commission migration stopped and requires administrator review.</p><p>Error: <strong>' . esc_html( $error ) . '</strong><br>Processed: <strong>' . absint( $state['processed'] ?? 0 ) . '</strong><br>Quarantined: <strong>' . absint( $state['quarantined'] ?? 0 ) . '</strong></p>', admin_url( 'admin.php?page=adc-affiliate-commissions' ), 'affiliate_commission_migration_failed' );
	}

	public static function reconciliation_failed( string $error, array $state = array() ): void {
		$key = 'affiliate_reconciliation:' . sanitize_key( $error ) . ':' . gmdate( 'Y-m-d' );
		self::notify( $key, 'فشل فحص تسوية عمولات المسوقين', 'Affiliate commission reconciliation failed', '<p>تعذر إكمال فحص سلامة سجل العمولات ويحتاج إلى مراجعة إدارية.</p><p>رمز الخطأ: <strong>' . esc_html( $error ) . '</strong></p>', '<p>The affiliate commission integrity scan failed and requires administrator review.</p><p>Error: <strong>' . esc_html( $error ) . '</strong></p>', admin_url( 'admin.php?page=adc-affiliate-commissions' ), 'affiliate_commission_reconciliation_failed' );
	}

	private static function notify( string $key, string $title_ar, string $title_en, string $body_ar, string $body_en, string $url, string $audit_key ): void {
		$alerts = get_option( self::OPTION, array() ); $alerts = is_array( $alerts ) ? $alerts : array(); if ( isset( $alerts[ $key ] ) ) { return; }
		$alerts[ $key ] = array( 'created_at'=>current_time( 'mysql', true ), 'event'=>$audit_key, 'sent'=>0 ); if ( count( $alerts ) > 100 ) { $alerts = array_slice( $alerts, -100, 100, true ); } update_option( self::OPTION, $alerts, false );
		$sent = 0; $users = get_users( array( 'capability'=>'manage_options', 'fields'=>array( 'ID','user_email' ) ) ); foreach ( $users as $user ) { $language = NewsletterService::language( (string) get_user_meta( (int) $user->ID, 'adc_preferred_language', true ) ); $ar = 'ar' === $language; $subject = $ar ? $title_ar : $title_en; $html = BrandedMail::render( $subject, $ar ? $body_ar : $body_en, $ar ? 'فتح لوحة المراجعة' : 'Open review dashboard', $url, '', $language ); if ( is_email( $user->user_email ) && BrandedMail::send( $user->user_email, $subject, $html ) ) { ++$sent; } }
		$alerts[ $key ]['sent'] = $sent; update_option( self::OPTION, $alerts, false ); AuditLog::record( $audit_key, 'administrative_alert', 0, 'Operational administrator alert emitted', null, array( 'alert_key'=>hash( 'sha256', $key ), 'recipient_count'=>$sent ) );
	}
}
