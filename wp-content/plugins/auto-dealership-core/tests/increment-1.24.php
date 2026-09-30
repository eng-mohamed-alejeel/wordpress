<?php
/** Branch-scoped operational reporting acceptance for 1.24. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Admin\OperationalReportPage;
use AutoDealership\Database\Schema;
use AutoDealership\Reports\OperationalReport;

$report_previous_user = get_current_user_id();
$report_from = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$report_to = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
$report_leads_table = Schema::table( 'leads' );
$report_branches_table = Schema::table( 'branches' );
$report_lead = null;
$report_branch_name = null;
$report_refund_reference = 'REPORT-RESERVATION-REFUND-124';

try {
	wp_set_current_user( $sales_a );
	$report_denied = OperationalReport::summary( $report_from, $report_to );
	adc_check( is_wp_error( $report_denied ) && 'adc_report_forbidden' === $report_denied->get_error_code(), 'Sales staff cannot read branch aggregate reports.' );

	wp_set_current_user( $manager_a );
	$invalid_dates = OperationalReport::summary( '2020-01-01', '2022-01-02' );
	adc_check( is_wp_error( $invalid_dates ) && 'adc_report_dates' === $invalid_dates->get_error_code(), 'Operational reporting rejects invalid or overlong date ranges.' );
	$manager_report = OperationalReport::summary( $report_from, $report_to );
	$manager_rows = array_merge( ...array_values( $manager_report['sections'] ) );
	adc_check( is_array( $manager_report ) && OperationalReport::SECTIONS === array_keys( $manager_report['sections'] ) && ! array_filter( $manager_rows, static fn( $row ) => (int) $row['branch_id'] !== (int) $branch_a['id'] ), 'Branch manager report includes every catalogue section and only the assigned active branch.' );

	$quote_rows = $manager_report['sections']['quotations'];
	$quote_count = array_sum( array_column( $quote_rows, 'total' ) );
	$quote_amount = array_sum( array_column( $quote_rows, 'amount' ) );
	$direct_quote = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) total,COALESCE(SUM(final_amount),0) amount FROM ' . Schema::table( 'quotations' ) . ' WHERE branch_id=%d AND created_at>=%s AND created_at<%s', $branch_a['id'], $report_from . ' 00:00:00', gmdate( 'Y-m-d 00:00:00', strtotime( $report_to . ' +1 day UTC' ) ) ), ARRAY_A );
	adc_check( $quote_count === (int) $direct_quote['total'] && $quote_amount === (int) $direct_quote['amount'], 'Operational quotation counts and monetary totals match the scoped source rows.' );

	$report_lead = $wpdb->get_row( $wpdb->prepare( "SELECT id,stage,next_action_at FROM $report_leads_table WHERE branch_id=%d ORDER BY id ASC LIMIT 1", $branch_a['id'] ), ARRAY_A );
	if ( ! $report_lead ) { throw new RuntimeException( 'Operational report lead fixture missing.' ); }
	$wpdb->update( $report_leads_table, array( 'stage'=>'new', 'next_action_at'=>'2000-01-01 00:00:00' ), array( 'id'=>(int) $report_lead['id'] ), array( '%s','%s' ), array( '%d' ) );
	$wpdb->insert( Schema::table( 'payment_refunds' ), array( 'return_id'=>0, 'cancellation_id'=>0, 'reservation_id'=>(int) $reservation['id'], 'sale_id'=>0, 'amount'=>1, 'currency'=>'SAR', 'method'=>'bank_transfer', 'reference'=>$report_refund_reference, 'status'=>'pending', 'requested_by'=>$finance_recorder, 'decided_by'=>0, 'decision_reason'=>'', 'created_at'=>current_time( 'mysql', true ) ) );
	$exception_report = OperationalReport::summary( $report_from, $report_to );
	$overdue = array_values( array_filter( $exception_report['sections']['exceptions'], static fn( $row ) => 'lead_follow_up_overdue' === $row['status'] ) );
	$reservation_refunds = array_values( array_filter( $exception_report['sections']['exceptions'], static fn( $row ) => 'reservation_refund_pending' === $row['status'] ) );
	adc_check( $overdue && array_sum( array_column( $overdue, 'total' ) ) >= 1 && $reservation_refunds && array_sum( array_column( $reservation_refunds, 'total' ) ) >= 1, 'Operational exceptions identify overdue follow-up and pending reservation refunds without exposing record identity.' );

	wp_set_current_user( $auditor );
	$auditor_report = OperationalReport::summary( $report_from, $report_to );
	$auditor_rows = array_merge( ...array_values( $auditor_report['sections'] ) );
	adc_check( is_array( $auditor_report ) && ! array_filter( $auditor_rows, static fn( $row ) => (int) $row['branch_id'] !== (int) $branch_a['id'] ), 'Auditor receives read-only aggregate metadata within their assigned branch.' );

	wp_set_current_user( $admin );
	$global_report = OperationalReport::summary( $report_from, $report_to );
	$global_rows = array_merge( ...array_values( $global_report['sections'] ) );
	adc_check( ! empty( array_filter( $global_rows, static fn( $row ) => (int) $row['branch_id'] === (int) $branch_b['id'] ) ), 'Global administrator report includes operational aggregates from another branch.' );

	$report_branch_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $report_branches_table WHERE id=%d", $branch_a['id'] ) );
	$wpdb->update( $report_branches_table, array( 'name'=>'=REPORT-BRANCH<script>alert(1)</script>' ), array( 'id'=>$branch_a['id'] ), array( '%s' ), array( '%d' ) );
	wp_set_current_user( $manager_a );
	$audit_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'operations.report_exported' ) );
	$export = OperationalReport::export( $report_from, $report_to );
	adc_check( is_array( $export ) && str_starts_with( $export['csv'], "\xEF\xBB\xBF" ) && str_contains( $export['csv'], "'=REPORT-BRANCH" ) && ! str_contains( $export['csv'], 'customer_name' ) && ! str_contains( $export['csv'], 'mobile' ) && ! str_contains( $export['csv'], 'vin' ), 'Operational CSV is aggregate-only, UTF-8 and neutralizes spreadsheet formulas.' );
	adc_check( $audit_before + 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_events' ) . ' WHERE event_key=%s', 'operations.report_exported' ) ), 'Operational CSV export appends a minimized audit event.' );
	adc_check( ! str_contains( $export['csv'], 'Synthetic B' ), 'Branch-scoped operational CSV excludes aggregates from foreign branches.' );

	add_filter( 'query', $break_audit );
	$failed_export = OperationalReport::export( $report_from, $report_to );
	remove_filter( 'query', $break_audit );
	adc_check( is_wp_error( $failed_export ) && 'adc_report_audit_failed' === $failed_export->get_error_code(), 'Operational CSV is withheld when its audit event cannot be persisted.' );

	$break_report_query = static function ( string $query ) use ( $report_leads_table ): string {
		return str_starts_with( $query, 'SELECT l.branch_id' ) ? str_replace( $report_leads_table, 'missing_adc_operational_leads', $query ) : $query;
	};
	add_filter( 'query', $break_report_query );
	$failed_report = OperationalReport::summary( $report_from, $report_to );
	remove_filter( 'query', $break_report_query );
	adc_check( is_wp_error( $failed_report ) && 'adc_report_query_failed' === $failed_report->get_error_code(), 'Operational report fails closed instead of returning partial aggregates after a query error.' );

	$_GET['from'] = $report_from; $_GET['to'] = $report_to;
	ob_start(); OperationalReportPage::render(); $report_html = ob_get_clean();
	adc_check( str_contains( $report_html, 'adc_operational_report_export' ) && str_contains( $report_html, '&lt;script&gt;' ) && ! str_contains( $report_html, '<script>alert(1)</script>' ) && ! str_contains( $report_html, 'Synthetic customer' ), 'Operational report screen renders protected aggregate export controls and escapes branch content without customer identity.' );
} finally {
	if ( $report_lead ) { $wpdb->update( $report_leads_table, array( 'stage'=>$report_lead['stage'], 'next_action_at'=>$report_lead['next_action_at'] ), array( 'id'=>(int) $report_lead['id'] ), array( '%s','%s' ), array( '%d' ) ); }
	if ( null !== $report_branch_name ) { $wpdb->update( $report_branches_table, array( 'name'=>$report_branch_name ), array( 'id'=>$branch_a['id'] ), array( '%s' ), array( '%d' ) ); }
	$wpdb->delete( Schema::table( 'payment_refunds' ), array( 'reference'=>$report_refund_reference ), array( '%s' ) );
	unset( $_GET['from'], $_GET['to'] );
	wp_set_current_user( $report_previous_user );
}
