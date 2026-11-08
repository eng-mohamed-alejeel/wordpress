<?php
namespace AutoDealership\Operations;

use AutoDealership\Accounts\BrandedMail;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Leads\NewsletterService;

defined( 'ABSPATH' ) || exit;

/** Durable administrator alerts with history, deduplication and retry support. */
final class AdministrativeAlert {
	private const OPTION = 'adc_administrative_alerts';
	private const MAX_RECORDS = 200;

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

	public static function list( array $filters = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_admin_alert_forbidden', 'Administrator permission is required.', array( 'status' => 403 ) ); }
		$records = get_option( self::OPTION, array() ); $records = is_array( $records ) ? $records : array(); $items = array();
		foreach ( $records as $key => $record ) {
			$record = is_array( $record ) ? $record : array(); $record['key'] = (string) ( $record['key'] ?? $key );
			$status = (string) ( $record['status'] ?? ( ! empty( $record['sent'] ) ? 'sent' : 'failed' ) ); $type = (string) ( $record['event'] ?? '' ); $search = sanitize_text_field( (string) ( $filters['search'] ?? '' ) );
			if ( ! empty( $filters['status'] ) && $status !== sanitize_key( (string) $filters['status'] ) ) { continue; }
			if ( ! empty( $filters['event'] ) && $type !== sanitize_key( (string) $filters['event'] ) ) { continue; }
			if ( '' !== $search && false === stripos( $record['key'] . ' ' . $type . ' ' . (string) ( $record['title_en'] ?? '' ), $search ) ) { continue; }
			$record['status'] = $status; $record['attempts'] = absint( $record['attempts'] ?? ( ! empty( $record['sent'] ) ? 1 : 0 ) ); $items[] = $record;
		}
		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );
		$total = count( $items ); $page = max( 1, absint( $filters['page'] ?? 1 ) ); $per_page = min( 100, max( 1, absint( $filters['per_page'] ?? 25 ) ) );
		return array( 'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ), 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'pages' => (int) ceil( $total / $per_page ) );
	}

	public static function retry( string $key ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_admin_alert_forbidden', 'Administrator permission is required.', array( 'status' => 403 ) ); }
		$alerts = get_option( self::OPTION, array() );
		if ( ! is_array( $alerts ) || empty( $alerts[ $key ] ) || ! is_array( $alerts[ $key ] ) ) { return new \WP_Error( 'adc_admin_alert_not_found', 'Administrative alert was not found.', array( 'status' => 404 ) ); }
		$record = $alerts[ $key ];
		if ( empty( $record['title_ar'] ) || empty( $record['body_ar'] ) ) { return new \WP_Error( 'adc_admin_alert_legacy', 'This legacy alert cannot be retried because its message template was not stored.', array( 'status' => 409 ) ); }
		$result = self::dispatch( $record ); $record['attempts'] = absint( $record['attempts'] ?? 0 ) + 1; $record['last_attempt_at'] = current_time( 'mysql', true ); $record['sent'] = (int) $result['sent']; $record['recipient_count'] = (int) $result['recipients']; $record['status'] = $result['sent'] > 0 ? 'sent' : 'failed'; $record['last_error'] = $result['error']; $alerts[ $key ] = $record;
		update_option( self::OPTION, $alerts, false ); AuditLog::record( 'administrative_alert.retried', 'administrative_alert', 0, 'Administrative alert retry attempted', null, array( 'alert_key' => hash( 'sha256', $key ), 'status' => $record['status'], 'recipient_count' => (int) $result['sent'] ) ); return $record;
	}

	private static function notify( string $key, string $title_ar, string $title_en, string $body_ar, string $body_en, string $url, string $audit_key ): void {
		$alerts = get_option( self::OPTION, array() ); $alerts = is_array( $alerts ) ? $alerts : array(); if ( isset( $alerts[ $key ] ) ) { return; }
		$record = array( 'key' => $key, 'created_at' => current_time( 'mysql', true ), 'event' => $audit_key, 'sent' => 0, 'status' => 'pending', 'attempts' => 0, 'recipient_count' => 0, 'last_attempt_at' => '', 'last_error' => '', 'title_ar' => $title_ar, 'title_en' => $title_en, 'body_ar' => $body_ar, 'body_en' => $body_en, 'url' => $url ); $alerts[ $key ] = $record;
		if ( count( $alerts ) > self::MAX_RECORDS ) { $alerts = array_slice( $alerts, -self::MAX_RECORDS, self::MAX_RECORDS, true ); } update_option( self::OPTION, $alerts, false );
		$result = self::dispatch( $record ); $record['attempts'] = 1; $record['last_attempt_at'] = current_time( 'mysql', true ); $record['sent'] = (int) $result['sent']; $record['recipient_count'] = (int) $result['recipients']; $record['status'] = $result['sent'] > 0 ? 'sent' : 'failed'; $record['last_error'] = $result['error']; $alerts[ $key ] = $record; update_option( self::OPTION, $alerts, false );
		AuditLog::record( $audit_key, 'administrative_alert', 0, 'Operational administrator alert emitted', null, array( 'alert_key' => hash( 'sha256', $key ), 'recipient_count' => (int) $result['sent'], 'status' => $record['status'] ) );
	}

	private static function dispatch( array $record ): array {
		$sent = 0; $recipients = 0; $users = get_users( array( 'capability' => 'manage_options', 'fields' => array( 'ID', 'user_email' ) ) );
		foreach ( $users as $user ) {
			if ( ! is_email( $user->user_email ) ) { continue; } ++$recipients; $language = NewsletterService::language( (string) get_user_meta( (int) $user->ID, 'adc_preferred_language', true ) ); $ar = 'ar' === $language; $subject = $ar ? (string) $record['title_ar'] : (string) $record['title_en']; $html = BrandedMail::render( $subject, $ar ? (string) $record['body_ar'] : (string) $record['body_en'], $ar ? 'فتح لوحة المراجعة' : 'Open review dashboard', (string) $record['url'], '', $language ); if ( BrandedMail::send( $user->user_email, $subject, $html ) ) { ++$sent; }
		}
		return array( 'sent' => $sent, 'recipients' => $recipients, 'error' => $recipients ? ( $sent === $recipients ? '' : 'partial_delivery' ) : 'no_admin_recipient' );
	}
}
