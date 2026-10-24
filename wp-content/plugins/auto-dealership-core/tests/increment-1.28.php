<?php
/** Business-model and v2 contract acceptance. Loaded only by the isolated runner. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\API\OpenApiSpecification;
use AutoDealership\API\ResponseContract;
use AutoDealership\Core\Capabilities;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleAcquisitionService;
use AutoDealership\Inventory\VehicleAcquisitionDocuments;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Purchasing\SupplierService;
use AutoDealership\Sales\SalesService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Security\SecurityAudit;

$increment_128_previous_user = get_current_user_id();
try {
	$role_matrix = Capabilities::role_matrix();
	adc_check( in_array( 'adc_manage_vehicle_costs', $role_matrix['dealership_purchasing'], true ) && in_array( 'adc_manage_suppliers', $role_matrix['dealership_purchasing'], true ) && ! in_array( 'adc_view_vehicle_costs', $role_matrix['dealership_inventory'], true ) && ! in_array( 'adc_manage_finance', $role_matrix['dealership_delivery'], true ), 'Purchasing, inventory and delivery roles have distinct 1.28 capabilities.' );
	adc_check( in_array( 'adc_approve_purchase_orders', $role_matrix['dealership_purchase_approver'], true ) && ! in_array( 'adc_approve_purchase_orders', $role_matrix['dealership_sales_manager'], true ) && in_array( 'adc_view_purchase_orders', $role_matrix['dealership_sales_manager'], true ), 'Purchase-order approval is separated from the sales-manager default and available to a dedicated approver role.' );
	$legacy_sales_manager_role = get_role( 'dealership_sales_manager' );
	if ( $legacy_sales_manager_role ) { $legacy_sales_manager_role->add_cap( 'adc_approve_purchase_orders' ); }
	update_option( 'adc_purchase_permissions_version', '1', false );
	Capabilities::migrate_purchase_permissions();
	$dedicated_purchase_approver = get_role( 'dealership_purchase_approver' );
	adc_check( '2' === get_option( 'adc_purchase_permissions_version' ) && $legacy_sales_manager_role && ! $legacy_sales_manager_role->has_cap( 'adc_approve_purchase_orders' ) && $dedicated_purchase_approver && $dedicated_purchase_approver->has_cap( 'adc_approve_purchase_orders' ), 'Purchase approval migration removes the default sales-manager grant and registers the dedicated approver role.' );
	$custom_role_definitions = get_option( 'adc_role_definitions', array() );
	$custom_role_definitions['dealership_sales_manager'] = array( 'caps' => array( 'adc_approve_purchase_orders' ) );
	update_option( 'adc_role_definitions', $custom_role_definitions, false );
	$legacy_sales_manager_role->add_cap( 'adc_approve_purchase_orders' );
	update_option( 'adc_purchase_permissions_version', '1', false );
	Capabilities::migrate_purchase_permissions();
	adc_check( $legacy_sales_manager_role->has_cap( 'adc_approve_purchase_orders' ), 'Purchase approval migration preserves an explicitly configured Sales Manager role grant.' );
	unset( $custom_role_definitions['dealership_sales_manager'] );
	update_option( 'adc_role_definitions', $custom_role_definitions, false );
	$legacy_sales_manager_role->remove_cap( 'adc_approve_purchase_orders' );
	update_option( 'adc_purchase_permissions_version', '2', false );
	adc_check( Schema::VERSION === get_option( 'adc_db_version' ) && array() === Schema::verify(), 'Business-model tables and indexes satisfy schema 1.17.0.' );

	wp_set_current_user( $admin );
	$purchasing_128 = $make_user( 'purchasing_128', 'dealership_purchasing', (int) $branch_a['id'] );
	$purchasing_other_128 = $make_user( 'purchasing_other_128', 'dealership_purchasing', (int) $branch_b['id'] );
	$delivery_128 = $make_user( 'delivery_128', 'dealership_delivery', (int) $branch_a['id'] );
	$supplier_128 = SupplierService::create( array( 'supplier_code'=>'VERIFY-128', 'display_name'=>'Synthetic Auto Supply', 'legal_name'=>'Synthetic Auto Supply LLC', 'country'=>'SA', 'contact_name'=>'Fixture contact', 'contact_email'=>'supplier@example.invalid', 'contact_phone'=>'+966500000000', 'tax_number'=>'TEST-128', 'notes'=>'Isolated fixture only' ) );
	adc_check( is_array( $supplier_128 ) && (int) $supplier_128['id'] > 0, 'Supplier creation persists a global reference with restricted contact details.' );
	adc_check( is_wp_error( SupplierService::create( array( 'supplier_code'=>'VERIFY-128', 'display_name'=>'Duplicate' ) ) ), 'Supplier code uniqueness rejects duplicates.' );
	$cost_supplier_viewer_128 = $make_user( 'cost_supplier_viewer_128', 'subscriber', (int) $branch_a['id'] );
	get_userdata( $cost_supplier_viewer_128 )->add_cap( 'adc_view_suppliers' );
	get_userdata( $cost_supplier_viewer_128 )->add_cap( 'adc_view_vehicle_costs' );
	wp_set_current_user( $cost_supplier_viewer_128 );
	$cost_supplier_rows_128 = SupplierService::all();
	$cost_supplier_row_128 = array_values( array_filter( $cost_supplier_rows_128, static fn( $row ) => (int) $row['id'] === (int) $supplier_128['id'] ) );
	adc_check( 1 === count( $cost_supplier_row_128 ) && 'Synthetic Auto Supply' === $cost_supplier_row_128[0]['display_name'] && ! array_key_exists( 'legal_name', $cost_supplier_row_128[0] ) && ! array_key_exists( 'tax_number', $cost_supplier_row_128[0] ) && ! array_key_exists( 'contact_email', $cost_supplier_row_128[0] ) && ! array_key_exists( 'contact_phone', $cost_supplier_row_128[0] ) && ! array_key_exists( 'notes', $cost_supplier_row_128[0] ), 'Vehicle cost visibility alone does not reveal restricted supplier identity, contact, tax or notes.' );
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
	$public_attachment_rejected_128 = VehicleAcquisitionService::update( $acquisition_vehicle_128, array( 'document_media_ids'=>array( 99999999 ) ), 'Invalid attachment' );
	adc_check( is_wp_error( $public_attachment_rejected_128 ) && 'adc_acquisition_public_document_unsupported' === $public_attachment_rejected_128->get_error_code(), 'Public WordPress media IDs are explicitly rejected for private acquisition evidence.' );
	$acquisition_128 = VehicleAcquisitionService::update( $acquisition_vehicle_128, array( 'supplier_id'=>(int) $supplier_128['id'], 'purchase_cost'=>7900000, 'additional_cost'=>125000, 'total_cost'=>8025000, 'wholesale_price'=>8300000, 'customs_reference'=>'VERIFY-CUSTOMS-128', 'arrival_date'=>'2026-10-01', 'internal_notes'=>'Fixture acquisition only' ), 'Verified acquisition fixture' );
	$acquisition_read_128 = VehicleAcquisitionService::get( $acquisition_vehicle_128 );
	adc_check( is_array( $acquisition_128 ) && is_array( $acquisition_read_128 ) && 7900000 === $acquisition_read_128['purchase_cost'] && 8025000 === $acquisition_read_128['total_cost'] && (int) $supplier_128['id'] === $acquisition_read_128['supplier_id'], 'Purchasing saves audited integer-halala acquisition values.' );
	$private_document_128 = VehicleAcquisitionDocuments::store( $acquisition_vehicle_128, 'private-evidence.pdf', "%PDF-1.4\nprivate acquisition fixture\n%%EOF\n" );
	adc_check( is_array( $private_document_128 ), 'A cost manager can store a validated private acquisition PDF.' );
	$private_document_id_128 = is_array( $private_document_128 ) ? (int) $private_document_128['id'] : 0;
	adc_check( is_wp_error( VehicleAcquisitionDocuments::store( $acquisition_vehicle_128, 'not-an-image.png', 'not image bytes' ) ), 'Private evidence rejects content that does not match an allowed file signature.' );
	$private_document_read_128 = $private_document_id_128 ? VehicleAcquisitionDocuments::retrieve( $private_document_id_128 ) : new WP_Error( 'fixture_upload_failed' );
	$private_document_list_128 = VehicleAcquisitionDocuments::all( $acquisition_vehicle_128 );
	$acquisition_with_documents_128 = VehicleAcquisitionService::get( $acquisition_vehicle_128 );
	adc_check( is_array( $private_document_read_128 ) && "%PDF-1.4\nprivate acquisition fixture\n%%EOF\n" === $private_document_read_128['file_bytes'] && 1 === count( $private_document_list_128 ) && ! isset( $acquisition_with_documents_128['document_media_ids'] ) && 0 === $acquisition_with_documents_128['legacy_public_document_count'], 'Private acquisition evidence is stored outside media, listed without exposing legacy media IDs, and downloadable to same-branch cost staff.' );
	$wpdb->update( Schema::table( 'vehicle_acquisition_documents' ), array( 'checksum'=>str_repeat( '0', 64 ) ), array( 'id'=>$private_document_id_128 ), array( '%s' ), array( '%d' ) );
	$private_document_corrupt_128 = VehicleAcquisitionDocuments::retrieve( $private_document_id_128 );
	$wpdb->update( Schema::table( 'vehicle_acquisition_documents' ), array( 'checksum'=>$private_document_read_128['checksum'] ), array( 'id'=>$private_document_id_128 ), array( '%s' ), array( '%d' ) );
	adc_check( is_wp_error( $private_document_corrupt_128 ), 'Private document download refuses bytes whose stored checksum no longer matches.' );
	$legacy_pdf_bytes_128 = "%PDF-1.4\nlegacy private acquisition fixture\n%%EOF\n";
	$legacy_upload_128 = wp_upload_bits( 'legacy-acquisition-fixture.pdf', null, $legacy_pdf_bytes_128 );
	$unsupported_upload_128 = wp_upload_bits( 'unsupported-acquisition-fixture.txt', null, 'not a supported evidence file' );
	$legacy_attachment_128 = is_array( $legacy_upload_128 ) && empty( $legacy_upload_128['error'] ) ? wp_insert_attachment( array( 'post_title'=>'Legacy acquisition fixture', 'post_mime_type'=>'application/pdf', 'post_status'=>'inherit' ), $legacy_upload_128['file'] ) : 0;
	$unsupported_attachment_128 = is_array( $unsupported_upload_128 ) && empty( $unsupported_upload_128['error'] ) ? wp_insert_attachment( array( 'post_title'=>'Unsupported acquisition fixture', 'post_mime_type'=>'text/plain', 'post_status'=>'inherit' ), $unsupported_upload_128['file'] ) : 0;
	try {
		adc_check( $legacy_attachment_128 > 0 && $unsupported_attachment_128 > 0, 'Legacy migration fixtures are real isolated media attachments.' );
		$wpdb->update( Schema::table( 'vehicles' ), array( 'document_media_ids'=>wp_json_encode( array( $legacy_attachment_128, $unsupported_attachment_128 ) ) ), array( 'id'=>$acquisition_vehicle_128 ), array( '%s' ), array( '%d' ) );
		$before_migration_count_128 = count( VehicleAcquisitionDocuments::all( $acquisition_vehicle_128 ) );
		$invalid_migration_128 = VehicleAcquisitionDocuments::migrate_legacy( $acquisition_vehicle_128 );
		$unchanged_legacy_ids_128 = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT document_media_ids FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d', $acquisition_vehicle_128 ) ), true );
		adc_check( is_wp_error( $invalid_migration_128 ) && 2 === count( $unchanged_legacy_ids_128 ) && $before_migration_count_128 === count( VehicleAcquisitionDocuments::all( $acquisition_vehicle_128 ) ), 'Legacy migration rejects unsupported media atomically without clearing references or partially copying files.' );
		$wpdb->update( Schema::table( 'vehicles' ), array( 'document_media_ids'=>wp_json_encode( array( $legacy_attachment_128 ) ) ), array( 'id'=>$acquisition_vehicle_128 ), array( '%s' ), array( '%d' ) );
		wp_set_current_user( $purchasing_other_128 );
		$foreign_migration_128 = VehicleAcquisitionDocuments::migrate_legacy( $acquisition_vehicle_128 );
		wp_set_current_user( $purchasing_128 );
		adc_check( is_wp_error( $foreign_migration_128 ), 'Legacy acquisition files cannot be migrated by a cost manager from another branch.' );
		$migration_audit_fault_128 = static function( string $sql ) use ( $wpdb ): string {
			return str_contains( $sql, $wpdb->prefix . 'adc_audit_events' ) && str_contains( $sql, 'vehicle.acquisition_documents_migrated' ) ? 'INSERT INTO adc_missing_migration_audit VALUES(1)' : $sql;
		};
		$wpdb->suppress_errors( true );
		add_filter( 'query', $migration_audit_fault_128 );
		$failed_audit_migration_128 = VehicleAcquisitionDocuments::migrate_legacy( $acquisition_vehicle_128 );
		remove_filter( 'query', $migration_audit_fault_128 );
		$wpdb->suppress_errors( false );
		$after_failed_audit_ids_128 = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT document_media_ids FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d', $acquisition_vehicle_128 ) ), true );
		adc_check( is_wp_error( $failed_audit_migration_128 ) && array( $legacy_attachment_128 ) === array_map( 'intval', $after_failed_audit_ids_128 ) && $before_migration_count_128 === count( VehicleAcquisitionDocuments::all( $acquisition_vehicle_128 ) ), 'A migration audit failure rolls back private copies and preserves the legacy reference.' );
		$migrated_legacy_128 = VehicleAcquisitionDocuments::migrate_legacy( $acquisition_vehicle_128 );
		$migrated_record_128 = VehicleAcquisitionService::get( $acquisition_vehicle_128 );
		$migrated_attachment_ids_128 = $wpdb->get_col( $wpdb->prepare( 'SELECT source_attachment_id FROM ' . Schema::table( 'vehicle_acquisition_documents' ) . ' WHERE vehicle_id=%d AND source_attachment_id>0', $acquisition_vehicle_128 ) );
		$legacy_retry_128 = VehicleAcquisitionDocuments::migrate_legacy( $acquisition_vehicle_128 );
		$copied_private_128 = $wpdb->get_row( $wpdb->prepare( 'SELECT file_bytes,checksum FROM ' . Schema::table( 'vehicle_acquisition_documents' ) . ' WHERE vehicle_id=%d AND source_attachment_id=%d', $acquisition_vehicle_128, $legacy_attachment_128 ), ARRAY_A );
		adc_check( is_array( $migrated_legacy_128 ) && 1 === $migrated_legacy_128['migrated'] && is_array( $migrated_record_128 ) && 0 === $migrated_record_128['legacy_public_document_count'] && 1 === $migrated_record_128['migrated_public_original_count'] && array( $legacy_attachment_128 ) === array_map( 'intval', $migrated_attachment_ids_128 ) && is_array( $legacy_retry_128 ) && 0 === $legacy_retry_128['migrated'] && is_array( $copied_private_128 ) && $legacy_pdf_bytes_128 === $copied_private_128['file_bytes'] && hash_equals( $copied_private_128['checksum'], hash( 'sha256', $legacy_pdf_bytes_128 ) ) && 'attachment' === get_post_type( $legacy_attachment_128 ), 'Legacy migration copies and verifies bytes, clears only the vehicle reference, records provenance, retains the public original, and is idempotent.' );
	} finally {
		if ( $legacy_attachment_128 > 0 ) { wp_delete_attachment( $legacy_attachment_128, true ); }
		if ( $unsupported_attachment_128 > 0 ) { wp_delete_attachment( $unsupported_attachment_128, true ); }
		if ( is_array( $legacy_upload_128 ) && ! empty( $legacy_upload_128['file'] ) && is_file( $legacy_upload_128['file'] ) ) { unlink( $legacy_upload_128['file'] ); }
		if ( is_array( $unsupported_upload_128 ) && ! empty( $unsupported_upload_128['file'] ) && is_file( $unsupported_upload_128['file'] ) ) { unlink( $unsupported_upload_128['file'] ); }
	}
	wp_set_current_user( $purchasing_other_128 );
	adc_check( is_wp_error( VehicleAcquisitionDocuments::retrieve( $private_document_id_128 ) ) && array() === VehicleAcquisitionDocuments::all( $acquisition_vehicle_128 ), 'Private acquisition evidence is unavailable outside its vehicle branch.' );
	wp_set_current_user( $inventory );
	adc_check( is_wp_error( VehicleAcquisitionDocuments::retrieve( $private_document_id_128 ) ) && is_wp_error( VehicleAcquisitionDocuments::store( $acquisition_vehicle_128, 'unauthorized.pdf', "%PDF-1.4\nunauthorized\n%%EOF\n" ) ), 'Staff without acquisition-cost permission cannot upload or download private evidence.' );
	wp_set_current_user( $inventory );
	$inventory_vehicle_128 = VehicleService::get( $acquisition_vehicle_128, true );
	adc_check( is_array( $inventory_vehicle_128 ) && ! array_key_exists( 'purchase_cost', $inventory_vehicle_128 ) && ! array_key_exists( 'supplier_id', $inventory_vehicle_128 ) && is_wp_error( VehicleAcquisitionService::get( $acquisition_vehicle_128 ) ), 'General inventory reads never reveal restricted costs or supplier details.' );
	wp_set_current_user( $purchasing_128 );
	$purchasing_vehicle_128 = VehicleService::get( $acquisition_vehicle_128, true );
	adc_check( is_array( $purchasing_vehicle_128 ) && ! array_key_exists( 'document_media_ids', $purchasing_vehicle_128 ), 'Cost-authorized vehicle projections do not disclose legacy public attachment IDs.' );
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
	$onyx_document_previous_get = $_GET;
	$_GET = array( 'page'=>'adc-onyx-documents', 'sale_id'=>(int) $finance_sale_128['id'] );
	$onyx_document_queries = array();
	$capture_onyx_document_query = static function( string $query ) use ( &$onyx_document_queries ): string { $onyx_document_queries[] = $query; return $query; };
	add_filter( 'query', $capture_onyx_document_query );
	ob_start();
	\AutoDealership\Sales\OnyxDocuments::render();
	ob_end_clean();
	remove_filter( 'query', $capture_onyx_document_query );
	$_GET = $onyx_document_previous_get;
	$onyx_document_sale_query = implode( "\n", array_filter( $onyx_document_queries, static fn( string $query ): bool => str_contains( $query, 'INNER JOIN ' . Schema::table( 'quotations' ) ) && str_contains( $query, 'WHERE s.id=' ) ) );
	adc_check( str_contains( $onyx_document_sale_query, 'SELECT s.id,s.vehicle_id,s.customer_id,s.quotation_id,v.branch_id,q.final_amount,q.tax_amount' ) && ! str_contains( $onyx_document_sale_query, 's.*' ), 'Onyx document access loads only sale ownership, branch and invoice amount fields.' );
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
