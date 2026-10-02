<?php
/** Business-model and v2 contract acceptance. Loaded only by the isolated runner. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\API\OpenApiSpecification;
use AutoDealership\API\ResponseContract;
use AutoDealership\Core\Capabilities;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleAcquisitionService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Purchasing\SupplierService;
use AutoDealership\Sales\SalesService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Security\SecurityAudit;

$increment_128_previous_user = get_current_user_id();
try {
	$role_matrix = Capabilities::role_matrix();
	adc_check( in_array( 'adc_manage_vehicle_costs', $role_matrix['dealership_purchasing'], true ) && in_array( 'adc_manage_suppliers', $role_matrix['dealership_purchasing'], true ) && ! in_array( 'adc_view_vehicle_costs', $role_matrix['dealership_inventory'], true ) && ! in_array( 'adc_manage_finance', $role_matrix['dealership_delivery'], true ), 'Purchasing, inventory and delivery roles have distinct 1.28 capabilities.' );
	adc_check( Schema::VERSION === get_option( 'adc_db_version' ) && array() === Schema::verify(), 'Business-model tables and indexes satisfy schema 1.17.0.' );

	wp_set_current_user( $admin );
	$purchasing_128 = $make_user( 'purchasing_128', 'dealership_purchasing', (int) $branch_a['id'] );
	$purchasing_other_128 = $make_user( 'purchasing_other_128', 'dealership_purchasing', (int) $branch_b['id'] );
	$delivery_128 = $make_user( 'delivery_128', 'dealership_delivery', (int) $branch_a['id'] );
	$supplier_128 = SupplierService::create( array( 'supplier_code'=>'VERIFY-128', 'display_name'=>'Synthetic Auto Supply', 'legal_name'=>'Synthetic Auto Supply LLC', 'country'=>'SA', 'contact_name'=>'Fixture contact', 'contact_email'=>'supplier@example.invalid', 'contact_phone'=>'+966500000000', 'tax_number'=>'TEST-128', 'notes'=>'Isolated fixture only' ) );
	adc_check( is_array( $supplier_128 ) && (int) $supplier_128['id'] > 0, 'Supplier creation persists a global reference with restricted contact details.' );
	adc_check( is_wp_error( SupplierService::create( array( 'supplier_code'=>'VERIFY-128', 'display_name'=>'Duplicate' ) ) ), 'Supplier code uniqueness rejects duplicates.' );
	wp_set_current_user( $inventory );
	$inventory_suppliers_128 = SupplierService::all();
	$inventory_supplier_128 = array_values( array_filter( $inventory_suppliers_128, static fn( $row ) => (int) $row['id'] === (int) $supplier_128['id'] ) );
	adc_check( 1 === count( $inventory_supplier_128 ) && ! array_key_exists( 'contact_email', $inventory_supplier_128[0] ) && ! array_key_exists( 'tax_number', $inventory_supplier_128[0] ), 'Inventory can identify suppliers without reading commercial contacts.' );
	adc_check( is_wp_error( SupplierService::set_active( (int) $supplier_128['id'], false, 'Unauthorized' ) ), 'Inventory cannot change supplier status.' );
	wp_set_current_user( $purchasing_128 );
	$purchasing_suppliers_128 = SupplierService::all();
	adc_check( 'supplier@example.invalid' === $purchasing_suppliers_128[0]['contact_email'], 'Purchasing can read restricted supplier fields.' );
	wp_set_current_user( $delivery_128 );
	adc_check( array() === SupplierService::all() && is_wp_error( SupplierService::create( array( 'supplier_code'=>'DENIED-128', 'display_name'=>'Denied' ) ) ), 'Delivery cannot read or create supplier records.' );

	$acquisition_vehicle_128 = $make_vehicle( '128' );
	wp_set_current_user( $purchasing_other_128 );
	adc_check( is_wp_error( VehicleAcquisitionService::get( $acquisition_vehicle_128 ) ) && is_wp_error( VehicleAcquisitionService::update( $acquisition_vehicle_128, array( 'purchase_cost'=>8000000 ), 'Foreign branch' ) ), 'Restricted acquisition reads and writes enforce branch scope.' );
	wp_set_current_user( $purchasing_128 );
	adc_check( is_wp_error( VehicleAcquisitionService::update( $acquisition_vehicle_128, array( 'purchase_cost'=>8000000 ), '' ) ), 'Acquisition changes require an audit reason.' );
	adc_check( is_wp_error( VehicleAcquisitionService::update( $acquisition_vehicle_128, array( 'document_media_ids'=>array( 99999999 ) ), 'Invalid attachment' ) ), 'Acquisition document IDs must identify readable PDF or image attachments.' );
	$acquisition_128 = VehicleAcquisitionService::update( $acquisition_vehicle_128, array( 'supplier_id'=>(int) $supplier_128['id'], 'purchase_cost'=>7900000, 'additional_cost'=>125000, 'total_cost'=>8025000, 'wholesale_price'=>8300000, 'customs_reference'=>'VERIFY-CUSTOMS-128', 'arrival_date'=>'2026-10-01', 'internal_notes'=>'Fixture acquisition only' ), 'Verified acquisition fixture' );
	$acquisition_read_128 = VehicleAcquisitionService::get( $acquisition_vehicle_128 );
	adc_check( is_array( $acquisition_128 ) && is_array( $acquisition_read_128 ) && 7900000 === $acquisition_read_128['purchase_cost'] && 8025000 === $acquisition_read_128['total_cost'] && (int) $supplier_128['id'] === $acquisition_read_128['supplier_id'], 'Purchasing saves audited integer-halala acquisition values.' );
	wp_set_current_user( $inventory );
	$inventory_vehicle_128 = VehicleService::get( $acquisition_vehicle_128, true );
	adc_check( is_array( $inventory_vehicle_128 ) && ! array_key_exists( 'purchase_cost', $inventory_vehicle_128 ) && ! array_key_exists( 'supplier_id', $inventory_vehicle_128 ) && is_wp_error( VehicleAcquisitionService::get( $acquisition_vehicle_128 ) ), 'General inventory reads never reveal restricted costs or supplier details.' );
	wp_set_current_user( $admin );
	adc_check( is_array( SupplierService::set_active( (int) $supplier_128['id'], false, 'End fixture supply' ) ) && null === SupplierService::active( (int) $supplier_128['id'] ), 'Supplier deactivation preserves history while blocking new assignments.' );
	wp_set_current_user( $purchasing_128 );
	adc_check( is_wp_error( VehicleAcquisitionService::update( $acquisition_vehicle_128, array( 'supplier_id'=>(int) $supplier_128['id'] ), 'Inactive supplier' ) ), 'Inactive suppliers cannot be assigned in a new acquisition change.' );
	$locked_vehicle_128 = $make_vehicle( '129' );
	$wpdb->update( Schema::table( 'vehicles' ), array( 'status'=>'delivered' ), array( 'id'=>$locked_vehicle_128 ), array( '%s' ), array( '%d' ) );
	adc_check( is_wp_error( VehicleAcquisitionService::update( $locked_vehicle_128, array( 'purchase_cost'=>4000000 ), 'Locked state' ) ), 'Delivered vehicles reject acquisition edits.' );

	$finance_vehicle_128 = $make_vehicle( '130' );
	wp_set_current_user( $sales_a );
	$finance_quote_128 = SalesService::create_quote( $customer_a, $finance_vehicle_128, $date );
	$finance_reservation_128 = ReservationService::create( array( 'vehicle_id'=>$finance_vehicle_128, 'customer_id'=>$customer_a, 'idempotency_key'=>wp_generate_uuid4() ) );
	$finance_sale_128 = is_array( $finance_quote_128 ) && is_array( $finance_reservation_128 ) ? SalesService::create_sale( (int) $finance_quote_128['id'], (int) $finance_reservation_128['id'] ) : null;
	adc_check( is_array( $finance_sale_128 ), 'Finance fixture has a valid pending sale and customer context.' );
	wp_set_current_user( $finance_recorder );
	$finance_amount_128 = (int) $finance_quote_128['final_amount'] - 100000;
	$finance_first_128 = SalesService::create_finance_request( (int) $finance_sale_128['id'], 'Fixture Provider A', $finance_amount_128, true, array( 'down_payment'=>100000, 'term_months'=>36, 'monthly_payment'=>250000 ) );
	adc_check( is_array( $finance_first_128 ) && 1 === $finance_first_128['attempt_number'] && 0 === $finance_first_128['previous_request_id'], 'First finance provider attempt records consent and commercial terms.' );
	adc_check( is_wp_error( SalesService::create_finance_request( (int) $finance_sale_128['id'], 'Fixture Provider B', $finance_amount_128, true ) ), 'An open finance attempt blocks a second provider.' );
	adc_check( is_wp_error( SalesService::update_finance_status( (int) $finance_first_128['id'], 'rejected', '', 'Self decision' ) ), 'Requester cannot decide their own finance attempt.' );
	wp_set_current_user( $finance_other );
	adc_check( is_wp_error( SalesService::finance_history( (int) $finance_sale_128['id'] ) ) && is_wp_error( SalesService::update_finance_status( (int) $finance_first_128['id'], 'rejected', '', 'Foreign branch' ) ), 'Finance history and decisions enforce branch scope.' );
	wp_set_current_user( $finance_verifier );
	adc_check( is_array( SalesService::update_finance_status( (int) $finance_first_128['id'], 'rejected', '', 'Fixture rejection' ) ), 'A separate finance user may reject the first attempt.' );
	wp_set_current_user( $finance_recorder );
	$finance_second_128 = SalesService::create_finance_request( (int) $finance_sale_128['id'], 'Fixture Provider B', $finance_amount_128, true );
	$finance_history_128 = SalesService::finance_history( (int) $finance_sale_128['id'] );
	adc_check( is_array( $finance_second_128 ) && 2 === $finance_second_128['attempt_number'] && (int) $finance_first_128['id'] === $finance_second_128['previous_request_id'] && 2 === count( $finance_history_128 ) && 'rejected' === $finance_history_128[0]['status'], 'Retry retains immutable attempt order and predecessor lineage.' );
	$finance_parallel_vehicle_128 = $make_vehicle( '131' );
	wp_set_current_user( $sales_a );
	$finance_parallel_quote_128 = SalesService::create_quote( $customer_a, $finance_parallel_vehicle_128, $date );
	$finance_parallel_reservation_128 = ReservationService::create( array( 'vehicle_id'=>$finance_parallel_vehicle_128, 'customer_id'=>$customer_a, 'idempotency_key'=>wp_generate_uuid4() ) );
	$finance_parallel_sale_128 = SalesService::create_sale( (int) $finance_parallel_quote_128['id'], (int) $finance_parallel_reservation_128['id'] );
	adc_check( is_array( $finance_parallel_sale_128 ), 'Parallel finance fixture has a valid pending sale.' );
	$finance_parallel_128 = static function ( int $sale_id, int $amount ) use ( $wpdb, $finance_recorder ): array {
		$barrier = 'adc_gate_' . bin2hex( random_bytes( 8 ) );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $barrier ) ) ) { throw new RuntimeException( 'Cannot acquire finance test barrier.' ); }
		$jobs = array();
		try {
			foreach ( array( 'Parallel Provider A', 'Parallel Provider B' ) as $provider ) {
				$process = proc_open( array( PHP_BINARY, __DIR__ . '/database-runner.php', '--worker' ), array( 0=>array( 'pipe', 'r' ), 1=>array( 'pipe', 'w' ), 2=>STDERR ), $pipes );
				if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start finance worker.' ); }
				fwrite( $pipes[0], json_encode( array( 'database'=>DB_NAME, 'source_database'=>'', 'host'=>DB_HOST, 'user'=>DB_USER, 'password'=>DB_PASSWORD, 'scenario'=>'finance_attempt', 'actor'=>$finance_recorder, 'input'=>array( 'sale_id'=>$sale_id, 'provider'=>$provider, 'amount'=>$amount ), 'barrier'=>$barrier ), JSON_THROW_ON_ERROR ) );
				fclose( $pipes[0] );
				$jobs[] = array( $process, $pipes[1] );
			}
			usleep( 500000 );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $barrier ) );
		}
		$results = array();
		foreach ( $jobs as list( $process, $output ) ) {
			$result = json_decode( stream_get_contents( $output ), true );
			fclose( $output );
			if ( 0 !== proc_close( $process ) || ! is_array( $result ) ) { throw new RuntimeException( 'Finance concurrency worker failed.' ); }
			$results[] = $result;
		}
		return $results;
	};
	$parallel_results_128 = $finance_parallel_128( (int) $finance_parallel_sale_128['id'], (int) $finance_parallel_quote_128['final_amount'] );
	$parallel_winners_128 = array_values( array_filter( $parallel_results_128, static fn( $result ) => isset( $result['id'] ) ) );
	$parallel_denials_128 = array_values( array_filter( $parallel_results_128, static fn( $result ) => 'adc_finance_attempt_open' === ( $result['error'] ?? '' ) ) );
	adc_check( 1 === count( $parallel_winners_128 ) && 1 === count( $parallel_denials_128 ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d', $finance_parallel_sale_128['id'] ) ), 'Independent concurrent providers can persist exactly one open finance attempt.' );
	Schema::install();
	adc_check( array() === Schema::verify() && 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'finance_requests' ) . ' WHERE sale_id=%d', $finance_sale_128['id'] ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'suppliers' ) . ' WHERE id=%d', $supplier_128['id'] ) ), 'Repeated 1.17.0 installation preserves supplier and finance-attempt rows.' );

	wp_set_current_user( $admin );
	$api_request_id_128 = wp_generate_uuid4();
	$v2_request_128 = new WP_REST_Request( 'GET', '/auto-dealership/v2/suppliers' );
	$v2_request_128->set_header( 'X-Request-ID', $api_request_id_128 );
	$v2_response_128 = ResponseContract::format( rest_do_request( $v2_request_128 ), rest_get_server(), $v2_request_128 );
	$v2_data_128 = $v2_response_128->get_data();
	adc_check( 200 === $v2_response_128->get_status() && true === ( $v2_data_128['success'] ?? null ) && $api_request_id_128 === ( $v2_data_128['meta']['request_id'] ?? '' ) && $api_request_id_128 === ( $v2_response_128->get_headers()['X-Request-ID'] ?? '' ), 'v2 success responses preserve a valid request ID in header and envelope.' );
	$v1_request_128 = new WP_REST_Request( 'GET', '/auto-dealership/v1/suppliers' );
	$v1_response_128 = ResponseContract::format( rest_do_request( $v1_request_128 ), rest_get_server(), $v1_request_128 );
	adc_check( 200 === $v1_response_128->get_status() && is_array( $v1_response_128->get_data() ) && ! isset( $v1_response_128->get_data()['success'] ), 'v1 supplier responses retain their compatibility shape.' );
	$spec_response_128 = rest_do_request( new WP_REST_Request( 'GET', '/auto-dealership/schema/v2' ) );
	$spec_128 = $spec_response_128->get_data();
	adc_check( 200 === $spec_response_128->get_status() && '3.1.0' === ( $spec_128['openapi'] ?? '' ) && isset( $spec_128['paths']['/suppliers']['get'], $spec_128['paths']['/vehicles/{id}/acquisition']['patch'] ) && $spec_128 === OpenApiSpecification::document(), 'Authenticated OpenAPI 3.1 is generated from current registered v2 routes.' );
	wp_set_current_user( 0 );
	$anonymous_request_128 = new WP_REST_Request( 'GET', '/auto-dealership/v2/suppliers' );
	$anonymous_v2_128 = ResponseContract::format( rest_do_request( $anonymous_request_128 ), rest_get_server(), $anonymous_request_128 );
	$anonymous_data_128 = $anonymous_v2_128->get_data();
	adc_check( in_array( $anonymous_v2_128->get_status(), array( 401, 403 ), true ) && false === ( $anonymous_data_128['success'] ?? null ) && ! empty( $anonymous_data_128['error']['code'] ) && ! empty( $anonymous_data_128['meta']['request_id'] ), 'v2 authorization failures use the documented error envelope.' );
	$anonymous_spec_128 = rest_do_request( new WP_REST_Request( 'GET', '/auto-dealership/schema/v2' ) );
	adc_check( in_array( $anonymous_spec_128->get_status(), array( 401, 403 ), true ), 'Anonymous callers cannot download the administrative OpenAPI document.' );

	$failed_identity_128 = 'fixture-login-128@example.invalid';
	$failed_before_128 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit WHERE event_key='security.login_failed'" );
	SecurityAudit::login_failed( $failed_identity_128 );
	SecurityAudit::login_failed( $failed_identity_128 );
	$failed_after_128 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit WHERE event_key='security.login_failed'" );
	$failed_record_128 = $wpdb->get_var( "SELECT after_data FROM $audit WHERE event_key='security.login_failed' ORDER BY id DESC LIMIT 1" );
	adc_check( $failed_after_128 === $failed_before_128 + 1 && ! str_contains( (string) $failed_record_128, $failed_identity_128 ) && 64 === strlen( (string) ( json_decode( $failed_record_128, true )['identity_hash'] ?? '' ) ), 'Failed-login audit is rate-bounded and stores a hash instead of identity text.' );
	wp_set_current_user( $admin );
	$user_128 = new WP_User( $delivery_128 );
	$user_128->set_role( 'dealership_customer_service' );
	adc_check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $audit WHERE event_key='security.user_role_set' AND subject_id=%d", $delivery_128 ) ) > 0, 'WordPress role changes are audited through the security hooks.' );
} finally {
	wp_set_current_user( $increment_128_previous_user );
}
