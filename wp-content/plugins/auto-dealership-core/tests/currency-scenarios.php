<?php
/** Focused currency integration; source wp-config.php and source database are never loaded. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
$checks = 0;
function adc_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['checks']; echo "PASS: $message\n";
}
require __DIR__ . '/increment-currency.php';
$currency_branch = AutoDealership\Branches\BranchService::create( array( 'name'=>'Currency fixture','code'=>'SAR-TEST' ) );
adc_check( is_array( $currency_branch ), 'Currency fixture branch is created.' );
$currency_request = new WP_REST_Request( 'POST', '/auto-dealership/v1/vehicles' );
$currency_request->set_body_params( array( 'vin'=>'TESTSAR0000000001','stock_number'=>'SAR-1','brand'=>'Test','model'=>'SAR fixture','model_year'=>2026,'condition'=>'new','branch_id'=>$currency_branch['id'],'retail_price_sar'=>'1234.56' ) );
$currency_response = rest_do_request( $currency_request );
$currency_vehicle = $currency_response->get_data();
adc_check( 200 === $currency_response->get_status() && isset( $currency_vehicle['id'] ), 'Real registered inventory REST endpoint accepts SAR input.' );
$currency_record = AutoDealership\Inventory\VehicleService::get( $currency_vehicle['id'] );
adc_check( is_array( $currency_record ) && 123456 === (int) $currency_record['retail_price'], 'SAR API input is stored as exact integer minor units.' );
$currency_acquisition = new WP_REST_Request( 'PATCH', '/auto-dealership/v1/vehicles/' . $currency_vehicle['id'] . '/acquisition' );
$currency_acquisition->set_body_params( array( 'acquisition'=>array( 'purchase_cost_sar'=>'1000.25','additional_cost_sar'=>'0.01','total_cost_sar'=>'1000.26','wholesale_price_sar'=>'1100.00' ),'reason'=>'Synthetic currency verification' ) );
$currency_cost_response = rest_do_request( $currency_acquisition );
adc_check( 200 === $currency_cost_response->get_status(), 'Real acquisition REST endpoint accepts nested SAR fields.' );
$currency_get = rest_do_request( new WP_REST_Request( 'GET', '/auto-dealership/v1/vehicles/' . $currency_vehicle['id'] . '/acquisition' ) );
adc_check( '1000.25' === ( $currency_get->get_data()['purchase_cost_sar'] ?? '' ) && 100025 === (int) ( $currency_get->get_data()['purchase_cost'] ?? 0 ), 'Real acquisition output exposes explicit SAR alongside compatible minor units.' );
ob_start(); AutoDealership\Admin\VehicleAcquisitionPage::render(); $currency_html = ob_get_clean();
adc_check( str_contains( $currency_html, '1000.25' ) && ! str_contains( $currency_html, '100025' ) && str_contains( $currency_html, 'step="0.01"' ), 'Acquisition form and table render actual SAR values.' );
ob_start(); AutoDealership\Admin\OperationsPages::render(); $currency_html = ob_get_clean();
adc_check( str_contains( $currency_html, '1234.56' ) && ! str_contains( $currency_html, '123456' ), 'Inventory view renders SAR without exposing raw minor units.' );
$currency_filters = AutoDealership\Inventory\PublicCatalog::normalize_filters( array( 'min_price'=>'12.34','max_price'=>'56.78' ), true );
adc_check( 1234 === $currency_filters['min_price'] && 5678 === $currency_filters['max_price'], 'Public price filters preserve halala fractions in SAR input.' );
$currency_request = new WP_REST_Request( 'GET', '/auto-dealership/v2/vehicles/' . $currency_vehicle['id'] . '/acquisition' );
$currency_response = rest_do_request( $currency_request );
$currency_response = apply_filters( 'rest_post_dispatch', $currency_response, rest_get_server(), $currency_request );
adc_check( true === $currency_response->get_data()['success'] && '1000.25' === $currency_response->get_data()['data']['purchase_cost_sar'], 'V2 envelope retains explicit SAR amounts.' );
$currency_request = new WP_REST_Request( 'POST', '/auto-dealership/v1/vehicles' );
$currency_request->set_body_params( array( 'retail_price'=>123456,'retail_price_sar'=>'1234.56' ) );
adc_check( 400 === rest_do_request( $currency_request )->get_status(), 'Registered REST rejects mixed units before inventory creation.' );
update_option( 'adc_pricing_fee_amount', 5025 );
update_option( 'adc_promotion_type', 'fixed' ); update_option( 'adc_promotion_value', 123456 );
update_option( 'adc_reservation_deposit_type', 'percentage' ); update_option( 'adc_reservation_deposit_value', 1500 );
ob_start(); AutoDealership\Admin\SettingsPage::render(); $currency_html = ob_get_clean();
adc_check( str_contains( $currency_html, 'value="50.25"' ) && str_contains( $currency_html, 'value="1234.56"' ) && str_contains( $currency_html, 'value="1500"' ), 'Settings render fixed amounts in SAR and preserve percentage basis points.' );
adc_check( str_contains( $currency_html, 'name="adc_money_unit" value="SAR"' ), 'Money forms declare their unit explicitly.' );
$currency_die_handler = static fn()=>static function($message){ throw new RuntimeException( (string) $message ); };
add_filter( 'wp_die_handler', $currency_die_handler );
try {
	$_POST = array(); $currency_rejected = false;
	try { AutoDealership\Pricing\Money::require_sar_form(); } catch ( RuntimeException $error ) { $currency_rejected = true; }
	adc_check( $currency_rejected, 'Stale forms opened before the currency change are rejected.' );
	$_POST = array( 'adc_money_unit'=>'SAR' ); AutoDealership\Pricing\Money::require_sar_form();
	adc_check( true, 'Current SAR forms pass the unit guard.' );
	$currency_rejected = false;
	try { AutoDealership\Pricing\Money::form_values( array( 'amount'=>'1.001' ), array( 'amount' ) ); } catch ( RuntimeException $error ) { $currency_rejected = true; }
	adc_check( $currency_rejected, 'Invalid monetary form precision cannot reach business services.' );
} finally { remove_filter( 'wp_die_handler', $currency_die_handler ); $_POST = array(); }
$currency_privacy = new ReflectionMethod( AutoDealership\Privacy\PrivacyTools::class, 'export_value' );
adc_check( '1234.56 SAR' === $currency_privacy->invoke( null, 'amount', '123456' ) && '123456' === $currency_privacy->invoke( null, 'id', '123456' ), 'Privacy export converts money while preserving identifiers.' );
require __DIR__ . '/currency-lifecycle.php';
echo "Completed $checks currency integration checks.\n";
