<?php
namespace AutoDealership\Reports;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Branch-scoped aggregate operations report without customer or vehicle identity data. */
final class OperationalReport {
	public const MAX_DAYS = 366;
	public const SECTIONS = array( 'inventory', 'leads', 'reservations', 'quotations', 'sales', 'deliveries', 'exceptions' );

	public static function summary( string $from, string $to ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_reports' ) ) { return self::error( 'adc_report_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_report_schema', 503 ); }
		$range = self::range( $from, $to );
		if ( is_wp_error( $range ) ) { return $range; }
		list( $start, $end ) = $range;

		$sections = array();
		$definitions = array(
			'inventory' => array(
				'sql' => 'SELECT v.branch_id,b.name branch_name,v.status,COUNT(*) total,0 amount FROM ' . Schema::table( 'vehicles' ) . ' v LEFT JOIN ' . Schema::table( 'branches' ) . ' b ON b.id=v.branch_id WHERE %s GROUP BY v.branch_id,b.name,v.status ORDER BY v.branch_id,v.status',
				'branch' => 'v.branch_id', 'dates' => false,
			),
			'leads' => array(
				'sql' => 'SELECT l.branch_id,b.name branch_name,l.stage status,COUNT(*) total,0 amount FROM ' . Schema::table( 'leads' ) . ' l LEFT JOIN ' . Schema::table( 'branches' ) . ' b ON b.id=l.branch_id WHERE %s AND l.created_at>=%s AND l.created_at<%s GROUP BY l.branch_id,b.name,l.stage ORDER BY l.branch_id,l.stage',
				'branch' => 'l.branch_id', 'dates' => true,
			),
			'reservations' => array(
				'sql' => 'SELECT r.branch_id,b.name branch_name,r.status,COUNT(*) total,0 amount FROM ' . Schema::table( 'reservations' ) . ' r LEFT JOIN ' . Schema::table( 'branches' ) . ' b ON b.id=r.branch_id WHERE %s AND r.created_at>=%s AND r.created_at<%s GROUP BY r.branch_id,b.name,r.status ORDER BY r.branch_id,r.status',
				'branch' => 'r.branch_id', 'dates' => true,
			),
			'quotations' => array(
				'sql' => 'SELECT q.branch_id,b.name branch_name,q.status,COUNT(*) total,COALESCE(SUM(q.final_amount),0) amount FROM ' . Schema::table( 'quotations' ) . ' q LEFT JOIN ' . Schema::table( 'branches' ) . ' b ON b.id=q.branch_id WHERE %s AND q.created_at>=%s AND q.created_at<%s GROUP BY q.branch_id,b.name,q.status ORDER BY q.branch_id,q.status',
				'branch' => 'q.branch_id', 'dates' => true,
			),
			'sales' => array(
				'sql' => 'SELECT q.branch_id,b.name branch_name,s.status,COUNT(*) total,COALESCE(SUM(q.final_amount),0) amount FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id LEFT JOIN ' . Schema::table( 'branches' ) . ' b ON b.id=q.branch_id WHERE %s AND s.created_at>=%s AND s.created_at<%s GROUP BY q.branch_id,b.name,s.status ORDER BY q.branch_id,s.status',
				'branch' => 'q.branch_id', 'dates' => true,
			),
			'deliveries' => array(
				'sql' => 'SELECT q.branch_id,b.name branch_name,d.status,COUNT(*) total,0 amount FROM ' . Schema::table( 'deliveries' ) . ' d INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=d.sale_id INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id LEFT JOIN ' . Schema::table( 'branches' ) . ' b ON b.id=q.branch_id WHERE %s AND d.created_at>=%s AND d.created_at<%s GROUP BY q.branch_id,b.name,d.status ORDER BY q.branch_id,d.status',
				'branch' => 'q.branch_id', 'dates' => true,
			),
		);

		foreach ( $definitions as $key => $definition ) {
			list( $scope, $scope_args ) = BranchScope::predicate( $definition['branch'] );
			$sql = sprintf( $definition['sql'], $scope, '%s', '%s' );
			$args = $scope_args;
			if ( $definition['dates'] ) { $args = array_merge( $args, array( $start, $end ) ); }
			$rows = self::query( $sql, $args );
			if ( is_wp_error( $rows ) ) { return $rows; }
			$sections[ $key ] = self::cast_rows( $rows );
		}

		$exceptions = self::exceptions();
		if ( is_wp_error( $exceptions ) ) { return $exceptions; }
		$sections['exceptions'] = $exceptions;

		return array(
			'from' => $from,
			'to' => $to,
			'generated_at' => current_time( 'mysql', true ),
			'sections' => $sections,
		);
	}

