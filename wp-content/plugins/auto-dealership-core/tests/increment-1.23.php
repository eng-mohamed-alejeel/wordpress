<?php
/** Durable outbox, retry controls and operations monitor acceptance for 1.23. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Admin\OutboxPage;
use AutoDealership\Database\Schema;
use AutoDealership\Operations\OutboxService;

$outbox = Schema::table( 'outbox' );
$previous_user = get_current_user_id();

try {
	// Recreate the 1.13 queue shape and prove that the additive upgrade preserves its row.
	$legacy_time = current_time( 'mysql', true );
	$wpdb->insert( $outbox, array( 'event_key'=>'legacy.pending', 'payload'=>'{}', 'attempts'=>0, 'next_attempt_at'=>$legacy_time, 'created_at'=>$legacy_time ), array( '%s','%s','%d','%s','%s' ) );
	$legacy_id = (int) $wpdb->insert_id;
	foreach ( array( 'idempotency_key','queue','lock_token' ) as $index ) { $wpdb->query( "ALTER TABLE `$outbox` DROP INDEX `$index`" ); }
	foreach ( array( 'idempotency_key','payload_hash','status','locked_at','lock_token','failed_at','last_error' ) as $column ) { $wpdb->query( "ALTER TABLE `$outbox` DROP COLUMN `$column`" ); }
	update_option( 'adc_db_version', '1.13.0', false );
	Schema::install();
	adc_check( Schema::is_ready() && array() === Schema::verify() && 'legacy.pending' === $wpdb->get_var( $wpdb->prepare( "SELECT event_key FROM $outbox WHERE id=%d", $legacy_id ) ), 'Outbox upgrade adds delivery state and indexes without replacing a legacy row.' );
	$wpdb->delete( $outbox, array( 'id'=>$legacy_id ), array( '%d' ) );

	adc_check( is_wp_error( OutboxService::enqueue( 'Bad Event', array(), 'bad-event' ) ), 'Outbox rejects an invalid event key.' );
	adc_check( is_wp_error( OutboxService::enqueue( 'test.private', array( 'email'=>'person@example.invalid' ), 'private-email' ) ), 'Outbox rejects personal fields outside the minimized payload contract.' );
	adc_check( is_wp_error( OutboxService::enqueue( 'test.nested', array( 'subject_id'=>array( 1 ) ), 'nested' ) ), 'Outbox rejects nested payload data.' );
	adc_check( is_wp_error( OutboxService::enqueue( 'test.blank_key', array( 'subject_id'=>1 ), '' ) ), 'Outbox requires an idempotency key.' );

	$payload = array( 'subject_type'=>'vehicle', 'subject_id'=>41, 'branch_id'=>$branch_a['id'], 'state'=>'available', 'locale'=>'ar', 'occurred_at'=>$legacy_time );
	$queued = OutboxService::enqueue( 'test.complete', $payload, 'complete-41' );
	$replayed = OutboxService::enqueue( 'test.complete', $payload, 'complete-41' );
	$conflict = OutboxService::enqueue( 'test.complete', array_merge( $payload, array( 'state'=>'reserved' ) ), 'complete-41' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $outbox WHERE id=%d", $queued['id'] ), ARRAY_A );
	adc_check( is_array( $queued ) && $queued['created'] && !$replayed['created'] && $queued['id'] === $replayed['id'] && is_wp_error( $conflict ) && 'adc_outbox_idempotency_conflict' === $conflict->get_error_code(), 'Outbox creates one row for identical retries and rejects a conflicting replay.' );
	adc_check( 64 === strlen( $row['idempotency_key'] ) && 64 === strlen( $row['payload_hash'] ) && ! str_contains( $row['payload'], 'email' ), 'Outbox persists hashed idempotency and only minimized references.' );

	$handled = 0;
	OutboxService::register_handler( 'test.complete', static function ( array $event_payload, array $event ) use ( &$handled ): bool {
		++$handled;
		return 41 === $event_payload['subject_id'] && ! empty( $event['idempotency_key'] );
	} );
	$delayed = OutboxService::enqueue( 'test.delayed', array( 'subject_type'=>'vehicle', 'subject_id'=>42 ), 'delayed-42', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
	$completed = OutboxService::process_due( 10 );
	adc_check( 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $outbox WHERE id=%d", $delayed['id'] ) ), 'Outbox does not claim an event before its available time.' );
	$completed_row = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts,completed_at,lock_token FROM $outbox WHERE id=%d", $queued['id'] ), ARRAY_A );
	OutboxService::process_due( 10 );
	adc_check( 1 === $completed['completed'] && 1 === $handled && 'completed' === $completed_row['status'] && 1 === (int) $completed_row['attempts'] && $completed_row['completed_at'] && null === $completed_row['lock_token'], 'Successful delivery completes exactly once and releases its lease.' );

	OutboxService::register_handler( 'test.failure', static fn() => new WP_Error( 'provider_timeout_with_no_private_detail', 'Private remote message must not be stored.' ) );
	$failing = OutboxService::enqueue( 'test.failure', array( 'subject_type'=>'sale', 'subject_id'=>9 ), 'failure-9' );
	for ( $attempt = 1; $attempt <= OutboxService::MAX_ATTEMPTS; ++$attempt ) {
		$wpdb->update( $outbox, array( 'next_attempt_at'=>'2000-01-01 00:00:00' ), array( 'id'=>$failing['id'] ), array( '%s' ), array( '%d' ) );
		OutboxService::process_due( 1 );
	}
	$failed_row = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts,last_error,failed_at FROM $outbox WHERE id=%d", $failing['id'] ), ARRAY_A );
	adc_check( 'failed' === $failed_row['status'] && OutboxService::MAX_ATTEMPTS === (int) $failed_row['attempts'] && 'provider_timeout_with_no_private_detail' === $failed_row['last_error'] && $failed_row['failed_at'], 'Bounded backoff moves repeated delivery failure to an operator-visible terminal state.' );
	adc_check( ! str_contains( wp_json_encode( $failed_row ), 'Private remote message' ), 'Outbox stores a safe failure code without the remote error message.' );

	$invalid_id = $wpdb->insert( $outbox, array( 'event_key'=>'test.invalid_payload', 'payload'=>'{"token":"SECRET-OUTBOX-VALUE"}', 'payload_hash'=>hash( 'sha256', '{"token":"SECRET-OUTBOX-VALUE"}' ), 'status'=>'pending', 'attempts'=>0, 'next_attempt_at'=>'2000-01-01 00:00:00', 'created_at'=>$legacy_time ), array( '%s','%s','%s','%s','%d','%s','%s' ) ) ? (int) $wpdb->insert_id : 0;
	$invalid_result = OutboxService::process_due( 1 );
	adc_check( 1 === $invalid_result['failed'] && 'failed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $outbox WHERE id=%d", $invalid_id ) ), 'Malformed stored payload fails terminally without reaching an adapter.' );
	$tampered_payload = '{"subject_id":52,"subject_type":"vehicle"}';
	$tampered_id = $wpdb->insert( $outbox, array( 'event_key'=>'test.tampered', 'payload'=>$tampered_payload, 'payload_hash'=>str_repeat( '0', 64 ), 'status'=>'pending', 'attempts'=>0, 'next_attempt_at'=>'2000-01-01 00:00:00', 'created_at'=>$legacy_time ), array( '%s','%s','%s','%s','%d','%s','%s' ) ) ? (int) $wpdb->insert_id : 0;
	$tampered_result = OutboxService::process_due( 1 );
	adc_check( 1 === $tampered_result['failed'] && 'adc_outbox_integrity' === $wpdb->get_var( $wpdb->prepare( "SELECT last_error FROM $outbox WHERE id=%d", $tampered_id ) ), 'Payload hash mismatch is detected before adapter dispatch.' );

	OutboxService::register_handler( 'test.stale', static fn() => true );
	$stale_payload = '{"subject_id":51,"subject_type":"vehicle"}';
	$stale_id = $wpdb->insert( $outbox, array( 'event_key'=>'test.stale', 'payload'=>$stale_payload, 'payload_hash'=>hash( 'sha256', $stale_payload ), 'status'=>'processing', 'attempts'=>0, 'next_attempt_at'=>'2000-01-01 00:00:00', 'locked_at'=>'2000-01-01 00:00:00', 'lock_token'=>wp_generate_uuid4(), 'created_at'=>$legacy_time ), array( '%s','%s','%s','%s','%d','%s','%s','%s','%s' ) ) ? (int) $wpdb->insert_id : 0;
	$stale_result = OutboxService::process_due( 1 );
	adc_check( 1 === $stale_result['completed'] && 'completed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $outbox WHERE id=%d", $stale_id ) ), 'A worker safely reclaims and completes an expired lease.' );

	$auditor = $make_user( 'outbox_auditor', 'dealership_auditor', $branch_a['id'] );
	$general_manager = $make_user( 'outbox_manager', 'dealership_general_manager', $branch_a['id'] );
	wp_set_current_user( $sales_a );
	$denied = OutboxService::retry_failed( $failing['id'], 'Unauthorized retry' );
	$denied_counts = OutboxService::counts();
	adc_check( is_wp_error( $denied ) && 'adc_outbox_forbidden' === $denied->get_error_code() && is_wp_error( $denied_counts ) && 'adc_outbox_forbidden' === $denied_counts->get_error_code(), 'Sales staff cannot inspect or retry the integration queue.' );
	wp_set_current_user( $auditor );
	$counts = OutboxService::counts();
	$visible = OutboxService::events( 'failed', 1, 50 );
	adc_check( is_array( $counts ) && $counts['failed'] >= 2 && is_array( $visible ) && is_wp_error( OutboxService::retry_failed( $failing['id'], 'Auditor retry' ) ), 'Auditor can inspect safe queue metadata but cannot retry events.' );

	wp_set_current_user( $general_manager );
	$audit_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'outbox.retry_requested' ) );
	$retried = OutboxService::retry_failed( $failing['id'], 'Provider recovered after operator review' );
	$retry_row = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts,failed_at,last_error FROM $outbox WHERE id=%d", $failing['id'] ), ARRAY_A );
	adc_check( is_array( $retried ) && 'retry' === $retry_row['status'] && 0 === (int) $retry_row['attempts'] && null === $retry_row['failed_at'] && '' === $retry_row['last_error'] && $audit_before + 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'outbox.retry_requested' ) ), 'General manager restarts a terminal event through an atomic audited action.' );
	$wpdb->update( $outbox, array( 'next_attempt_at'=>gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ), array( 'id'=>$failing['id'] ), array( '%s' ), array( '%d' ) );

	$wpdb->update( $outbox, array( 'status'=>'failed', 'attempts'=>5, 'failed_at'=>$legacy_time, 'last_error'=>'test_failure' ), array( 'id'=>$invalid_id ), array( '%s','%d','%s','%s' ), array( '%d' ) );
	add_filter( 'query', $break_audit );
	$rollback_retry = OutboxService::retry_failed( $invalid_id, 'Audit rollback retry' );
	remove_filter( 'query', $break_audit );
	adc_check( is_wp_error( $rollback_retry ) && 'failed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $outbox WHERE id=%d", $invalid_id ) ), 'Audit persistence failure rolls an operator retry back.' );

	wp_set_current_user( $admin );
	ob_start(); OutboxPage::render(); $outbox_html = ob_get_clean();
	adc_check( str_contains( $outbox_html, 'adc_retry_outbox' ) && str_contains( $outbox_html, 'adc_process_outbox' ) && ! str_contains( $outbox_html, 'SECRET-OUTBOX-VALUE' ), 'Operations monitor exposes protected controls and job health without rendering payload content.' );

	$concurrent = OutboxService::enqueue( 'test.concurrent', array( 'subject_type'=>'vehicle', 'subject_id'=>77 ), 'concurrent-77' );
	$barrier = 'adc_gate_' . bin2hex( random_bytes( 8 ) );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $barrier ) ) ) { throw new RuntimeException( 'Cannot acquire outbox test barrier.' ); }
	$jobs = array();
	try {
		for ( $worker = 0; $worker < 2; ++$worker ) {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/database-runner.php', '--worker' ), array( 0=>array( 'pipe','r' ), 1=>array( 'pipe','w' ), 2=>STDERR ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start outbox concurrency worker.' ); }
			fwrite( $pipes[0], json_encode( array( 'database'=>DB_NAME, 'source_database'=>'', 'host'=>DB_HOST, 'user'=>DB_USER, 'password'=>DB_PASSWORD, 'scenario'=>'outbox', 'actor'=>$admin, 'input'=>array(), 'barrier'=>$barrier ), JSON_THROW_ON_ERROR ) );
			fclose( $pipes[0] );
			$jobs[] = array( $process, $pipes[1] );
		}
		usleep( 500000 );
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $barrier ) );
	}
	$worker_claims = 0;
	foreach ( $jobs as list( $process, $output ) ) {
		$result = json_decode( stream_get_contents( $output ), true );
		fclose( $output );
		if ( 0 !== proc_close( $process ) || ! is_array( $result ) ) { throw new RuntimeException( 'Outbox concurrency worker failed.' ); }
		$worker_claims += (int) ( $result['claimed'] ?? 0 );
	}
	$concurrent_row = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts FROM $outbox WHERE id=%d", $concurrent['id'] ), ARRAY_A );
	adc_check( 1 === $worker_claims && 'completed' === $concurrent_row['status'] && 1 === (int) $concurrent_row['attempts'], 'Concurrent workers claim and deliver one due event exactly once.' );

	$schedules = apply_filters( 'cron_schedules', wp_get_schedules() );
	adc_check( 300 === (int) $schedules['adc_five_minutes']['interval'] && false !== has_action( 'adc_process_outbox', array( OutboxService::class, 'run' ) ), 'Outbox worker is wired to the five-minute operational schedule.' );
} finally {
	wp_set_current_user( $previous_user );
	$wpdb->query( "DELETE FROM $outbox WHERE event_key LIKE 'test.%' OR event_key='legacy.pending'" );
}
