<?php
namespace AutoDealership\Reports;

use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Branch-scoped CRM aggregates; report rows contain no customer or employee identity. */
final class LeadPerformanceReport {
	public static function summary( string $from, string $to ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_reports' ) && ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_lead_report_forbidden', __( 'لا تملك صلاحية عرض تقرير أداء CRM.', 'auto-dealership-core' ), array( 'status'=>403 ) ); }
		if ( ! Schema::is_ready() ) { return new \WP_Error( 'adc_lead_report_schema', __( 'مخطط قاعدة البيانات غير جاهز للتقرير.', 'auto-dealership-core' ), array( 'status'=>503 ) ); }
		$site_timezone = wp_timezone();
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $site_timezone );
		$end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $site_timezone );
		$start_errors = \DateTimeImmutable::getLastErrors();
		$end_errors = \DateTimeImmutable::getLastErrors();
		if ( ! $start || ! $end || ( is_array( $start_errors ) && ( $start_errors['warning_count'] || $start_errors['error_count'] ) ) || ( is_array( $end_errors ) && ( $end_errors['warning_count'] || $end_errors['error_count'] ) ) || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to || $start > $end || $start->diff( $end )->days > 365 ) {
			return new \WP_Error( 'adc_lead_report_dates', __( 'اختر فترة صحيحة لا تتجاوز 366 يومًا.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}

		list( $scope, $scope_args ) = BranchScope::predicate( 'l.branch_id' );
		$utc = new \DateTimeZone( 'UTC' );
		$start_at = $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_at = $end->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$now = current_time( 'mysql', true );
		$lead_table = Schema::table( 'leads' );
		$aggregate = "SELECT l.branch_id,COALESCE(b.name,'') branch_name,COUNT(*) total,SUM(l.stage='new') new_count,SUM(l.first_contact_at IS NOT NULL) first_contact_count,SUM(l.first_contact_at IS NOT NULL AND l.first_contact_at>=l.created_at) valid_first_contact_count,AVG(CASE WHEN l.first_contact_at IS NOT NULL AND l.first_contact_at>=l.created_at THEN TIMESTAMPDIFF(SECOND,l.created_at,l.first_contact_at) ELSE NULL END) avg_first_contact_seconds,SUM(l.next_action_at IS NOT NULL AND l.next_action_at<=%s AND l.stage NOT IN ('won','lost')) overdue_count,SUM(l.stage='won') won_count,SUM(l.stage='lost') lost_count FROM $lead_table l LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=l.branch_id WHERE $scope AND l.created_at>=%s AND l.created_at<%s GROUP BY l.branch_id,b.name ORDER BY l.branch_id";
		$rows = $wpdb->get_results( $wpdb->prepare( $aggregate, array_merge( array( $now ), $scope_args, array( $start_at, $end_at ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { error_log( 'Auto Dealership Core: CRM performance report query failed: ' . $wpdb->last_error ); return new \WP_Error( 'adc_lead_report_unavailable', __( 'تعذر تحميل تقرير أداء CRM.', 'auto-dealership-core' ), array( 'status'=>503 ) ); }

		$pipeline_sql = "SELECT l.branch_id,l.stage,COUNT(*) total FROM $lead_table l WHERE $scope AND l.created_at>=%s AND l.created_at<%s GROUP BY l.branch_id,l.stage ORDER BY l.branch_id,l.stage";
		$pipeline = $wpdb->get_results( $wpdb->prepare( $pipeline_sql, array_merge( $scope_args, array( $start_at, $end_at ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { error_log( 'Auto Dealership Core: CRM pipeline report query failed: ' . $wpdb->last_error ); return new \WP_Error( 'adc_lead_report_unavailable', __( 'تعذر تحميل مراحل تقرير CRM.', 'auto-dealership-core' ), array( 'status'=>503 ) ); }

		list( $history_scope, $history_scope_args ) = BranchScope::predicate( 'h.branch_id' );
		$history_table = Schema::table( 'lead_stage_history' );
		$transitions_sql = "SELECT h.branch_id,COALESCE(b.name,'') branch_name,h.from_stage,h.to_stage,COUNT(*) transition_count,COUNT(DISTINCT h.lead_id) lead_count,AVG(h.duration_seconds) avg_duration_seconds FROM $history_table h LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=h.branch_id WHERE $history_scope AND h.from_stage<>'' AND h.changed_at>=%s AND h.changed_at<%s GROUP BY h.branch_id,b.name,h.from_stage,h.to_stage ORDER BY h.branch_id,h.from_stage,h.to_stage";
		$transitions = $wpdb->get_results( $wpdb->prepare( $transitions_sql, array_merge( $history_scope_args, array( $start_at, $end_at ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { error_log( 'Auto Dealership Core: CRM transition report query failed: ' . $wpdb->last_error ); return new \WP_Error( 'adc_lead_report_unavailable', __( 'تعذر تحميل سجل انتقالات CRM.', 'auto-dealership-core' ), array( 'status'=>503 ) ); }
		$transition_sources_sql = "SELECT h.branch_id,h.from_stage,COUNT(DISTINCT h.lead_id) lead_count FROM $history_table h WHERE $history_scope AND h.from_stage<>'' AND h.changed_at>=%s AND h.changed_at<%s GROUP BY h.branch_id,h.from_stage";
		$transition_sources = $wpdb->get_results( $wpdb->prepare( $transition_sources_sql, array_merge( $history_scope_args, array( $start_at, $end_at ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { error_log( 'Auto Dealership Core: CRM transition denominator query failed: ' . $wpdb->last_error ); return new \WP_Error( 'adc_lead_report_unavailable', __( 'تعذر حساب نسب انتقالات CRM.', 'auto-dealership-core' ), array( 'status'=>503 ) ); }
		$transition_source_counts = array();
		foreach ( $transition_sources as $source ) {
			$key = implode( '|', array( (int) $source['branch_id'], $source['from_stage'] ) );
			$transition_source_counts[$key] = (int) $source['lead_count'];
		}

		$branches = array();
		$totals = array( 'total'=>0, 'new'=>0, 'first_contact'=>0, 'overdue'=>0, 'won'=>0, 'lost'=>0, 'avg_first_contact_seconds'=>null );
		$weighted_seconds = 0.0;
		foreach ( $rows as $row ) {
			foreach ( array( 'branch_id','total','new_count','first_contact_count','valid_first_contact_count','overdue_count','won_count','lost_count' ) as $key ) { $row[$key] = (int) $row[$key]; }
			$row['avg_first_contact_seconds'] = null === $row['avg_first_contact_seconds'] ? null : (float) $row['avg_first_contact_seconds'];
			$branch_key = (int) $row['branch_id'];
			if ( ! isset( $branches[$branch_key] ) ) {
				$branches[$branch_key] = array( 'branch_id'=>$row['branch_id'], 'branch_name'=>$row['branch_name'], 'total'=>0, 'new_count'=>0, 'first_contact_count'=>0, 'valid_first_contact_count'=>0, 'avg_first_contact_seconds'=>null, 'overdue_count'=>0, 'won_count'=>0, 'lost_count'=>0, '_weighted_seconds'=>0.0 );
			}
			foreach ( array( 'total', 'new_count', 'first_contact_count', 'valid_first_contact_count', 'overdue_count', 'won_count', 'lost_count' ) as $key ) { $branches[$branch_key][$key] += $row[$key]; }
			if ( null !== $row['avg_first_contact_seconds'] ) { $branches[$branch_key]['_weighted_seconds'] += $row['avg_first_contact_seconds'] * $row['valid_first_contact_count']; }
			foreach ( array( 'total'=>'total', 'new_count'=>'new', 'first_contact_count'=>'first_contact', 'overdue_count'=>'overdue', 'won_count'=>'won', 'lost_count'=>'lost' ) as $source => $target ) { $totals[$target] += $row[$source]; }
			if ( null !== $row['avg_first_contact_seconds'] ) { $weighted_seconds += $row['avg_first_contact_seconds'] * $row['valid_first_contact_count']; }
		}
		$valid_contact_count = array_sum( array_column( $rows, 'valid_first_contact_count' ) );
		if ( $valid_contact_count > 0 ) { $totals['avg_first_contact_seconds'] = $weighted_seconds / $valid_contact_count; }
		foreach ( $branches as &$branch ) {
			if ( $branch['valid_first_contact_count'] > 0 ) { $branch['avg_first_contact_seconds'] = $branch['_weighted_seconds'] / $branch['valid_first_contact_count']; }
			unset( $branch['valid_first_contact_count'] );
			unset( $branch['_weighted_seconds'] );
		}
		unset( $branch );
		foreach ( $pipeline as &$item ) { $item['branch_id'] = (int) $item['branch_id']; $item['total'] = (int) $item['total']; }
		unset( $item );
		foreach ( $transitions as &$transition ) {
			foreach ( array( 'branch_id', 'transition_count', 'lead_count' ) as $key ) { $transition[$key] = (int) $transition[$key]; }
			$transition['avg_duration_seconds'] = null === $transition['avg_duration_seconds'] ? null : (float) $transition['avg_duration_seconds'];
			$source_key = implode( '|', array( $transition['branch_id'], $transition['from_stage'] ) );
			$transition['destination_share_percent'] = ! empty( $transition_source_counts[$source_key] ) ? 100 * $transition['lead_count'] / $transition_source_counts[$source_key] : null;
			$transition['transition_rate_percent'] = $transition['destination_share_percent'];
		}
		unset( $transition );

		return array( 'from'=>$from, 'to'=>$to, 'totals'=>$totals, 'branches'=>array_values( $branches ), 'pipeline'=>$pipeline, 'transitions'=>$transitions );
	}
}
