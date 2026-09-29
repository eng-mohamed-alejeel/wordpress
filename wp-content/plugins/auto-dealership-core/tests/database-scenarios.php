<?php
/** Loaded only by the isolated runner after a fresh WordPress installation. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Database\Schema;
use AutoDealership\Branches\BranchService;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleIntakeService;
use AutoDealership\Inventory\VehicleIssueService;
use AutoDealership\Inventory\VehicleReturnService;
use AutoDealership\Leads\LeadService;
use AutoDealership\Sales\SalesService;
use AutoDealership\Sales\SaleCancellationService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Security\CustomerScope;
use AutoDealership\Security\BranchScope;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Payments\RefundService;
use AutoDealership\Delivery\DeliveryService;
use AutoDealership\Pricing\QuoteHistory;
use AutoDealership\Pricing\QuoteDocument;
use AutoDealership\Privacy\PrivacyTools;
use AutoDealership\Core\ConfigurationService;
use AutoDealership\Privacy\RetentionService;
use AutoDealership\Reports\FinancialExport;
use AutoDealership\Migration\MigrationInventory;
use AutoDealership\Reference\ReferenceService;

$checks = 0;
function adc_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['checks'];
	echo "PASS: $message\n";
}
adc_check( array() === Schema::verify(), 'Installed schema matches columns, defaults, indexes and InnoDB.' );
adc_check( Schema::VERSION === get_option( 'adc_db_version' ), 'Schema version advances only after successful verification.' );
Schema::install();
adc_check( array() === Schema::verify(), 'Repeat schema installation is idempotent.' );

$vehicles = Schema::table( 'vehicles' );
$customers = Schema::table( 'customers' );
$reservations = Schema::table( 'reservations' );
$quotes = Schema::table( 'quotations' );
$audit = Schema::table( 'audit_events' );
$wpdb->query( "ALTER TABLE `$vehicles` DROP INDEX vin" );
adc_check( in_array( $vehicles . ':index:vin', Schema::verify(), true ), 'Missing unique VIN index is detected.' );
$wpdb->query( "ALTER TABLE `$vehicles` ADD UNIQUE KEY vin (vin(8))" );
adc_check( in_array( $vehicles . ':index:vin', Schema::verify(), true ), 'A truncated VIN index cannot satisfy full VIN uniqueness.' );
$wpdb->query( "ALTER TABLE `$vehicles` DROP INDEX vin" );
Schema::install();
adc_check( array() === Schema::verify(), 'Missing index is repaired without replacing the table.' );
$wpdb->query( "ALTER TABLE `$vehicles` MODIFY mileage int(10) NOT NULL DEFAULT 0" );
adc_check( in_array( $vehicles . ':column:mileage', Schema::verify(), true ), 'Signedness drift is detected.' );
Schema::install();
$wpdb->query( "ALTER TABLE `$customers` ALTER consent_marketing SET DEFAULT 1" );
adc_check( in_array( $customers . ':default:consent_marketing', Schema::verify(), true ), 'Consent default drift is detected.' );
Schema::install();
$wpdb->query( "ALTER TABLE `$vehicles` ENGINE=MyISAM" );
Schema::install();
adc_check( false === get_option( 'adc_db_version' ) && in_array( $vehicles . ':requires_innodb', get_option( 'adc_schema_issues', array() ), true ), 'Nontransactional tables block the installed version marker.' );
adc_check( ! AutoDealership\Database\Transaction::begin(), 'Service transactions refuse an unverified schema.' );
$guard_result = AutoDealership\Database\SchemaGuard::rest( null, null, new WP_REST_Request( 'POST', '/auto-dealership/v1/reservations' ) );
adc_check( is_wp_error( $guard_result ) && 503 === $guard_result->get_error_data()['status'], 'Incomplete schema returns HTTP 503 before dealership REST dispatch.' );
adc_check( null === AutoDealership\Database\SchemaGuard::rest( null, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) ), 'Schema guard does not block other WordPress APIs.' );
$wpdb->query( "ALTER TABLE `$vehicles` ENGINE=InnoDB" );
$wpdb->query( "ALTER TABLE `$customers` ALTER consent_marketing SET DEFAULT 0" );
Schema::install();
adc_check( array() === Schema::verify() && Schema::VERSION === get_option( 'adc_db_version' ), 'Verified repair restores the version marker.' );

$admin = get_current_user_id();
$branch_a = BranchService::create( array( 'code' => 'VERIFY-A', 'name' => 'Synthetic A' ) );
$branch_b = BranchService::create( array( 'code' => 'VERIFY-B', 'name' => 'Synthetic B' ) );
adc_check( is_array( $branch_a ) && is_array( $branch_b ), 'Create synthetic branches.' );
$migration_car = wp_insert_post( array( 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'Migration source vehicle' ) );
update_post_meta( $migration_car, '_car_condition', 'new' );
update_post_meta( $migration_car, '_car_inventory_status', 'available' );
update_post_meta( $migration_car, '_car_vin', '3M8GDM9AXKP000001' ); update_post_meta( $migration_car, '_car_stock_number', 'MIGRATION-1' ); update_post_meta( $migration_car, '_car_make', 'Synthetic' ); update_post_meta( $migration_car, '_car_model', 'Source' ); update_post_meta( $migration_car, '_car_year', '2025' ); update_post_meta( $migration_car, '_car_location', 'Synthetic A' ); update_post_meta( $migration_car, '_car_price', '125000' );
$invalid_migration_car = wp_insert_post( array( 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'Invalid migration source' ) );
$migration_offer = wp_insert_post( array( 'post_type' => 'car_offer', 'post_status' => 'publish', 'post_title' => 'Linked migration offer' ) ); update_post_meta( $migration_offer, '_car_id', $migration_car );
$orphan_offer = wp_insert_post( array( 'post_type' => 'car_offer', 'post_status' => 'publish', 'post_title' => 'Orphan migration offer' ) ); update_post_meta( $orphan_offer, '_car_id', 99999999 );
$migration_report = MigrationInventory::report();
adc_check( 2 === $migration_report['vehicles']['source_total'] && 1 === $migration_report['vehicles']['eligible_unmapped'] && 1 === $migration_report['vehicles']['invalid_identity'], 'Migration inventory classifies eligible and invalid legacy vehicles without writing target rows.' );
adc_check( 2 === $migration_report['offers']['source_total'] && 1 === $migration_report['offers']['linked_to_vehicle'] && 1 === $migration_report['offers']['unlinked_or_orphaned'], 'Migration inventory identifies linked and orphaned legacy offers.' );
adc_check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicles' ) ), 'Migration inventory is read-only for operational inventory.' );
$invalid_report = MigrationInventory::report( 99999999 );
adc_check( 'invalid_fallback_branch' === ( $invalid_report['error'] ?? '' ), 'Migration inventory rejects an inactive or unknown fallback branch.' );
$brand_reference = ReferenceService::create_brand( array( 'key' => 'synthetic', 'name_ar' => 'تركيبية', 'name_en' => 'Synthetic' ) );
$location_reference = ReferenceService::create_location( array( 'branch_id' => $branch_a['id'], 'code' => 'A-YARD', 'name' => 'Synthetic A Yard', 'type' => 'yard' ) );
$location_reference_2 = ReferenceService::create_location( array( 'branch_id' => $branch_a['id'], 'code' => 'A-WAREHOUSE', 'name' => 'Synthetic A Warehouse', 'type' => 'warehouse' ) );
$location_reference_b = ReferenceService::create_location( array( 'branch_id' => $branch_b['id'], 'code' => 'B-YARD', 'name' => 'Synthetic B Yard', 'type' => 'yard' ) );
adc_check( is_array( $brand_reference ) && is_array( $location_reference ), 'Administrator creates audited brand and yard reference records.' );
adc_check( is_wp_error( ReferenceService::create_brand( array( 'key' => 'synthetic', 'name_ar' => 'مكرر' ) ) ), 'Duplicate brand keys are rejected.' );
adc_check( is_wp_error( ReferenceService::create_location( array( 'branch_id' => 999999, 'code' => 'BAD', 'name' => 'Invalid', 'type' => 'yard' ) ) ), 'Locations require an active parent branch.' );
$payment_table = Schema::table( 'payment_confirmations' );
$wpdb->query( "DROP TABLE `$payment_table`" );
update_option( 'adc_db_version', '1.0.0' );
Schema::install();
adc_check( Schema::is_ready() && array() === Schema::verify() && '2' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'branches' ) ), 'Upgrade adds payment table while preserving existing branch records.' );
$make_user = static function ( string $login, string $role, int $branch ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.invalid', 'role' => $role ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture user creation failed.' ); }
	update_user_meta( $id, 'adc_branch_id', $branch );
	return (int) $id;
};
$sales_a = $make_user( 'sales_a', 'dealership_sales', $branch_a['id'] );
$sales_a2 = $make_user( 'sales_a2', 'dealership_sales', $branch_a['id'] );
$sales_b = $make_user( 'sales_b', 'dealership_sales', $branch_b['id'] );
$manager_a = $make_user( 'manager_a', 'dealership_sales_manager', $branch_a['id'] );
$make_customer = static function ( int $branch, int $owner ) use ( $wpdb ): int {
	$lead = LeadService::create_public( array( 'name' => 'Synthetic customer', 'mobile' => '+966500000001', 'branch_id' => $branch ) );
	if ( ! is_array( $lead ) || is_wp_error( LeadService::assign( $lead['id'], $owner ) ) ) { throw new RuntimeException( 'Fixture lead assignment failed.' ); }
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . Schema::table( 'leads' ) . ' WHERE id = %d', $lead['id'] ) );
};
$customer_a = $make_customer( $branch_a['id'], $sales_a );
$customer_a2 = $make_customer( $branch_a['id'], $sales_a2 );
$customer_b = $make_customer( $branch_b['id'], $sales_b );
$make_vehicle = static function ( string $suffix ) use ( $branch_a, $admin, $brand_reference, $location_reference ): int {
	$previous = get_current_user_id();
	wp_set_current_user( $admin );
	$row = VehicleService::create( array( 'vin' => '1M8GDM9AXKP' . str_pad( $suffix, 6, '0', STR_PAD_LEFT ), 'stock_number' => 'TEST-' . $suffix, 'brand' => 'Synthetic', 'brand_id' => $brand_reference['id'], 'model' => 'Fixture', 'model_year' => 2026, 'condition' => 'new', 'branch_id' => $branch_a['id'], 'location_id' => $location_reference['id'], 'retail_price' => 10000000, 'minimum_price' => 8500000, 'purchase_cost' => 8000000 ) );
	if ( ! is_array( $row ) ) { throw new RuntimeException( 'Fixture vehicle creation failed: ' . $row->get_error_code() ); }
	$checklist = array_fill_keys( array( 'exterior','interior','engine','tires','vin' ), 'pass' );
	if ( is_wp_error( VehicleIntakeService::receive( $row['id'], array( 'condition'=>'good', 'document_reference'=>'FIXTURE-' . $suffix ) ) ) || is_wp_error( VehicleService::transition( $row['id'], 'inspection', 'Fixture preparation' ) ) || is_wp_error( VehicleIntakeService::inspect( $row['id'], array( 'checklist'=>$checklist ) ) ) || is_wp_error( VehicleService::transition( $row['id'], 'available', 'Fixture inspection passed' ) ) ) { throw new RuntimeException( 'Fixture intake failed.' ); }
	wp_set_current_user( $previous );
	return (int) $row['id'];
};
$vehicle = $make_vehicle( '1' );
adc_check( is_wp_error( ReferenceService::set_active( 'brand', $brand_reference['id'], false ) ) && is_wp_error( ReferenceService::set_active( 'location', $location_reference['id'], false ) ), 'Brand and location in active inventory cannot be disabled.' );
adc_check( is_array( VehicleService::move_location( $vehicle, $location_reference_2['id'], 'Move to warehouse' ) ) && (int) $wpdb->get_var( $wpdb->prepare( "SELECT location_id FROM $vehicles WHERE id=%d", $vehicle ) ) === (int) $location_reference_2['id'] && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_movements' ) . ' WHERE vehicle_id=%d AND from_location_id=%d AND to_location_id=%d', $vehicle, $location_reference['id'], $location_reference_2['id'] ) ) === 1, 'Authorized same-branch location move updates inventory and movement history.' );
adc_check( is_wp_error( VehicleService::move_location( $vehicle, $location_reference_b['id'], 'Invalid cross-branch location' ) ), 'A vehicle cannot move to a physical location in another branch.' );
$vin_fixture = VehicleService::create( array( 'vin' => '4M8GDM9AXKP000001', 'stock_number' => 'VIN-CORRECTION', 'brand' => 'Synthetic', 'brand_id' => $brand_reference['id'], 'model' => 'VIN Fixture', 'model_year' => 2026, 'condition' => 'new', 'branch_id' => $branch_a['id'], 'location_id' => $location_reference['id'], 'retail_price' => 10000000 ) );
adc_check( is_array( $vin_fixture ) && is_array( VehicleService::change_vin( $vin_fixture['id'], '4M8GDM9AXKP000002', 'Correct receiving document' ) ), 'Elevated user corrects VIN before sale workflow with a documented reason.' );
wp_set_current_user( $sales_a );
adc_check( is_wp_error( VehicleService::change_vin( $vin_fixture['id'], '4M8GDM9AXKP000003', 'Unauthorized correction' ) ), 'Sales user cannot change vehicle VIN.' );
wp_set_current_user( $admin );
adc_check( is_wp_error( VehicleService::change_vin( $vehicle, '4M8GDM9AXKP000004', 'Too late' ) ), 'VIN becomes immutable after the vehicle reaches available state.' );
VehicleIntakeService::receive( $vin_fixture['id'], array( 'condition'=>'damaged', 'document_reference'=>'DAMAGED-RECEIPT', 'notes'=>'Damage on arrival' ) );
VehicleService::transition( $vin_fixture['id'], 'inspection', 'Inspect damaged arrival' );
$failed_checklist=array_fill_keys(array('exterior','interior','engine','tires','vin'),'pass'); $failed_checklist['exterior']='fail';
$failed_inspection=VehicleIntakeService::inspect($vin_fixture['id'],array('checklist'=>$failed_checklist,'notes'=>'Exterior damage requires repair'));
adc_check( is_array($failed_inspection) && $failed_inspection['issue_id']>0 && 'maintenance'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicles WHERE id=%d",$vin_fixture['id'])), 'Failed inspection automatically opens maintenance and removes the vehicle from availability.' );
adc_check( is_wp_error(VehicleService::transition($vin_fixture['id'],'available','Bypass repair')), 'Open maintenance cannot bypass resolution and reinspection.' );
$resolved_issue=VehicleIssueService::resolve($failed_inspection['issue_id'],'Exterior repaired and verified');
adc_check( is_array($resolved_issue) && 'inspection'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicles WHERE id=%d",$vin_fixture['id'])), 'Resolving maintenance returns the vehicle to inspection.' );
$passed_checklist=array_fill_keys(array('exterior','interior','engine','tires','vin'),'pass'); VehicleIntakeService::inspect($vin_fixture['id'],array('checklist'=>$passed_checklist));
adc_check( is_array(VehicleService::transition($vin_fixture['id'],'available','Reinspection passed')), 'Vehicle becomes available only after maintenance resolution and a new passed inspection.' );
wp_set_current_user( $sales_a );
adc_check( CustomerScope::allows( $customer_a, $branch_a['id'] ), 'Sales may use their assigned customer in their branch.' );
adc_check( ! CustomerScope::allows( $customer_a2, $branch_a['id'] ), 'Another salesperson customer is denied.' );
adc_check( ! CustomerScope::allows( $customer_b, $branch_a['id'] ), 'Other-branch customer is denied even with a same-branch vehicle.' );
$date = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
adc_check( is_wp_error( SalesService::create_quote( $customer_b, $vehicle, $date ) ), 'Quote service rejects foreign customer IDs.' );
adc_check( is_wp_error( SalesService::create_quote( $customer_a2, $vehicle, $date ) ), 'Quote service rejects another owner customer.' );
$quote = SalesService::create_quote( $customer_a, $vehicle, $date );
adc_check( is_array( $quote ), 'Quote creation succeeds for an accessible customer.' );
$input = array( 'vehicle_id' => $vehicle, 'customer_id' => $customer_b, 'idempotency_key' => wp_generate_uuid4() );
adc_check( is_wp_error( ReservationService::create( $input ) ), 'Reservation service rejects foreign customer IDs.' );
$input['customer_id'] = $customer_a;
$reservation = ReservationService::create( $input );
adc_check( is_array( $reservation ), 'Authorized reservation commits.' );
adc_check( $reservation === ReservationService::create( $input ), 'Identical retry returns the same response without another reservation.' );
adc_check( is_wp_error( ReservationService::create( array_merge( $input, array( 'deposit_amount' => 100 ) ) ) ), 'Reusing a key with another deposit is rejected.' );
adc_check( is_wp_error( ReservationService::create( array_merge( $input, array( 'idempotency_key' => wp_generate_uuid4() ) ) ) ), 'A second key cannot reserve the same vehicle.' );
wp_set_current_user( $sales_a2 );
adc_check( is_wp_error( ReservationService::create( $input ) ), 'A different employee cannot replay another employee reservation key.' );
wp_set_current_user( $sales_a );
update_user_meta( $sales_a, 'adc_branch_id', $branch_b['id'] );
adc_check( is_wp_error( ReservationService::create( $input ) ), 'Reassignment revokes reservation replay access.' );
adc_check( is_wp_error( SalesService::request_discount( $quote['id'], 100, 'Test' ) ), 'Reassignment revokes discount requests on old-branch quotes.' );
update_user_meta( $sales_a, 'adc_branch_id', $branch_a['id'] );

// Quote money is integer-only, tax is frozen, and every state change is append-only.
update_option( 'adc_vat_rate_bps', 1500, false );
update_option( 'adc_sales_manager_discount_limit', 20000, false );
$pricing_vehicle = $make_vehicle( '5' );
$pricing_quote = SalesService::create_quote( $customer_a, $pricing_vehicle, $date );
adc_check( is_array( $pricing_quote ) && 1500 === $pricing_quote['tax_rate_bps'] && 11500000 === $pricing_quote['final_amount'], 'Quote stores the configured VAT rate and integer total.' );
adc_check( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'quotation_versions' ) . ' WHERE quotation_id = %d', $pricing_quote['id'] ) ), 'Quote creation captures immutable version one.' );
$history_table = Schema::table( 'quotation_versions' );
$break_history = static function ( string $query ) use ( $history_table ): string {
	return str_starts_with( $query, 'INSERT INTO ' . $history_table ) ? str_replace( $history_table, 'missing_adc_quote_history', $query ) : $query;
};
$wpdb->suppress_errors( true );
add_filter( 'query', $break_history );
adc_check( is_wp_error( SalesService::request_discount( $pricing_quote['id'], 10000, 'History rollback test' ) ), 'Quote-history failure rejects a discount request.' );
remove_filter( 'query', $break_history );
$pricing_state = $wpdb->get_row( $wpdb->prepare( "SELECT status,version FROM $quotes WHERE id = %d", $pricing_quote['id'] ), ARRAY_A );
adc_check( 'approved' === $pricing_state['status'] && 1 === (int) $pricing_state['version'] && '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'discount_requests' ) . ' WHERE quotation_id = %d', $pricing_quote['id'] ) ), 'Failed history capture rolls back quote and discount rows.' );
$pricing_discount = SalesService::request_discount( $pricing_quote['id'], 10000, 'Approved synthetic discount' );
adc_check( is_array( $pricing_discount ) && 2 === $pricing_discount['quotation_version'], 'Discount request creates quote version two.' );
update_option( 'adc_vat_rate_bps', 500, false );
wp_set_current_user( $manager_a );
$pricing_decision = SalesService::decide_discount( $pricing_discount['id'], true, 'Manager approval' );
$pricing_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $quotes WHERE id = %d", $pricing_quote['id'] ), ARRAY_A );
adc_check( is_array( $pricing_decision ) && 3 === $pricing_decision['quotation_version'] && 1498500 === (int) $pricing_row['tax_amount'] && 11488500 === (int) $pricing_row['final_amount'], 'Discount approval uses the original 15 percent VAT snapshot after settings change.' );
adc_check( '3' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $history_table WHERE quotation_id = %d", $pricing_quote['id'] ) ), 'Created, pending and approved quote revisions are preserved.' );
$pricing_v1 = QuoteHistory::version( $pricing_quote['id'], 1 );
adc_check( is_array( $pricing_v1 ) && 'Synthetic customer' === $pricing_v1['customer_name'] && 'TEST-5' === $pricing_v1['vehicle_stock_number'] && str_contains( $pricing_v1['vehicle_description'], 'Synthetic Fixture' ) && (int) $branch_a['id'] === (int) $pricing_v1['branch_id'], 'Quote revision freezes minimal customer, vehicle and originating-branch identity.' );
$wpdb->update( $customers, array( 'full_name' => '<script>changed</script>' ), array( 'id' => $customer_a ) );
$wpdb->update( $vehicles, array( 'model' => 'Changed model' ), array( 'id' => $pricing_vehicle ) );
$pricing_v1_after_change = QuoteHistory::version( $pricing_quote['id'], 1 );
adc_check( 'Synthetic customer' === $pricing_v1_after_change['customer_name'] && ! str_contains( $pricing_v1_after_change['vehicle_description'], 'Changed model' ), 'Later customer and vehicle edits do not rewrite an existing quote snapshot.' );
$unsafe_document = QuoteDocument::render( array_merge( $pricing_v1_after_change, array( 'customer_name' => '<script>alert(1)</script>' ) ) );
adc_check( ! str_contains( $unsafe_document, '<script>alert(1)</script>' ) && str_contains( $unsafe_document, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && str_contains( $unsafe_document, 'window.print()' ), 'Printable quote escapes snapshot text and exposes the browser PDF action.' );
$wpdb->update( $customers, array( 'full_name' => 'Synthetic customer' ), array( 'id' => $customer_a ) );
$wpdb->update( $vehicles, array( 'model' => 'Fixture' ), array( 'id' => $pricing_vehicle ) );
$legacy_inserted = $wpdb->insert( $quotes, array( 'quote_number' => 'Q-LEGACY-CURRENT', 'customer_id' => $customer_a, 'vehicle_id' => $pricing_vehicle, 'owner_user_id' => $sales_a, 'base_amount' => 200000, 'discount_amount' => 10000, 'tax_rate_bps' => null, 'tax_amount' => 28500, 'final_amount' => 218500, 'valid_until' => $date, 'status' => 'approved', 'version' => 7, 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s' ) );
$legacy_quote_id = (int) $wpdb->insert_id;
adc_check( false !== $legacy_inserted && QuoteHistory::backfill(), 'Legacy backfill preserves the current known quote revision.' );
$legacy_history = $wpdb->get_row( $wpdb->prepare( "SELECT version,tax_rate_bps,tax_amount,final_amount,event_key FROM $history_table WHERE quotation_id = %d", $legacy_quote_id ), ARRAY_A );
adc_check( 7 === (int) $legacy_history['version'] && null === $legacy_history['tax_rate_bps'] && 28500 === (int) $legacy_history['tax_amount'] && 218500 === (int) $legacy_history['final_amount'] && 'legacy.captured' === $legacy_history['event_key'], 'Backfill does not invent prior versions or an unknown historic VAT rate.' );
QuoteHistory::backfill();
adc_check( '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $history_table WHERE quotation_id = %d", $legacy_quote_id ) ), 'Repeated legacy backfill is idempotent.' );
wp_set_current_user( $sales_a );

// Fail only audit INSERTs, exercising real InnoDB rollback with production services.
$break_audit = static function ( string $query ) use ( $audit ): string {
	return str_starts_with( $query, 'INSERT INTO `' . $audit . '`' ) ? str_replace( '`' . $audit . '`', '`missing_adc_test_audit`', $query ) : $query;
};
$wpdb->suppress_errors( true );
$leads_table = Schema::table( 'leads' );
$activities_table = Schema::table( 'activities' );
wp_set_current_user( $admin );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( BranchService::create( array( 'code' => 'AUDIT-FAIL', 'name' => 'Rollback branch' ) ) ), 'Audit failure rejects branch creation.' );
remove_filter( 'query', $break_audit );
adc_check( '0' === (string) $wpdb->get_var( "SELECT COUNT(*) FROM " . Schema::table( 'branches' ) . " WHERE code='AUDIT-FAIL'" ), 'Failed branch audit leaves no branch row.' );
add_filter( 'query', $break_audit );
$failed_location_move = VehicleService::move_location( $vehicle, $location_reference['id'], 'Audit rollback location' );
remove_filter( 'query', $break_audit );
adc_check( is_wp_error( $failed_location_move ) && (int) $location_reference_2['id'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT location_id FROM $vehicles WHERE id=%d", $vehicle ) ), 'Audit failure rolls back vehicle location and movement history.' );
add_filter( 'query', $break_audit );
$failed_vin_change = VehicleService::change_vin( $vin_fixture['id'], '4M8GDM9AXKP000005', 'Audit rollback VIN' );
remove_filter( 'query', $break_audit );
adc_check( is_wp_error( $failed_vin_change ) && '4M8GDM9AXKP000002' === $wpdb->get_var( $wpdb->prepare( "SELECT vin FROM $vehicles WHERE id=%d", $vin_fixture['id'] ) ), 'Audit failure rolls back VIN correction.' );
$settings_before = array( (int) get_option( 'adc_vat_rate_bps' ), (int) get_option( 'adc_reservation_hours', 24 ), (int) get_option( 'adc_sales_manager_discount_limit' ), (int) get_option( 'adc_default_branch_id' ) );
add_filter( 'query', $break_audit );
$settings_failure = ConfigurationService::update( array( 'vat_rate_bps' => 700, 'reservation_hours' => 48, 'sales_manager_discount_limit' => 30000, 'default_branch_id' => $branch_a['id'] ) );
remove_filter( 'query', $break_audit );
adc_check( is_wp_error( $settings_failure ), 'Audit failure rejects dealership setting changes.' );
adc_check( $settings_before === array( (int) get_option( 'adc_vat_rate_bps' ), (int) get_option( 'adc_reservation_hours', 24 ), (int) get_option( 'adc_sales_manager_discount_limit' ), (int) get_option( 'adc_default_branch_id' ) ), 'Failed settings audit restores every prior option value.' );
add_filter( 'query', $break_audit );
$assignment_failure = ConfigurationService::assign_branch( $sales_a, $branch_b['id'] );
remove_filter( 'query', $break_audit );
adc_check( is_wp_error( $assignment_failure ), 'Audit failure rejects staff branch reassignment.' );
adc_check( (int) $branch_a['id'] === BranchScope::assigned_branch( $sales_a ) && array( (int) $branch_a['id'] ) === BranchScope::assigned_branches( $sales_a ), 'Failed assignment audit restores the prior staff branch and absence-compatible branch list.' );
$multi_assignment = ConfigurationService::assign_branches( $sales_a, $branch_a['id'], array( $branch_b['id'], $branch_a['id'] ) );
adc_check( is_array( $multi_assignment ) && array( (int) $branch_a['id'], (int) $branch_b['id'] ) === BranchScope::assigned_branches( $sales_a ) && BranchScope::allows( $branch_a['id'], $sales_a ) && BranchScope::allows( $branch_b['id'], $sales_a ), 'Administrator can grant two active branches while retaining a primary branch.' );
list( $multi_predicate, $multi_args ) = BranchScope::predicate( 'branch_id', $sales_a );
adc_check( 'branch_id IN (%d,%d)' === $multi_predicate && array( (int) $branch_a['id'], (int) $branch_b['id'] ) === $multi_args, 'Multi-branch assignments produce a prepared bounded SQL predicate.' );
adc_check( is_wp_error( ConfigurationService::assign_branches( $sales_a, $branch_a['id'], array( $branch_b['id'] ) ) ), 'Primary branch must be included in the allowed branch list.' );
adc_check( is_array( ConfigurationService::assign_branch( $sales_a, $branch_a['id'] ) ) && array( (int) $branch_a['id'] ) === BranchScope::assigned_branches( $sales_a ), 'Single-branch compatibility API replaces the allowed list deterministically.' );
$failed_vin = '1M8GDM9AXKP000006';
add_filter( 'query', $break_audit );
$failed_vehicle = VehicleService::create( array( 'vin' => $failed_vin, 'stock_number' => 'AUDIT-FAIL-6', 'brand' => 'Synthetic', 'model' => 'Rollback', 'model_year' => 2026, 'condition' => 'new', 'branch_id' => $branch_a['id'], 'retail_price' => 10000000 ) );
adc_check( is_wp_error( $failed_vehicle ), 'Audit failure rejects vehicle creation.' );
remove_filter( 'query', $break_audit );
adc_check( '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $vehicles WHERE vin = %s", $failed_vin ) ), 'Failed vehicle audit leaves no inventory row.' );
wp_set_current_user( $sales_a );
$lead_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table" );
$customer_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $customers" );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( LeadService::create_public( array( 'name' => 'Audit rollback', 'mobile' => '+966500000099', 'branch_id' => $branch_a['id'] ) ) ), 'Audit failure rejects public lead creation.' );
remove_filter( 'query', $break_audit );
adc_check( $lead_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table" ) && $customer_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $customers" ), 'Failed lead audit rolls back both customer and lead.' );
$break_activity_insert = static function ( string $query ) use ( $activities_table ): string {
	return str_starts_with( $query, 'INSERT INTO `' . $activities_table . '`' ) ? str_replace( '`' . $activities_table . '`', '`missing_adc_test_activity`', $query ) : $query;
};
add_filter( 'query', $break_activity_insert );
adc_check( is_wp_error( LeadService::create_public( array( 'name' => 'Legacy rollback', 'mobile' => '+966500000098', 'branch_id' => $branch_a['id'] ), array( 'type' => 'message', 'id' => 998 ), 'Imported request context' ) ), 'Legacy context failure rejects the imported lead.' );
remove_filter( 'query', $break_activity_insert );
adc_check( $lead_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table" ) && $customer_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $customers" ), 'Failed legacy context insert rolls back customer and lead for safe retry.' );
$owned_lead_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $leads_table WHERE customer_id = %d", $customer_a ) );
$activity_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $activities_table WHERE lead_id = %d", $owned_lead_id ) );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( LeadService::update_stage( $owned_lead_id, 'contacted', 'Audit rollback' ) ), 'Audit failure rejects lead stage change.' );
remove_filter( 'query', $break_audit );
adc_check( 'new' === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $leads_table WHERE id = %d", $owned_lead_id ) ) && $activity_count === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $activities_table WHERE lead_id = %d", $owned_lead_id ) ), 'Failed stage audit rolls back lead and activity changes.' );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( LeadService::add_activity( $owned_lead_id, 'call', 'Audit rollback activity' ) ), 'Audit failure rejects CRM activity creation.' );
remove_filter( 'query', $break_audit );
adc_check( $activity_count === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $activities_table WHERE lead_id = %d", $owned_lead_id ) ), 'Failed activity audit leaves no activity row.' );
wp_set_current_user( $manager_a );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( LeadService::assign( $owned_lead_id, $sales_a2 ) ), 'Audit failure rejects lead reassignment.' );
remove_filter( 'query', $break_audit );
adc_check( $sales_a === (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $leads_table WHERE id = %d", $owned_lead_id ) ), 'Failed assignment audit preserves the prior owner.' );
wp_set_current_user( $sales_a );
$vehicle_2 = $make_vehicle( '2' );
wp_set_current_user( $admin );
$vehicle_2_movements = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_movements' ) . ' WHERE vehicle_id = %d', $vehicle_2 ) );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( VehicleIssueService::open( $vehicle_2, 'hold', 'Audit rollback' ) ), 'Audit failure rejects inventory hold creation.' );
remove_filter( 'query', $break_audit );
adc_check( 'available' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $vehicle_2 ) ) && $vehicle_2_movements === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_movements' ) . ' WHERE vehicle_id = %d', $vehicle_2 ) ), 'Failed transition audit rolls back vehicle status and movement.' );
wp_set_current_user( $sales_a );
$input_2 = array( 'vehicle_id' => $vehicle_2, 'customer_id' => $customer_a, 'idempotency_key' => wp_generate_uuid4() );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( ReservationService::create( $input_2 ) ), 'Audit failure rejects reservation creation.' );
remove_filter( 'query', $break_audit );
adc_check( 'available' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $vehicle_2 ) ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $reservations WHERE vehicle_id = %d", $vehicle_2 ) ), 'Failed reservation rolls back inventory and reservation rows.' );
$before_quotes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quotes" );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( SalesService::create_quote( $customer_a, $vehicle_2, $date ) ), 'Audit failure rejects quote creation.' );
remove_filter( 'query', $break_audit );
adc_check( $before_quotes === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quotes" ), 'Failed quote leaves no quote row.' );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( SalesService::create_sale( $quote['id'], $reservation['id'] ) ), 'Audit failure rejects sale creation.' );
remove_filter( 'query', $break_audit );
adc_check( 'confirmed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $reservations WHERE id = %d", $reservation['id'] ) ) && '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'sales' ) ), 'Failed sale rolls back reservation conversion and sale row.' );

wp_set_current_user( $manager_a );
adc_check( CustomerScope::allows( $customer_a2, $branch_a['id'] ), 'Branch manager may use customers owned by their branch sales staff.' );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( ReservationService::cancel( $reservation['id'], 'Synthetic cancellation' ) ), 'Audit failure rejects cancellation.' );
remove_filter( 'query', $break_audit );
adc_check( 'confirmed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $reservations WHERE id = %d", $reservation['id'] ) ), 'Failed cancellation preserves confirmed reservation.' );
adc_check( is_array( ReservationService::cancel( $reservation['id'], 'Synthetic cancellation' ) ), 'Authorized cancellation commits.' );
wp_set_current_user( $sales_a );
$expire = ReservationService::create( $input_2 );
adc_check( is_array( $expire ), 'Audit recovery allows the same failed request to succeed.' );
$wpdb->update( $reservations, array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $expire['id'] ) );
add_filter( 'query', $break_audit );
adc_check( 0 === ReservationService::expire_due(), 'Audit failure rolls back expiration.' );
remove_filter( 'query', $break_audit );
adc_check( 'reserved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $vehicle_2 ) ), 'Failed expiration preserves reserved inventory.' );
adc_check( 1 === ReservationService::expire_due() && 0 === ReservationService::expire_due(), 'Expiration commits once and repeated execution is idempotent.' );
adc_check( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_movements' ) . " WHERE vehicle_id = %d AND reason = %s", $vehicle_2, 'Reservation expired #' . $expire['id'] ) ), 'Expiration records the inventory movement.' );

AutoDealership\Database\SchemaGuard::boot();
$server = rest_get_server();
wp_set_current_user( 0 );
$rest_request = new WP_REST_Request( 'POST', '/auto-dealership/v1/quotations' );
$rest_request->set_body_params( array( 'customer_id' => $customer_a, 'vehicle_id' => $vehicle, 'valid_until' => $date ) );
adc_check( 401 === $server->dispatch( $rest_request )->get_status(), 'REST rejects anonymous quote creation.' );
wp_set_current_user( $sales_a );
$rest_request->set_param( 'customer_id', $customer_b );
adc_check( 400 === $server->dispatch( $rest_request )->get_status(), 'REST quote creation enforces customer scope.' );
$rest_request = new WP_REST_Request( 'GET', '/auto-dealership/v1/leads' );
$rest_response = $server->dispatch( $rest_request );
$lead_items = $rest_response->get_data()['items'] ?? $rest_response->get_data();
adc_check( 200 === $rest_response->get_status() && count( $lead_items ) === 1 && (int) $lead_items[0]['customer_id'] === $customer_a, 'REST lead list returns only owned same-branch customer.' );
$versions_request = new WP_REST_Request( 'GET', '/auto-dealership/v1/quotations/' . $pricing_quote['id'] . '/versions' );
$versions_response = $server->dispatch( $versions_request );
adc_check( 200 === $versions_response->get_status() && 3 === count( $versions_response->get_data() ) && 3 === (int) $versions_response->get_data()[0]['version'], 'Quote owner can read ordered immutable versions through REST.' );
$wpdb->update( $vehicles, array( 'branch_id' => $branch_b['id'] ), array( 'id' => $pricing_vehicle ) );
adc_check( 200 === $server->dispatch( $versions_request )->get_status(), 'Vehicle relocation does not revoke the owner from the originating quote branch.' );
wp_set_current_user( $sales_b );
adc_check( 404 === $server->dispatch( $versions_request )->get_status(), 'Vehicle relocation does not expose historical quote documents to the destination branch.' );
$wpdb->update( $vehicles, array( 'branch_id' => $branch_a['id'] ), array( 'id' => $pricing_vehicle ) );
wp_set_current_user( $sales_a2 );
adc_check( 404 === $server->dispatch( $versions_request )->get_status(), 'Another salesperson cannot read quote history outside ownership scope.' );
wp_set_current_user( $sales_a );

/** Two real PHP/WordPress processes with independent InnoDB connections. */
$reserve_concurrently = static function ( array $inputs ) use ( $wpdb, $sales_a ): array {
	$barrier = 'adc_gate_' . bin2hex( random_bytes( 8 ) );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $barrier ) ) ) { throw new RuntimeException( 'Cannot acquire test barrier.' ); }
	$jobs = array();
	try {
		foreach ( $inputs as $input ) {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/database-runner.php', '--worker' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => STDERR ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start concurrency worker.' ); }
			fwrite( $pipes[0], json_encode( array( 'database' => DB_NAME, 'source_database' => '', 'host' => DB_HOST, 'user' => DB_USER, 'password' => DB_PASSWORD, 'scenario' => 'reserve', 'actor' => $sales_a, 'input' => $input, 'barrier' => $barrier ), JSON_THROW_ON_ERROR ) );
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
		if ( 0 !== proc_close( $process ) || ! is_array( $result ) ) { throw new RuntimeException( 'Concurrency worker failed.' ); }
		$results[] = $result;
	}
	return $results;
};
$concurrent_vehicle = $make_vehicle( '3' );
$parallel_input = array( 'vehicle_id' => $concurrent_vehicle, 'customer_id' => $customer_a, 'idempotency_key' => wp_generate_uuid4() );
$parallel = $reserve_concurrently( array( $parallel_input, $parallel_input ) );
adc_check( isset( $parallel[0]['id'], $parallel[1]['id'] ) && $parallel[0] === $parallel[1], 'Concurrent retries with the same key return one reservation.' );
adc_check( '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $reservations WHERE vehicle_id = %d", $concurrent_vehicle ) ), 'Same-key concurrency persists exactly one reservation.' );
$concurrent_vehicle = $make_vehicle( '4' );
$parallel_input['vehicle_id'] = $concurrent_vehicle;
$parallel_input['idempotency_key'] = wp_generate_uuid4();
$second_input = array_merge( $parallel_input, array( 'idempotency_key' => wp_generate_uuid4() ) );
$parallel = $reserve_concurrently( array( $parallel_input, $second_input ) );
adc_check( 1 === count( array_filter( $parallel, static fn( $result ) => isset( $result['id'] ) ) ), 'Competing reservation keys allow exactly one winner.' );
adc_check( '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $reservations WHERE vehicle_id = %d", $concurrent_vehicle ) ), 'Competing-key concurrency persists exactly one reservation.' );

wp_set_current_user( $admin );
$finance_recorder = $make_user( 'finance_record', 'dealership_finance', $branch_a['id'] );
$finance_verifier = $make_user( 'finance_verify', 'dealership_finance', $branch_a['id'] );
$finance_other = $make_user( 'finance_other', 'dealership_finance', $branch_b['id'] );
$inventory = $make_user( 'inventory', 'dealership_inventory', $branch_a['id'] );
$inventory_b = $make_user( 'inventory_b', 'dealership_inventory', $branch_b['id'] );
$transfer_vehicle = $make_vehicle( '6' );
$transfers_table = Schema::table( 'vehicle_transfers' );
wp_set_current_user( $inventory );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( AutoDealership\Inventory\TransferService::request( $transfer_vehicle, $branch_b['id'], 'Audit rollback transfer' ) ), 'Audit failure rejects transfer request.' );
remove_filter( 'query', $break_audit );
adc_check( 'available' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $transfer_vehicle ) ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $transfers_table WHERE vehicle_id = %d", $transfer_vehicle ) ), 'Failed transfer-request audit restores available inventory and removes the request.' );
$transfer = AutoDealership\Inventory\TransferService::request( $transfer_vehicle, $branch_b['id'], 'Synthetic branch transfer' );
adc_check( is_array( $transfer ), 'Authorized transfer request commits with audit.' );
wp_set_current_user( $inventory_b );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( AutoDealership\Inventory\TransferService::decide( $transfer['id'], true ) ), 'Audit failure rejects transfer approval.' );
remove_filter( 'query', $break_audit );
adc_check( 'requested' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $transfers_table WHERE id = %d", $transfer['id'] ) ), 'Failed approval audit preserves requested transfer state.' );
adc_check( is_array( AutoDealership\Inventory\TransferService::decide( $transfer['id'], true ) ), 'Destination inventory staff approves the transfer.' );
wp_set_current_user( $inventory );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( AutoDealership\Inventory\TransferService::dispatch( $transfer['id'] ) ), 'Audit failure rejects transfer dispatch.' );
remove_filter( 'query', $break_audit );
adc_check( 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $transfers_table WHERE id = %d", $transfer['id'] ) ) && 'transferred' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $transfer_vehicle ) ), 'Failed dispatch audit preserves approved transfer and held inventory.' );
adc_check( is_array( AutoDealership\Inventory\TransferService::dispatch( $transfer['id'] ) ), 'Source requester dispatches the transfer.' );
wp_set_current_user( $inventory_b );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( AutoDealership\Inventory\TransferService::receive( $transfer['id'] ) ), 'Audit failure rejects transfer receipt.' );
remove_filter( 'query', $break_audit );
adc_check( 'dispatched' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $transfers_table WHERE id = %d", $transfer['id'] ) ) && (int) $branch_a['id'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT branch_id FROM $vehicles WHERE id = %d", $transfer_vehicle ) ), 'Failed receipt audit preserves source ownership and dispatched state.' );
adc_check( is_array( AutoDealership\Inventory\TransferService::receive( $transfer['id'] ) ), 'Separate destination inventory staff receives the transfer.' );
adc_check( 'available' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d AND branch_id = %d", $transfer_vehicle, $branch_b['id'] ) ), 'Received transfer makes inventory available only in the destination branch.' );
wp_set_current_user( $sales_a );
$sale_reservation = ReservationService::create( array( 'vehicle_id' => $vehicle, 'customer_id' => $customer_a, 'idempotency_key' => wp_generate_uuid4() ) );
$sale = SalesService::create_sale( $quote['id'], $sale_reservation['id'] );
adc_check( is_array( $sale ), 'Matching authorized quote and reservation become a sale.' );
adc_check( is_wp_error( SalesService::request_discount( $quote['id'], 100, 'After sale' ) ), 'Committed quote prices cannot change after sale creation.' );
adc_check( is_wp_error( PaymentService::record( $sale['id'], 100, 'bank_transfer', 'NO-SALES-ACCESS' ) ), 'Sales staff cannot record payment confirmations.' );
wp_set_current_user( $finance_recorder );
$finance_request = SalesService::create_finance_request( $sale['id'], 'Synthetic finance provider', $quote['final_amount'], true );
wp_set_current_user( $finance_verifier );
adc_check( is_array( SalesService::update_finance_status( $finance_request['id'], 'approved', 'SYNTHETIC-FINANCE' ) ), 'Finance request can be approved by a separate employee.' );
wp_set_current_user( $manager_a );
adc_check( is_array( SalesService::approve_sale( $sale['id'], 'SYNTHETIC-INVOICE' ) ), 'Manager approves sale with invoice reference.' );
$unpaid = DeliveryService::prepare( $sale['id'] );
adc_check( is_wp_error( $unpaid ) && 'adc_payment_unverified' === $unpaid->get_error_code(), 'Approved finance request alone cannot unlock delivery.' );
wp_set_current_user( $finance_recorder );
$half = intdiv( $quote['final_amount'], 2 );
$receipt_request = new WP_REST_Request( 'POST', '/auto-dealership/v1/sales/' . $sale['id'] . '/payments' );
$receipt_request->set_body_params( array( 'amount' => -1, 'source' => 'bank_transfer', 'reference' => 'SYNTHETIC-RECEIPT-1' ) );
adc_check( 400 === $server->dispatch( $receipt_request )->get_status(), 'Receipt REST schema rejects negative amounts.' );
$receipt_request->set_param( 'amount', $half );
wp_set_current_user( $sales_a );
adc_check( 403 === $server->dispatch( $receipt_request )->get_status(), 'Receipt REST endpoint rejects sales-role access.' );
wp_set_current_user( $finance_recorder );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( PaymentService::record( $sale['id'], $half, 'bank_transfer', 'SYNTHETIC-RECEIPT-1' ) ), 'Audit failure rejects receipt recording.' );
remove_filter( 'query', $break_audit );
adc_check( '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'payment_confirmations' ) ), 'Failed receipt leaves no payment row.' );
$receipt_response = $server->dispatch( $receipt_request );
adc_check( 200 === $receipt_response->get_status(), 'Receipt REST endpoint accepts authorized financial staff.' );
$receipt = $receipt_response->get_data();
adc_check( is_array( $receipt ) && 'pending' === $receipt['status'], 'Receipt remains pending until independently verified.' );
adc_check( $receipt === PaymentService::record( $sale['id'], $half, 'bank_transfer', 'SYNTHETIC-RECEIPT-1' ), 'Identical receipt submission is idempotent.' );
adc_check( is_wp_error( PaymentService::record( $sale['id'], $half - 1, 'bank_transfer', 'SYNTHETIC-RECEIPT-1' ) ), 'Receipt reference cannot be reused with another amount.' );
adc_check( is_wp_error( PaymentService::decide( $receipt['id'], true, 'Self verify' ) ), 'Recorder cannot approve their own receipt.' );
wp_set_current_user( $finance_other );
adc_check( is_wp_error( PaymentService::decide( $receipt['id'], true, 'Other branch' ) ) && array() === PaymentService::list_for_current_user(), 'Foreign-branch finance user cannot approve or list receipts.' );
wp_set_current_user( $manager_a );
adc_check( is_wp_error( DeliveryService::prepare( $sale['id'] ) ), 'Pending receipts cannot unlock delivery.' );
wp_set_current_user( $finance_verifier );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( PaymentService::decide( $receipt['id'], true, 'Matched synthetic source' ) ), 'Audit failure rejects payment verification.' );
remove_filter( 'query', $break_audit );
adc_check( ! PaymentService::is_settled( $sale['id'] ), 'Uncommitted verification does not count toward settlement.' );
adc_check( is_array( PaymentService::decide( $receipt['id'], true, 'Matched synthetic source' ) ), 'Separate financial reviewer verifies the receipt.' );
wp_set_current_user( $manager_a );
adc_check( is_wp_error( DeliveryService::prepare( $sale['id'] ) ), 'Partial settlement cannot unlock delivery.' );
wp_set_current_user( $finance_recorder );
$overpayment = PaymentService::record( $sale['id'], $quote['final_amount'], 'bank_transfer', 'SYNTHETIC-OVERPAYMENT' );
$remaining = PaymentService::record( $sale['id'], $quote['final_amount'] - $half, 'finance_disbursement', 'SYNTHETIC-RECEIPT-2' );
wp_set_current_user( $finance_verifier );
adc_check( is_wp_error( PaymentService::decide( $overpayment['id'], true, 'Too much' ) ), 'Verification cannot exceed the outstanding sale amount.' );
adc_check( is_array( PaymentService::decide( $overpayment['id'], false, 'Duplicate amount rejected' ) ), 'Invalid receipt may be rejected with a reason.' );
adc_check( is_array( PaymentService::decide( $remaining['id'], true, 'Matched finance disbursement' ) ) && PaymentService::is_settled( $sale['id'] ), 'Verified receipts cover the full sale amount.' );
wp_set_current_user( $manager_a );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( DeliveryService::prepare( $sale['id'] ) ), 'Audit failure rolls back delivery preparation.' );
remove_filter( 'query', $break_audit );
adc_check( 'sold' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $vehicle ) ), 'Failed preparation keeps the vehicle sold.' );
$delivery = DeliveryService::prepare( $sale['id'] );
adc_check( is_array( $delivery ) && $delivery['id'] > 0, 'Settled sale creates a delivery with a valid identifier.' );
wp_set_current_user( $inventory );
$vin = $wpdb->get_var( $wpdb->prepare( "SELECT vin FROM $vehicles WHERE id = %d", $vehicle ) );
adc_check( is_wp_error( DeliveryService::confirm_vin( $delivery['id'], 'WRONG-VIN' ) ), 'Mismatched VIN cannot be confirmed.' );
adc_check( is_array( DeliveryService::confirm_vin( $delivery['id'], $vin ) ), 'Inventory employee confirms the vehicle VIN.' );
wp_get_current_user()->add_cap( 'adc_approve_delivery' );
adc_check( is_wp_error( DeliveryService::approve( $delivery['id'] ) ), 'VIN confirmer cannot approve delivery even with both capabilities.' );
wp_set_current_user( $manager_a );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( DeliveryService::approve( $delivery['id'] ) ), 'Audit failure rejects delivery approval.' );
remove_filter( 'query', $break_audit );
adc_check( is_array( DeliveryService::approve( $delivery['id'] ) ), 'Separate authorized manager approves delivery.' );
$payments_table = Schema::table( 'payment_confirmations' );
$wpdb->update( $payments_table, array( 'status' => 'pending' ), array( 'id' => $remaining['id'] ) );
adc_check( is_wp_error( DeliveryService::release( $delivery['id'] ) ), 'Release rechecks settlement rather than trusting earlier preparation.' );
$wpdb->update( $payments_table, array( 'status' => 'verified' ), array( 'id' => $remaining['id'] ) );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( DeliveryService::release( $delivery['id'] ) ), 'Audit failure rolls back vehicle release.' );
remove_filter( 'query', $break_audit );
adc_check( 'ready_for_delivery' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $vehicle ) ), 'Failed release preserves vehicle readiness.' );
adc_check( is_array( DeliveryService::release( $delivery['id'] ) ), 'Vehicle releases after full verified settlement, sale, VIN and delivery approvals.' );
adc_check( is_wp_error( DeliveryService::release( $delivery['id'] ) ), 'Delivered vehicle cannot be released twice.' );
wp_set_current_user( $inventory );
$return_input = array( 'location_id' => $location_reference['id'], 'condition' => 'good', 'odometer' => 120, 'document_reference' => 'RETURN-SYNTHETIC-1', 'reason' => 'Customer return accepted for synthetic verification' );
adc_check( is_wp_error( VehicleReturnService::receive( $delivery['id'], $return_input ) ), 'Inventory staff cannot authorize a delivered vehicle return.' );
wp_set_current_user( $manager_a );
adc_check( is_wp_error( VehicleService::transition( $vehicle, 'returned', 'Bypass documented return' ) ), 'Delivered inventory cannot bypass the documented return workflow.' );
$wrong_return = $return_input; $wrong_return['location_id'] = $location_reference_b['id'];
adc_check( is_wp_error( VehicleReturnService::receive( $delivery['id'], $wrong_return ) ), 'A returned vehicle cannot be received into another branch location.' );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( VehicleReturnService::receive( $delivery['id'], $return_input ) ), 'Audit failure rejects vehicle return receipt.' );
remove_filter( 'query', $break_audit );
adc_check( 'delivered' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id=%d", $vehicle ) ) && '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicle_returns' ) ), 'Failed return preserves delivery, sale and inventory state.' );
$vehicle_return = VehicleReturnService::receive( $delivery['id'], $return_input );
adc_check( is_array( $vehicle_return ) && 'pending_refund' === $vehicle_return['financial_status'] && 'returned' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id=%d", $vehicle ) ), 'Authorized return records the pending refund obligation and returned inventory.' );
adc_check( is_wp_error( VehicleReturnService::receive( $delivery['id'], $return_input ) ), 'A completed delivery return cannot be received twice.' );
adc_check( is_wp_error( RefundService::request( $vehicle_return['id'], $half, 'bank_transfer', 'REFUND-SYNTHETIC-1' ) ), 'Return manager cannot record a financial refund.' );
wp_set_current_user( $finance_recorder );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( RefundService::request( $vehicle_return['id'], $half, 'bank_transfer', 'REFUND-SYNTHETIC-1' ) ), 'Audit failure rejects a refund request.' );
remove_filter( 'query', $break_audit );
adc_check( '0' === (string)$wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table('payment_refunds') ), 'Failed refund request leaves no financial row.' );
$refund_one = RefundService::request( $vehicle_return['id'], $half, 'bank_transfer', 'REFUND-SYNTHETIC-1' );
adc_check( is_array($refund_one) && $refund_one === RefundService::request( $vehicle_return['id'], $half, 'bank_transfer', 'REFUND-SYNTHETIC-1' ), 'Identical refund retry returns the original pending request.' );
adc_check( is_wp_error( RefundService::request( $vehicle_return['id'], $half - 1, 'bank_transfer', 'REFUND-SYNTHETIC-1' ) ) && is_wp_error( RefundService::decide( $refund_one['id'], true, 'Self approval' ) ), 'Refund references cannot change and requesters cannot self-approve.' );
wp_set_current_user( $finance_other );
adc_check( is_wp_error( RefundService::decide( $refund_one['id'], true, 'Foreign branch' ) ) && array() === RefundService::list_for_current_user(), 'Foreign-branch finance staff cannot review or list refunds.' );
wp_set_current_user( $finance_verifier );
add_filter( 'query', $break_audit );
adc_check( is_wp_error( RefundService::decide( $refund_one['id'], true, 'Matched return evidence' ) ), 'Audit failure rejects refund verification.' );
remove_filter( 'query', $break_audit );
adc_check( is_array( RefundService::decide( $refund_one['id'], true, 'Matched return evidence' ) ) && 'partially_refunded' === $wpdb->get_var( $wpdb->prepare( 'SELECT financial_status FROM ' . Schema::table('vehicle_returns') . ' WHERE id=%d', $vehicle_return['id'] ) ), 'Independent reviewer verifies a partial refund.' );
wp_set_current_user( $finance_recorder );
$refund_remaining = $quote['final_amount'] - $half;
adc_check( is_wp_error( RefundService::request( $vehicle_return['id'], $refund_remaining + 1, 'bank_transfer', 'REFUND-OVER' ) ), 'Refund request cannot exceed the verified unrefunded balance.' );
$refund_two = RefundService::request( $vehicle_return['id'], $refund_remaining, 'finance_reversal', 'REFUND-SYNTHETIC-2' );
wp_set_current_user( $finance_verifier );
adc_check( is_array( RefundService::decide( $refund_two['id'], true, 'Matched finance reversal' ) ) && 'refunded' === $wpdb->get_var( $wpdb->prepare( 'SELECT financial_status FROM ' . Schema::table('vehicle_returns') . ' WHERE id=%d', $vehicle_return['id'] ) ), 'Verified refunds close the full returned-sale obligation.' );
wp_set_current_user( $inventory );
adc_check( is_array( VehicleService::transition( $vehicle, 'inspection', 'Returned vehicle requires inspection' ) ) && is_wp_error( VehicleService::transition( $vehicle, 'available', 'Attempt to reuse old inspection' ) ), 'A pre-sale inspection cannot make a returned vehicle available.' );
$return_checklist = array_fill_keys( array( 'exterior','interior','engine','tires','vin' ), 'pass' );
adc_check( is_array( VehicleIntakeService::inspect( $vehicle, array( 'checklist' => $return_checklist, 'notes' => 'Post-return inspection passed' ) ) ) && is_array( VehicleService::transition( $vehicle, 'available', 'Post-return inspection passed' ) ), 'Returned inventory becomes available only after a new passed inspection.' );

