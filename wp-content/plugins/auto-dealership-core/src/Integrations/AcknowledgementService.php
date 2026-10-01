<?php
namespace AutoDealership\Integrations;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Operations\OutboxService;

defined( 'ABSPATH' ) || exit;

/** Durable provider acknowledgements and asynchronous operator reconciliation. */
final class AcknowledgementService {
	public const STATUSES = array( 'pending', 'accepted', 'rejected', 'mismatch' );

	public static function table(): string { return Schema::table( 'integration_receipts' ); }

	public static function boot(): void {
		OutboxService::register_handler( 'integration.reconcile', array( self::class, 'process_reconciliation' ) );
	}

	/** Called by the registry after a provider accepted a delivery attempt. */
	public static function record_delivery( string $adapter_id, string $event_key, array $event, ProviderResult $result ) {
		$outbox_id = absint( $event['id'] ?? 0 );
		if ( $outbox_id < 1 || $event_key !== (string) ( $event['event_key'] ?? '' ) ) {
			return self::error( 'adc_integration_receipt_input', 400 );
		}
		return self::apply_result( $outbox_id, $adapter_id, $event_key, $result, 'delivery' );
	}

	/**
	 * Provider adapters call this only after authenticating and verifying a webhook.
	 * Unknown but well-formed acknowledgements remain visible and can link to a later delivery.
	 */
	public static function receive_acknowledgement( string $adapter_id, string $event_key, ProviderResult $result ) {
		if ( IntegrationRegistry::route( $event_key ) !== $adapter_id ) {
			return self::error( 'adc_integration_ack_route', 409 );
		}
		return self::apply_result( 0, $adapter_id, $event_key, $result, 'webhook' );
	}

