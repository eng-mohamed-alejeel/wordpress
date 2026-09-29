<?php
/** Pricing, reservation-deposit, refund and delivery-document acceptance for 1.19. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

$policy_keys = array(
	'adc_vat_rate_bps', 'adc_pricing_fee_amount', 'adc_promotion_code', 'adc_promotion_type',
	'adc_promotion_value', 'adc_promotion_starts_at', 'adc_promotion_ends_at',
	'adc_reservation_deposit_type', 'adc_reservation_deposit_value',
	'adc_sales_manager_discount_limit', 'adc_general_manager_discount_limit',
	'adc_seller_name', 'adc_seller_tax_number', 'adc_seller_address', 'adc_seller_phone',
	'adc_delivery_required_documents',
);
$policy_before = array();
foreach ( $policy_keys as $key ) {
	$sentinel = new stdClass();
	$value = get_option( $key, $sentinel );
	$policy_before[ $key ] = array( 'exists'=>$value !== $sentinel, 'value'=>$value === $sentinel ? null : $value );
}
$restore_policy = static function () use ( $policy_before ): void {
	foreach ( $policy_before as $key=>$state ) { $state['exists'] ? update_option( $key, $state['value'], false ) : delete_option( $key ); }
};

try {
	wp_set_current_user( $admin );
	adc_check( is_wp_error( AutoDealership\Core\ConfigurationService::update( array( 'promotion_type'=>'percentage', 'promotion_code'=>'bad', 'promotion_value'=>10000, 'reservation_deposit_type'=>'none', 'general_manager_discount_limit'=>1, 'sales_manager_discount_limit'=>2 ) ) ), 'Policy settings reject invalid percentages and reversed approval ceilings without writes.' );
	foreach ( array(
		'adc_vat_rate_bps'=>1500, 'adc_pricing_fee_amount'=>100000, 'adc_promotion_code'=>'launch19',
		'adc_promotion_type'=>'fixed', 'adc_promotion_value'=>200000,
		'adc_promotion_starts_at'=>gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'adc_promotion_ends_at'=>gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
		'adc_reservation_deposit_type'=>'fixed', 'adc_reservation_deposit_value'=>500000,
		'adc_sales_manager_discount_limit'=>300000, 'adc_general_manager_discount_limit'=>1000000,
		'adc_seller_name'=>'Synthetic Dealer 1.19', 'adc_seller_tax_number'=>'VAT-119',
		'adc_seller_address'=>'Riyadh', 'adc_seller_phone'=>'+966500001119',
		'adc_delivery_required_documents'=>array( 'invoice','customer_identity' ),
	) as $key=>$value ) { update_option( $key, $value, false ); }

	$general_manager = $make_user( 'general_manager_119', 'dealership_general_manager', $branch_a['id'] );
	$pricing_119_vehicle = $make_vehicle( '1901' );
	wp_set_current_user( $sales_a );
	$quote_119 = AutoDealership\Sales\SalesService::create_quote( $customer_a, $pricing_119_vehicle, $date, 'LAUNCH19' );
	adc_check( is_array( $quote_119 ) && 100000 === $quote_119['fee_amount'] && 200000 === $quote_119['promotion_amount'] && 9900000 === $quote_119['subtotal_amount'] && 1485000 === $quote_119['tax_amount'] && 11385000 === $quote_119['final_amount'], 'Quote applies the configured fee, promotion and VAT in a deterministic order.' );
	$quote_119_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AutoDealership\Database\Schema::table( 'quotations' ) . ' WHERE id=%d', $quote_119['id'] ), ARRAY_A );
	adc_check( 'Synthetic Dealer 1.19' === $quote_119_row['seller_name'] && 'VAT-119' === $quote_119_row['seller_tax_number'] && 'launch19' === $quote_119_row['promotion_code'], 'Quotation stores immutable seller and promotion identity snapshots.' );
	update_option( 'adc_pricing_fee_amount', 900000, false );
	update_option( 'adc_promotion_value', 900000, false );
	$discount_119 = AutoDealership\Sales\SalesService::request_discount( $quote_119['id'], 300000, 'Manager-tier margin test' );
	$discount_119_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AutoDealership\Database\Schema::table( 'discount_requests' ) . ' WHERE id=%d', $discount_119['id'] ), ARRAY_A );
	adc_check( is_array( $discount_119 ) && 'sales_manager' === $discount_119['approval_tier'] && 1800000 === (int) $discount_119_row['margin_before'] && 1500000 === (int) $discount_119_row['margin_after'], 'Discount request freezes its approval tier and before/after purchase-cost margins.' );
	wp_set_current_user( $manager_a );
	$discount_119_decision = AutoDealership\Sales\SalesService::decide_discount( $discount_119['id'], true, 'Within manager ceiling' );
	$quote_119_after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AutoDealership\Database\Schema::table( 'quotations' ) . ' WHERE id=%d', $quote_119['id'] ), ARRAY_A );
	adc_check( is_array( $discount_119_decision ) && 100000 === (int) $quote_119_after['fee_amount'] && 200000 === (int) $quote_119_after['promotion_amount'] && 11040000 === (int) $quote_119_after['final_amount'], 'Discount approval recalculates from the frozen quote policy despite later settings changes.' );

	$high_vehicle = $make_vehicle( '1902' );
	update_option( 'adc_pricing_fee_amount', 0, false ); update_option( 'adc_promotion_type', 'none', false ); update_option( 'adc_promotion_code', '', false ); update_option( 'adc_promotion_value', 0, false );
	wp_set_current_user( $sales_a );
	$high_quote = AutoDealership\Sales\SalesService::create_quote( $customer_a, $high_vehicle, $date );
	$high_discount = AutoDealership\Sales\SalesService::request_discount( $high_quote['id'], 400000, 'General-manager tier test' );
	adc_check( is_array( $high_discount ) && 'general_manager' === $high_discount['approval_tier'], 'Discount above the branch-manager ceiling is routed to the general-manager tier.' );
	wp_set_current_user( $manager_a );
	adc_check( is_wp_error( AutoDealership\Sales\SalesService::decide_discount( $high_discount['id'], true, 'Insufficient tier' ) ), 'Branch manager cannot approve a frozen general-manager discount request.' );
	wp_set_current_user( $general_manager );
	adc_check( is_array( AutoDealership\Sales\SalesService::decide_discount( $high_discount['id'], true, 'General-manager approval' ) ), 'General manager can approve a request assigned to the high-discount tier.' );
	$no_cost_vehicle = $make_vehicle( '1903' );
	$wpdb->update( $vehicles, array( 'purchase_cost'=>null ), array( 'id'=>$no_cost_vehicle ) );
	wp_set_current_user( $sales_a );
	$no_cost_quote = AutoDealership\Sales\SalesService::create_quote( $customer_a, $no_cost_vehicle, $date );
	$no_cost_discount = AutoDealership\Sales\SalesService::request_discount( $no_cost_quote['id'], 10000, 'Missing-cost rejection' );
	adc_check( is_wp_error( $no_cost_discount ) && 'adc_purchase_cost_required' === $no_cost_discount->get_error_code(), 'Margin-based discount is blocked when vehicle purchase cost is unavailable.' );

	$deposit_vehicle = $make_vehicle( '1904' );
	wp_set_current_user( $sales_a );
	$deposit_quote = AutoDealership\Sales\SalesService::create_quote( $customer_a, $deposit_vehicle, $date );
	$deposit_reservation = AutoDealership\Reservations\ReservationService::create( array( 'vehicle_id'=>$deposit_vehicle, 'customer_id'=>$customer_a, 'deposit_amount'=>500000, 'idempotency_key'=>wp_generate_uuid4() ) );
	adc_check( is_array( $deposit_reservation ) && 500000 === $deposit_reservation['deposit_required_amount'] && 0 === $deposit_reservation['deposit_verified_amount'], 'Reservation freezes the required deposit while keeping declared money unverified.' );
	update_option( 'adc_reservation_deposit_type', 'percentage', false ); update_option( 'adc_reservation_deposit_value', 2000, false );
	adc_check( is_wp_error( AutoDealership\Sales\SalesService::create_sale( $deposit_quote['id'], $deposit_reservation['id'] ) ), 'Sale creation is blocked until the frozen reservation deposit is independently verified.' );
	wp_set_current_user( $finance_recorder );
	$deposit_evidence = AutoDealership\Reservations\ReservationService::record_deposit( $deposit_reservation['id'], 500000, 'bank_transfer', 'DEPOSIT-119-SALE' );
	adc_check( is_array( $deposit_evidence ) && $deposit_evidence === AutoDealership\Reservations\ReservationService::record_deposit( $deposit_reservation['id'], 500000, 'bank_transfer', 'DEPOSIT-119-SALE' ), 'Identical deposit evidence retry is idempotent.' );
	adc_check( is_wp_error( AutoDealership\Reservations\ReservationService::decide_deposit( $deposit_evidence['id'], true, 'Self review' ) ), 'Deposit recorder cannot verify their own evidence.' );
	wp_set_current_user( $finance_other );
	adc_check( is_wp_error( AutoDealership\Reservations\ReservationService::decide_deposit( $deposit_evidence['id'], true, 'Foreign branch' ) ), 'Foreign-branch finance user cannot verify deposit evidence.' );
	wp_set_current_user( $finance_verifier );
	adc_check( is_array( AutoDealership\Reservations\ReservationService::decide_deposit( $deposit_evidence['id'], true, 'Matched external receipt' ) ), 'Independent same-branch reviewer verifies reservation deposit evidence.' );
	wp_set_current_user( $sales_a );
	adc_check( is_array( AutoDealership\Sales\SalesService::create_sale( $deposit_quote['id'], $deposit_reservation['id'] ) ), 'Verified reservation deposit satisfies the sale conversion gate.' );

	update_option( 'adc_reservation_deposit_type', 'fixed', false ); update_option( 'adc_reservation_deposit_value', 500000, false );
	$refund_vehicle = $make_vehicle( '1905' );
	wp_set_current_user( $sales_a );
	$refund_reservation = AutoDealership\Reservations\ReservationService::create( array( 'vehicle_id'=>$refund_vehicle, 'customer_id'=>$customer_a, 'deposit_amount'=>500000, 'idempotency_key'=>wp_generate_uuid4() ) );
	wp_set_current_user( $finance_recorder );
	$refund_deposit = AutoDealership\Reservations\ReservationService::record_deposit( $refund_reservation['id'], 500000, 'card', 'DEPOSIT-119-REFUND' );
	wp_set_current_user( $manager_a );
	adc_check( is_wp_error( AutoDealership\Reservations\ReservationService::cancel( $refund_reservation['id'], 'Pending evidence must be decided' ) ), 'Reservation cancellation is blocked while deposit evidence is pending.' );
	wp_set_current_user( $finance_verifier ); AutoDealership\Reservations\ReservationService::decide_deposit( $refund_deposit['id'], true, 'Verified for cancellation fixture' );
	wp_set_current_user( $manager_a );
	$refund_cancel = AutoDealership\Reservations\ReservationService::cancel( $refund_reservation['id'], 'Customer cancelled paid reservation' );
	adc_check( is_array( $refund_cancel ) && 'hold' === $refund_cancel['vehicle_status'] && 'pending_refund' === $refund_cancel['deposit_refund_status'], 'Paid reservation cancellation places the vehicle on hold and opens a refund obligation.' );
	wp_set_current_user( $finance_recorder );
	$reservation_refund = AutoDealership\Payments\RefundService::request_reservation( $refund_reservation['id'], 500000, 'bank_transfer', 'RESERVATION-REFUND-119' );
	adc_check( is_array( $reservation_refund ) && $reservation_refund === AutoDealership\Payments\RefundService::request_reservation( $refund_reservation['id'], 500000, 'bank_transfer', 'RESERVATION-REFUND-119' ), 'Reservation-deposit refund request is bounded and idempotent.' );
	adc_check( is_wp_error( AutoDealership\Payments\RefundService::decide( $reservation_refund['id'], true, 'Self review' ) ), 'Refund requester cannot verify their own reservation refund.' );
	wp_set_current_user( $finance_verifier );
	$reservation_refund_decision = AutoDealership\Payments\RefundService::decide( $reservation_refund['id'], true, 'External refund matched' );
	adc_check( is_array( $reservation_refund_decision ) && 'refunded' === $reservation_refund_decision['financial_status'] && 'available' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id=%d", $refund_vehicle ) ), 'Verified full deposit refund releases held inventory and closes the obligation.' );

	update_option( 'adc_reservation_deposit_type', 'none', false ); update_option( 'adc_reservation_deposit_value', 0, false );
	$document_vehicle = $make_vehicle( '1906' );
	wp_set_current_user( $sales_a );
	$document_quote = AutoDealership\Sales\SalesService::create_quote( $customer_a, $document_vehicle, $date );
	$document_reservation = AutoDealership\Reservations\ReservationService::create( array( 'vehicle_id'=>$document_vehicle, 'customer_id'=>$customer_a, 'idempotency_key'=>wp_generate_uuid4() ) );
	$document_sale = AutoDealership\Sales\SalesService::create_sale( $document_quote['id'], $document_reservation['id'] );
	wp_set_current_user( $finance_recorder ); $document_payment = AutoDealership\Payments\PaymentService::record( $document_sale['id'], $document_quote['final_amount'], 'bank_transfer', 'DELIVERY-119-PAID' );
	wp_set_current_user( $finance_verifier ); AutoDealership\Payments\PaymentService::decide( $document_payment['id'], true, 'Matched in full' );
	wp_set_current_user( $manager_a ); AutoDealership\Sales\SalesService::approve_sale( $document_sale['id'], 'INVOICE-119' ); $document_delivery = AutoDealership\Delivery\DeliveryService::prepare( $document_sale['id'] );
	wp_set_current_user( $inventory ); $document_vin = $wpdb->get_var( $wpdb->prepare( "SELECT vin FROM $vehicles WHERE id=%d", $document_vehicle ) ); AutoDealership\Delivery\DeliveryService::confirm_vin( $document_delivery['id'], $document_vin );
	$document_checklist = AutoDealership\Delivery\DeliveryService::checklist( $document_delivery['id'] );
	adc_check( is_array( $document_checklist ) && ! $document_checklist['complete'] && 2 === count( $document_checklist['items'] ), 'Delivery checklist exposes each configured missing document.' );
	wp_set_current_user( $manager_a );
	adc_check( is_wp_error( AutoDealership\Delivery\DeliveryService::approve( $document_delivery['id'] ) ), 'Delivery approval is blocked while required documents are incomplete.' );
	wp_set_current_user( $inventory_b );
	adc_check( is_wp_error( AutoDealership\Delivery\DeliveryService::record_document( $document_delivery['id'], 'invoice', 'FOREIGN-DOC' ) ), 'Foreign-branch inventory cannot record delivery documents.' );
	wp_set_current_user( $inventory );
	AutoDealership\Delivery\DeliveryService::record_document( $document_delivery['id'], 'invoice', 'INVOICE-119' );
	$complete_checklist = AutoDealership\Delivery\DeliveryService::record_document( $document_delivery['id'], 'customer_identity', 'IDENTITY-119' );
	adc_check( is_array( $complete_checklist ) && $complete_checklist['complete'], 'Recording every required reference completes the delivery checklist.' );
	wp_set_current_user( $manager_a );
	adc_check( is_array( AutoDealership\Delivery\DeliveryService::approve( $document_delivery['id'] ) ) && is_array( AutoDealership\Delivery\DeliveryService::release( $document_delivery['id'] ) ), 'Complete documents allow separated delivery approval and final release.' );
} finally {
	$restore_policy();
}
