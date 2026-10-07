<?php
/** Local CLI audit: SHORTINIT avoids plugins/cron; every audit query runs in a read-only transaction. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'SHORTINIT', true );
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
require dirname( __DIR__ ) . '/wp-content/plugins/auto-dealership-core/src/Pricing/Money.php';
use AutoDealership\Pricing\Money;
global $wpdb;
$wpdb->hide_errors();
if ( false === $wpdb->query( 'START TRANSACTION READ ONLY' ) ) { fwrite( STDERR, "Cannot start a read-only audit.\n" ); exit( 1 ); }
try {
	$definitions = array(
		'vehicles'=>array( 'retail_price','minimum_price','purchase_cost','additional_cost','total_cost','wholesale_price' ),
		'quotations'=>array( 'base_amount','fee_amount','promotion_amount','discount_amount','subtotal_amount','tax_amount','final_amount' ),
		'quotation_versions'=>array( 'base_amount','fee_amount','promotion_amount','discount_amount','subtotal_amount','tax_amount','final_amount' ),
		'reservations'=>array( 'deposit_required_amount','deposit_amount' ),
		'payment_confirmations'=>array( 'amount' ), 'reservation_deposits'=>array( 'amount' ), 'payment_refunds'=>array( 'amount' ),
		'finance_requests'=>array( 'requested_amount','down_payment','monthly_payment' ),
	);
	$report = array( 'read_only'=>true, 'currency'=>'SAR', 'storage_unit'=>'halala', 'tables'=>array(), 'findings'=>array(), 'limits'=>array() );
	$rows_by_table = array();
	foreach ( $definitions as $name=>$fields ) {
		$table = $wpdb->prefix . 'adc_' . $name;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( ! $exists ) { $report['limits'][] = 'Missing table: ' . $name; continue; }
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
		$select = array_merge( array( 'id' ), $fields );
		foreach ( array( 'currency','tax_rate_bps','public_post_id' ) as $extra ) { if ( in_array( $extra, $columns, true ) ) { $select[] = $extra; } }
		if ( array_diff( $select, $columns ) ) { throw new RuntimeException( 'Financial schema mismatch: ' . $name ); }
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
		$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $select ) . " FROM `$table` ORDER BY id LIMIT 10000", ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Financial audit read failed: ' . $name ); }
		$rows_by_table[$name] = $rows;
		$report['tables'][$name] = array( 'count'=>$count, 'checked'=>count( $rows ), 'invalid_integer_values'=>0, 'non_sar_rows'=>0 );
		if ( $count > count( $rows ) ) { $report['limits'][] = 'Audit sample truncated: ' . $name; }
		foreach ( $rows as $row ) {
			if ( isset( $row['currency'] ) && 'SAR' !== $row['currency'] ) { ++$report['tables'][$name]['non_sar_rows']; }
			foreach ( $fields as $field ) {
				if ( null !== $row[$field] && null === Money::parse( $row[$field] ) ) {
					++$report['tables'][$name]['invalid_integer_values'];
					$report['findings'][] = array( 'table'=>$name,'id'=>$row['id'],'field'=>$field,'reason'=>'invalid_minor_integer' );
				}
			}
		}
	}
	$report['quote_arithmetic'] = array( 'checked'=>0,'unknown_historical_tax'=>0,'mismatches'=>0 );
	foreach ( array( 'quotations','quotation_versions' ) as $name ) {
		foreach ( $rows_by_table[$name] ?? array() as $row ) {
			if ( null === $row['tax_rate_bps'] ) { ++$report['quote_arithmetic']['unknown_historical_tax']; continue; }
			++$report['quote_arithmetic']['checked'];
			try {
				$expected = Money::calculate( (int) $row['base_amount'] + (int) $row['fee_amount'] - (int) $row['promotion_amount'], (int) $row['discount_amount'], (int) $row['tax_rate_bps'] );
				$match = $expected['tax_amount'] === (int) $row['tax_amount'] && $expected['final_amount'] === (int) $row['final_amount'] && $expected['final_amount'] - $expected['tax_amount'] === (int) $row['subtotal_amount'];
			} catch ( Throwable $error ) { $match = false; }
			if ( ! $match ) { ++$report['quote_arithmetic']['mismatches']; $report['findings'][] = array( 'table'=>$name,'id'=>$row['id'],'reason'=>'quote_arithmetic_mismatch' ); }
		}
	}
	$report['legacy_price_comparison'] = array( 'compared'=>0,'equal'=>0,'different'=>0,'missing_or_invalid'=>0 );
	foreach ( $rows_by_table['vehicles'] ?? array() as $row ) {
		if ( empty( $row['public_post_id'] ) ) { continue; }
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_car_price' ORDER BY meta_id LIMIT 1", $row['public_post_id'] ) );
		$legacy_minor = Money::from_sar( $raw );
		if ( null === $legacy_minor ) { ++$report['legacy_price_comparison']['missing_or_invalid']; continue; }
		++$report['legacy_price_comparison']['compared'];
		if ( $legacy_minor === (int) $row['retail_price'] ) { ++$report['legacy_price_comparison']['equal']; }
		else { ++$report['legacy_price_comparison']['different']; $report['findings'][] = array( 'table'=>'vehicles','id'=>$row['id'],'reason'=>'legacy_price_differs_review_source' ); }
	}
	$report['limits'][] = 'Arithmetic and internal price agreement cannot prove agreement with external receipts or contracts.';
	$report['limits'][] = 'No historical value was changed; differing legacy prices can be intentional.';
	echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); $audit_failed = true; }
finally { $wpdb->query( 'ROLLBACK' ); }
if ( ! empty( $audit_failed ) ) { exit( 1 ); }
