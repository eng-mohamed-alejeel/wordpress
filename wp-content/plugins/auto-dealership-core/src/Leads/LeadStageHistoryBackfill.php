<?php
namespace AutoDealership\Leads;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Recovers only stage changes supported by the append-only lead audit trail. */
final class LeadStageHistoryBackfill {
	private const BATCH_SIZE = 200;
	private const CURSOR_OPTION = 'adc_lead_stage_history_backfill_cursor';
	private const COMPLETE_OPTION = 'adc_lead_stage_history_backfill_complete';
	private const DURATION_CURSOR_OPTION = 'adc_lead_stage_history_duration_cursor';
	private const DURATION_COMPLETE_OPTION = 'adc_lead_stage_history_duration_complete';
	private const EVENT_KEYS = array(
		'lead.created',
		'lead.claimed',
		'lead.claim_expired',
		'lead.assignment_released',
		'lead.stage_changed',
		'lead.first_contact_recorded',
		'lead.assigned',
		'lead.auto_assigned',
	);

	public static function boot(): void {
		add_action( 'adc_backfill_lead_stage_history', array( self::class, 'run_scheduled' ) );
		add_action( 'plugins_loaded', array( self::class, 'schedule_if_needed' ), 20 );
	}

	public static function schedule_if_needed(): void {
		if ( ( ! get_option( self::COMPLETE_OPTION, false ) || ! get_option( self::DURATION_COMPLETE_OPTION, false ) ) && ! wp_next_scheduled( 'adc_backfill_lead_stage_history' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'adc_backfill_lead_stage_history' );
		}
	}