	/** Queue provider polling. The remote request is never made inside the admin transaction. */
	public static function request_reconciliation( int $receipt_id, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_integrations' ) ) { return self::error( 'adc_integration_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( $receipt_id < 1 || mb_strlen( trim( $reason ) ) < 5 || mb_strlen( $reason ) > 500 ) { return self::error( 'adc_integration_reconcile_input', 400 ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,outbox_id,adapter_id,event_key,status,updated_at FROM ' . self::table() . ' WHERE id=%d', $receipt_id ), ARRAY_A );
		if ( ! $row || empty( $row['outbox_id'] ) || ! in_array( $row['status'], array( 'pending','mismatch' ), true ) ) { return self::error( 'adc_integration_reconcile_state', 409 ); }
		$adapter = IntegrationRegistry::adapter( $row['adapter_id'] );
		if ( ! $adapter instanceof ReconciliationContract ) { return self::error( 'adc_integration_reconcile_unsupported', 409 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_integration_schema', 503 ); }
		$key = 'reconcile:' . $receipt_id . ':' . hash( 'sha256', $row['status'] . '|' . $row['updated_at'] );
		$queued = OutboxService::enqueue( 'integration.reconcile', array( 'subject_type'=>'integration_receipt', 'subject_id'=>$receipt_id ), $key );
		if ( is_wp_error( $queued ) || ! Transaction::commit( static fn() => AuditLog::record( 'integration.reconciliation_requested', 'integration_receipt', $receipt_id, $reason, array( 'status'=>$row['status'] ), array( 'status'=>$row['status'], 'queued'=>true ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_integration_reconcile_queue', 500 );
		}
		return array( 'id'=>$receipt_id, 'queued'=>true, 'outbox_id'=>(int) $queued['id'] );
	}

	/** Outbox callback for provider polling. */
	public static function process_reconciliation( array $payload, array $event ) {
		global $wpdb;
		if ( 'integration_receipt' !== ( $payload['subject_type'] ?? '' ) || empty( $payload['subject_id'] ) ) { return self::error( 'adc_integration_reconcile_payload', 400 ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', (int) $payload['subject_id'] ), ARRAY_A );
		if ( ! $row ) { return self::error( 'adc_integration_receipt_missing', 404 ); }
		if ( in_array( $row['status'], array( 'accepted','rejected' ), true ) ) { return true; }
		$adapter = IntegrationRegistry::adapter( $row['adapter_id'] );
		if ( ! $adapter instanceof ReconciliationContract ) { return self::error( 'adc_integration_reconcile_unsupported', 409 ); }
		try {
			$result = $adapter->reconcile( $row['event_key'], $row['remote_reference'], self::safe_record( $row ) );
		} catch ( \Throwable $error ) {
			return self::error( 'adc_integration_reconcile_exception', 503 );
		}
		if ( ! $result instanceof ProviderResult || ! hash_equals( $row['reference_hash'], hash( 'sha256', $result->remote_reference() ) ) ) {
			return self::error( 'adc_integration_reconcile_result', 503 );
		}
		$applied = self::apply_result( (int) $row['outbox_id'], $row['adapter_id'], $row['event_key'], $result, 'reconciliation' );
		return is_wp_error( $applied ) ? $applied : true;
	}

	public static function counts() {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_integrations' ) ) { return self::error( 'adc_integration_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_integration_schema', 503 ); }
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $wpdb->get_results( 'SELECT status,COUNT(*) total FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) ?: array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) { $counts[ $row['status'] ] = (int) $row['total']; }
		}
		$counts['unmatched'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE outbox_id IS NULL' );
		return $counts;
	}

	public static function records( string $status = '', int $page = 1, int $limit = 50 ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_integrations' ) ) { return self::error( 'adc_integration_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_integration_schema', 503 ); }
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';
		$page = max( 1, $page ); $limit = min( 100, max( 1, $limit ) ); $offset = ( $page - 1 ) * $limit;
		$where = $status ? $wpdb->prepare( ' WHERE status=%s', $status ) : '';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . $where );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,outbox_id,adapter_id,event_key,reference_hash,status,acknowledged_at,last_checked_at,last_error,created_at,updated_at FROM ' . self::table() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A ) ?: array();
		foreach ( $rows as &$row ) {
			$row['reference_hint'] = 'sha256:' . substr( (string) $row['reference_hash'], 0, 12 );
			unset( $row['reference_hash'] );
		}
		unset( $row );
		return array( 'rows'=>$rows, 'total'=>$total, 'page'=>$page, 'limit'=>$limit, 'status'=>$status );
	}

	private static function apply_result( int $outbox_id, string $adapter_id, string $event_key, ProviderResult $result, string $source ) {
		global $wpdb;
		$adapter_id = sanitize_key( $adapter_id );
		$reference = $result->remote_reference();
		$reference_hash = hash( 'sha256', $reference );
		if ( ! preg_match( '/\A[a-z][a-z0-9_.-]{1,63}\z/', $adapter_id ) || ! isset( IntegrationRegistry::EVENTS[ $event_key ] ) || ! in_array( $source, array( 'delivery','webhook','reconciliation' ), true ) ) {
			return self::error( 'adc_integration_receipt_input', 400 );
		}
		if ( ! Transaction::begin() ) { return self::error( 'adc_integration_schema', 503 ); }
		$table = self::table();
		$by_reference = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE adapter_id=%s AND reference_hash=%s FOR UPDATE", $adapter_id, $reference_hash ), ARRAY_A );
		$by_outbox = $outbox_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE outbox_id=%d FOR UPDATE", $outbox_id ), ARRAY_A ) : null;
		if ( $by_reference && $by_outbox && (int) $by_reference['id'] !== (int) $by_outbox['id'] ) {
			$wpdb->update( $table, array( 'status'=>'mismatch', 'last_error'=>'adc_integration_reference_conflict', 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>(int) $by_outbox['id'] ), array( '%s','%s','%s' ), array( '%d' ) );
			Transaction::commit( static fn() => AuditLog::record( 'integration.acknowledgement_mismatch', 'integration_receipt', (int) $by_outbox['id'], '', array( 'status'=>$by_outbox['status'] ), array( 'status'=>'mismatch', 'error_code'=>'adc_integration_reference_conflict' ) ) );
			return self::error( 'adc_integration_reference_conflict', 409 );
		}
		$row = $by_reference ?: $by_outbox;
		$now = current_time( 'mysql', true );
		if ( ! $row ) {
			$inserted = $wpdb->insert( $table, array(
				'outbox_id'=>$outbox_id > 0 ? $outbox_id : null, 'adapter_id'=>$adapter_id, 'event_key'=>$event_key,
				'remote_reference'=>$reference, 'reference_hash'=>$reference_hash, 'status'=>$result->status(),
				'acknowledged_at'=>'pending' === $result->status() ? null : $now, 'last_checked_at'=>$now,
				'last_error'=>$result->code(), 'created_at'=>$now, 'updated_at'=>$now,
			), array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ) );
			if ( 1 !== $inserted ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_integration_receipt_persistence', 500 ); }
			$id = (int) $wpdb->insert_id;
			$audit = 'delivery' === $source ? static fn() => true : static fn() => AuditLog::record( 'integration.acknowledgement_received', 'integration_receipt', $id, '', null, array( 'event_key'=>$event_key, 'status'=>$result->status(), 'matched'=>$outbox_id > 0 ) );
			if ( ! Transaction::commit( $audit ) ) { return self::error( 'adc_integration_receipt_persistence', 500 ); }
			do_action( 'adc_integration_acknowledgement_changed', array( 'id'=>$id, 'adapter_id'=>$adapter_id, 'event_key'=>$event_key, 'status'=>$result->status(), 'matched'=>$outbox_id > 0, 'source'=>$source ) );
			return array( 'id'=>$id, 'created'=>true, 'status'=>$result->status(), 'matched'=>$outbox_id > 0 );
		}
		$identity_conflict = $row['adapter_id'] !== $adapter_id || $row['event_key'] !== $event_key || ! hash_equals( $row['reference_hash'], $reference_hash ) || ( $outbox_id > 0 && ! empty( $row['outbox_id'] ) && (int) $row['outbox_id'] !== $outbox_id );
		$old_status = $row['status'];
		$new_status = $old_status;
		$error_code = $result->code();
		if ( $identity_conflict || 'mismatch' === $old_status || ( in_array( $old_status, array( 'accepted','rejected' ), true ) && 'pending' !== $result->status() && $old_status !== $result->status() ) ) {
			$new_status = 'mismatch';
			$error_code = $identity_conflict ? 'adc_integration_identity_conflict' : 'adc_integration_status_conflict';
		} elseif ( 'pending' === $old_status || ( empty( $row['outbox_id'] ) && 'delivery' !== $source ) ) {
			$new_status = $result->status();
		}
		$linked_outbox = ! empty( $row['outbox_id'] ) ? (int) $row['outbox_id'] : ( $outbox_id > 0 ? $outbox_id : null );
		$updated = $wpdb->update( $table, array(
			'outbox_id'=>$linked_outbox, 'status'=>$new_status,
			'acknowledged_at'=>'pending' === $new_status ? null : ( $row['acknowledged_at'] ?: $now ),
			'last_checked_at'=>$now, 'last_error'=>$error_code, 'updated_at'=>$now,
		), array( 'id'=>(int) $row['id'] ), array( '%d','%s','%s','%s','%s','%s' ), array( '%d' ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_integration_receipt_persistence', 500 ); }
		$needs_audit = 'delivery' !== $source || 'mismatch' === $new_status;
		$audit = $needs_audit ? static fn() => AuditLog::record( 'mismatch' === $new_status ? 'integration.acknowledgement_mismatch' : 'integration.acknowledgement_received', 'integration_receipt', (int) $row['id'], '', array( 'status'=>$old_status ), array( 'status'=>$new_status, 'matched'=>null !== $linked_outbox, 'error_code'=>$error_code ) ) : static fn() => true;
		if ( ! Transaction::commit( $audit ) ) { return self::error( 'adc_integration_receipt_persistence', 500 ); }
		if ( $new_status !== $old_status || 'delivery' !== $source ) {
			do_action( 'adc_integration_acknowledgement_changed', array( 'id'=>(int) $row['id'], 'adapter_id'=>$adapter_id, 'event_key'=>$event_key, 'status'=>$new_status, 'matched'=>null !== $linked_outbox, 'source'=>$source ) );
		}
		if ( 'mismatch' === $new_status ) { return self::error( 'adc_integration_ack_mismatch', 409 ); }
		return array( 'id'=>(int) $row['id'], 'created'=>false, 'status'=>$new_status, 'matched'=>null !== $linked_outbox );
	}

	private static function safe_record( array $row ): array {
		return array(
			'id'=>(int) $row['id'], 'outbox_id'=>(int) $row['outbox_id'], 'adapter_id'=>$row['adapter_id'],
			'event_key'=>$row['event_key'], 'status'=>$row['status'], 'attempted_at'=>$row['last_checked_at'],
		);
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Provider acknowledgement operation failed.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
