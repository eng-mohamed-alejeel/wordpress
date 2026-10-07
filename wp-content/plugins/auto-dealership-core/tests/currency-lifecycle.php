<?php
/** Complete monetary lifecycle on the disposable fixture, through real form handlers and services. */
if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Database\Schema;
use AutoDealership\Pricing\Money;
use AutoDealership\Sales\SalesService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Payments\RefundService;

$currency_admin = get_current_user_id();
$currency_actors = array();
foreach ( array( 'manager'=>'dealership_sales_manager','recorder'=>'dealership_finance','reviewer'=>'dealership_finance' ) as $label=>$role ) {
	$id = wp_insert_user( array( 'user_login'=>'currency_' . $label,'user_email'=>'currency-' . $label . '@example.invalid','user_pass'=>wp_generate_password( 32 ),'role'=>$role ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Currency actor setup failed.' ); }
	update_user_meta( $id, 'adc_branch_id', $currency_branch['id'] ); $currency_actors[$label] = $id;
}
$currency_options = array( 'adc_vat_rate_bps'=>1500,'adc_pricing_fee_amount'=>1234,'adc_promotion_type'=>'none','adc_reservation_deposit_type'=>'fixed','adc_reservation_deposit_value'=>10025,'adc_sales_manager_discount_limit'=>10000,'adc_general_manager_discount_limit'=>1000000 );
$currency_previous_options = array();
foreach ( $currency_options as $key=>$value ) { $currency_previous_options[$key] = get_option( $key, null ); update_option( $key, $value ); }
$currency_listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
$currency_address = stream_socket_get_name( $currency_listener, false ); fclose( $currency_listener );
$currency_origin = 'http://' . $currency_address;
$currency_server = null; $currency_server_log = tempnam( sys_get_temp_dir(), 'adc-currency-http-' );
try {
	update_option( 'active_plugins', array( 'auto-dealership-core/auto-dealership-core.php' ) );
	$currency_server = proc_open( array( PHP_BINARY,'-S',$currency_address,__DIR__ . '/http-router.php' ), array( 0=>array( 'pipe','r' ),1=>array( 'file',$currency_server_log,'a' ),2=>array( 'file',$currency_server_log,'a' ) ), $currency_pipes, ABSPATH, array_merge( getenv(), array( 'ADC_HTTP_TEST_CONFIG'=>ADC_TEST_HTTP_CONFIG,'ADC_THEME_TEST'=>'1','ADC_CURRENCY_BROWSER'=>'1' ) ) );
	if ( ! is_resource( $currency_server ) ) { throw new RuntimeException( 'Currency HTTP server failed.' ); } fclose( $currency_pipes[0] );
	$currency_http = static function( string $path, string $cookie = '', ?array $body = null ) use ( $currency_origin ): array {
		$handle = curl_init( $currency_origin . $path );
		$options = array( CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIE=>$cookie );
		if ( null !== $body ) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = http_build_query( $body ); }
		curl_setopt_array( $handle, $options ); $raw = curl_exec( $handle );
		if ( false === $raw ) { throw new RuntimeException( 'Currency HTTP request failed: ' . curl_error( $handle ) ); }
		$size = curl_getinfo( $handle, CURLINFO_HEADER_SIZE ); $status = curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ); curl_close( $handle );
		return array( $status,substr( $raw,$size ),substr( $raw,0,$size ) );
	};
	for ( $i=0;$i<50;++$i ) { $probe = @stream_socket_client( 'tcp://' . $currency_address,$errno,$errstr,0.1 ); if ( $probe ) { fclose( $probe ); break; } usleep( 100000 ); }
	$currency_cookies = array();
	foreach ( array_merge( array( 'admin'=>$currency_admin ),$currency_actors ) as $label=>$id ) {
		$session = $currency_http( '/adc-test-session?user_id=' . $id . '&key=' . hash( 'sha256',ADC_TEST_HTTP_CONFIG . '|session' ) );
		preg_match_all( '/^Set-Cookie:\s*([^;\r\n]+)/mi',$session[2],$matches );
		if ( 200 !== $session[0] || ! $matches[1] ) { throw new RuntimeException( 'Currency actor session failed.' ); }
		$currency_cookies[$label] = implode( '; ',$matches[1] );
	}
	$currency_form = static function( string $action, string $nonce_action, string $actor, array $body ) use ( $currency_http,$currency_cookies ): array {
		$nonce = $currency_http( '/adc-test-nonce?action=' . rawurlencode( $nonce_action ),$currency_cookies[$actor] );
		if ( 200 !== $nonce[0] ) { throw new RuntimeException( 'Currency form nonce failed.' ); }
		return $currency_http( '/wp-admin/admin-post.php',$currency_cookies[$actor],array_merge( array( 'action'=>$action,'_wpnonce'=>trim( $nonce[1] ),'adc_money_unit'=>'SAR' ),$body ) );
	};
	$currency_create = $currency_form( 'adc_create_vehicle','adc_create_vehicle','admin',array( 'vin'=>'TESTSAR0000000002','stock_number'=>'SAR-FORM','brand'=>'Test','model'=>'SAR form fixture','model_year'=>'2026','condition'=>'new','branch_id'=>$currency_branch['id'],'retail_price'=>'2345.67' ) );
	$currency_created_price = $wpdb->get_var( 'SELECT retail_price FROM ' . Schema::table( 'vehicles' ) . " WHERE stock_number='SAR-FORM'" );
	adc_check( 302 === $currency_create[0] && 234567 === (int) $currency_created_price,'Inventory form saves decimal SAR exactly once.' );
	$currency_cost = $currency_form( 'adc_update_vehicle_acquisition','adc_update_vehicle_acquisition_' . $currency_vehicle['id'],'admin',array( 'id'=>$currency_vehicle['id'],'purchase_cost'=>'1000.25','additional_cost'=>'12.34','total_cost'=>'1012.59','wholesale_price'=>'1100.00','reason'=>'Synthetic SAR form verification' ) );
	$currency_cost_record = AutoDealership\Inventory\VehicleAcquisitionService::get( $currency_vehicle['id'] );
	adc_check( 302 === $currency_cost[0] && 101259 === (int) $currency_cost_record['total_cost'],'Acquisition form persists decimal SAR costs without double conversion.' );
	$currency_intake = AutoDealership\Inventory\VehicleIntakeService::receive( $currency_vehicle['id'],array( 'condition'=>'good','document_reference'=>'SAR-RECEIVING' ) );
	$currency_inspection_state = AutoDealership\Inventory\VehicleService::transition( $currency_vehicle['id'],'inspection','Synthetic preparation' );
	$currency_inspection = AutoDealership\Inventory\VehicleIntakeService::inspect( $currency_vehicle['id'],array( 'checklist'=>array_fill_keys( array( 'exterior','interior','engine','tires','vin' ),'pass' ) ) );
	$currency_available = AutoDealership\Inventory\VehicleService::transition( $currency_vehicle['id'],'available','Synthetic passed inspection' );
	adc_check( ! is_wp_error( $currency_intake ) && ! is_wp_error( $currency_inspection_state ) && ! is_wp_error( $currency_inspection ) && ! is_wp_error( $currency_available ),'Currency vehicle completes intake and becomes available.' );
	$currency_lead = AutoDealership\Leads\LeadService::create_public( array( 'name'=>'Synthetic currency customer','mobile'=>'+966500009999','branch_id'=>$currency_branch['id'] ) );
	if ( is_wp_error( $currency_lead ) ) { throw new RuntimeException( 'Currency customer failed.' ); }
	$currency_customer = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d',$currency_lead['id'] ) );
	$currency_quote = SalesService::create_quote( $currency_customer,$currency_vehicle['id'],gmdate( 'Y-m-d',time()+7*DAY_IN_SECONDS ) );
	if ( is_wp_error( $currency_quote ) ) { throw new RuntimeException( 'Currency quote failed: ' . $currency_quote->get_error_code() ); }
	$currency_discount = SalesService::request_discount( $currency_quote['id'],1234,'Synthetic 12.34 SAR discount' );
	if ( is_wp_error( $currency_discount ) ) { throw new RuntimeException( 'Currency discount failed: ' . $currency_discount->get_error_code() ); }
	wp_set_current_user( $currency_actors['manager'] );
	$currency_discount_decision = SalesService::decide_discount( $currency_discount['id'],true,'Synthetic independent approval' );
	wp_set_current_user( $currency_admin );
	$currency_quote_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'quotations' ) . ' WHERE id=%d',$currency_quote['id'] ),ARRAY_A );
	adc_check( ! is_wp_error( $currency_discount_decision ) && 123456 === (int) $currency_quote_row['subtotal_amount'] && 18518 === (int) $currency_quote_row['tax_amount'] && 141974 === (int) $currency_quote_row['final_amount'],'Quote fee, approved discount and VAT retain exact SAR fractions.' );
	$currency_reservation = ReservationService::create( array( 'vehicle_id'=>$currency_vehicle['id'],'customer_id'=>$currency_customer,'idempotency_key'=>wp_generate_uuid4() ) );
	if ( is_wp_error( $currency_reservation ) ) { throw new RuntimeException( 'Currency reservation failed.' ); }
	$currency_deposit_form = $currency_form( 'adc_record_reservation_deposit','adc_record_reservation_deposit','recorder',array( 'reservation_id'=>$currency_reservation['id'],'amount'=>'100.25','source'=>'bank_transfer','reference'=>'SAR-DEPOSIT' ) );
	$currency_deposit = $wpdb->get_row( $wpdb->prepare( 'SELECT id,amount FROM ' . Schema::table( 'reservation_deposits' ) . ' WHERE reservation_id=%d',$currency_reservation['id'] ),ARRAY_A );
	adc_check( 302 === $currency_deposit_form[0] && 10025 === (int) ( $currency_deposit['amount'] ?? 0 ),'Reservation deposit form records 100.25 SAR as 10025 minor units.' );
	wp_set_current_user( $currency_actors['reviewer'] ); $currency_verified_deposit = ReservationService::decide_deposit( $currency_deposit['id'],true,'Synthetic deposit evidence matched' ); wp_set_current_user( $currency_admin );
	$currency_sale = SalesService::create_sale( $currency_quote['id'],$currency_reservation['id'] );
	adc_check( ! is_wp_error( $currency_verified_deposit ) && is_array( $currency_sale ),'Independently verified deposit funds the reservation and permits sale creation.' );
	$currency_finance = $currency_form( 'adc_create_finance_request','adc_create_finance_request_' . $currency_sale['id'],'admin',array( 'sale_id'=>$currency_sale['id'],'provider'=>'Synthetic finance','amount'=>'1319.49','down_payment'=>'100.25','monthly_payment'=>'55.55','term_months'=>'24','consent'=>'1' ) );
	$currency_finance_row = $wpdb->get_row( $wpdb->prepare( 'SELECT requested_amount,down_payment,monthly_payment FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d',$currency_sale['id'] ),ARRAY_A );
	adc_check( 302 === $currency_finance[0] && 131949 === (int) ( $currency_finance_row['requested_amount'] ?? 0 ) && 10025 === (int) $currency_finance_row['down_payment'] && 5555 === (int) $currency_finance_row['monthly_payment'],'Finance form stores requested amount, down payment and monthly payment in the correct units.' );
	$currency_bad_payment = $currency_form( 'adc_record_payment','adc_record_payment','recorder',array( 'sale_id'=>$currency_sale['id'],'amount'=>'1319.491','source'=>'bank_transfer','reference'=>'SAR-INVALID' ) );
	adc_check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'payment_confirmations' ) . " WHERE reference='SAR-INVALID'" ),'Invalid precision through the payment form never writes a receipt.' );
	$currency_payment_form = $currency_form( 'adc_record_payment','adc_record_payment','recorder',array( 'sale_id'=>$currency_sale['id'],'amount'=>'1319.49','source'=>'bank_transfer','reference'=>'SAR-PAYMENT' ) );
	$currency_payment = $wpdb->get_row( $wpdb->prepare( 'SELECT id,amount FROM ' . Schema::table( 'payment_confirmations' ) . ' WHERE sale_id=%d',$currency_sale['id'] ),ARRAY_A );
	adc_check( 302 === $currency_payment_form[0] && 131949 === (int) ( $currency_payment['amount'] ?? 0 ),'Payment form records the exact remaining SAR balance.' );
	wp_set_current_user( $currency_actors['reviewer'] ); $currency_payment_verification = PaymentService::decide( $currency_payment['id'],true,'Synthetic bank evidence matched' );
	adc_check( ! is_wp_error( $currency_payment_verification ) && PaymentService::is_settled( $currency_sale['id'] ),'Deposit plus verified payment settles the immutable quote total.' );
	$currency_finance_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d',$currency_sale['id'] ) );
	adc_check( ! is_wp_error( SalesService::update_finance_status( $currency_finance_id,'approved','SAR-FINANCE','Synthetic independent finance decision' ) ),'Finance request receives an independent decision before sale approval.' );
	wp_set_current_user( $currency_actors['manager'] ); $currency_approval = SalesService::approve_sale( $currency_sale['id'],'SAR-INVOICE' );
	adc_check( ! is_wp_error( $currency_approval ),'Fully funded sale receives independent approval.' );
	wp_set_current_user( $currency_admin );
	$currency_export = AutoDealership\Reports\FinancialExport::create( gmdate( 'Y-m-d' ),gmdate( 'Y-m-d' ) );
	$currency_operational = AutoDealership\Reports\OperationalReport::export( gmdate( 'Y-m-d' ),gmdate( 'Y-m-d' ) );
	adc_check( ! is_wp_error( $currency_export ) && str_contains( $currency_export['csv'],'1419.74' ) && ! str_contains( $currency_export['csv'],'141974' ),'Settlement CSV exports the funded sale in SAR.' );
	adc_check( ! is_wp_error( $currency_operational ) && str_contains( $currency_operational['csv'],'amount_sar' ) && str_contains( $currency_operational['csv'],'1419.74' ) && ! str_contains( $currency_operational['csv'],'141974' ),'Operational CSV exports actual aggregate amounts in SAR.' );
	$currency_print_nonce = $currency_http( '/adc-test-nonce?action=adc_print_quote_' . $currency_quote['id'] . '_3',$currency_cookies['admin'] );
	$currency_print_path = '/wp-admin/admin-post.php?action=adc_print_quote&quote_id=' . $currency_quote['id'] . '&version=3&_wpnonce=' . trim( $currency_print_nonce[1] );
	$currency_print = $currency_http( $currency_print_path,$currency_cookies['admin'] );
	adc_check( 200 === $currency_print[0] && str_contains( $currency_print[1],'1419.74' ) && str_contains( $currency_print[1],'185.18' ),'Printable approved quotation preserves the total and tax in SAR.' );
	wp_set_current_user( $currency_actors['manager'] );
	$currency_cancel = AutoDealership\Sales\SaleCancellationService::cancel( $currency_sale['id'],'Synthetic undelivered sale cancellation' );
	wp_set_current_user( $currency_admin );
	adc_check( is_array( $currency_cancel ) && 141974 === $currency_cancel['verified_amount'],'Cancellation preserves the complete verified refund obligation.' );
	$currency_refund_form = $currency_form( 'adc_request_refund','adc_request_refund','recorder',array( 'cancellation_id'=>$currency_cancel['id'],'amount'=>'0.01','method'=>'bank_transfer','reference'=>'SAR-REFUND-ONE-HALALA' ) );
	$currency_refund = $wpdb->get_row( $wpdb->prepare( 'SELECT id,amount FROM ' . Schema::table( 'payment_refunds' ) . ' WHERE cancellation_id=%d',$currency_cancel['id'] ),ARRAY_A );
	adc_check( 302 === $currency_refund_form[0] && 1 === (int) ( $currency_refund['amount'] ?? 0 ),'Refund form records one halala as 0.01 SAR.' );
	wp_set_current_user( $currency_actors['reviewer'] ); $currency_first_refund = RefundService::decide( $currency_refund['id'],true,'Synthetic partial refund verified' ); wp_set_current_user( $currency_admin );
	$currency_final_refund_form = $currency_form( 'adc_request_refund','adc_request_refund','recorder',array( 'cancellation_id'=>$currency_cancel['id'],'amount'=>'1419.73','method'=>'bank_transfer','reference'=>'SAR-REFUND-REMAINING' ) );
	$currency_final_refund_id = $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'payment_refunds' ) . " WHERE reference='SAR-REFUND-REMAINING'" );
	wp_set_current_user( $currency_actors['reviewer'] ); $currency_last_refund = RefundService::decide( (int) $currency_final_refund_id,true,'Synthetic final refund verified' ); wp_set_current_user( $currency_admin );
	$currency_refunded = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount) FROM ' . Schema::table( 'payment_refunds' ) . " WHERE cancellation_id=%d AND status='verified'",$currency_cancel['id'] ) );
	adc_check( ! is_wp_error( $currency_first_refund ) && 302 === $currency_final_refund_form[0] && ! is_wp_error( $currency_last_refund ) && 141974 === (int) $currency_refunded,'Partial and final verified refunds reconcile exactly to the received total.' );
	if ( '1' === getenv( 'ADC_CURRENCY_VISUAL' ) ) {
		$browser = proc_open( array( getenv( 'ADC_NODE' ) ?: 'C:/Program Files/nodejs/node.exe',__DIR__ . '/browser-currency.cjs' ),array( 0=>array( 'pipe','r' ),1=>STDOUT,2=>STDERR ),$browser_pipes );
		fwrite( $browser_pipes[0],wp_json_encode( array( 'origin'=>$currency_origin,'cookie'=>$currency_cookies['admin'],'print_path'=>$currency_print_path ) ) ); fclose( $browser_pipes[0] );
		if ( 0 !== proc_close( $browser ) ) { throw new RuntimeException( 'Currency visual browser checks failed.' ); }
	}
} finally {
	wp_set_current_user( $currency_admin );
	foreach ( $currency_previous_options as $key=>$value ) { null === $value ? delete_option( $key ) : update_option( $key,$value ); }
	if ( is_resource( $currency_server ) ) { proc_terminate( $currency_server ); proc_close( $currency_server ); }
	echo 'Currency lifecycle HTTP server stopped. Log: ' . $currency_server_log . "\n";
}