$cancel_vehicle=$make_vehicle('31');wp_set_current_user($sales_a);$cancel_quote=SalesService::create_quote($customer_a,$cancel_vehicle,$date);$cancel_reservation=ReservationService::create(array('vehicle_id'=>$cancel_vehicle,'customer_id'=>$customer_a,'idempotency_key'=>wp_generate_uuid4()));$cancel_sale=SalesService::create_sale($cancel_quote['id'],$cancel_reservation['id']);
wp_set_current_user($finance_recorder);$cancel_payment=PaymentService::record($cancel_sale['id'],$cancel_quote['final_amount'],'bank_transfer','CANCEL-PAYMENT-1');wp_set_current_user($finance_verifier);PaymentService::decide($cancel_payment['id'],true,'Matched cancellation fixture');
wp_set_current_user($manager_a);SalesService::approve_sale($cancel_sale['id'],'CANCEL-INVOICE-1');$cancel_delivery=DeliveryService::prepare($cancel_sale['id']);
add_filter('query',$break_audit);$failed_cancellation=SaleCancellationService::cancel($cancel_sale['id'],'Audit rollback cancellation');remove_filter('query',$break_audit);
adc_check(is_wp_error($failed_cancellation)&&'ready_for_delivery'===$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Schema::table('sales').' WHERE id=%d',$cancel_sale['id']))&&'ready_for_delivery'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicles WHERE id=%d",$cancel_vehicle)),'Audit failure rolls sale cancellation back across delivery, sale and inventory.');
$cancellation=SaleCancellationService::cancel($cancel_sale['id'],'Customer cancelled before delivery');
adc_check(is_array($cancellation)&&'pending_refund'===$cancellation['financial_status']&&'hold'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicles WHERE id=%d",$cancel_vehicle))&&'cancelled'===$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Schema::table('deliveries').' WHERE id=%d',$cancel_delivery['id'])),'Paid cancelled delivery places inventory on documented hold with a refund obligation.');
adc_check(is_wp_error(SaleCancellationService::cancel($cancel_sale['id'],'Duplicate cancellation')),'A sale cannot be cancelled twice.');
$cancel_issue=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Schema::table('vehicle_issues').' WHERE cancellation_id=%d',$cancellation['id']));wp_set_current_user($inventory);
adc_check(is_wp_error(VehicleIssueService::resolve($cancel_issue,'Attempt before refund')),'Cancellation hold cannot be released before the verified refund.');
wp_set_current_user($finance_recorder);$cancel_refund=RefundService::request_cancellation($cancellation['id'],$cancel_quote['final_amount'],'bank_transfer','CANCEL-REFUND-1');wp_set_current_user($finance_verifier);
adc_check(is_array(RefundService::decide($cancel_refund['id'],true,'Matched cancellation refund'))&&'refunded'===$wpdb->get_var($wpdb->prepare('SELECT financial_status FROM '.Schema::table('sale_cancellations').' WHERE id=%d',$cancellation['id'])),'Independent refund verification settles the cancelled sale obligation.');
wp_set_current_user($inventory);adc_check(is_array(VehicleIssueService::resolve($cancel_issue,'Refund verified; inspect before release'))&&'inspection'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicles WHERE id=%d",$cancel_vehicle)),'Settled cancellation hold returns inventory to inspection.');
$cancel_checklist=array_fill_keys(array('exterior','interior','engine','tires','vin'),'pass');VehicleIntakeService::inspect($cancel_vehicle,array('checklist'=>$cancel_checklist));adc_check(is_array(VehicleService::transition($cancel_vehicle,'available','Cancellation reinspection passed')),'Cancelled-sale inventory becomes available after hold resolution and inspection.');
$unpaid_cancel_vehicle=$make_vehicle('32');wp_set_current_user($sales_a);$unpaid_cancel_quote=SalesService::create_quote($customer_a,$unpaid_cancel_vehicle,$date);$unpaid_cancel_reservation=ReservationService::create(array('vehicle_id'=>$unpaid_cancel_vehicle,'customer_id'=>$customer_a,'idempotency_key'=>wp_generate_uuid4()));$unpaid_cancel_sale=SalesService::create_sale($unpaid_cancel_quote['id'],$unpaid_cancel_reservation['id']);wp_set_current_user($finance_recorder);$pending_cancel_payment=PaymentService::record($unpaid_cancel_sale['id'],100,'bank_transfer','CANCEL-PENDING-1');wp_set_current_user($manager_a);$unpaid_cancellation=SaleCancellationService::cancel($unpaid_cancel_sale['id'],'Unpaid sale cancelled');
adc_check(is_array($unpaid_cancellation)&&'no_refund_due'===$unpaid_cancellation['financial_status']&&'available'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicles WHERE id=%d",$unpaid_cancel_vehicle))&&'cancelled'===$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Schema::table('reservations').' WHERE id=%d',$unpaid_cancel_reservation['id']))&&'cancelled'===$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Schema::table('payment_confirmations').' WHERE id=%d',$pending_cancel_payment['id'])),'Unpaid pending sale cancellation releases inventory, closes its reservation and cancels pending receipt evidence.');
require __DIR__ . '/increment-1.14.php';
require __DIR__ . '/increment-1.19.php';
require __DIR__ . '/increment-1.20.php';
require __DIR__ . '/increment-crm.php';
require __DIR__ . '/account-workflow-scenarios.php';
require __DIR__ . '/customer-preferences-scenarios.php';
wp_set_current_user( $finance_verifier );
ob_start();
AutoDealership\Admin\PaymentPages::render();
$payment_html = ob_get_clean();
adc_check( str_contains( $payment_html, 'SYNTHETIC-RECEIPT-1' ) && str_contains( $payment_html, 'SYNTHETIC-RECEIPT-2' ), 'Payment admin screen renders scoped receipts and decision states.' );
wp_set_current_user( $finance_other );
ob_start();
AutoDealership\Admin\PaymentPages::render();
$payment_html = ob_get_clean();
adc_check( ! str_contains( $payment_html, 'SYNTHETIC-RECEIPT-' ), 'Payment admin screen excludes foreign-branch receipt references.' );

wp_set_current_user( $admin );
$wpdb->update( Schema::table( 'sales' ), array( 'invoice_reference' => '=FORMULA-TEST' ), array( 'id' => $sale['id'] ) );
$financial_export = FinancialExport::create( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
adc_check( is_array( $financial_export ) && str_contains( $financial_export['csv'], "'=FORMULA-TEST" ) && ! str_contains( $financial_export['csv'], 'SYNTHETIC-RECEIPT-' ) && ! str_contains( $financial_export['csv'], '+966500000001' ), 'Financial CSV is branch-aware, minimized and neutralizes spreadsheet formulas.' );
adc_check( is_wp_error( FinancialExport::create( '2020-01-01', '2022-01-01' ) ), 'Financial export rejects ranges longer than 366 days.' );
add_filter( 'query', $break_audit );
$failed_export = FinancialExport::create( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
remove_filter( 'query', $break_audit );
adc_check( is_wp_error( $failed_export ) && 'adc_export_audit_failed' === $failed_export->get_error_code(), 'Financial export is withheld when its audit record fails.' );

$old_time = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
$wpdb->insert( $customers, array( 'full_name' => 'Retention Person', 'mobile' => '+966599999999', 'email' => 'retention@example.invalid', 'city' => 'Riyadh', 'consent_marketing' => 1, 'consent_at' => $old_time, 'created_at' => $old_time, 'updated_at' => $old_time ) );
$retention_customer = (int) $wpdb->insert_id;
$wpdb->insert( Schema::table( 'leads' ), array( 'customer_id' => $retention_customer, 'branch_id' => $branch_b['id'], 'owner_user_id' => $sales_b, 'source' => 'website', 'stage' => 'lost', 'lost_reason' => 'Contains personal note', 'created_at' => $old_time, 'updated_at' => $old_time ) );
update_option( 'adc_privacy_retention_days', 365, false );
$retention_result = RetentionService::run();
adc_check( 1 === $retention_result['processed'] && 'Retained customer' === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $retention_customer ) ), 'Retention anonymizes an inactive customer only after the configured period.' );
$wpdb->update( $customers, array( 'updated_at' => $old_time ), array( 'id' => $customer_b ) );
RetentionService::run();
adc_check( 'Retained customer' !== $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $customer_b ) ), 'Retention preserves a customer with active operational records.' );
$wpdb->insert( $customers, array( 'full_name' => 'Rollback Person', 'mobile' => '+966588888888', 'email' => 'rollback@example.invalid', 'city' => 'Jeddah', 'consent_marketing' => 0, 'created_at' => $old_time, 'updated_at' => $old_time ) );
$rollback_customer = (int) $wpdb->insert_id;
add_filter( 'query', $break_audit ); RetentionService::run(); remove_filter( 'query', $break_audit );
adc_check( 'Rollback Person' === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $rollback_customer ) ), 'Retention rolls identity changes back when audit persistence fails.' );