	public static function export( string $from, string $to ) {
		$report = self::summary( $from, $to );
		if ( is_wp_error( $report ) ) { return $report; }
		$row_count = array_sum( array_map( 'count', $report['sections'] ) );
		if ( ! AuditLog::record( 'operations.report_exported', 'operational_report', 0, 'Operational aggregate CSV export', null, array( 'from'=>$from, 'to'=>$to, 'row_count'=>$row_count ) ) ) {
			return self::error( 'adc_report_audit_failed', 500 );
		}
		return array( 'csv'=>self::csv( $report ), 'rows'=>$row_count );
	}

	private static function exceptions() {
		$queries = array(
			array( 'key'=>'lead_follow_up_overdue', 'branch'=>'l.branch_id', 'sql'=>'SELECT l.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'leads' ) . " l LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=l.branch_id WHERE %s AND l.stage NOT IN ('won','lost') AND l.next_action_at IS NOT NULL AND l.next_action_at<%s GROUP BY l.branch_id,b.name", 'time'=>current_time( 'mysql', true ) ),
			array( 'key'=>'reservation_expired_confirmed', 'branch'=>'r.branch_id', 'sql'=>'SELECT r.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'reservations' ) . " r LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=r.branch_id WHERE %s AND r.status='confirmed' AND r.expires_at<=%s GROUP BY r.branch_id,b.name", 'time'=>current_time( 'mysql', true ) ),
			array( 'key'=>'vehicle_issue_open', 'branch'=>'v.branch_id', 'sql'=>'SELECT v.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'vehicle_issues' ) . ' i INNER JOIN ' . Schema::table( 'vehicles' ) . " v ON v.id=i.vehicle_id LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=v.branch_id WHERE %s AND i.status='open' GROUP BY v.branch_id,b.name" ),
			array( 'key'=>'discount_pending', 'branch'=>'q.branch_id', 'sql'=>'SELECT q.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'discount_requests' ) . ' d INNER JOIN ' . Schema::table( 'quotations' ) . " q ON q.id=d.quotation_id LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=q.branch_id WHERE %s AND d.status='pending' GROUP BY q.branch_id,b.name" ),
			array( 'key'=>'finance_request_pending', 'branch'=>'q.branch_id', 'sql'=>'SELECT q.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'finance_requests' ) . ' f INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=f.sale_id INNER JOIN ' . Schema::table( 'quotations' ) . " q ON q.id=s.quotation_id LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=q.branch_id WHERE %s AND f.status IN ('submitted','under_review') GROUP BY q.branch_id,b.name" ),
			array( 'key'=>'payment_evidence_pending', 'branch'=>'q.branch_id', 'sql'=>'SELECT q.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'payment_confirmations' ) . ' p INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=p.sale_id INNER JOIN ' . Schema::table( 'quotations' ) . " q ON q.id=s.quotation_id LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=q.branch_id WHERE %s AND p.status='pending' GROUP BY q.branch_id,b.name" ),
			array( 'key'=>'reservation_deposit_pending', 'branch'=>'r.branch_id', 'sql'=>'SELECT r.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'reservation_deposits' ) . ' d INNER JOIN ' . Schema::table( 'reservations' ) . " r ON r.id=d.reservation_id LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=r.branch_id WHERE %s AND d.status='pending' GROUP BY r.branch_id,b.name" ),
			array( 'key'=>'refund_pending', 'branch'=>'q.branch_id', 'sql'=>'SELECT q.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'payment_refunds' ) . ' r INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=r.sale_id INNER JOIN ' . Schema::table( 'quotations' ) . " q ON q.id=s.quotation_id LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=q.branch_id WHERE %s AND r.status='pending' GROUP BY q.branch_id,b.name" ),
			array( 'key'=>'reservation_refund_pending', 'branch'=>'rs.branch_id', 'sql'=>'SELECT rs.branch_id,b.name branch_name,%s status,COUNT(*) total,0 amount FROM ' . Schema::table( 'payment_refunds' ) . ' r INNER JOIN ' . Schema::table( 'reservations' ) . " rs ON rs.id=r.reservation_id LEFT JOIN " . Schema::table( 'branches' ) . " b ON b.id=rs.branch_id WHERE %s AND r.status='pending' GROUP BY rs.branch_id,b.name" ),
		);
		$result = array();
		foreach ( $queries as $definition ) {
			list( $scope, $scope_args ) = BranchScope::predicate( $definition['branch'] );
			$tail_args = isset( $definition['time'] ) ? array( $definition['time'] ) : array();
			$args = array_merge( array( $definition['key'] ), $scope_args, $tail_args );
			$rows = self::query( sprintf( $definition['sql'], '%s', $scope, '%s', '%s' ), $args );
			if ( is_wp_error( $rows ) ) { return $rows; }
			$result = array_merge( $result, self::cast_rows( $rows ) );
		}
		usort( $result, static fn( array $a, array $b ): int => array( $a['branch_id'], $a['status'] ) <=> array( $b['branch_id'], $b['status'] ) );
		return $result;
	}

	private static function query( string $sql, array $args ) {
		global $wpdb;
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return self::error( 'adc_report_query_failed', 500 ); }
		return is_array( $rows ) ? $rows : array();
	}

	private static function cast_rows( array $rows ): array {
		return array_map( static function ( array $row ): array {
			return array(
				'branch_id'=>(int) $row['branch_id'],
				'branch_name'=>(string) ( $row['branch_name'] ?: __( 'Unassigned', 'auto-dealership-core' ) ),
				'status'=>(string) $row['status'],
				'total'=>(int) $row['total'],
				'amount'=>(int) $row['amount'],
			);
		}, $rows );
	}

	private static function range( string $from, string $to ) {
		$timezone = new \DateTimeZone( 'UTC' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $timezone );
		$end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $timezone );
		if ( ! $start || ! $end || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to || $end < $start || $start->diff( $end )->days > self::MAX_DAYS ) {
			return self::error( 'adc_report_dates', 400 );
		}
		return array( $from . ' 00:00:00', $end->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) );
	}

	private static function csv( array $report ): string {
		$stream = fopen( 'php://temp', 'w+' );
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, array( 'report','branch_id','branch_name','status','count','amount_sar','currency','from','to','generated_at_utc' ) );
		foreach ( $report['sections'] as $section => $rows ) {
			foreach ( $rows as $row ) {
				$currency = in_array( $section, array( 'quotations','sales' ), true ) ? 'SAR' : '';
				$amount = $currency ? \AutoDealership\Pricing\Money::decimal( $row['amount'] ) : '';
				$cells = array( $section,$row['branch_id'],$row['branch_name'],$row['status'],$row['total'],$amount,$currency,$report['from'],$report['to'],$report['generated_at'] );
				fputcsv( $stream, array_map( array( self::class, 'safe_cell' ), $cells ) );
			}
		}
		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );
		return (string) $csv;
	}

	private static function safe_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Operational report failed.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
