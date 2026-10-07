<?php
namespace AutoDealership\Reports;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Audited, branch-scoped and minimized settlement export. */
final class FinancialExport {
	public static function create( string $from, string $to ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_finance' ) ) { return new \WP_Error( 'adc_export_forbidden', __( 'Finance viewing permission is required.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new \DateTimeZone( 'UTC' ) );
		$end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, new \DateTimeZone( 'UTC' ) );
		if ( ! $start || ! $end || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to || $end < $start || $start->diff( $end )->days > 366 ) {
			return new \WP_Error( 'adc_export_dates', __( 'Choose a valid date range of at most 366 days.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		list( $scope, $scope_args ) = BranchScope::predicate( 'v.branch_id' );
		$sql = 'SELECT s.id AS sale_id,s.status,v.branch_id,v.stock_number,q.quote_number,q.final_amount,\'SAR\' AS currency,s.invoice_reference,s.created_at,MAX(r.deposit_amount)+COALESCE(SUM(CASE WHEN p.status=\'verified\' THEN p.amount ELSE 0 END),0) AS verified_amount FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'reservations' ) . ' r ON r.id=s.reservation_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id LEFT JOIN ' . Schema::table( 'payment_confirmations' ) . ' p ON p.sale_id=s.id WHERE ' . $scope . ' AND s.created_at >= %s AND s.created_at < %s GROUP BY s.id,s.status,v.branch_id,v.stock_number,q.quote_number,q.final_amount,s.invoice_reference,s.created_at ORDER BY s.id ASC LIMIT 5001';
		$args = array_merge( $scope_args, array( $from . ' 00:00:00', $end->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
		$truncated = count( $rows ) > 5000;
		if ( $truncated ) { array_pop( $rows ); }
		if ( ! AuditLog::record( 'finance.exported', 'financial_report', 0, 'Financial settlement CSV export', null, array( 'from' => $from, 'to' => $to, 'row_count' => count( $rows ), 'truncated' => $truncated ) ) ) {
			return new \WP_Error( 'adc_export_audit_failed', __( 'The export could not be audited.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'csv' => self::csv( $rows ), 'rows' => count( $rows ), 'truncated' => $truncated );
	}

	private static function csv( array $rows ): string {
		$stream = fopen( 'php://temp', 'w+' );
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, array( 'sale_id','status','branch_id','stock_number','quote_number','final_amount_sar','currency','invoice_reference','created_at','verified_amount_sar' ) );
		foreach ( $rows as $row ) { foreach ( array( 'final_amount','verified_amount' ) as $field ) { $row[$field] = \AutoDealership\Pricing\Money::decimal( (string) $row[$field] ); } fputcsv( $stream, array_map( array( self::class, 'safe_cell' ), array_values( $row ) ) ); }
		rewind( $stream ); $csv = stream_get_contents( $stream ); fclose( $stream ); return (string) $csv;
	}

	private static function safe_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}
}