// Leave branch-B workflow records pending so the HTTP boundary can exercise transition routes.
wp_set_current_user( $admin );
$branch_c = BranchService::create( array( 'code' => 'VERIFY-C', 'name' => 'Synthetic C' ) );
if ( ! is_array( $branch_c ) ) { throw new RuntimeException( 'HTTP workflow branch fixture failed.' ); }
$manager_b = $make_user( 'manager_b', 'dealership_sales_manager', $branch_b['id'] );
$finance_b_recorder = $make_user( 'finance_b_recorder', 'dealership_finance', $branch_b['id'] );
$finance_b_reviewer = $make_user( 'finance_b_reviewer', 'dealership_finance', $branch_b['id'] );
$inventory_c = $make_user( 'inventory_c', 'dealership_inventory', $branch_c['id'] );
$make_branch_vehicle = static function ( int $branch_id, string $suffix ) use ( $admin ): int {
	$previous = get_current_user_id();
	wp_set_current_user( $admin );
	$row = VehicleService::create( array( 'vin' => '2M8GDM9AXKP' . str_pad( $suffix, 6, '0', STR_PAD_LEFT ), 'stock_number' => 'HTTP-' . $suffix, 'brand' => 'Synthetic', 'model' => 'HTTP Fixture', 'model_year' => 2026, 'condition' => 'new', 'branch_id' => $branch_id, 'retail_price' => 10000000, 'minimum_price' => 8500000, 'purchase_cost' => 8000000 ) );
	if ( ! is_array( $row ) ) { throw new RuntimeException( 'HTTP vehicle fixture creation failed.' ); }
	$checklist = array_fill_keys( array( 'exterior','interior','engine','tires','vin' ), 'pass' );
	if ( is_wp_error( VehicleIntakeService::receive( $row['id'], array( 'condition'=>'good', 'document_reference'=>'HTTP-FIXTURE-' . $suffix ) ) ) || is_wp_error( VehicleService::transition( $row['id'], 'inspection', 'HTTP fixture preparation' ) ) || is_wp_error( VehicleIntakeService::inspect( $row['id'], array( 'checklist'=>$checklist ) ) ) || is_wp_error( VehicleService::transition( $row['id'], 'available', 'HTTP fixture passed' ) ) ) { throw new RuntimeException( 'HTTP vehicle fixture intake failed.' ); }
	wp_set_current_user( $previous );
	return (int) $row['id'];
};
$http_discount_vehicle = $make_branch_vehicle( $branch_b['id'], '101' );
$http_sale_vehicle = $make_branch_vehicle( $branch_b['id'], '102' );
$http_transfer_vehicle = $make_branch_vehicle( $branch_b['id'], '103' );
wp_set_current_user( $sales_b );
$http_discount_quote = SalesService::create_quote( $customer_b, $http_discount_vehicle, $date );
$http_discount_request = SalesService::request_discount( $http_discount_quote['id'], 10000, 'HTTP decision fixture' );
$http_sale_quote = SalesService::create_quote( $customer_b, $http_sale_vehicle, $date );
$http_sale_reservation = ReservationService::create( array( 'vehicle_id' => $http_sale_vehicle, 'customer_id' => $customer_b, 'idempotency_key' => wp_generate_uuid4() ) );
$http_sale = SalesService::create_sale( $http_sale_quote['id'], $http_sale_reservation['id'] );
wp_set_current_user( $finance_b_recorder );
$http_finance = SalesService::create_finance_request( $http_sale['id'], 'HTTP finance fixture', $http_sale_quote['final_amount'], true );
$http_payment = PaymentService::record( $http_sale['id'], 100, 'bank_transfer', 'HTTP-PAYMENT-FIXTURE' );
wp_set_current_user( $inventory_b );
$http_transfer = AutoDealership\Inventory\TransferService::request( $http_transfer_vehicle, $branch_c['id'], 'HTTP transfer fixture' );
if ( ! is_array( $http_discount_request ) || ! is_array( $http_sale ) || ! is_array( $http_finance ) || ! is_array( $http_payment ) || ! is_array( $http_transfer ) ) { throw new RuntimeException( 'HTTP transition fixtures failed.' ); }