	public static function run_scheduled(): void {
		if ( ! self::run_batch() ) {
			$health = get_option( 'adc_lead_stage_history_backfill_health', array() );
			if ( ! is_array( $health ) ) { $health = array(); }
			$health['last_error_at'] = current_time( 'mysql', true );
			$health['cursor'] = absint( get_option( self::CURSOR_OPTION, 0 ) );
			$health['processed_total'] = absint( get_option( 'adc_lead_stage_history_backfill_processed_total', 0 ) );
			$health['duration_cursor'] = absint( get_option( self::DURATION_CURSOR_OPTION, 0 ) );
			$health['duration_processed_total'] = absint( get_option( 'adc_lead_stage_history_duration_processed_total', 0 ) );
			update_option( 'adc_lead_stage_history_backfill_health', $health, false );
			error_log( 'Auto Dealership Core: lead stage history audit backfill failed; it will be retried.' );
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'adc_backfill_lead_stage_history' );
		}
	}

	/** Processes one bounded, idempotent batch. */
	public static function run_batch(): bool {
		global $wpdb;
		if ( get_option( self::COMPLETE_OPTION, false ) ) { return self::recalculate_all_durations_batch(); }
		$placeholders = implode( ',', array_fill( 0, count( self::EVENT_KEYS ), '%s' ) );
		$cursor = absint( get_option( self::CURSOR_OPTION, 0 ) );
		$events = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id,actor_user_id,event_key,subject_id,before_data,after_data,created_at FROM ' . AuditLog::table_name() . " WHERE id>%d AND subject_type='lead' AND event_key IN ($placeholders) ORDER BY id ASC LIMIT %d",
				array_merge( array( $cursor ), self::EVENT_KEYS, array( self::BATCH_SIZE ) )
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) { return false; }
		if ( ! $events ) {
			update_option( self::COMPLETE_OPTION, true, false );
			update_option( 'adc_lead_stage_history_backfill_health', array( 'completed_at'=>current_time( 'mysql', true ), 'cursor'=>$cursor, 'processed_total'=>absint( get_option( 'adc_lead_stage_history_backfill_processed_total', 0 ) ), 'duration_cursor'=>absint( get_option( self::DURATION_CURSOR_OPTION, 0 ) ), 'duration_complete'=>(bool) get_option( self::DURATION_COMPLETE_OPTION, false ) ), false );
			return self::recalculate_all_durations_batch();
		}

		$touched_leads = array();
		$history_table = Schema::table( 'lead_stage_history' );
		$lead_table = Schema::table( 'leads' );
		foreach ( $events as $event ) {
			$event_id = (int) $event['id'];
			$cursor = $event_id;
			$lead_id = (int) $event['subject_id'];
			$before = json_decode( (string) $event['before_data'], true );
			$after = json_decode( (string) $event['after_data'], true );
			$before = is_array( $before ) ? $before : array();
			$after = is_array( $after ) ? $after : array();
			$to = sanitize_key( (string) ( $after['stage'] ?? '' ) );
			$from = sanitize_key( (string) ( $before['stage'] ?? '' ) );
			$is_initial = 'lead.created' === $event['event_key'] && '' === $from;
			if ( $is_initial && 'assigned' === $to ) { $to = 'new'; }
			if ( ! $lead_id || ! $to || ! array_key_exists( $to, LeadService::stage_keys() ) || ( ! $is_initial && ( ! $from || $from === $to || ! array_key_exists( $from, LeadService::stage_keys() ) ) ) ) {
				continue;
			}
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $history_table WHERE audit_event_id=%d", $event_id ) );
			if ( $wpdb->last_error ) { return false; }
			if ( ! $existing ) {
				$matching = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM $history_table WHERE lead_id=%d AND from_stage=%s AND to_stage=%s AND audit_event_id IS NULL AND ABS(TIMESTAMPDIFF(SECOND,changed_at,%s))<=5 ORDER BY ABS(TIMESTAMPDIFF(SECOND,changed_at,%s)) ASC,id ASC LIMIT 1",
					$lead_id,
					$from,
					$to,
					$event['created_at'],
					$event['created_at']
				) );
				if ( $wpdb->last_error ) { return false; }
				if ( $matching ) {
					$updated = $wpdb->update( $history_table, array( 'audit_event_id'=>$event_id ), array( 'id'=>(int) $matching ), array( '%d' ), array( '%d' ) );
					if ( 1 !== $updated ) { return false; }
				} else {
					$lead = $wpdb->get_row( $wpdb->prepare( "SELECT branch_id,owner_user_id FROM $lead_table WHERE id=%d", $lead_id ), ARRAY_A );
					if ( $wpdb->last_error || ! $lead ) { return false; }
					$context = self::prior_assignment_context( $lead_id, $event_id );
					if ( false === $context ) { return false; }
					$branch_id = absint( $after['branch_id'] ?? $before['branch_id'] ?? $context['branch_id'] ?? $lead['branch_id'] );
					$owner_user_id = absint( $after['owner_user_id'] ?? $before['owner_user_id'] ?? $context['owner_user_id'] ?? $lead['owner_user_id'] );
					if ( $is_initial ) { $owner_user_id = 0; }
					if ( in_array( $event['event_key'], array( 'lead.claim_expired', 'lead.assignment_released' ), true ) ) {
						$owner_user_id = absint( $before['owner_user_id'] ?? $context['owner_user_id'] ?? $lead['owner_user_id'] );
					}
					$inserted = $wpdb->insert(
						$history_table,
						array(
							'lead_id'=>$lead_id,
							'branch_id'=>$branch_id,
							'owner_user_id'=>$owner_user_id,
							'actor_user_id'=>(int) $event['actor_user_id'],
							'audit_event_id'=>$event_id,
							'from_stage'=>$from,
							'to_stage'=>$to,
							'duration_seconds'=>null,
							'changed_at'=>$event['created_at'],
						),
						array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
					);
					if ( 1 !== $inserted ) { return false; }
				}
			}
			$touched_leads[$lead_id] = true;
		}

		foreach ( array_keys( $touched_leads ) as $lead_id ) {
			if ( ! self::recalculate_durations( (int) $lead_id ) ) { return false; }
		}

		update_option( self::CURSOR_OPTION, $cursor, false );
		$processed_total = absint( get_option( 'adc_lead_stage_history_backfill_processed_total', 0 ) ) + count( $events );
		update_option( 'adc_lead_stage_history_backfill_processed_total', $processed_total, false );
		update_option( 'adc_lead_stage_history_backfill_health', array( 'completed_at'=>null, 'cursor'=>$cursor, 'processed'=>count( $events ), 'processed_total'=>$processed_total, 'duration_cursor'=>absint( get_option( self::DURATION_CURSOR_OPTION, 0 ) ), 'duration_complete'=>(bool) get_option( self::DURATION_COMPLETE_OPTION, false ) ), false );
		if ( count( $events ) === self::BATCH_SIZE ) {
			if ( ! wp_next_scheduled( 'adc_backfill_lead_stage_history' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'adc_backfill_lead_stage_history' );
			}
		} else {
			update_option( self::COMPLETE_OPTION, true, false );
			update_option( 'adc_lead_stage_history_backfill_health', array( 'completed_at'=>current_time( 'mysql', true ), 'cursor'=>$cursor, 'processed'=>count( $events ), 'processed_total'=>$processed_total, 'duration_cursor'=>absint( get_option( self::DURATION_CURSOR_OPTION, 0 ) ), 'duration_complete'=>(bool) get_option( self::DURATION_COMPLETE_OPTION, false ) ), false );
			return self::recalculate_all_durations_batch();
		}
		return true;
	}

	private static function recalculate_all_durations_batch(): bool {
		global $wpdb;
		if ( get_option( self::DURATION_COMPLETE_OPTION, false ) ) { return true; }
		$cursor = absint( get_option( self::DURATION_CURSOR_OPTION, 0 ) );
		$table = Schema::table( 'lead_stage_history' );
		$lead_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT lead_id FROM $table WHERE lead_id>%d ORDER BY lead_id ASC LIMIT %d", $cursor, self::BATCH_SIZE ) );
		if ( $wpdb->last_error ) { return false; }
		if ( ! $lead_ids ) {
			update_option( self::DURATION_COMPLETE_OPTION, true, false );
			$health = get_option( 'adc_lead_stage_history_backfill_health', array() );
			if ( ! is_array( $health ) ) { $health = array(); }
			$health['duration_cursor'] = $cursor;
			$health['duration_processed_total'] = absint( get_option( 'adc_lead_stage_history_duration_processed_total', 0 ) );
			$health['duration_complete'] = true;
			$health['completed_at'] = current_time( 'mysql', true );
			update_option( 'adc_lead_stage_history_backfill_health', $health, false );
			return true;
		}
		foreach ( $lead_ids as $lead_id ) {
			if ( ! self::recalculate_durations( (int) $lead_id ) ) { return false; }
			$cursor = (int) $lead_id;
		}
		$processed_total = absint( get_option( 'adc_lead_stage_history_duration_processed_total', 0 ) ) + count( $lead_ids );
		$finished = count( $lead_ids ) < self::BATCH_SIZE;
		update_option( self::DURATION_CURSOR_OPTION, $cursor, false );
		update_option( 'adc_lead_stage_history_duration_processed_total', $processed_total, false );
		if ( $finished ) { update_option( self::DURATION_COMPLETE_OPTION, true, false ); }
		$health = get_option( 'adc_lead_stage_history_backfill_health', array() );
		if ( ! is_array( $health ) ) { $health = array(); }
		$health['duration_cursor'] = $cursor;
		$health['duration_processed_total'] = $processed_total;
		$health['duration_complete'] = $finished;
		if ( $finished ) { $health['completed_at'] = current_time( 'mysql', true ); }
		update_option( 'adc_lead_stage_history_backfill_health', $health, false );
		if ( ! $finished && ! wp_next_scheduled( 'adc_backfill_lead_stage_history' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'adc_backfill_lead_stage_history' );
		}
		return true;
	}

	private static function prior_assignment_context( int $lead_id, int $event_id ) {
		global $wpdb;
		$keys = array( 'lead.created', 'lead.claimed', 'lead.claim_expired', 'lead.assigned', 'lead.auto_assigned', 'lead.assignment_released' );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT event_key,before_data,after_data FROM ' . AuditLog::table_name() . " WHERE subject_type='lead' AND subject_id=%d AND id<%d AND event_key IN ($placeholders) ORDER BY id DESC LIMIT 1",
			array_merge( array( $lead_id, $event_id ), $keys )
		), ARRAY_A );
		if ( $wpdb->last_error ) { return false; }
		if ( ! $row ) { return array(); }
		$data = json_decode( (string) $row['after_data'], true );
		$before = json_decode( (string) $row['before_data'], true );
		$data = is_array( $data ) ? $data : array();
		$before = is_array( $before ) ? $before : array();
		$owner = in_array( $row['event_key'], array( 'lead.claim_expired', 'lead.assignment_released' ), true )
			? (int) ( $data['owner_user_id'] ?? 0 )
			: (int) ( $data['owner_user_id'] ?? $before['owner_user_id'] ?? 0 );
		return array( 'branch_id'=>absint( $data['branch_id'] ?? $before['branch_id'] ?? 0 ), 'owner_user_id'=>$owner );
	}

	private static function recalculate_durations( int $lead_id ): bool {
		global $wpdb;
		$table = Schema::table( 'lead_stage_history' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,from_stage,changed_at FROM $table WHERE lead_id=%d ORDER BY changed_at ASC,id ASC", $lead_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return false; }
		$previous_timestamp = null;
		foreach ( $rows as $row ) {
			$duration = null;
			$current_timestamp = strtotime( $row['changed_at'] . ' UTC' );
			if ( false === $current_timestamp ) { return false; }
			if ( '' !== $row['from_stage'] && null !== $previous_timestamp ) {
				$duration = max( 0, $current_timestamp - $previous_timestamp );
			}
			$updated = $wpdb->update( $table, array( 'duration_seconds'=>$duration ), array( 'id'=>(int) $row['id'] ), array( '%d' ), array( '%d' ) );
			if ( false === $updated ) { return false; }
			$previous_timestamp = $current_timestamp;
		}
		return true;
	}
}
