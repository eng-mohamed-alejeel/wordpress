<?php
/** Local integration smoke test. Run only on disposable/staging WordPress data. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ADC_RUN_INTEGRATION_TESTS' ) ) {
	echo "SKIP: set ADC_RUN_INTEGRATION_TESTS=1 and run from CLI on a disposable database.\n";
	exit( 0 );
}
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
function adc_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
adc_test_assert( ! empty( $admins ) && class_exists( '\\AutoDealership\\Inventory\\VehicleService' ), 'Core plugin is active and an administrator exists.' );
$server = rest_get_server();
do_action( 'rest_api_init', $server );
$routes = $server->get_routes();
adc_test_assert( isset( $routes['/auto-dealership/v1/vehicles'], $routes['/auto-dealership/v1/reservations'], $routes['/auto-dealership/v1/deliveries/(?P<id>\d+)/release'] ), 'Versioned API routes register.' );
wp_set_current_user( $admins[0]->ID );
\AutoDealership\Core\Capabilities::activate();
$suffix = wp_generate_password( 8, false, false );
$users = array();
$rows = array_fill_keys( array( 'branches', 'vehicles', 'customers', 'leads', 'quotations', 'discount_requests', 'reservations', 'sales', 'finance_requests', 'payment_confirmations', 'deliveries' ), 0 );
$legacy_message_id = $legacy_lead_id = $legacy_customer_id = 0;
$vin = '1M8GDM9AXKP' . str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
try {
	$branch = \AutoDealership\Branches\BranchService::create( array( 'code' => 'T' . strtoupper( $suffix ), 'name' => 'Temporary integration branch', 'city' => 'Test' ) );
	adc_test_assert( is_array( $branch ), 'Create isolated branch.' );
	$rows['branches'] = (int) $branch['id'];
	$create_user = static function ( string $role ) use ( &$users, $rows, $suffix ): int {
		$login = 'adc_test_' . $role . '_' . count( $users ) . '_' . $suffix;
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 30 ), 'user_email' => $login . '@example.invalid', 'role' => $role ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Could not create test role user.' ); }
		$users[] = (int) $id;
		update_user_meta( $id, 'adc_branch_id', $rows['branches'] );
		return (int) $id;
	};
	$sales = $create_user( 'dealership_sales' );
	$manager = $create_user( 'dealership_sales_manager' );
	$general = $create_user( 'dealership_general_manager' );
	$inventory = $create_user( 'dealership_inventory' );
	$finance_submitter = $create_user( 'dealership_finance' );
	$finance_approver = $create_user( 'dealership_finance' );
	wp_set_current_user( $inventory );
	$vehicle = \AutoDealership\Inventory\VehicleService::create( array( 'vin' => $vin, 'stock_number' => 'T-' . $suffix, 'brand' => 'Test', 'model' => 'Test', 'model_year' => (int) gmdate( 'Y' ), 'condition' => 'new', 'branch_id' => $rows['branches'], 'retail_price' => 15000000 ) );
	adc_test_assert( is_array( $vehicle ), 'Create vehicle with a valid unique VIN.' );
	$rows['vehicles'] = (int) $vehicle['id'];
	foreach ( array( 'inspection', 'available' ) as $status ) {
		adc_test_assert( is_array( \AutoDealership\Inventory\VehicleService::transition( $rows['vehicles'], $status, 'Integration test' ) ), 'Vehicle reaches ' . $status . '.' );
	}
	$lead = \AutoDealership\Leads\LeadService::create_public( array( 'name' => 'Temporary test customer', 'mobile' => '+966500000001', 'email' => 'test-' . $suffix . '@example.invalid', 'branch_id' => $rows['branches'] ) );
	adc_test_assert( is_array( $lead ), 'Create public lead.' );
	$rows['leads'] = (int) $lead['id'];
	$rows['customers'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . \AutoDealership\Database\Schema::table( 'leads' ) . ' WHERE id = %d', $rows['leads'] ) );
	$legacy_table = $wpdb->prefix . 'car_dealer_messages';
	$wpdb->insert( $legacy_table, array( 'name' => 'Legacy integration request', 'email' => 'legacy-' . $suffix . '@example.invalid', 'phone' => '+966500000002', 'message' => 'Theme compatibility check', 'status' => 'new', 'customer_reply' => '', 'created_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	$legacy_message_id = (int) $wpdb->insert_id;
	\AutoDealership\Leads\LeadService::capture_theme_request( 'message', $legacy_message_id );
	\AutoDealership\Leads\LeadService::capture_theme_request( 'message', $legacy_message_id );
	$legacy = $wpdb->get_row( $wpdb->prepare( 'SELECT id,customer_id FROM ' . \AutoDealership\Database\Schema::table( 'leads' ) . ' WHERE legacy_request_type = %s AND legacy_request_id = %d', 'message', $legacy_message_id ), ARRAY_A );
	adc_test_assert( (bool) $legacy, 'Import theme request idempotently on the engagement hook.' );
	$legacy_lead_id = (int) $legacy['id'];
	$legacy_customer_id = (int) $legacy['customer_id'];
	wp_set_current_user( $manager );
	adc_test_assert( is_array( \AutoDealership\Leads\LeadService::assign( $rows['leads'], $sales ) ), 'Assign lead to same-branch sales user.' );
	wp_set_current_user( $sales );
	$quote = \AutoDealership\Sales\SalesService::create_quote( $rows['customers'], $rows['vehicles'], gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) );
	adc_test_assert( is_array( $quote ), 'Create quote with configured tax rate.' );
	$rows['quotations'] = (int) $quote['id'];
	$discount = \AutoDealership\Sales\SalesService::request_discount( $rows['quotations'], 10000, 'Integration test' );
	adc_test_assert( is_array( $discount ), 'Submit discount request.' );
	$rows['discount_requests'] = (int) $discount['id'];
	wp_set_current_user( $manager );
	adc_test_assert( is_wp_error( \AutoDealership\Sales\SalesService::decide_discount( $rows['discount_requests'], true ) ), 'High discount requires general manager.' );
	wp_set_current_user( $general );
	adc_test_assert( is_array( \AutoDealership\Sales\SalesService::decide_discount( $rows['discount_requests'], true ) ), 'General manager approves discount.' );
	wp_set_current_user( $sales );
	$reservation = \AutoDealership\Reservations\ReservationService::create( array( 'vehicle_id' => $rows['vehicles'], 'customer_id' => $rows['customers'], 'idempotency_key' => wp_generate_uuid4() ) );
	adc_test_assert( is_array( $reservation ), 'Create atomic reservation.' );
	$rows['reservations'] = (int) $reservation['id'];
	adc_test_assert( is_wp_error( \AutoDealership\Reservations\ReservationService::create( array( 'vehicle_id' => $rows['vehicles'], 'customer_id' => $rows['customers'], 'idempotency_key' => wp_generate_uuid4() ) ) ), 'Prevent duplicate active reservation.' );
	wp_set_current_user( $manager );
	adc_test_assert( is_array( \AutoDealership\Reservations\ReservationService::cancel( $rows['reservations'], 'Integration cancellation' ) ), 'Cancel reservation and release unpaid vehicle.' );
	wp_set_current_user( $sales );
	$reservation = \AutoDealership\Reservations\ReservationService::create( array( 'vehicle_id' => $rows['vehicles'], 'customer_id' => $rows['customers'], 'idempotency_key' => wp_generate_uuid4() ) );
	adc_test_assert( is_array( $reservation ), 'Re-reserve released vehicle.' );
	$rows['reservations'] = (int) $reservation['id'];
	$sale = \AutoDealership\Sales\SalesService::create_sale( $rows['quotations'], $rows['reservations'] );
	adc_test_assert( is_array( $sale ), 'Create sale from matching quote and reservation.' );
	$rows['sales'] = (int) $sale['id'];
	wp_set_current_user( $finance_submitter );
	$finance = \AutoDealership\Sales\SalesService::create_finance_request( $rows['sales'], 'Test provider', 100000, true );
	adc_test_assert( is_array( $finance ), 'Submit finance request with consent.' );
	$rows['finance_requests'] = (int) $finance['id'];
	wp_set_current_user( $general );
	adc_test_assert( is_wp_error( \AutoDealership\Sales\SalesService::approve_sale( $rows['sales'], 'TEST-INVOICE' ) ), 'Block sale while finance request is unresolved.' );
	wp_set_current_user( $finance_approver );
	adc_test_assert( is_array( \AutoDealership\Sales\SalesService::update_finance_status( $rows['finance_requests'], 'approved', 'TEST-REF' ) ), 'Finance decision by a separate user.' );
	wp_set_current_user( $general );
	adc_test_assert( is_array( \AutoDealership\Sales\SalesService::approve_sale( $rows['sales'], 'TEST-INVOICE' ) ), 'Approve sale with invoice reference.' );
	adc_test_assert( is_wp_error( \AutoDealership\Delivery\DeliveryService::prepare( $rows['sales'] ) ), 'Finance approval alone cannot release unpaid inventory.' );
	$amount = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT final_amount FROM ' . \AutoDealership\Database\Schema::table( 'quotations' ) . ' WHERE id = %d', $rows['quotations'] ) );
	wp_set_current_user( $finance_submitter );
	$payment = \AutoDealership\Payments\PaymentService::record( $rows['sales'], $amount, 'finance_disbursement', 'TEST-RECEIPT-' . $suffix );
	adc_test_assert( is_array( $payment ), 'Record external receipt pending separate verification.' );
	$rows['payment_confirmations'] = (int) $payment['id'];
	wp_set_current_user( $finance_approver );
	adc_test_assert( is_array( \AutoDealership\Payments\PaymentService::decide( $payment['id'], true, 'Synthetic receipt verification' ) ), 'Verify full settlement through a separate finance user.' );
	wp_set_current_user( $general );
	$delivery = \AutoDealership\Delivery\DeliveryService::prepare( $rows['sales'] );
	adc_test_assert( is_array( $delivery ), 'Prepare delivery.' );
	$rows['deliveries'] = (int) $delivery['id'];
	wp_set_current_user( $inventory );
	adc_test_assert( is_array( \AutoDealership\Delivery\DeliveryService::confirm_vin( $rows['deliveries'], $vin ) ), 'Confirm VIN.' );
	wp_set_current_user( $general );
	adc_test_assert( is_array( \AutoDealership\Delivery\DeliveryService::approve( $rows['deliveries'] ) ), 'Approve delivery as a different staff member.' );
	adc_test_assert( is_array( \AutoDealership\Delivery\DeliveryService::release( $rows['deliveries'] ) ), 'Release vehicle after approval.' );
} finally {
	foreach ( array( 'deliveries', 'payment_confirmations', 'finance_requests', 'sales', 'reservations', 'discount_requests', 'quotations', 'leads', 'customers', 'vehicles', 'branches' ) as $table ) {
		if ( $rows[ $table ] ) { $wpdb->delete( \AutoDealership\Database\Schema::table( $table ), array( 'id' => $rows[ $table ] ), array( '%d' ) ); }
	}
	if ( $legacy_lead_id ) { $wpdb->delete( \AutoDealership\Database\Schema::table( 'leads' ), array( 'id' => $legacy_lead_id ), array( '%d' ) ); }
	if ( $legacy_customer_id ) { $wpdb->delete( \AutoDealership\Database\Schema::table( 'customers' ), array( 'id' => $legacy_customer_id ), array( '%d' ) ); }
	if ( $legacy_lead_id ) { $wpdb->delete( \AutoDealership\Database\Schema::table( 'activities' ), array( 'lead_id' => $legacy_lead_id ), array( '%d' ) ); }
	if ( $legacy_message_id ) { $wpdb->delete( $wpdb->prefix . 'car_dealer_messages', array( 'id' => $legacy_message_id ), array( '%d' ) ); }
	if ( $rows['leads'] ) { $wpdb->delete( \AutoDealership\Database\Schema::table( 'activities' ), array( 'lead_id' => $rows['leads'] ), array( '%d' ) ); }
	if ( $rows['vehicles'] ) { $wpdb->delete( \AutoDealership\Database\Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => $rows['vehicles'] ), array( '%d' ) ); }
	foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
}