$privacy_email = 'privacy-quote@example.invalid';
$wpdb->update( $customers, array( 'email' => $privacy_email ), array( 'id' => $customer_a ) );
$export = PrivacyTools::export( $privacy_email );
$export_items = $export['data'][0]['data'] ?? array();
adc_check( count( array_filter( $export_items, static fn( $item ) => 'Quotation revision' === $item['name'] ) ) >= 1, 'Privacy export includes stored quotation identity snapshots and financial revisions.' );
$erasure = PrivacyTools::erase( $privacy_email );
adc_check( $erasure['done'] && 'Erased customer' === $wpdb->get_var( $wpdb->prepare( "SELECT customer_name FROM $history_table WHERE quotation_id = %d AND version = 1", $pricing_quote['id'] ) ), 'Privacy erasure anonymizes customer identity in quote snapshots while retaining financial history.' );
$wpdb->update( Schema::table( 'branches' ), array( 'active' => 0 ), array( 'id' => $branch_a['id'] ) );
BranchScope::clear_cache();
wp_set_current_user( $sales_a );
adc_check( ! BranchScope::allows( $branch_a['id'] ) && array() === LeadService::list_for_current_user(), 'Deactivating an assigned branch immediately revokes service and list scope.' );
adc_check( 404 === $server->dispatch( $versions_request )->get_status(), 'Inactive-branch staff cannot retrieve an existing quote by direct REST identifier.' );
wp_set_current_user( $admin );
adc_check( 200 === $server->dispatch( $versions_request )->get_status(), 'Global administrator retains inactive-branch remediation access.' );

