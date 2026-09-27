<?php
/** Included by database-scenarios.php before branch A is deactivated. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleSpecifications;
use AutoDealership\Inventory\VehicleIssueService;
use AutoDealership\Inventory\VehicleIntakeService;
use AutoDealership\Migration\LegacyVehicleMapper;
use AutoDealership\Migration\MigrationInventory;
use AutoDealership\Core\VehicleMigrationCommand;
use AutoDealership\Sales\SalesService;
use AutoDealership\Sales\SaleCancellationService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Payments\RefundService;

// Fault injection always removes its filter, even if a service throws.
$with_sql_failure = static function ( callable $matches, callable $operation ) {
	$hits = 0;
	$filter = static function ( string $sql ) use ( $matches, &$hits ): string {
		if ( $matches( $sql ) ) { ++$hits; return 'SELECT * FROM missing_adc_114_fault'; }
		return $sql;
	};
	add_filter( 'query', $filter );
	try { $result = $operation(); } finally { remove_filter( 'query', $filter ); }
	adc_check( $hits > 0, 'SQL fault injection reached its intended statement.' );
	return $result;
};
$error_is = static fn( $result, string $code ): bool => is_wp_error( $result ) && $code === $result->get_error_code();
$spec_id = $make_vehicle( '114' );
wp_set_current_user( $admin );
$original_inventory_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" );
foreach ( VehicleSpecifications::fields() as $field ) { $wpdb->query( "ALTER TABLE $vehicles DROP COLUMN $field" ); }
$wpdb->query( 'ALTER TABLE ' . Schema::table( 'vehicle_issues' ) . ' DROP COLUMN inspection_baseline_id' );
update_option( 'adc_db_version', '1.9.0' );
Schema::install();
adc_check( Schema::is_ready() && array() === Schema::verify() && $original_inventory_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" ), '1.9 to 1.10 additive schema upgrade preserves existing inventory and installs all specification columns.' );
adc_check( '10000000' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT retail_price FROM $vehicles WHERE id=%d", $spec_id ) ), 'Specification upgrade preserves existing vehicle prices.' );

wp_set_current_user( $sales_a );
adc_check( $error_is( VehicleSpecifications::update( $spec_id, array( 'doors'=>4 ), 'Sales attempt' ), 'adc_forbidden' ), 'Sales role cannot edit vehicle specifications.' );
$foreign_inventory = $make_user( 'inventory_114_b', 'dealership_inventory', $branch_b['id'] );
wp_set_current_user( $foreign_inventory );
adc_check( $error_is( VehicleSpecifications::update( $spec_id, array( 'doors'=>4 ), 'Foreign attempt' ), 'adc_vehicle_not_found' ), 'Another branch cannot edit specifications by direct ID.' );
wp_set_current_user( $inventory );
$spec_values = array( 'exterior_color'=>'أبيض', 'interior_color'=>'Black', 'engine_size'=>'2.0 L', 'drivetrain'=>'awd', 'doors'=>4, 'seats'=>5, 'horsepower'=>250, 'warranty'=>'3 years', 'interior_features'=>'Navigation', 'exterior_features'=>'Roof rails', 'safety_features'=>'ABS' );
adc_check( is_array( VehicleSpecifications::update( $spec_id, $spec_values, 'Record inspected specifications' ) ), 'Inventory staff save all eleven specifications in their branch.' );
$spec_read = VehicleService::get( $spec_id );
adc_check( 'أبيض' === $spec_read['exterior_color'] && 5 === (int) $spec_read['seats'] && 'ABS' === $spec_read['safety_features'] && ! isset( $spec_read['purchase_cost'] ), 'Scoped vehicle reads include specifications without private purchase cost.' );
$event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit" );
$spec_retry = VehicleSpecifications::update( $spec_id, $spec_values, 'Retry' );
adc_check( false === $spec_retry['updated'] && $event_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit" ), 'Identical specification retry creates no duplicate audit event.' );
foreach ( array( array( 'seats'=>0 ), array( 'doors'=>11 ), array( 'horsepower'=>5001 ), array( 'doors'=>true ), array( 'seats'=>'1.5' ), array( 'doors'=>array( 4 ) ), array( 'drivetrain'=>'unknown' ), array( 'exterior_color'=>str_repeat( 'أ', 81 ) ), array( 'purchase_cost'=>1 ), array() ) as $invalid_specs ) {
	adc_check( $error_is( VehicleSpecifications::update( $spec_id, $invalid_specs, 'Invalid input' ), 'adc_specification_invalid' ), 'Invalid or private specification payload is rejected.' );
}
adc_check( $error_is( VehicleSpecifications::update( $spec_id, array( 'doors'=>2 ), '  ' ), 'adc_specification_invalid' ), 'Specification changes require a nonblank reason.' );
$failed_specs = $with_sql_failure( static fn( $q ) => str_starts_with( $q, 'INSERT INTO `' . $audit . '`' ), static fn() => VehicleSpecifications::update( $spec_id, array( 'doors'=>2 ), 'Fail audit' ) );
adc_check( is_wp_error( $failed_specs ) && 4 === (int) VehicleService::get( $spec_id )['doors'] && $event_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit" ), 'Failed specification audit rolls back the value and leaves history unchanged.' );
adc_check( is_array( VehicleSpecifications::update( $spec_id, array( 'seats'=>null, 'warranty'=>'' ), 'Clear optional values' ) ) && null === VehicleService::get( $spec_id )['seats'] && 'أبيض' === VehicleService::get( $spec_id )['exterior_color'], 'Optional specification clearing preserves omitted fields.' );
foreach ( array( 'reserved','sold','ready_for_delivery','delivered','transferred','cancelled' ) as $locked_status ) {
	$wpdb->update( $vehicles, array( 'status'=>$locked_status ), array( 'id'=>$spec_id ) );
	adc_check( $error_is( VehicleSpecifications::update( $spec_id, array( 'doors'=>2 ), 'Locked edit' ), 'adc_specification_state_locked' ), 'Specifications are locked in state ' . $locked_status . '.' );
}
$wpdb->update( $vehicles, array( 'status'=>'available' ), array( 'id'=>$spec_id ) );

// Exercise the command itself (not a duplicate importer) with captured WP-CLI output.
require_once __DIR__ . '/cli-double.php';
$migrate = static function ( int $post, bool $dry = false ): array {
	WP_CLI::$messages = array();
	$args = array( 'post-id'=>$post ); if ( $dry ) { $args['dry-run'] = true; }
	( new VehicleMigrationCommand() )( array(), $args );
	return json_decode( substr( WP_CLI::$messages[0], strpos( WP_CLI::$messages[0], '{' ) ), true, 512, JSON_THROW_ON_ERROR );
};
wp_set_current_user( $admin );
foreach ( array( '0.01'=>1, '12.3'=>1230, '0012.34'=>1234, '125000.99'=>12500099 ) as $major => $minor ) {
	adc_check( $minor === LegacyVehicleMapper::price( $major ), 'Decimal SAR converts exactly: ' . $major . '.' );
}
foreach ( array( '-1', '1.001', '1e3', '1,000', ' 1', '', (string) PHP_INT_MAX ) as $bad_price ) {
	adc_check( null === LegacyVehicleMapper::price( $bad_price ), 'Malformed or overflowing migration price is rejected.' );
}
update_post_meta( $migration_car, '_car_price', '125000.99' );
update_post_meta( $migration_car, '_car_condition', 'certified' );
update_post_meta( $migration_car, '_car_mileage', '0' );
update_post_meta( $migration_car, '_car_kilometers', '123' );
update_post_meta( $migration_car, '_car_color', 'Blue' );
update_post_meta( $migration_car, '_car_seats', '5' );
update_post_meta( $migration_car, '_car_stock', 'MIGRATION-FALLBACK' );
$mapped_values = LegacyVehicleMapper::map( $migration_car );
adc_check( 0 === $mapped_values['mileage'] && 'MIGRATION-1' === $mapped_values['stock_number'] && 'certified' === $mapped_values['condition_key'] && 12500099 === $mapped_values['retail_price'], 'Mapper preserves primary keys, zero mileage, decimal price and certified condition.' );
delete_post_meta( $migration_car, '_car_stock_number' ); delete_post_meta( $migration_car, '_car_mileage' );
$alias_values = LegacyVehicleMapper::map( $migration_car );
adc_check( 'MIGRATION-FALLBACK' === $alias_values['stock_number'] && 123 === $alias_values['mileage'], 'Mapper uses legacy aliases only when preferred metadata is empty.' );
update_post_meta( $migration_car, '_car_stock_number', 'MIGRATION-1' ); update_post_meta( $migration_car, '_car_mileage', '0' );
foreach ( array( '', 'mystery', 'reserved', 'sold' ) as $legacy_status ) {
	update_post_meta( $migration_car, '_car_inventory_status', $legacy_status );
	adc_check( 1 === $migrate( $migration_car, true )['skipped_workflow'], 'Migration dry run refuses unsupported status: ' . $legacy_status . '.' );
}
update_post_meta( $migration_car, '_car_inventory_status', 'available' );
$before_import = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" );
adc_check( 1 === $migrate( $migration_car, true )['eligible'] && $before_import === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" ), 'Valid migration dry run writes no inventory.' );
$migration_failure = $with_sql_failure( static fn( $q ) => str_starts_with( $q, 'INSERT INTO `' . $audit . '`' ), static fn() => $migrate( $migration_car ) );
adc_check( 1 === $migration_failure['conflict'] && $before_import === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" ), 'Migration audit failure rolls back the inserted vehicle.' );
$migration_failure = $with_sql_failure( static fn( $q ) => str_starts_with( $q, 'INSERT INTO `' . Schema::table( 'vehicle_movements' ) . '`' ), static fn() => $migrate( $migration_car ) );
adc_check( 1 === $migration_failure['conflict'] && $before_import === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" ), 'Migration movement failure rolls back the inserted vehicle.' );
adc_check( 1 === $migrate( $migration_car )['imported'], 'Actual vehicle migration imports the valid mapped source.' );
$migrated_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $vehicles WHERE public_post_id=%d", $migration_car ) );
$migrated = VehicleService::get( $migrated_id );
adc_check( 12500099 === (int) $migrated['retail_price'] && 'Blue' === $migrated['exterior_color'] && 5 === (int) $migrated['seats'], 'Imported inventory retains the exact source price and specifications.' );
adc_check( 1 === $migrate( $migration_car )['already_imported'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_movements' ) . ' WHERE vehicle_id=%d', $migrated_id ) ), 'Migration retry preserves one vehicle and one initial movement.' );
adc_check( 1 === MigrationInventory::report()['vehicles']['matched'], 'Reconciliation agrees with the actual migrated fields.' );
update_post_meta( $migration_car, '_car_color', 'Red' );
adc_check( 1 === MigrationInventory::report()['vehicles']['drifted'] && 'Blue' === VehicleService::get( $migrated_id )['exterior_color'], 'Specification drift is reported without overwriting target inventory.' );
update_post_meta( $migration_car, '_car_color', 'Blue' );

// Build a pending sale with verified funds, a pending receipt and finance under review.
// Clone source metadata into a new synthetic post for collision and concurrency checks.
$parallel_post = wp_insert_post( array( 'post_type'=>'car', 'post_status'=>'publish', 'post_title'=>'Concurrent migration source' ) );
foreach ( get_post_meta( $migration_car ) as $key => $values ) { update_post_meta( $parallel_post, $key, maybe_unserialize( $values[0] ) ); }
adc_check( 1 === $migrate( $parallel_post, true )['conflict'], 'Migration dry run detects a VIN or stock collision before writes.' );
update_post_meta( $parallel_post, '_car_vin', '3M8GDM9AXKP000114' );
update_post_meta( $parallel_post, '_car_stock_number', 'MIGRATION-PARALLEL' );
update_post_meta( $parallel_post, '_car_seats', '101' );
adc_check( 1 === $migrate( $parallel_post, true )['skipped_invalid'], 'Out-of-range source specifications block migration.' );
update_post_meta( $parallel_post, '_car_seats', '5' );
update_post_meta( $parallel_post, '_car_inventory_status', array( 'available' ) );
adc_check( 1 === $migrate( $parallel_post, true )['skipped_workflow'], 'Serialized array status is reported as blocked instead of crashing the importer.' );
update_post_meta( $parallel_post, '_car_inventory_status', 'available' );
update_post_meta( $parallel_post, '_car_stock_number', array( 'corrupt' ) );
adc_check( 1 === $migrate( $parallel_post, true )['skipped_invalid'], 'Serialized identity metadata is rejected without converting it to text.' );
update_post_meta( $parallel_post, '_car_stock_number', 'MIGRATION-PARALLEL' );
require __DIR__ . '/migration-concurrency.php';

$failure_vehicle = $make_vehicle( '115' );
wp_set_current_user( $sales_a );
$failure_quote = SalesService::create_quote( $customer_a, $failure_vehicle, $date );
$failure_reservation = ReservationService::create( array( 'vehicle_id'=>$failure_vehicle, 'customer_id'=>$customer_a, 'idempotency_key'=>wp_generate_uuid4() ) );
$failure_sale = SalesService::create_sale( $failure_quote['id'], $failure_reservation['id'] );
wp_set_current_user( $finance_recorder );
$failure_payment = PaymentService::record( $failure_sale['id'], 1000, 'bank_transfer', '114-VERIFIED' );
$failure_pending = PaymentService::record( $failure_sale['id'], 100, 'bank_transfer', '114-PENDING' );
$failure_finance = SalesService::create_finance_request( $failure_sale['id'], 'Synthetic 114', 1000, true );
wp_set_current_user( $finance_verifier );
adc_check( is_array( PaymentService::decide( $failure_payment['id'], true, 'Fixture funds' ) ) && is_array( SalesService::update_finance_status( $failure_finance['id'], 'under_review' ) ), 'Cancellation fault fixture has verified funds and finance under review.' );
$owner = new WP_User( $sales_a ); $owner->add_cap( 'adc_cancel_sales' ); wp_set_current_user( $sales_a );
try { adc_check( $error_is( SaleCancellationService::cancel( $failure_sale['id'], 'Owner cancellation' ), 'adc_sale_cancel_self' ), 'Even with cancellation capability, sale owner cannot cancel their own sale.' ); } finally { $owner->remove_cap( 'adc_cancel_sales' ); }
wp_set_current_user( $manager_a );
$cancellations_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'sale_cancellations' ) );
$cancellation_faults = array(
	static fn( $q ) => str_starts_with( $q, 'UPDATE `' . Schema::table( 'payment_confirmations' ) . '`' ),
	static fn( $q ) => str_starts_with( $q, 'UPDATE ' . Schema::table( 'finance_requests' ) ),
	static fn( $q ) => str_contains( $q, 'SUM(amount)' ) && str_contains( $q, Schema::table( 'payment_confirmations' ) ),
	static fn( $q ) => str_starts_with( $q, 'SELECT id,status FROM ' . Schema::table( 'deliveries' ) ),
);
foreach ( $cancellation_faults as $fault ) {
	$result = $with_sql_failure( $fault, static fn() => SaleCancellationService::cancel( $failure_sale['id'], 'Injected failure' ) );
	adc_check( is_wp_error( $result ) && 500 === $result->get_error_data()['status'] && $cancellations_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'sale_cancellations' ) ) && 'reserved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id=%d", $failure_vehicle ) ) && 'pending' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'payment_confirmations' ) . ' WHERE id=%d', $failure_pending['id'] ) ) && 'under_review' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'finance_requests' ) . ' WHERE id=%d', $failure_finance['id'] ) ), 'Cancellation SQL failure preserves inventory, receipt, finance and cancellation records.' );
}
$failure_cancellation = SaleCancellationService::cancel( $failure_sale['id'], 'Cancel after fault recovery' );
adc_check( is_array( $failure_cancellation ) && 'cancelled' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'finance_requests' ) . ' WHERE id=%d', $failure_finance['id'] ) ), 'Successful cancellation closes finance under review.' );
wp_set_current_user( $finance_recorder );
$refund_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'payment_refunds' ) );
foreach ( array( 'payment_confirmations','payment_refunds' ) as $balance_table ) {
	$fault = static fn( $q ) => str_contains( $q, 'SUM(amount)' ) && str_contains( $q, Schema::table( $balance_table ) );
	$result = $with_sql_failure( $fault, static fn() => RefundService::request_cancellation( $failure_cancellation['id'], 1000, 'bank_transfer', '114-REFUND' ) );
	adc_check( $error_is( $result, 'adc_refund_balance_failed' ) && $refund_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'payment_refunds' ) ), 'Refund request fails closed when a balance aggregate cannot be read.' );
}
$failure_refund = RefundService::request_cancellation( $failure_cancellation['id'], 1000, 'bank_transfer', '114-REFUND' );
wp_set_current_user( $finance_verifier );
foreach ( array( 'payment_confirmations','payment_refunds' ) as $balance_table ) {
	$fault = static fn( $q ) => str_contains( $q, 'SUM(amount)' ) && str_contains( $q, Schema::table( $balance_table ) );
	$result = $with_sql_failure( $fault, static fn() => RefundService::decide( $failure_refund['id'], true, 'Injected balance failure' ) );
	adc_check( $error_is( $result, 'adc_refund_balance_failed' ) && 'pending' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'payment_refunds' ) . ' WHERE id=%d', $failure_refund['id'] ) ), 'Refund verification fails closed and preserves pending state after an aggregate error.' );
}
adc_check( is_array( RefundService::decide( $failure_refund['id'], true, 'Independent reversal evidence' ) ), 'Refund verification succeeds after the database fault is removed.' );
$refund_audit = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT after_data FROM $audit WHERE event_key='refund.verified' AND subject_id=%d ORDER BY id DESC LIMIT 1", $failure_refund['id'] ) ), true );
adc_check( $failure_cancellation['id'] === $refund_audit['cancellation_id'], 'Refund verification audit identifies the cancellation obligation.' );
wp_set_current_user( $finance_recorder );
$replayed = RefundService::request_cancellation( $failure_cancellation['id'], 1000, 'bank_transfer', '114-REFUND' );
adc_check( $failure_refund['id'] === $replayed['id'] && 'verified' === $replayed['status'], 'Completed cancellation refund retries return the original verified record.' );
$replayed_return = RefundService::request( $vehicle_return['id'], $half, 'bank_transfer', 'REFUND-SYNTHETIC-1' );
adc_check( $refund_one['id'] === $replayed_return['id'] && 'verified' === $replayed_return['status'], 'Completed return refund retries return the original verified record.' );
wp_set_current_user( $sales_a );
adc_check( array() === RefundService::refundable_returns() && array() === RefundService::refundable_cancellations() && array() === SaleCancellationService::list_for_current_user(), 'Operational list methods deny roles lacking their financial or cancellation capability.' );
wp_set_current_user( $inventory );
$failure_issue = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicle_issues' ) . ' WHERE cancellation_id=%d', $failure_cancellation['id'] ) );
adc_check( is_array( VehicleIssueService::resolve( $failure_issue, 'Refund settled' ) ), 'Verified cancellation obligation allows hold resolution.' );
adc_check( ! VehicleIntakeService::passed( $failure_vehicle ) && is_wp_error( VehicleService::transition( $failure_vehicle, 'available', 'Reuse old inspection' ) ), 'An inspection passed before hold resolution cannot release inventory.' );
adc_check( is_array( VehicleIntakeService::inspect( $failure_vehicle, array( 'checklist'=>array_fill_keys( array( 'exterior','interior','engine','tires','vin' ), 'pass' ) ) ) ) && is_array( VehicleService::transition( $failure_vehicle, 'available', 'Fresh inspection passed' ) ), 'A fresh passed inspection after hold resolution permits availability.' );
