<?php
namespace AutoDealership\Operations;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Durable, at-least-once local event queue. External adapters register explicit handlers. */
final class OutboxService {
	public const MAX_ATTEMPTS = 5;
	public const LEASE_SECONDS = 900;
	public const STATUSES = array( 'pending', 'processing', 'retry', 'completed', 'failed' );
	private const BACKOFF_SECONDS = array( 60, 300, 900, 3600 );
	private const PAYLOAD_FIELDS = array(
		'subject_type'=>'key', 'subject_id'=>'id', 'branch_id'=>'id', 'actor_user_id'=>'id',
		'recipient_type'=>'key', 'recipient_id'=>'id', 'state'=>'key', 'version'=>'id',
		'correlation_id'=>'uuid', 'locale'=>'locale', 'occurred_at'=>'datetime',
	);
	private static array $handlers = array();

	public static function table(): string { return Schema::table( 'outbox' ); }

	/** Register one in-process adapter. The handler must return true or WP_Error. */
	public static function register_handler( string $event_key, callable $handler ): bool {
		if ( ! self::valid_event_key( $event_key ) ) { return false; }
		self::$handlers[ $event_key ] = $handler;
		return true;
	}

	/** Queue only minimized references. Contact, payment and credential fields are rejected. */
	public static function enqueue( string $event_key, array $payload, string $idempotency_key, string $available_at = '' ) {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return self::error( 'adc_outbox_schema', 503 ); }
		if ( ! self::valid_event_key( $event_key ) ) { return self::error( 'adc_outbox_event', 400 ); }
		$payload = self::normalize_payload( $payload );
		if ( is_wp_error( $payload ) ) { return $payload; }
		$idempotency_key = trim( $idempotency_key );
		if ( '' === $idempotency_key || mb_strlen( $idempotency_key ) > 190 ) { return self::error( 'adc_outbox_idempotency', 400 ); }
		$available_at = self::valid_datetime( $available_at ) ? $available_at : current_time( 'mysql', true );
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) { return self::error( 'adc_outbox_payload', 400 ); }
		$key_hash = hash( 'sha256', $event_key . '|' . $idempotency_key );
		$payload_hash = hash( 'sha256', $json );
		$inserted = $wpdb->insert( self::table(), array(
			'event_key'=>$event_key, 'idempotency_key'=>$key_hash, 'payload'=>$json,
			'payload_hash'=>$payload_hash, 'status'=>'pending', 'attempts'=>0,
			'next_attempt_at'=>$available_at, 'created_at'=>current_time( 'mysql', true ),
		), array( '%s','%s','%s','%s','%s','%d','%s','%s' ) );
		if ( 1 === $inserted ) { return array( 'id'=>(int) $wpdb->insert_id, 'created'=>true, 'idempotency_key'=>$key_hash ); }
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,event_key,payload_hash FROM ' . self::table() . ' WHERE idempotency_key=%s', $key_hash ), ARRAY_A );
		if ( $existing && $event_key === $existing['event_key'] && hash_equals( $existing['payload_hash'], $payload_hash ) ) {
			return array( 'id'=>(int) $existing['id'], 'created'=>false, 'idempotency_key'=>$key_hash );
		}
		return self::error( $existing ? 'adc_outbox_idempotency_conflict' : 'adc_outbox_persistence', $existing ? 409 : 500 );
	}

	/** Cron entry point. */
	public static function run(): void {
		$started = current_time( 'mysql', true );
		$result = self::process_due( 20 );
		$summary = is_wp_error( $result ) ? array( 'error'=>$result->get_error_code() ) : $result;
		update_option( 'adc_outbox_health', array( 'started_at'=>$started, 'finished_at'=>current_time( 'mysql', true ), 'summary'=>$summary ), false );
	}

	/** Claim due rows with conditional writes so concurrent workers cannot own the same event. */
	public static function process_due( int $limit = 20 ) {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return self::error( 'adc_outbox_schema', 503 ); }
		$limit = min( 100, max( 1, $limit ) );
		$now = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::LEASE_SECONDS );
		$table = self::table();
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $table WHERE ((status IN ('pending','retry') AND next_attempt_at<=%s) OR (status='processing' AND locked_at IS NOT NULL AND locked_at<%s)) ORDER BY id ASC LIMIT %d",
			$now, $stale, min( 300, $limit * 3 )
		) ) ?: array();
		$summary = array( 'claimed'=>0, 'completed'=>0, 'retried'=>0, 'failed'=>0, 'skipped'=>0 );
		foreach ( $candidates as $candidate ) {
			if ( $summary['claimed'] >= $limit ) { break; }
			$token = wp_generate_uuid4();
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE $table SET status='processing',lock_token=%s,locked_at=%s WHERE id=%d AND ((status IN ('pending','retry') AND next_attempt_at<=%s) OR (status='processing' AND locked_at IS NOT NULL AND locked_at<%s))",
				$token, $now, (int) $candidate, $now, $stale
			) );
			if ( 1 !== $claimed ) { ++$summary['skipped']; continue; }
			++$summary['claimed'];
			$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND lock_token=%s", (int) $candidate, $token ), ARRAY_A );
			if ( ! $event ) { ++$summary['skipped']; continue; }
			$outcome = self::dispatch( $event );
			if ( true === $outcome ) {
				$done = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='completed',attempts=attempts+1,completed_at=%s,failed_at=NULL,last_error='',locked_at=NULL,lock_token=NULL WHERE id=%d AND status='processing' AND lock_token=%s", $now, (int) $event['id'], $token ) );
				if ( 1 === $done ) { ++$summary['completed']; do_action( 'adc_outbox_completed', $event ); } else { ++$summary['skipped']; }
				continue;
			}
			$code = is_wp_error( $outcome ) ? sanitize_key( $outcome->get_error_code() ) : 'adc_outbox_handler_failed';
			$code = $code ?: 'adc_outbox_handler_failed';
			$terminal = in_array( $code, array( 'adc_outbox_payload', 'adc_outbox_integrity' ), true );
			$attempts = (int) $event['attempts'] + 1;
			if ( $terminal || $attempts >= self::MAX_ATTEMPTS ) {
				$failed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='failed',attempts=%d,failed_at=%s,last_error=%s,locked_at=NULL,lock_token=NULL WHERE id=%d AND status='processing' AND lock_token=%s", $attempts, $now, mb_substr( $code, 0, 100 ), (int) $event['id'], $token ) );
				if ( 1 === $failed ) {
					++$summary['failed'];
					do_action( 'adc_outbox_failed', array( 'id'=>(int) $event['id'], 'event_key'=>$event['event_key'], 'attempts'=>$attempts, 'error_code'=>$code ) );
				} else { ++$summary['skipped']; }
			} else {
				$delay = self::BACKOFF_SECONDS[ min( count( self::BACKOFF_SECONDS ) - 1, $attempts - 1 ) ];
				$next = gmdate( 'Y-m-d H:i:s', time() + $delay );
				$retry = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='retry',attempts=%d,next_attempt_at=%s,last_error=%s,locked_at=NULL,lock_token=NULL WHERE id=%d AND status='processing' AND lock_token=%s", $attempts, $next, mb_substr( $code, 0, 100 ), (int) $event['id'], $token ) );
				1 === $retry ? ++$summary['retried'] : ++$summary['skipped'];
			}
		}
		return $summary;
	}

	/** An authorized operator may restart only a terminal failure; the action is audited. */
	public static function retry_failed( int $id, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_outbox' ) ) { return self::error( 'adc_outbox_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( $id < 1 || '' === trim( $reason ) || mb_strlen( $reason ) > 500 ) { return self::error( 'adc_outbox_retry_input', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_outbox_schema', 503 ); }
		$table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,attempts,last_error FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || 'failed' !== $row['status'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_outbox_not_failed', 409 ); }
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='retry',attempts=0,next_attempt_at=%s,locked_at=NULL,lock_token=NULL,failed_at=NULL,last_error='' WHERE id=%d AND status='failed'", current_time( 'mysql', true ), $id ) );
		if ( 1 !== $updated || ! Transaction::commit( static fn() => AuditLog::record( 'outbox.retry_requested', 'outbox_event', $id, $reason, array( 'status'=>'failed', 'attempts'=>(int) $row['attempts'], 'error_code'=>$row['last_error'] ), array( 'status'=>'retry', 'attempts'=>0 ) ) ) ) {
			$wpdb->query( 'ROLLBACK' ); return self::error( 'adc_outbox_retry_failed', 500 );
		}
		return array( 'id'=>$id, 'status'=>'retry' );
	}

	public static function counts() {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_outbox' ) ) { return self::error( 'adc_outbox_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_outbox_schema', 503 ); }
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $wpdb->get_results( 'SELECT status,COUNT(*) total FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) ?: array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) { $counts[ $row['status'] ] = (int) $row['total']; }
		}
		return $counts;
	}

	public static function events( string $status = '', int $page = 1, int $limit = 50 ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_outbox' ) ) { return self::error( 'adc_outbox_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_outbox_schema', 503 ); }
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';
		$page = max( 1, $page ); $limit = min( 100, max( 1, $limit ) ); $offset = ( $page - 1 ) * $limit;
		$where = $status ? $wpdb->prepare( ' WHERE status=%s', $status ) : '';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . $where );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,event_key,status,attempts,next_attempt_at,locked_at,completed_at,failed_at,last_error,created_at FROM ' . self::table() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A ) ?: array();
		return array( 'rows'=>$rows, 'total'=>$total, 'page'=>$page, 'limit'=>$limit, 'status'=>$status );
	}

	private static function dispatch( array $event ) {
		$raw = (string) $event['payload'];
		$stored_hash = (string) ( $event['payload_hash'] ?? '' );
		if ( 64 !== strlen( $stored_hash ) || ! hash_equals( $stored_hash, hash( 'sha256', $raw ) ) ) { return self::error( 'adc_outbox_integrity', 500 ); }
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) { return self::error( 'adc_outbox_payload', 500 ); }
		$normalized = self::normalize_payload( $decoded );
		if ( is_wp_error( $normalized ) ) { return self::error( 'adc_outbox_payload', 500 ); }
		$handler = self::$handlers[ $event['event_key'] ] ?? apply_filters( 'adc_outbox_handler', null, $event['event_key'], $event );
		if ( ! is_callable( $handler ) ) { return self::error( 'adc_outbox_no_handler', 503 ); }
		try {
			$result = $handler( $normalized, $event );
			return true === $result ? true : ( is_wp_error( $result ) ? $result : self::error( 'adc_outbox_handler_failed', 503 ) );
		} catch ( \Throwable $error ) {
			return self::error( 'adc_outbox_handler_exception', 503 );
		}
	}

	private static function normalize_payload( array $payload ) {
		$normalized = array();
		foreach ( $payload as $key=>$value ) {
			if ( ! is_string( $key ) || ! isset( self::PAYLOAD_FIELDS[ $key ] ) || ! is_scalar( $value ) ) { return self::error( 'adc_outbox_payload', 400 ); }
			$type = self::PAYLOAD_FIELDS[ $key ];
			if ( 'id' === $type ) {
				if ( ! preg_match( '/\A[0-9]+\z/', (string) $value ) ) { return self::error( 'adc_outbox_payload', 400 ); }
				$normalized[ $key ] = (int) $value;
			} elseif ( 'key' === $type ) {
				$value = (string) $value;
				if ( ! preg_match( '/\A[a-z][a-z0-9_.-]{0,63}\z/', $value ) ) { return self::error( 'adc_outbox_payload', 400 ); }
				$normalized[ $key ] = $value;
			} elseif ( 'uuid' === $type ) {
				if ( ! preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i', (string) $value ) ) { return self::error( 'adc_outbox_payload', 400 ); }
				$normalized[ $key ] = strtolower( (string) $value );
			} elseif ( 'locale' === $type ) {
				if ( ! in_array( $value, array( 'ar','en' ), true ) ) { return self::error( 'adc_outbox_payload', 400 ); }
				$normalized[ $key ] = (string) $value;
			} elseif ( 'datetime' === $type ) {
				if ( ! self::valid_datetime( (string) $value ) ) { return self::error( 'adc_outbox_payload', 400 ); }
				$normalized[ $key ] = (string) $value;
			}
		}
		ksort( $normalized );
		return $normalized;
	}

	private static function valid_event_key( string $key ): bool { return 1 === preg_match( '/\A[a-z][a-z0-9_.-]{1,99}\z/', $key ); }
	private static function valid_datetime( string $value ): bool {
		if ( ! preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value ) ) { return false; }
		$parsed = strtotime( $value . ' UTC' );
		return false !== $parsed && gmdate( 'Y-m-d H:i:s', $parsed ) === $value;
	}
	private static function error( string $code, int $status ): \WP_Error { return new \WP_Error( $code, __( 'Outbox operation failed.', 'auto-dealership-core' ), array( 'status'=>$status ) ); }
}