// Exercise WordPress cookie authentication and nonces through a real localhost HTTP boundary.
if ( ! function_exists( 'curl_init' ) || ! defined( 'ADC_TEST_HTTP_CONFIG' ) ) {
	throw new RuntimeException( 'HTTP verification requires cURL and the isolated database configuration.' );
}
update_option( 'active_plugins', array( 'auto-dealership-core/auto-dealership-core.php' ) );
$listener = stream_socket_server( 'tcp://127.0.0.1:0', $socket_error, $socket_message );
if ( false === $listener ) { throw new RuntimeException( 'Cannot allocate localhost test port: ' . $socket_message ); }
$listener_name = stream_socket_get_name( $listener, false );
fclose( $listener );
$http_port = (int) substr( $listener_name, strrpos( $listener_name, ':' ) + 1 );
$http_log = tempnam( sys_get_temp_dir(), 'adc-http-' );
$http_error_log = tempnam( sys_get_temp_dir(), 'adc-http-error-' );
$http_process = null;
try {
	$http_environment = array_merge( getenv(), array( 'ADC_HTTP_TEST_CONFIG' => ADC_TEST_HTTP_CONFIG ) );
	$http_process = proc_open(
		array( PHP_BINARY, '-S', '127.0.0.1:' . $http_port, __DIR__ . '/http-router.php' ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $http_log, 'a' ), 2 => array( 'file', $http_error_log, 'a' ) ),
		$http_pipes,
		ABSPATH,
		$http_environment
	);
	if ( ! is_resource( $http_process ) ) { throw new RuntimeException( 'Cannot start localhost HTTP verification server.' ); }
	fclose( $http_pipes[0] );
	$ready = false;
	for ( $attempt = 0; $attempt < 50; ++$attempt ) {
		$probe = @fsockopen( '127.0.0.1', $http_port, $probe_error, $probe_message, 0.1 );
		if ( is_resource( $probe ) ) { fclose( $probe ); $ready = true; break; }
		usleep( 100000 );
	}
	if ( ! $ready ) { throw new RuntimeException( 'Localhost HTTP verification server did not become ready.' ); }

	$http_request = static function ( string $path, array $auth = array(), string $method = 'GET', ?array $body = null, bool $form = false ) use ( $http_port ): array {
		$headers = array( 'Host: adc-verification.invalid', 'Accept: application/json' );
		if ( isset( $auth['cookie'] ) ) { $headers[] = 'Cookie: ' . $auth['cookie']; }
		if ( isset( $auth['nonce'] ) ) { $headers[] = 'X-WP-Nonce: ' . $auth['nonce']; }
		$handle = curl_init( 'http://127.0.0.1:' . $http_port . $path );
		$options = array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false );
		if ( null !== $body ) {
			$headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
			$options[CURLOPT_HTTPHEADER] = $headers;
			$options[CURLOPT_POSTFIELDS] = $form ? http_build_query( $body ) : wp_json_encode( $body );
		}
		curl_setopt_array( $handle, $options );
		$response_body = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error = curl_error( $handle );
		curl_close( $handle );
		if ( false === $response_body ) { throw new RuntimeException( 'Local HTTP request failed: ' . $error ); }
		return array( $status, $response_body );
	};
	$http_auth = static function ( int $user_id ) use ( $http_port, $http_request ): array {
		$key = hash( 'sha256', ADC_TEST_HTTP_CONFIG . '|session' );
		$handle = curl_init( 'http://127.0.0.1:' . $http_port . '/adc-test-session?user_id=' . $user_id . '&key=' . rawurlencode( $key ) );
		curl_setopt_array( $handle, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => array( 'Host: adc-verification.invalid' ), CURLOPT_TIMEOUT => 5 ) );
		$response = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$header_size = (int) curl_getinfo( $handle, CURLINFO_HEADER_SIZE );
		curl_close( $handle );
		if ( false === $response || 200 !== $status ) { throw new RuntimeException( 'Cannot obtain isolated HTTP session.' ); }
		$headers = substr( $response, 0, $header_size );
		$payload = json_decode( substr( $response, $header_size ), true );
		preg_match_all( '/^Set-Cookie:\s*([^=;\r\n]+)=([^;\r\n]*)/mi', $headers, $cookie_matches, PREG_SET_ORDER );
		$cookies = array();
		foreach ( $cookie_matches as $match ) { $cookies[] = $match[1] . '=' . $match[2]; }
		if ( ! is_array( $payload ) || ! $cookies ) { throw new RuntimeException( 'Invalid isolated HTTP session response.' ); }
		$auth = array( 'cookie' => implode( '; ', $cookies ) );
		list( $nonce_status, $nonce_body ) = $http_request( '/adc-test-nonce?action=wp_rest', $auth );
		if ( 200 !== $nonce_status || 10 !== strlen( trim( $nonce_body ) ) ) { throw new RuntimeException( 'WordPress rejected the isolated HTTP session cookie (' . $nonce_status . ': ' . substr( wp_strip_all_tags( $nonce_body ), 0, 120 ) . ').' ); }
		$auth['nonce'] = trim( $nonce_body );
		return $auth;
	};
	$rest_path = static fn( string $route ): string => '/?rest_route=' . rawurlencode( $route );
	require __DIR__ . '/customer-preferences-http.php';

	$versions_path = $rest_path( '/auto-dealership/v1/quotations/' . $pricing_quote['id'] . '/versions' );
	list( $anonymous_status, $anonymous_body ) = $http_request( $versions_path );
	adc_check( 401 === $anonymous_status, 'Real HTTP REST request rejects an anonymous visitor (received ' . $anonymous_status . ': ' . substr( wp_strip_all_tags( $anonymous_body ), 0, 160 ) . ').' );
	$admin_http = $http_auth( $admin );
	adc_check( 10 === strlen( $admin_http['nonce'] ), 'HTTP server issues an administrator session cookie and REST nonce.' );
	adc_check( 403 === $http_request( $versions_path, array( 'cookie' => $admin_http['cookie'], 'nonce' => 'invalid' ) )[0], 'Real HTTP REST request rejects an invalid WordPress nonce.' );
	list( $admin_status, $admin_body ) = $http_request( $versions_path, $admin_http );
	adc_check( 200 === $admin_status, 'Real HTTP REST request accepts an administrator cookie and nonce (received ' . $admin_status . ': ' . substr( wp_strip_all_tags( $admin_body ), 0, 160 ) . ').' );
	$sales_b_http = $http_auth( $sales_b );
	adc_check( 10 === strlen( $sales_b_http['nonce'] ), 'HTTP server issues a branch-user session cookie and REST nonce.' );
	adc_check( 404 === $http_request( $versions_path, $sales_b_http )[0], 'Real HTTP REST request hides a quote from another branch.' );
	$sales_a_http = $http_auth( $sales_a );
	adc_check( 10 === strlen( $sales_a_http['nonce'] ), 'HTTP server issues a disabled-branch staff session without restoring branch access.' );
	adc_check( 404 === $http_request( $versions_path, $sales_a_http )[0], 'Real HTTP REST request denies staff assigned to a disabled branch.' );

	$quote_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quotes" );
	$missing_nonce_auth = array( 'cookie' => $admin_http['cookie'] );
	$quote_payload = array( 'customer_id' => $customer_a, 'vehicle_id' => $pricing_vehicle, 'valid_until' => $date );
	adc_check( 401 === $http_request( $rest_path( '/auto-dealership/v1/quotations' ), $missing_nonce_auth, 'POST', $quote_payload )[0] && $quote_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quotes" ), 'HTTP quote creation without a REST nonce is rejected without mutation.' );
	adc_check( 400 === $http_request( $rest_path( '/auto-dealership/v1/quotations' ), $admin_http, 'POST', array( 'customer_id' => $customer_a, 'vehicle_id' => $pricing_vehicle ) )[0] && $quote_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $quotes" ), 'HTTP quote schema rejects a missing required field without mutation.' );

	$discount_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'discount_requests' ) );
	$pricing_version = (int) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM $quotes WHERE id = %d", $pricing_quote['id'] ) );
	$foreign_discount = $http_request( $rest_path( '/auto-dealership/v1/quotations/' . $pricing_quote['id'] . '/discounts' ), $sales_b_http, 'POST', array( 'amount' => 20000, 'reason' => 'Foreign branch attempt' ) );
	adc_check( 400 === $foreign_discount[0] && $discount_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'discount_requests' ) ) && $pricing_version === (int) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM $quotes WHERE id = %d", $pricing_quote['id'] ) ), 'Foreign-branch HTTP discount request is rejected without changing quote history.' );

	$reservation_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $reservations" );
	$inactive_reservation = $http_request( $rest_path( '/auto-dealership/v1/reservations' ), $sales_a_http, 'POST', array( 'vehicle_id' => $transfer_vehicle, 'customer_id' => $customer_a, 'idempotency_key' => wp_generate_uuid4() ) );
	adc_check( 409 === $inactive_reservation[0] && $reservation_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $reservations" ), 'Disabled-branch staff cannot create a reservation through HTTP.' );
	$foreign_reservation = $http_request( $rest_path( '/auto-dealership/v1/reservations' ), $sales_b_http, 'POST', array( 'vehicle_id' => $transfer_vehicle, 'customer_id' => $customer_a, 'idempotency_key' => wp_generate_uuid4() ) );
	adc_check( 409 === $foreign_reservation[0] && $reservation_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $reservations" ), 'HTTP reservation rejects a customer identifier from another branch without mutation.' );

	$payment_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'payment_confirmations' ) );
	$payment_path = $rest_path( '/auto-dealership/v1/sales/' . $sale['id'] . '/payments' );
	$payment_payload = array( 'amount' => 1, 'source' => 'bank_transfer', 'reference' => 'HTTP-UNAUTHORIZED' );
	adc_check( 403 === $http_request( $payment_path, $sales_b_http, 'POST', $payment_payload )[0] && $payment_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'payment_confirmations' ) ), 'HTTP payment route rejects a sales role before service mutation.' );
	$finance_other_http = $http_auth( $finance_other );
	adc_check( 409 === $http_request( $payment_path, $finance_other_http, 'POST', $payment_payload )[0] && $payment_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'payment_confirmations' ) ), 'Foreign-branch finance staff cannot record a receipt against another branch sale.' );

	$delivery_status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'deliveries' ) . ' WHERE id = %d', $delivery['id'] ) );
	$inventory_b_http = $http_auth( $inventory_b );
	$foreign_vin = $http_request( $rest_path( '/auto-dealership/v1/deliveries/' . $delivery['id'] . '/vin' ), $inventory_b_http, 'POST', array( 'vin' => $vin ) );
	adc_check( 409 === $foreign_vin[0] && $delivery_status === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'deliveries' ) . ' WHERE id = %d', $delivery['id'] ) ), 'Foreign-branch HTTP delivery action is rejected without changing delivery state.' );

	$manager_a_http = $http_auth( $manager_a );
	$manager_b_http = $http_auth( $manager_b );
	list( $branches_status, $branches_body ) = $http_request( $rest_path( '/auto-dealership/v1/branches' ) );
	$public_branches = json_decode( $branches_body, true );
	$public_branch_codes = is_array( $public_branches ) ? array_column( $public_branches, 'code' ) : array();
	adc_check( 200 === $branches_status && ! in_array( 'VERIFY-A', $public_branch_codes, true ) && in_array( 'VERIFY-B', $public_branch_codes, true ) && in_array( 'VERIFY-C', $public_branch_codes, true ), 'Public HTTP branch list includes active branches and excludes the disabled branch.' );
	$branch_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'branches' ) );
	adc_check( 403 === $http_request( $rest_path( '/auto-dealership/v1/branches' ), $sales_b_http, 'POST', array( 'code' => 'HTTP-DENIED', 'name' => 'Denied branch' ) )[0] && $branch_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'branches' ) ), 'Non-administrator cannot create a branch through HTTP.' );

	$leads_path = $rest_path( '/auto-dealership/v1/leads' );
	list( $public_lead_status, $public_lead_body ) = $http_request( $leads_path, array(), 'POST', array( 'name' => 'HTTP CRM customer', 'mobile' => '+966511111111', 'email' => 'http-crm@example.invalid', 'branch_id' => $branch_b['id'], 'consent_marketing' => true ) );
	$public_lead = json_decode( $public_lead_body, true );
	$http_lead_id = (int) ( $public_lead['id'] ?? 0 );
	adc_check( 200 === $public_lead_status && $http_lead_id > 0 && 'new' === $wpdb->get_var( $wpdb->prepare( 'SELECT stage FROM ' . Schema::table( 'leads' ) . ' WHERE id = %d', $http_lead_id ) ), 'Public HTTP lead intake creates a validated new lead in an active branch.' );
	adc_check( 401 === $http_request( $leads_path )[0], 'Anonymous visitor cannot list CRM leads through HTTP.' );
	$assignment_path = $rest_path( '/auto-dealership/v1/leads/' . $http_lead_id . '/assignment' );
	adc_check( 400 === $http_request( $assignment_path, $manager_a_http, 'POST', array( 'staff_id' => $sales_b ) )[0] && '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_user_id FROM ' . Schema::table( 'leads' ) . ' WHERE id = %d', $http_lead_id ) ), 'Foreign inactive-branch manager cannot assign an active-branch lead through HTTP.' );
	adc_check( 200 === $http_request( $assignment_path, $manager_b_http, 'POST', array( 'staff_id' => $sales_b ) )[0] && $sales_b === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_user_id FROM ' . Schema::table( 'leads' ) . ' WHERE id = %d', $http_lead_id ) ), 'Same-branch manager can assign an unowned lead through HTTP.' );
	$stage_path = $rest_path( '/auto-dealership/v1/leads/' . $http_lead_id . '/stage' );
	adc_check( 404 === $http_request( $stage_path, $sales_a_http, 'POST', array( 'stage' => 'contacted', 'reason' => 'Foreign attempt' ) )[0] && 'new' === $wpdb->get_var( $wpdb->prepare( 'SELECT stage FROM ' . Schema::table( 'leads' ) . ' WHERE id = %d', $http_lead_id ) ), 'Foreign inactive-branch sales user cannot change lead stage through HTTP.' );
	adc_check( 200 === $http_request( $stage_path, $sales_b_http, 'POST', array( 'stage' => 'contacted', 'reason' => 'Customer reached' ) )[0] && 'contacted' === $wpdb->get_var( $wpdb->prepare( 'SELECT stage FROM ' . Schema::table( 'leads' ) . ' WHERE id = %d', $http_lead_id ) ), 'Assigned salesperson can advance their lead stage through HTTP.' );
	$activity_path = $rest_path( '/auto-dealership/v1/leads/' . $http_lead_id . '/activities' );
	$activity_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'activities' ) . ' WHERE lead_id = %d', $http_lead_id ) );
	adc_check( 404 === $http_request( $activity_path, $sales_a_http, 'POST', array( 'type' => 'call', 'notes' => 'Foreign activity' ) )[0] && $activity_count === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'activities' ) . ' WHERE lead_id = %d', $http_lead_id ) ), 'Foreign inactive-branch user cannot add a CRM activity through HTTP.' );
	adc_check( 200 === $http_request( $activity_path, $sales_b_http, 'POST', array( 'type' => 'call', 'notes' => 'Qualified follow-up call' ) )[0] && $activity_count + 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'activities' ) . ' WHERE lead_id = %d', $http_lead_id ) ), 'Assigned salesperson can add a CRM activity through HTTP.' );
	list( $lead_list_status, $lead_list_body ) = $http_request( $leads_path . '&per_page=100', $sales_b_http );
	$lead_list = json_decode( $lead_list_body, true );
	adc_check( 200 === $lead_list_status && in_array( $http_lead_id, array_map( 'intval', array_column( is_array( $lead_list ) ? $lead_list : array(), 'id' ) ), true ), 'Salesperson HTTP lead list includes their newly assigned lead.' );

	$discount_decision_path = $rest_path( '/auto-dealership/v1/discounts/' . $http_discount_request['id'] . '/decision' );
	$discounts_table = Schema::table( 'discount_requests' );
	adc_check( 409 === $http_request( $discount_decision_path, $manager_a_http, 'POST', array( 'approve' => true, 'reason' => 'Foreign branch' ) )[0] && 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $discounts_table WHERE id = %d", $http_discount_request['id'] ) ), 'Inactive foreign-branch manager cannot decide a discount through HTTP.' );
	adc_check( 200 === $http_request( $discount_decision_path, $manager_b_http, 'POST', array( 'approve' => true, 'reason' => 'Valid branch review' ) )[0] && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $discounts_table WHERE id = %d", $http_discount_request['id'] ) ), 'Correct branch manager can approve a pending discount through HTTP.' );

	$finance_a_http = $http_auth( $finance_verifier );
	$finance_b_http = $http_auth( $finance_b_reviewer );
	$finance_path = $rest_path( '/auto-dealership/v1/finance-requests/' . $http_finance['id'] . '/status' );
	$finance_table = Schema::table( 'finance_requests' );
	adc_check( 409 === $http_request( $finance_path, $finance_a_http, 'POST', array( 'status' => 'approved', 'provider_reference' => 'FOREIGN' ) )[0] && 'submitted' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $finance_table WHERE id = %d", $http_finance['id'] ) ), 'Foreign-branch finance reviewer cannot decide a financing request through HTTP.' );
	adc_check( 200 === $http_request( $finance_path, $finance_b_http, 'POST', array( 'status' => 'approved', 'provider_reference' => 'HTTP-FINANCE-APPROVED' ) )[0] && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $finance_table WHERE id = %d", $http_finance['id'] ) ), 'Separate same-branch finance reviewer can approve financing through HTTP.' );

	$sale_approval_path = $rest_path( '/auto-dealership/v1/sales/' . $http_sale['id'] . '/approval' );
	$sales_table = Schema::table( 'sales' );
	adc_check( 403 === $http_request( $sale_approval_path, $manager_a_http, 'POST', array( 'invoice_reference' => 'FOREIGN-INVOICE' ) )[0] && 'pending_approval' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $sales_table WHERE id = %d", $http_sale['id'] ) ), 'Foreign-branch manager cannot approve a sale through HTTP.' );
	adc_check( 200 === $http_request( $sale_approval_path, $manager_b_http, 'POST', array( 'invoice_reference' => 'HTTP-INVOICE-APPROVED' ) )[0] && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $sales_table WHERE id = %d", $http_sale['id'] ) ), 'Correct branch manager can approve an eligible sale through HTTP.' );

	$payment_decision_path = $rest_path( '/auto-dealership/v1/payments/' . $http_payment['id'] . '/decision' );
	$payments_table = Schema::table( 'payment_confirmations' );
	adc_check( 409 === $http_request( $payment_decision_path, $finance_a_http, 'POST', array( 'approve' => true, 'reason' => 'Foreign branch' ) )[0] && 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $payments_table WHERE id = %d", $http_payment['id'] ) ), 'Foreign-branch finance reviewer cannot verify a receipt through HTTP.' );
	adc_check( 200 === $http_request( $payment_decision_path, $finance_b_http, 'POST', array( 'approve' => true, 'reason' => 'Matched HTTP fixture' ) )[0] && 'verified' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $payments_table WHERE id = %d", $http_payment['id'] ) ), 'Separate same-branch finance reviewer can verify a receipt through HTTP.' );

	$finance_b_recorder_http = $http_auth( $finance_b_recorder );
	$remaining_amount = (int) $http_sale_quote['final_amount'] - 100;
	$http_sale_payment_path = $rest_path( '/auto-dealership/v1/sales/' . $http_sale['id'] . '/payments' );
	$remaining_payload = array( 'amount' => $remaining_amount, 'source' => 'finance_disbursement', 'reference' => 'HTTP-DELIVERY-BALANCE' );
	$payment_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $payments_table" );
	adc_check( 409 === $http_request( $http_sale_payment_path, $finance_a_http, 'POST', $remaining_payload )[0] && $payment_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $payments_table" ), 'Foreign inactive-branch finance user cannot record the delivery balance through HTTP.' );
	list( $remaining_status, $remaining_body ) = $http_request( $http_sale_payment_path, $finance_b_recorder_http, 'POST', $remaining_payload );
	$remaining_payment = json_decode( $remaining_body, true );
	$remaining_payment_id = (int) ( $remaining_payment['id'] ?? 0 );
	adc_check( 200 === $remaining_status && $remaining_payment_id > 0 && 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $payments_table WHERE id = %d", $remaining_payment_id ) ), 'Same-branch finance recorder can submit the remaining sale balance through HTTP.' );
	$remaining_decision_path = $rest_path( '/auto-dealership/v1/payments/' . $remaining_payment_id . '/decision' );
	adc_check( 200 === $http_request( $remaining_decision_path, $finance_b_http, 'POST', array( 'approve' => true, 'reason' => 'Full balance matched' ) )[0] && 'verified' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $payments_table WHERE id = %d", $remaining_payment_id ) ), 'Separate finance reviewer verifies the remaining balance through HTTP.' );

	$deliveries_table = Schema::table( 'deliveries' );
	$delivery_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $deliveries_table" );
	$prepare_path = $rest_path( '/auto-dealership/v1/sales/' . $http_sale['id'] . '/delivery' );
	adc_check( 409 === $http_request( $prepare_path, $manager_a_http, 'POST', array() )[0] && $delivery_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $deliveries_table" ), 'Foreign inactive-branch manager cannot prepare a funded sale for delivery through HTTP.' );
	list( $prepare_status, $prepare_body ) = $http_request( $prepare_path, $manager_b_http, 'POST', array() );
	$http_delivery = json_decode( $prepare_body, true );
	$http_delivery_id = (int) ( $http_delivery['id'] ?? 0 );
	adc_check( 200 === $prepare_status && $http_delivery_id > 0 && 'preparing' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $deliveries_table WHERE id = %d", $http_delivery_id ) ), 'Same-branch manager prepares a fully settled sale for delivery through HTTP.' );
	$inventory_a_http = $http_auth( $inventory );
	$http_sale_vin = (string) $wpdb->get_var( $wpdb->prepare( "SELECT vin FROM $vehicles WHERE id = %d", $http_sale_vehicle ) );
	$http_vin_path = $rest_path( '/auto-dealership/v1/deliveries/' . $http_delivery_id . '/vin' );
	adc_check( 409 === $http_request( $http_vin_path, $inventory_a_http, 'POST', array( 'vin' => $http_sale_vin ) )[0] && 'preparing' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $deliveries_table WHERE id = %d", $http_delivery_id ) ), 'Foreign inactive-branch inventory user cannot confirm delivery VIN through HTTP.' );
	adc_check( 200 === $http_request( $http_vin_path, $inventory_b_http, 'POST', array( 'vin' => $http_sale_vin ) )[0] && 'vin_confirmed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $deliveries_table WHERE id = %d", $http_delivery_id ) ), 'Same-branch inventory user confirms the exact delivery VIN through HTTP.' );
	$http_delivery_approval_path = $rest_path( '/auto-dealership/v1/deliveries/' . $http_delivery_id . '/approval' );
	adc_check( 409 === $http_request( $http_delivery_approval_path, $manager_a_http, 'POST', array() )[0] && 'vin_confirmed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $deliveries_table WHERE id = %d", $http_delivery_id ) ), 'Foreign inactive-branch manager cannot approve delivery through HTTP.' );
	adc_check( 200 === $http_request( $http_delivery_approval_path, $manager_b_http, 'POST', array() )[0] && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $deliveries_table WHERE id = %d", $http_delivery_id ) ), 'Separate same-branch manager approves VIN-confirmed delivery through HTTP.' );
	$http_release_path = $rest_path( '/auto-dealership/v1/deliveries/' . $http_delivery_id . '/release' );
	adc_check( 409 === $http_request( $http_release_path, $manager_a_http, 'POST', array() )[0] && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $deliveries_table WHERE id = %d", $http_delivery_id ) ), 'Foreign inactive-branch manager cannot release an approved delivery through HTTP.' );
	adc_check( 200 === $http_request( $http_release_path, $manager_b_http, 'POST', array() )[0] && 'delivered' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $deliveries_table WHERE id = %d", $http_delivery_id ) ) && 'delivered' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $http_sale_vehicle ) ), 'Eligible manager releases the fully verified vehicle through HTTP.' );

	$inventory_c_http = $http_auth( $inventory_c );
	$vehicles_path = $rest_path( '/auto-dealership/v1/vehicles' );
	$http_vehicle_payload = array( 'vin' => '3M8GDM9AXKP000201', 'stock_number' => 'HTTP-201', 'brand' => 'Synthetic', 'model' => 'Catalog HTTP', 'model_year' => 2026, 'condition' => 'new', 'branch_id' => $branch_b['id'], 'retail_price' => 12000000, 'mileage' => 240, 'body_type' => 'suv', 'fuel_type' => 'hybrid', 'transmission' => 'automatic' );
	$http_vehicle_payload['interior_color'] = 'Black';
	$http_vehicle_payload['engine_size'] = '2.0 L';
	$invalid_spec_creation = array_merge( $http_vehicle_payload, array( 'doors'=>array( 4 ) ) );
	adc_check( 400 === $http_request( $vehicles_path, $inventory_b_http, 'POST', $invalid_spec_creation )[0], 'HTTP creation rejects malformed specifications before inserting inventory.' );
	$vehicle_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" );
	adc_check( 403 === $http_request( $vehicles_path, $sales_b_http, 'POST', $http_vehicle_payload )[0] && $vehicle_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" ), 'Sales role cannot create inventory through HTTP.' );
	adc_check( 403 === $http_request( $vehicles_path, $inventory_a_http, 'POST', $http_vehicle_payload )[0] && $vehicle_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vehicles" ), 'Inactive foreign-branch inventory user cannot create a branch-B vehicle through HTTP.' );
	list( $vehicle_create_status, $vehicle_create_body ) = $http_request( $vehicles_path, $inventory_b_http, 'POST', $http_vehicle_payload );
	$http_vehicle = json_decode( $vehicle_create_body, true );
	$http_vehicle_id = (int) ( $http_vehicle['id'] ?? 0 );
	adc_check( 'Black' === ( $http_vehicle['interior_color'] ?? null ) && '2.0 L' === ( $http_vehicle['engine_size'] ?? null ), 'HTTP vehicle creation persists and returns supplied specifications.' );
	$spec_path = $rest_path( '/auto-dealership/v1/vehicles/' . $http_vehicle_id . '/specifications' );
	$spec_payload = array( 'reason'=>'Documented specifications', 'specifications'=>array( 'exterior_color'=>'Silver', 'seats'=>5, 'drivetrain'=>'awd' ) );
	adc_check( 401 === $http_request( $spec_path, array(), 'PATCH', $spec_payload )[0], 'HTTP specification changes reject anonymous callers.' );
	adc_check( 403 === $http_request( $spec_path, array( 'cookie'=>$inventory_b_http['cookie'], 'nonce'=>'invalid' ), 'PATCH', $spec_payload )[0], 'HTTP specification changes reject an invalid cookie-session nonce.' );
	adc_check( 403 === $http_request( $spec_path, $sales_b_http, 'PATCH', $spec_payload )[0], 'HTTP specification changes reject the sales role.' );
	adc_check( 404 === $http_request( $spec_path, $inventory_a_http, 'PATCH', $spec_payload )[0], 'HTTP specification changes reject an inactive foreign branch.' );
	adc_check( 400 === $http_request( $spec_path, $inventory_b_http, 'PATCH', array( 'reason'=>'Private input', 'specifications'=>array( 'purchase_cost'=>1 ) ) )[0], 'HTTP specifications cannot change a private pricing field.' );
	adc_check( 400 === $http_request( $spec_path, $inventory_b_http, 'PATCH', array( 'reason'=>'Malformed input', 'specifications'=>array( 'seats'=>array( 5 ) ) ) )[0], 'HTTP specifications reject nested malformed numeric input.' );
	adc_check( 200 === $http_request( $spec_path, $inventory_b_http, 'PATCH', $spec_payload )[0] && 'Silver' === $wpdb->get_var( $wpdb->prepare( "SELECT exterior_color FROM $vehicles WHERE id=%d", $http_vehicle_id ) ), 'Authorized HTTP PATCH persists the public specifications.' );
	$spec_replay = $http_request( $spec_path, $inventory_b_http, 'PATCH', $spec_payload );
	adc_check( 200 === $spec_replay[0] && false === json_decode( $spec_replay[1], true )['updated'], 'Identical HTTP specification retry reports no change.' );
	adc_check( 200 === $vehicle_create_status && $http_vehicle_id > 0 && 'received' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $http_vehicle_id ) ), 'Same-branch inventory user can receive a vehicle through HTTP.' );
	$vehicle_status_path = $rest_path( '/auto-dealership/v1/vehicles/' . $http_vehicle_id . '/status' );
	adc_check( 404 === $http_request( $vehicle_status_path, $inventory_a_http, 'POST', array( 'status' => 'inspection', 'reason' => 'Foreign attempt' ) )[0] && 'received' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $http_vehicle_id ) ), 'Foreign inactive-branch inventory user cannot change vehicle state through HTTP.' );
	adc_check( 409 === $http_request( $vehicle_status_path, $inventory_b_http, 'POST', array( 'status' => 'inspection', 'reason' => 'Missing receipt' ) )[0], 'HTTP inventory cannot enter inspection without a receipt record.' );
	$receipt_path = $rest_path( '/auto-dealership/v1/vehicles/' . $http_vehicle_id . '/receipt' );
	adc_check( 200 === $http_request( $receipt_path, $inventory_b_http, 'POST', array( 'condition'=>'good', 'odometer'=>12, 'document_reference'=>'HTTP-RECEIPT-201' ) )[0], 'Same-branch inventory records HTTP receiving evidence.' );
	adc_check( 200 === $http_request( $vehicle_status_path, $inventory_b_http, 'POST', array( 'status' => 'inspection', 'reason' => 'HTTP inspection' ) )[0] && 'inspection' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $http_vehicle_id ) ), 'Same-branch inventory user can move a received vehicle to inspection through HTTP.' );
	adc_check( 409 === $http_request( $vehicle_status_path, $inventory_b_http, 'POST', array( 'status' => 'available', 'reason' => 'Missing checklist' ) )[0], 'HTTP inventory cannot become available without a passed checklist.' );
	$inspection_path = $rest_path( '/auto-dealership/v1/vehicles/' . $http_vehicle_id . '/inspection' );
	adc_check( 200 === $http_request( $inspection_path, $inventory_b_http, 'POST', array( 'checklist'=>array_fill_keys( array('exterior','interior','engine','tires','vin'), 'pass' ) ) )[0], 'Same-branch inventory completes the mandatory HTTP inspection checklist.' );
	adc_check( 200 === $http_request( $vehicle_status_path, $inventory_b_http, 'POST', array( 'status' => 'available', 'reason' => 'HTTP inspection passed' ) )[0] && 'available' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $vehicles WHERE id = %d", $http_vehicle_id ) ), 'Same-branch inventory user can make an inspected vehicle available through HTTP.' );
	wp_set_current_user( $admin );
	$catalog_post_id = wp_insert_post( array( 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'HTTP public catalog vehicle' ) );
	if ( is_wp_error( $catalog_post_id ) || $catalog_post_id < 1 ) { throw new RuntimeException( 'Catalog post fixture failed.' ); }
	$wpdb->update( $vehicles, array( 'public_post_id' => (int) $catalog_post_id ), array( 'id' => $http_vehicle_id ), array( '%d' ), array( '%d' ) );
	list( $catalog_status, $catalog_body ) = $http_request( $vehicles_path . '&per_page=48' );
	$catalog = json_decode( $catalog_body, true );
	$catalog_items = is_array( $catalog['items'] ?? null ) ? $catalog['items'] : array();
	$catalog_match = array_values( array_filter( $catalog_items, static fn( $item ) => $http_vehicle_id === (int) ( $item['id'] ?? 0 ) ) );
	adc_check( 200 === $catalog_status && 1 === count( $catalog_match ) && ! array_key_exists( 'vin', $catalog_match[0] ) && ! array_key_exists( 'purchase_cost', $catalog_match[0] ), 'Public HTTP catalog exposes the mapped available vehicle without VIN or purchase cost.' );
	adc_check( 'Silver' === $catalog_match[0]['exterior_color'] && 5 === (int) $catalog_match[0]['seats'] && 'awd' === $catalog_match[0]['drivetrain'] && ! array_key_exists( 'minimum_price', $catalog_match[0] ), 'Public HTTP catalog includes updated specifications without the private price floor.' );
	$catalog_filter_query = http_build_query( array(
		'brand'=>'Synthetic', 'model'=>'Catalog HTTP', 'body_type'=>'suv', 'fuel_type'=>'hybrid',
		'transmission'=>'automatic', 'engine_size'=>'2.0 L', 'drivetrain'=>'awd',
		'exterior_color'=>'Silver', 'interior_color'=>'Black', 'condition'=>'new',
		'model_year'=>2026, 'min_year'=>2026, 'max_year'=>2026,
		'min_price'=>12000000, 'max_price'=>12000000, 'min_mileage'=>200, 'max_mileage'=>300,
		'branch_id'=>$branch_b['id'], 'search'=>'HTTP-201', 'sort'=>'price_asc', 'page'=>1, 'per_page'=>1,
	), '', '&', PHP_QUERY_RFC3986 );
	list( $catalog_filter_status, $catalog_filter_body ) = $http_request( $vehicles_path . '&' . $catalog_filter_query );
	$catalog_filtered = json_decode( $catalog_filter_body, true );
	$catalog_filtered_item = $catalog_filtered['items'][0] ?? array();
	adc_check( 200 === $catalog_filter_status && 1 === (int) ( $catalog_filtered['total'] ?? 0 ) && $http_vehicle_id === (int) ( $catalog_filtered_item['id'] ?? 0 ) && 'price_asc' === ( $catalog_filtered['filters']['sort'] ?? '' ), 'Public HTTP catalog composes every documented filter and returns normalized pagination metadata.' );
	adc_check( ! array_key_exists( 'vin', $catalog_filtered_item ) && ! array_key_exists( 'purchase_cost', $catalog_filtered_item ) && ! array_key_exists( 'minimum_price', $catalog_filtered_item ) && ! array_key_exists( 'location_id', $catalog_filtered_item ) && ! array_key_exists( 'brand_id', $catalog_filtered_item ), 'Filtered HTTP catalog response keeps private and internal inventory fields out of the public contract.' );
	list( $private_search_status, $private_search_body ) = $http_request( $vehicles_path . '&search=3M8GDM9AXKP000201' );
	$private_search = json_decode( $private_search_body, true );
	adc_check( 200 === $private_search_status && 0 === (int) ( $private_search['total'] ?? -1 ), 'Public HTTP catalog does not search private VIN data.' );
	adc_check( 400 === $http_request( $vehicles_path . '&sort=unsupported' )[0] && 400 === $http_request( $vehicles_path . '&per_page=49' )[0], 'Public HTTP catalog rejects unsupported sorting and pagination outside the documented bound.' );
	$transfer_decision_path = $rest_path( '/auto-dealership/v1/transfers/' . $http_transfer['id'] . '/decision' );
	$transfer_dispatch_path = $rest_path( '/auto-dealership/v1/transfers/' . $http_transfer['id'] . '/dispatch' );
	$transfer_receipt_path = $rest_path( '/auto-dealership/v1/transfers/' . $http_transfer['id'] . '/receipt' );
	$http_transfers_table = Schema::table( 'vehicle_transfers' );
	adc_check( 403 === $http_request( $transfer_decision_path, $inventory_a_http, 'POST', array( 'approve' => true ) )[0] && 'requested' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $http_transfers_table WHERE id = %d", $http_transfer['id'] ) ), 'Unrelated inactive-branch inventory user cannot decide a transfer through HTTP.' );
	adc_check( 200 === $http_request( $transfer_decision_path, $inventory_c_http, 'POST', array( 'approve' => true ) )[0] && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $http_transfers_table WHERE id = %d", $http_transfer['id'] ) ), 'Destination inventory user can approve a transfer through HTTP.' );
	adc_check( 403 === $http_request( $transfer_dispatch_path, $inventory_c_http, 'POST', array() )[0] && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $http_transfers_table WHERE id = %d", $http_transfer['id'] ) ), 'Destination inventory user cannot dispatch the source transfer through HTTP.' );
	adc_check( 200 === $http_request( $transfer_dispatch_path, $inventory_b_http, 'POST', array() )[0] && 'dispatched' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $http_transfers_table WHERE id = %d", $http_transfer['id'] ) ), 'Source requester can dispatch an approved transfer through HTTP.' );
	adc_check( 403 === $http_request( $transfer_receipt_path, $inventory_b_http, 'POST', array() )[0] && 'dispatched' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $http_transfers_table WHERE id = %d", $http_transfer['id'] ) ), 'Source requester cannot receive their own transfer through HTTP.' );
	adc_check( 200 === $http_request( $transfer_receipt_path, $inventory_c_http, 'POST', array() )[0] && 'received' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $http_transfers_table WHERE id = %d", $http_transfer['id'] ) ), 'Destination inventory user can receive a dispatched transfer through HTTP.' );
	$print_path = '/wp-admin/admin-post.php?action=adc_print_quote&quote_id=' . $pricing_quote['id'] . '&version=1&_wpnonce=';
	adc_check( 403 === $http_request( $print_path . 'invalid', $admin_http )[0], 'Printable quote action rejects an invalid form nonce over HTTP.' );
	list( $print_nonce_status, $print_nonce ) = $http_request( '/adc-test-nonce?action=' . rawurlencode( 'adc_print_quote_' . $pricing_quote['id'] . '_1' ), $admin_http );
	$print_nonce = trim( $print_nonce );
	adc_check( 200 === $print_nonce_status && 10 === strlen( $print_nonce ), 'Authenticated administrator obtains a nonce for the print action.' );
	list( $print_status, $print_body ) = $http_request( $print_path . rawurlencode( $print_nonce ), $admin_http );
	adc_check( 200 === $print_status && str_contains( $print_body, 'TEST-5' ), 'Printable quote action accepts a valid user cookie and action nonce over HTTP.' );
	require __DIR__ . '/increment-crm-http.php';
} finally {
	if ( is_resource( $http_process ) ) {
		proc_terminate( $http_process );
		proc_close( $http_process );
	}
	if ( is_string( $http_log ) && is_file( $http_log ) ) { unlink( $http_log ); }
	if ( is_string( $http_error_log ) && is_file( $http_error_log ) ) { unlink( $http_error_log ); }
}
if ( '1' === getenv( 'ADC_BROWSER_JOURNEY' ) ) { require __DIR__ . '/account-journey.php'; }
echo "Completed $checks isolated database checks.\n";
