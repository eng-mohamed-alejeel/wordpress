<?php
/** Runs only inside the disposable database suite with real WordPress REST classes. */
use AutoDealership\API\CurrencyContract;
use AutoDealership\Pricing\Money;

$sar = CurrencyContract::input( array( 'amount_major'=>'1234.56', 'term_months'=>24 ) );
adc_check( 123456 === $sar['amount'] && 24 === $sar['term_months'] && ! isset( $sar['amount_major'] ), 'SAR API fields convert once without changing duration.' );
adc_check( is_wp_error( CurrencyContract::input( array( 'amount'=>123456,'amount_major'=>'1234.56' ) ) ), 'Mixed API units fail closed.' );
adc_check( is_wp_error( CurrencyContract::input( array( 'amount_major'=>'0.001' ) ) ), 'API excess precision fails closed.' );
adc_check( 123 === CurrencyContract::input( array( 'acquisition'=>array( 'purchase_cost_major'=>'1.23' ) ) )['acquisition']['purchase_cost'], 'Nested acquisition fields use SAR conversion.' );
$affiliate_summary_response = rest_do_request( new WP_REST_Request( 'GET', '/auto-dealership/v2/affiliate/account/summary' ) );
adc_check( 200 === $affiliate_summary_response->get_status() && 'SAR' === ( $affiliate_summary_response->get_data()['currency'] ?? '' ) && isset( $affiliate_summary_response->get_data()['net_ledger_amount'] ), 'Affiliate account summary REST contract exposes explicit SAR totals.' );
$affiliate_list_request = new WP_REST_Request( 'GET', '/auto-dealership/v2/affiliate/commissions' );
$affiliate_list_request->set_param( 'per_page', 1 );
$affiliate_list_response = rest_do_request( $affiliate_list_request );
adc_check( 200 === $affiliate_list_response->get_status() && 'SAR' === ( $affiliate_list_response->get_data()['currency'] ?? '' ) && isset( $affiliate_list_response->get_data()['items'], $affiliate_list_response->get_data()['pages'] ), 'Affiliate commission REST ledger exposes bounded pagination and currency.' );
$endpoint = CurrencyContract::endpoint( array( 'args'=>array( 'amount'=>array( 'type'=>'integer','required'=>true,'minimum'=>1 ), 'down_payment'=>array( 'type'=>'integer','default'=>0,'minimum'=>0 ) ), 'callback'=>static fn($r)=>rest_ensure_response( array( 'amount'=>$r['amount'],'down_payment'=>$r['down_payment'] ) ) ) );
$request = new WP_REST_Request( 'POST', '/currency-test' );
$request->set_param( 'amount_major', '1234.56' );
$response = $endpoint['callback']( $request );
adc_check( 123456 === $response->get_data()['amount'] && '1234.56' === $response->get_data()['amount_major'] && 0 === $response->get_data()['down_payment'], 'Endpoint preserves old fields, adds SAR output and applies optional defaults.' );
$request = new WP_REST_Request( 'POST', '/currency-test' );
adc_check( is_wp_error( $endpoint['callback']( $request ) ), 'Missing required API amount remains rejected.' );
$request->set_param( 'amount_major', '0.00' );
adc_check( is_wp_error( $endpoint['callback']( $request ) ), 'SAR input respects original positive bounds.' );
$request = new WP_REST_Request( 'POST', '/currency-test' ); $request->set_param( 'amount', 123456 );
adc_check( 123456 === $endpoint['callback']( $request )->get_data()['amount'], 'Legacy minor-unit API remains compatible.' );
$formatted = CurrencyContract::output( array( 'id'=>123,'margin_before'=>-123,'total_cost'=>null,'items'=>array( array( 'retail_price'=>123456 ) ) ) );
adc_check( 123 === $formatted['id'] && '-1.23' === $formatted['margin_before_major'] && null === $formatted['total_cost_major'] && '1234.56' === $formatted['items'][0]['retail_price_major'], 'Output preserves IDs, signed margins, unknown costs and nested records.' );
$form = Money::form_values( array( 'purchase_cost'=>'','total_cost'=>'12.34','supplier_id'=>123 ), array( 'purchase_cost','total_cost','wholesale_price' ), array( 'purchase_cost' ) );
adc_check( '' === $form['purchase_cost'] && 1234 === $form['total_cost'] && ! isset( $form['wholesale_price'] ) && 123 === $form['supplier_id'], 'Partial cost forms preserve unknowns and omitted fields.' );
$csv_method = new ReflectionMethod( AutoDealership\Reports\FinancialExport::class, 'csv' );
if ( PHP_VERSION_ID < 80100 ) { $csv_method->setAccessible( true ); }
$csv = $csv_method->invoke( null, array( array( 'sale_id'=>1,'status'=>'approved','branch_id'=>1,'stock_number'=>'=FORMULA','quote_number'=>'Q-1','final_amount'=>123456,'currency'=>'SAR','invoice_reference'=>'I-1','created_at'=>'2026-10-07','verified_amount'=>'123456' ) ) );
adc_check( str_contains( $csv, 'final_amount' ) && str_contains( $csv, 'verified_amount' ) && str_contains( $csv, '1234.56' ) && ! str_contains( $csv, '123456' ) && str_contains( $csv, "'=FORMULA" ), 'CSV exports exact SAR values with explicit unit column and formula protection.' );
