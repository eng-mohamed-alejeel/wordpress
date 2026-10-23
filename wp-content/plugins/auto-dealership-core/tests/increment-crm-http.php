<?php
/** Real cookies, REST nonces, AJAX forms and admin-post against the isolated database. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Leads\CustomerIdentity;
use AutoDealership\Leads\RequestWorkflow;
use AutoDealership\Database\Schema;

// HTTP requests share a different REMOTE_ADDR from CLI fixtures.
$wpdb->delete( Schema::table( 'request_limits' ), array( 'policy_key'=>'intake' ), array( '%s' ) );
$crm_http_auth = $http_auth( $crm_account );
$crm_http_nonce = trim( $http_request( '/adc-test-nonce?action=car_dealer_frontend', $crm_http_auth )[1] );
$crm_http_form = array( 'action'=>'car_dealer_contact', 'nonce'=>$crm_http_nonce, 'name'=>'Forged form name', 'email'=>'forged@example.invalid', 'phone'=>'123', 'message'=>'HTTP account enquiry', 'branch_id'=>$branch_b['id'], 'idempotency_key'=>wp_generate_uuid4() );
$crm_before = $crm_counts();
list( $crm_bad_status, $crm_bad_body ) = $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', array_replace( $crm_http_form, array( 'nonce'=>'invalid' ) ), true );
adc_check( 403 === $crm_bad_status && $crm_before === $crm_counts(), 'Plugin AJAX rejects a bad form nonce without creating CRM records (HTTP ' . $crm_bad_status . ': ' . substr( wp_strip_all_tags( $crm_bad_body ), 0, 120 ) . ').' );
list( $crm_status, $crm_body ) = $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', $crm_http_form, true );
adc_check( 200 === $crm_status && true === ( json_decode( $crm_body, true )['success'] ?? false ), 'Plugin contact handler accepts an authenticated nonce-protected theme form.' );
$crm_http_row = $wpdb->get_row( "SELECT * FROM $crm_messages ORDER BY id DESC LIMIT 1", ARRAY_A );
adc_check( 'crm-new@example.invalid' === $crm_http_row['email'] && $crm_account === (int) $crm_http_row['user_id'] && '+966501234567' === $crm_http_row['phone'] && 'Forged form name' !== $crm_http_row['name'], 'Plugin AJAX replaces forged contact fields with the authenticated profile.' );
$crm_http_lead = RequestWorkflow::linked_lead( 'message', (int) $crm_http_row['id'] );
$crm_after = $crm_counts();
adc_check( 200 === $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', $crm_http_form, true )[0] && $crm_after === $crm_counts(), 'Repeated plugin AJAX intake does not duplicate the message or customer.' );
$crm_http_current_user = get_current_user_id();
wp_set_current_user( 0 );
$crm_anonymous_nonce = wp_create_nonce( 'car_dealer_frontend' );
wp_set_current_user( $crm_http_current_user );
$crm_public_lead_form = array( 'action'=>'car_dealer_lead', 'nonce'=>$crm_anonymous_nonce, 'name'=>'Anonymous finance enquiry', 'email'=>'http-finance@example.invalid', 'phone'=>'+966501234568', 'branch_id'=>$branch_b['id'], 'request_kind'=>'finance', 'message'=>'HTTP public finance enquiry', 'idempotency_key'=>wp_generate_uuid4() );
$crm_public_lead_before = $crm_counts();
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', array_replace( $crm_public_lead_form, array( 'nonce'=>'invalid' ) ), true )[0] && $crm_public_lead_before === $crm_counts(), 'Anonymous lead AJAX rejects an invalid nonce without writing CRM records.' );
$crm_public_lead_response = $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', $crm_public_lead_form, true );
$crm_public_lead_data = json_decode( $crm_public_lead_response[1], true );
$crm_public_lead_after = $crm_counts();
adc_check( 200 === $crm_public_lead_response[0] && true === ( $crm_public_lead_data['success'] ?? false ) && ! isset( $crm_public_lead_data['data']['id'] ) && ! isset( $crm_public_lead_data['data']['customer_id'] ) && ! isset( $crm_public_lead_data['data']['lead_id'] ) && array( 1,1,0,0,1,1 ) === array_map( static fn( $before, $after ) => $after - $before, $crm_public_lead_before, $crm_public_lead_after ), 'Anonymous lead AJAX creates one scoped CRM record without exposing internal identifiers.' );
adc_check( 200 === $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', $crm_public_lead_form, true )[0] && $crm_public_lead_after === $crm_counts(), 'Anonymous lead AJAX retry with the same idempotency key does not duplicate records.' );
$crm_http_current_user = get_current_user_id();
wp_set_current_user( 0 );
$public_tools_nonce = wp_create_nonce( 'adc_public_tools' );
$crm_public_subscription_nonce = wp_create_nonce( 'car_dealer_frontend' );
wp_set_current_user( $crm_http_current_user );
$crm_compare_form = array( 'action'=>'car_dealer_comparison', 'nonce'=>$public_tools_nonce, 'car_id'=>$crm_car, 'compare_action'=>'add' );
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', array_replace( $crm_compare_form, array( 'nonce'=>'invalid' ) ), true )[0], 'Anonymous comparison AJAX rejects an invalid nonce.' );
adc_check( 400 === $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', array_replace( $crm_compare_form, array( 'car_id'=>999999999 ) ), true )[0], 'Anonymous comparison AJAX rejects a vehicle outside the public catalogue.' );
$crm_compare_response = $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', $crm_compare_form, true );
$crm_compare_data = json_decode( $crm_compare_response[1], true );
adc_check( 200 === $crm_compare_response[0] && true === ( $crm_compare_data['success'] ?? false ) && array( (int) $crm_car ) === array_map( 'intval', $crm_compare_data['data']['ids'] ?? array() ) && 1 === (int) ( $crm_compare_data['data']['count'] ?? 0 ), 'Anonymous comparison AJAX adds a publicly eligible vehicle within the comparison response contract.' );
$crm_calculator_form = array( 'action'=>'car_dealer_loan_calculator', 'nonce'=>$public_tools_nonce, 'price'=>'100000', 'salary'=>'10000', 'months'=>'60', 'sector'=>'government', 'nationality'=>'saudi', 'transfer'=>'st', 'provider'=>'all', 'campaign'=>'regular', 'category'=>'A', 'chinese'=>'no', 'employer'=>'approved', 'insurance_rate'=>'3', 'obligations'=>'0', 'brand'=>'', 'assumed_down'=>'', 'assumed_balloon'=>'', 'assumed_admin'=>'' );
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', array_replace( $crm_calculator_form, array( 'nonce'=>'invalid' ) ), true )[0], 'Anonymous loan calculator AJAX rejects an invalid nonce.' );
$crm_calculator_invalid_response = $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', array_replace( $crm_calculator_form, array( 'price'=>'1e5' ) ), true );
adc_check( 400 === $crm_calculator_invalid_response[0], 'Anonymous loan calculator AJAX rejects malformed monetary input.' );
$crm_calculator_response = $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', $crm_calculator_form, true );
$crm_calculator_data = json_decode( $crm_calculator_response[1], true );
adc_check( 200 === $crm_calculator_response[0] && true === ( $crm_calculator_data['success'] ?? false ) && true === ( $crm_calculator_data['data']['estimate_only'] ?? false ) && count( $crm_calculator_data['data']['results'] ?? array() ) > 0, 'Anonymous loan calculator AJAX returns estimates through its public response contract.' );

$newsletter_table = \AutoDealership\Leads\NewsletterService::table( 'campaigns' );
$newsletter_rows_before_preview = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $newsletter_table" );
$newsletter_admin_nonce = trim( $http_request( '/adc-test-nonce?action=adc_newsletter_preview', $admin_http )[1] );
$newsletter_preview_form = array(
	'action'=>'adc_newsletter_preview', 'nonce'=>$newsletter_admin_nonce, 'preview_language'=>'en',
	'subject_en'=>'HTTP preview subject', 'body_en'=>'<p>HTTP preview content</p>',
	'subject_ar'=>'', 'body_ar'=>'', 'button_text_en'=>'', 'button_url_en'=>'',
	'button_text_ar'=>'', 'button_url_ar'=>'', 'creation_key'=>wp_generate_uuid4(),
);
$newsletter_preview_response = $http_request( '/wp-admin/admin-ajax.php', $admin_http, 'POST', $newsletter_preview_form, true );
$newsletter_preview_data = json_decode( $newsletter_preview_response[1], true );
adc_check( 200 === $newsletter_preview_response[0] && ! empty( $newsletter_preview_data['success'] ) && str_contains( (string) ( $newsletter_preview_data['data']['html'] ?? '' ), 'HTTP preview subject' ) && $newsletter_rows_before_preview === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $newsletter_table" ), 'Authorized HTTP newsletter preview renders unsaved content without creating a campaign.' );
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', $sales_b_http, 'POST', $newsletter_preview_form, true )[0] && 403 === $http_request( '/wp-admin/admin-ajax.php', $admin_http, 'POST', array_replace( $newsletter_preview_form, array( 'nonce'=>'invalid' ) ), true )[0], 'Newsletter preview rejects unauthorized staff and invalid nonces over HTTP.' );

$job_runtime_nonce = trim( $http_request( '/adc-test-nonce?action=adc_job_runtime', $admin_http )[1] );
$job_tick_form = array( 'action'=>'adc_jobs_tick', 'nonce'=>$job_runtime_nonce );
$job_probe_form = array( 'action'=>'adc_jobs_probe', 'nonce'=>$job_runtime_nonce );
$job_tick_response = $http_request( '/wp-admin/admin-ajax.php', $admin_http, 'POST', $job_tick_form, true );
$job_tick_data = json_decode( $job_tick_response[1], true );
adc_check( 200 === $job_tick_response[0] && ! empty( $job_tick_data['success'] ) && 'server_mode' === ( $job_tick_data['data']['result']['status'] ?? '' ), 'Authenticated HTTP job tick respects the isolated server-mode policy without running browser jobs.' );
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', $sales_b_http, 'POST', $job_tick_form, true )[0] && 403 === $http_request( '/wp-admin/admin-ajax.php', $admin_http, 'POST', array_replace( $job_tick_form, array( 'nonce'=>'invalid' ) ), true )[0], 'Job tick rejects non-administrators and invalid action nonces over HTTP.' );
$job_probe_response = $http_request( '/wp-admin/admin-ajax.php', $admin_http, 'POST', $job_probe_form, true );
$job_probe_data = json_decode( $job_probe_response[1], true );
adc_check( 200 === $job_probe_response[0] && ! empty( $job_probe_data['success'] ) && isset( $job_probe_data['data']['checked'], $job_probe_data['data']['site'] ) && 403 === $http_request( '/wp-admin/admin-ajax.php', $sales_b_http, 'POST', $job_probe_form, true )[0], 'Job transport probe requires an administrator and returns bounded diagnostics over HTTP.' );

$crm_subscribers = $wpdb->prefix . 'car_dealer_subscribers';
$crm_subscription_email = 'http-subscription@example.invalid';
$crm_subscription_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $crm_subscribers WHERE email=%s", $crm_subscription_email ) );
$crm_subscription_form = array( 'action'=>'car_dealer_subscribe', 'nonce'=>$crm_public_subscription_nonce, 'lang'=>'en', 'email'=>$crm_subscription_email, 'consent_marketing'=>'1' );
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', array_replace( $crm_subscription_form, array( 'nonce'=>'invalid' ) ), true )[0], 'Anonymous newsletter AJAX rejects an invalid nonce.' );
$crm_subscription_invalid = $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', array_replace( $crm_subscription_form, array( 'consent_marketing'=>'0' ) ), true );
adc_check( 400 === $crm_subscription_invalid[0] && $crm_subscription_before === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $crm_subscribers WHERE email=%s", $crm_subscription_email ) ), 'Newsletter AJAX refuses missing explicit consent without creating a subscriber.' );
$crm_subscription_response = $http_request( '/wp-admin/admin-ajax.php', array(), 'POST', $crm_subscription_form, true );
$crm_subscription_data = json_decode( $crm_subscription_response[1], true );
$crm_subscription_row = $wpdb->get_row( $wpdb->prepare( "SELECT status,locale,consent_key FROM $crm_subscribers WHERE email=%s", $crm_subscription_email ), ARRAY_A );
adc_check( 200 === $crm_subscription_response[0] && true === ( $crm_subscription_data['success'] ?? false ) && $crm_subscription_row && 'pending' === $crm_subscription_row['status'] && 'en' === $crm_subscription_row['locale'] && wp_is_uuid( $crm_subscription_row['consent_key'] ), 'Newsletter AJAX records an explicit, pending opt-in with the requested locale.' );
$crm_lookup_vehicle = \AutoDealership\Inventory\VehicleService::create( array( 'vin'=>'1HGCM82633A123456', 'stock_number'=>'HTTP-LOOKUP-1', 'brand'=>'Honda', 'model'=>'Accord', 'model_year'=>2025, 'condition'=>'new', 'branch_id'=>(int) $branch_b['id'], 'retail_price'=>8500000 ) );
if ( is_wp_error( $crm_lookup_vehicle ) ) { throw new RuntimeException( 'Could not create isolated HTTP VIN lookup vehicle: ' . $crm_lookup_vehicle->get_error_code() ); }
$crm_lookup_stock = \AutoDealership\Database\Schema::table( 'onyx_stock' );
$crm_lookup_stock_values = array( 'model_id'=>0, 'brand'=>'Honda', 'model'=>'Accord', 'trim_name'=>'EX', 'model_year'=>2025, 'listed_price'=>8500000, 'warehouse_name'=>'HTTP lookup warehouse', 'notes'=>'Isolated HTTP lookup fixture', 'last_import_id'=>0, 'created_at'=>current_time( 'mysql', true ), 'updated_at'=>current_time( 'mysql', true ) );
if ( 1 !== $wpdb->insert( $crm_lookup_stock, array_merge( $crm_lookup_stock_values, array( 'source_identifier'=>'A123456', 'vehicle_id'=>(int) $crm_lookup_vehicle['id'] ) ) ) ) { throw new RuntimeException( 'Could not create linked Onyx source for isolated HTTP VIN lookup.' ); }
$crm_lookup_linked_source_id = (int) $wpdb->insert_id;
$crm_lookup_warehouse_map = get_option( 'adc_onyx_warehouse_branches', array() );
update_option( 'adc_onyx_warehouse_branches', array_merge( is_array( $crm_lookup_warehouse_map ) ? $crm_lookup_warehouse_map : array(), array( 'HTTP lookup warehouse'=>(int) $branch_b['id'] ) ), false );
if ( 1 !== $wpdb->insert( $crm_lookup_stock, array_merge( $crm_lookup_stock_values, array( 'source_identifier'=>'B654321' ) ) ) ) { throw new RuntimeException( 'Could not create unlinked Onyx source for isolated HTTP VIN lookup.' ); }
$crm_lookup_unlinked_source_id = (int) $wpdb->insert_id;
$crm_lookup_nonce = trim( $http_request( '/adc-test-nonce?action=adc_lookup_vin', $inventory_b_http )[1] );
$crm_lookup_form = array( 'action'=>'adc_lookup_vin', 'nonce'=>$crm_lookup_nonce, 'identifier'=>'1HGCM82633A123456' );
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', $inventory_b_http, 'POST', array_replace( $crm_lookup_form, array( 'nonce'=>'invalid' ) ), true )[0], 'VIN lookup AJAX rejects an invalid nonce.' );
$crm_lookup_branch_response = $http_request( '/wp-admin/admin-ajax.php', $inventory_b_http, 'POST', $crm_lookup_form, true );
$crm_lookup_branch_data = json_decode( $crm_lookup_branch_response[1], true );
adc_check( 200 === $crm_lookup_branch_response[0] && true === ( $crm_lookup_branch_data['success'] ?? false ) && true === ( $crm_lookup_branch_data['data']['exists'] ?? false ) && true === ( $crm_lookup_branch_data['data']['operational_exists'] ?? false ) && (int) ( $crm_lookup_branch_data['data']['vehicle']['id'] ?? 0 ) === (int) $crm_lookup_vehicle['id'] && 'A123456' === ( $crm_lookup_branch_data['data']['source']['source_identifier'] ?? '' ), 'Assigned-branch inventory AJAX returns the matching vehicle and linked Onyx identity.' );
$crm_lookup_foreign_nonce = trim( $http_request( '/adc-test-nonce?action=adc_lookup_vin', $inventory_c_http )[1] );
$crm_lookup_foreign_response = $http_request( '/wp-admin/admin-ajax.php', $inventory_c_http, 'POST', array( 'action'=>'adc_lookup_vin', 'nonce'=>$crm_lookup_foreign_nonce, 'identifier'=>'1HGCM82633A123456' ), true );
$crm_lookup_foreign_data = json_decode( $crm_lookup_foreign_response[1], true );
adc_check( 200 === $crm_lookup_foreign_response[0] && true === ( $crm_lookup_foreign_data['success'] ?? false ) && false === ( $crm_lookup_foreign_data['data']['exists'] ?? true ) && false === ( $crm_lookup_foreign_data['data']['operational_exists'] ?? true ) && false === ( $crm_lookup_foreign_data['data']['ambiguous'] ?? true ) && array_key_exists( 'vehicle', $crm_lookup_foreign_data['data'] ?? array() ) && null === $crm_lookup_foreign_data['data']['vehicle'] && array_key_exists( 'source', $crm_lookup_foreign_data['data'] ?? array() ) && null === $crm_lookup_foreign_data['data']['source'], 'Foreign-branch inventory AJAX cannot infer an out-of-scope vehicle or linked source.' );
$crm_lookup_unlinked_nonce = trim( $http_request( '/adc-test-nonce?action=adc_lookup_vin', $inventory_b_http )[1] );
$crm_lookup_unlinked_response = $http_request( '/wp-admin/admin-ajax.php', $inventory_b_http, 'POST', array( 'action'=>'adc_lookup_vin', 'nonce'=>$crm_lookup_unlinked_nonce, 'identifier'=>'B654321' ), true );
$crm_lookup_unlinked_data = json_decode( $crm_lookup_unlinked_response[1], true );
adc_check( 200 === $crm_lookup_unlinked_response[0] && true === ( $crm_lookup_unlinked_data['data']['exists'] ?? false ) && false === ( $crm_lookup_unlinked_data['data']['operational_exists'] ?? true ) && 'B654321' === ( $crm_lookup_unlinked_data['data']['source']['source_identifier'] ?? '' ), 'Assigned-branch inventory AJAX returns an Onyx source mapped by warehouse.' );
$crm_lookup_unlinked_foreign_nonce = trim( $http_request( '/adc-test-nonce?action=adc_lookup_vin', $inventory_c_http )[1] );
$crm_lookup_unlinked_foreign_response = $http_request( '/wp-admin/admin-ajax.php', $inventory_c_http, 'POST', array( 'action'=>'adc_lookup_vin', 'nonce'=>$crm_lookup_unlinked_foreign_nonce, 'identifier'=>'B654321' ), true );
$crm_lookup_unlinked_foreign_data = json_decode( $crm_lookup_unlinked_foreign_response[1], true );
adc_check( 200 === $crm_lookup_unlinked_foreign_response[0] && true === ( $crm_lookup_unlinked_foreign_data['success'] ?? false ) && false === ( $crm_lookup_unlinked_foreign_data['data']['exists'] ?? true ) && array_key_exists( 'source', $crm_lookup_unlinked_foreign_data['data'] ?? array() ) && null === $crm_lookup_unlinked_foreign_data['data']['source'], 'Foreign-branch inventory AJAX cannot infer an unlinked warehouse-mapped Onyx source.' );
$crm_lookup_admin_nonce = trim( $http_request( '/adc-test-nonce?action=adc_lookup_vin', $admin_http )[1] );
$crm_lookup_admin_response = $http_request( '/wp-admin/admin-ajax.php', $admin_http, 'POST', array( 'action'=>'adc_lookup_vin', 'nonce'=>$crm_lookup_admin_nonce, 'identifier'=>'1HGCM82633A123456' ), true );
$crm_lookup_admin_data = json_decode( $crm_lookup_admin_response[1], true );
adc_check( 200 === $crm_lookup_admin_response[0] && true === ( $crm_lookup_admin_data['data']['exists'] ?? false ) && (int) ( $crm_lookup_admin_data['data']['vehicle']['id'] ?? 0 ) === (int) $crm_lookup_vehicle['id'], 'Global administrator retains HTTP access to the matching VIN lookup.' );
update_option( 'adc_onyx_warehouse_branches', $crm_lookup_warehouse_map, false );
if ( 1 !== $wpdb->delete( $crm_lookup_stock, array( 'id'=>$crm_lookup_linked_source_id ), array( '%d' ) ) || 1 !== $wpdb->delete( $crm_lookup_stock, array( 'id'=>$crm_lookup_unlinked_source_id ), array( '%d' ) ) || 1 !== $wpdb->delete( \AutoDealership\Database\Schema::table( 'vehicles' ), array( 'id'=>(int) $crm_lookup_vehicle['id'] ), array( '%d' ) ) ) { throw new RuntimeException( 'Could not clean isolated HTTP VIN lookup inventory fixtures.' ); }
$wpdb->delete( \AutoDealership\Audit\AuditLog::table_name(), array( 'event_key'=>'vehicle.created', 'subject_type'=>'vehicle', 'subject_id'=>(int) $crm_lookup_vehicle['id'] ), array( '%s', '%s', '%d' ) );
$crm_request_path = $rest_path( '/auto-dealership/v1/leads/' . $crm_http_lead . '/request' );
$crm_http_lead_scope = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_user_id,branch_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', (int) $crm_http_lead ), ARRAY_A );
$crm_http_request_assigned = (int) ( $crm_http_lead_scope['owner_user_id'] ?? 0 ) === (int) $sales_b;
if ( (int) ( $crm_http_lead_scope['branch_id'] ?? 0 ) === (int) $branch_b['id'] && (int) ( $crm_http_lead_scope['owner_user_id'] ?? 0 ) !== (int) $sales_b ) {
	$crm_http_previous_user = get_current_user_id();
	wp_set_current_user( $manager_b );
	$crm_http_request_assigned = ! is_wp_error( \AutoDealership\Leads\LeadService::assign( (int) $crm_http_lead, (int) $sales_b, 'HTTP request read-scope fixture' ) );
	wp_set_current_user( $crm_http_previous_user );
	$crm_http_lead_scope = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_user_id,branch_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', (int) $crm_http_lead ), ARRAY_A );
}
adc_check( $crm_http_request_assigned && (int) ( $crm_http_lead_scope['branch_id'] ?? 0 ) === (int) $branch_b['id'] && (int) ( $crm_http_lead_scope['owner_user_id'] ?? 0 ) === (int) $sales_b, 'HTTP request read-scope fixture is assigned to its branch-B lead owner.' );
$crm_request_owner_v1 = $http_request( $crm_request_path, $sales_b_http );
$crm_request_owner_v2 = $http_request( $rest_v2_path( $crm_request_path ), $sales_b_http );
adc_check(
	200 === $crm_request_owner_v1[0] && isset( json_decode( $crm_request_owner_v1[1], true )['revision'] )
	&& 200 === $crm_request_owner_v2[0] && isset( json_decode( $crm_request_owner_v2[1], true )['data']['revision'] ),
	'Assigned lead owner can read the linked request in both API versions.'
);
adc_check( 401 === $http_request( $crm_request_path )[0] && 403 === $http_request( $crm_request_path, $crm_http_auth )[0] && 404 === $http_request( $crm_request_path, $sales_a_http )[0] && 404 === $http_request( $rest_v2_path( $crm_request_path ), $sales_a_http )[0], 'HTTP request details reject anonymous, customer and foreign-branch staff access in both API versions.' );
$crm_http_lead_owner = (int) ( $crm_http_lead_scope['owner_user_id'] ?? 0 );
$crm_http_nonowner = $crm_http_lead_owner === (int) $sales_b ? $crm_second_sales : $sales_b;
$crm_http_nonowner_auth = $http_auth( $crm_http_nonowner );
$crm_http_nonowner_branch = (int) get_user_meta( $crm_http_nonowner, 'adc_branch_id', true );
adc_check( $crm_http_nonowner_branch === (int) ( $crm_http_lead_scope['branch_id'] ?? 0 ) && 404 === $http_request( $crm_request_path, $crm_http_nonowner_auth )[0], 'Same-branch sales staff cannot read another employee request without lead ownership.' );
$crm_request_v2_path = $rest_v2_path( $crm_request_path );
adc_check( 404 === $http_request( $crm_request_v2_path, $crm_http_nonowner_auth )[0], 'Version 2 request details preserve same-branch non-owner denial.' );
list( $crm_status, $crm_body ) = $http_request( $crm_request_path, $admin_http );
$crm_http_state = json_decode( $crm_body, true );
adc_check( 200 === $crm_status && isset( $crm_http_state['revision'] ), 'HTTP administrator reads the linked request revision.' );
$crm_request_v2_admin = $http_request( $rest_v2_path( $crm_request_path ), $admin_http );
$crm_request_v2_state = json_decode( $crm_request_v2_admin[1], true );
adc_check( 200 === $crm_request_v2_admin[0] && isset( $crm_request_v2_state['data']['revision'] ), 'Version 2 HTTP request read returns the current authorized revision.' );
$crm_http_patch = array( 'revision'=>$crm_http_state['revision'], 'status'=>'read', 'customer_reply'=>'HTTP visible reply' );
adc_check( 403 === $http_request( $crm_request_path, array_replace( $admin_http, array( 'nonce'=>'bad' ) ), 'PATCH', $crm_http_patch )[0], 'HTTP request PATCH requires a valid REST nonce.' );
adc_check( 200 === $http_request( $crm_request_path, $admin_http, 'PATCH', $crm_http_patch )[0] && 409 === $http_request( $crm_request_path, $admin_http, 'PATCH', $crm_http_patch )[0], 'HTTP request PATCH persists once and rejects the stale revision.' );
$crm_activity_path = $rest_path( '/auto-dealership/v1/leads/' . $crm_http_lead . '/activities' );
$crm_activity_v2_path = $rest_v2_path( $crm_activity_path );
adc_check(
	200 === $http_request( $crm_activity_path, $admin_http )[0]
	&& str_contains( $http_request( $crm_activity_path, $admin_http )[1], 'HTTP visible reply' )
	&& 200 === $http_request( $crm_activity_path, $sales_b_http )[0]
	&& 200 === $http_request( $crm_activity_v2_path, $sales_b_http )[0]
	&& 404 === $http_request( $crm_activity_path, $crm_http_nonowner_auth )[0]
	&& 404 === $http_request( $crm_activity_v2_path, $crm_http_nonowner_auth )[0]
	&& 200 === $http_request( $crm_activity_v2_path, $admin_http )[0],
	'HTTP activity history in both API versions is visible to the lead owner and administrator, but not a same-branch non-owner.'
);
$crm_assignment_auth = $http_auth( $crm_second_sales );
$crm_marketer_auth = $http_auth( $crm_marketer );
$crm_board_nonce = trim( $http_request( '/adc-test-nonce?action=adc_crm_board', $crm_marketer_auth )[1] );
$crm_marketer_stage_before = $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $crm_leads WHERE id=%d", (int) $crm_marketer_result['id'] ) );
$crm_marketer_stage_response = $http_request( '/wp-admin/admin-ajax.php', $crm_marketer_auth, 'POST', array( 'action'=>'adc_move_lead_stage', 'nonce'=>$crm_board_nonce, 'lead_id'=>(int) $crm_marketer_result['id'], 'stage'=>'lost', 'reason'=>'Unauthorized CRM board update' ), true );
adc_check( 403 === $crm_marketer_stage_response[0] && $crm_marketer_stage_before === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $crm_leads WHERE id=%d", (int) $crm_marketer_result['id'] ) ), 'CRM board AJAX denies marketing-only stage changes without mutating the creator’s lead (HTTP ' . $crm_marketer_stage_response[0] . ': ' . substr( wp_strip_all_tags( $crm_marketer_stage_response[1] ), 0, 160 ) . ').' );
$crm_marketer_leads_response = $http_request( $rest_path( '/auto-dealership/v1/leads' ) . '&per_page=100', $crm_marketer_auth );
$crm_marketer_leads = json_decode( $crm_marketer_leads_response[1], true );
$crm_marketer_lead_ids = is_array( $crm_marketer_leads ) ? array_map( 'intval', array_column( $crm_marketer_leads, 'id' ) ) : array();
$crm_marketer_leads_v2_response = $http_request( $rest_v2_path( $rest_path( '/auto-dealership/v1/leads' ) ) . '&per_page=100', $crm_marketer_auth );
$crm_marketer_leads_v2_envelope = json_decode( $crm_marketer_leads_v2_response[1], true );
$crm_marketer_leads_v2 = (array) ( $crm_marketer_leads_v2_envelope['data'] ?? array() );
$crm_marketer_lead_v2_ids = array_map( 'intval', array_column( $crm_marketer_leads_v2, 'id' ) );
adc_check( 200 === $crm_marketer_leads_response[0] && in_array( (int) $crm_marketer_result['id'], $crm_marketer_lead_ids, true ) && ! in_array( (int) $crm_marketing_result['id'], $crm_marketer_lead_ids, true ) && 200 === $crm_marketer_leads_v2_response[0] && in_array( (int) $crm_marketer_result['id'], $crm_marketer_lead_v2_ids, true ) && ! in_array( (int) $crm_marketing_result['id'], $crm_marketer_lead_v2_ids, true ), 'Marketing REST listing in v1 and v2 exposes only the current marketer’s lead (HTTP ' . $crm_marketer_leads_response[0] . '/' . $crm_marketer_leads_v2_response[0] . ').' );
$crm_marketer_activity_path = $rest_path( '/auto-dealership/v1/leads/' . (int) $crm_marketer_result['id'] . '/activities' );
$crm_marketer_activity_v2_path = $rest_v2_path( $crm_marketer_activity_path );
$crm_other_marketing_activity_path = $rest_path( '/auto-dealership/v1/leads/' . (int) $crm_marketing_result['id'] . '/activities' );
adc_check(
	200 === $http_request( $crm_marketer_activity_path, $crm_marketer_auth )[0]
	&& 200 === $http_request( $crm_marketer_activity_v2_path, $crm_marketer_auth )[0]
	&& 404 === $http_request( $crm_other_marketing_activity_path, $crm_marketer_auth )[0]
	&& 404 === $http_request( $rest_v2_path( $crm_other_marketing_activity_path ), $crm_marketer_auth )[0],
	'Marketing REST activity history in v1 and v2 follows the same creator-only lead scope.'
);
$crm_marketer_activity_response = $http_request( $crm_marketer_activity_path, $crm_marketer_auth, 'POST', array( 'type'=>'note', 'notes'=>'HTTP marketing activity' ) );
adc_check( 200 === $crm_marketer_activity_response[0] && 'HTTP marketing activity' === $wpdb->get_var( $wpdb->prepare( "SELECT notes FROM $crm_activities WHERE lead_id=%d AND notes=%s", (int) $crm_marketer_result['id'], 'HTTP marketing activity' ) ), 'Marketing REST activity creation accepts the dedicated capability for the creator’s own lead.' );
adc_check( 404 === $http_request( $crm_other_marketing_activity_path, $crm_marketer_auth, 'POST', array( 'type'=>'note', 'notes'=>'Must remain hidden' ) )[0], 'Marketing REST activity creation cannot target another creator’s lead.' );
$crm_marketing_assignment_path = $rest_path( '/auto-dealership/v1/leads/' . (int) $crm_marketing_result['id'] . '/assignment' );
$crm_marketing_assignment_response = $http_request( $crm_marketing_assignment_path, $crm_assignment_auth, 'POST', array( 'staff_id'=>$crm_second_sales, 'reason'=>'Scoped REST assignment' ) );
adc_check( 200 === $crm_marketing_assignment_response[0] && $crm_second_sales === (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $crm_leads WHERE id=%d", (int) $crm_marketing_result['id'] ) ), 'REST permits same-branch marketing assignment with the dedicated capability alone.' );
$crm_nonmarketing_assignment_path = $rest_path( '/auto-dealership/v1/leads/' . (int) $crm_assignment_lead['id'] . '/assignment' );
$crm_nonmarketing_assignment_response = $http_request( $crm_nonmarketing_assignment_path, $crm_assignment_auth, 'POST', array( 'staff_id'=>$sales_b, 'reason'=>'Must remain denied' ) );
adc_check( 403 === $crm_nonmarketing_assignment_response[0] && 'new' === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $crm_leads WHERE id=%d", (int) $crm_assignment_lead['id'] ) ), 'REST cannot use the dedicated capability to assign a non-marketing lead.' );
$crm_staff_nonce = trim( $http_request( '/adc-test-nonce?action=adc_update_request_' . $crm_http_lead, $admin_http )[1] );
$crm_admin_form = array( 'action'=>'adc_update_request', 'lead_id'=>$crm_http_lead, 'revision'=>RequestWorkflow::read( $crm_http_lead )['revision'], 'status'=>'completed', '_wpnonce'=>'invalid' );
adc_check( 403 === $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', $crm_admin_form, true )[0], 'Admin request form rejects an invalid action nonce.' );
$crm_admin_form['_wpnonce'] = $crm_staff_nonce;
adc_check( 302 === $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', $crm_admin_form, true )[0] && 'completed' === RequestWorkflow::read( $crm_http_lead )['status'], 'Admin request form saves and redirects with the correct action nonce.' );

$crm_booking_form = array_replace( $crm_http_form, array( 'action'=>'car_dealer_booking', 'car_id'=>$crm_car, 'date'=>wp_date( 'Y-m-d', time() + 4 * DAY_IN_SECONDS ), 'time'=>'11:30', 'idempotency_key'=>wp_generate_uuid4() ) );
list( $crm_status, $crm_body ) = $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', $crm_booking_form, true );
$crm_http_booking = json_decode( $crm_body, true );
adc_check( 200 === $crm_status && ! empty( $crm_http_booking['data']['booking_id'] ), 'Plugin booking handler returns the compatibility booking ID to the theme form.' );
$crm_cancel_path = $rest_path( '/auto-dealership/v1/bookings/' . $crm_http_booking['data']['booking_id'] . '/cancel' );
adc_check( 401 === $http_request( $crm_cancel_path, array(), 'POST', array() )[0] && 404 === $http_request( $crm_cancel_path, $sales_b_http, 'POST', array() )[0], 'HTTP booking cancellation rejects anonymous and nonowner actors.' );
adc_check( 200 === $http_request( $crm_cancel_path, $crm_http_auth, 'POST', array() )[0] && false === ( json_decode( $http_request( $crm_cancel_path, $crm_http_auth, 'POST', array() )[1], true )['updated'] ?? null ), 'HTTP owner cancellation succeeds once and is safely repeatable.' );

wp_set_current_user( $admin );
$crm_http_pair = $crm_pair();
$crm_duplicate_path = $rest_path( '/auto-dealership/v1/customers/' . $crm_http_pair[0]['customer'] . '/duplicates' );
$crm_duplicate_admin_response = $http_request( $crm_duplicate_path, $admin_http );
$crm_duplicate_admin_v2_response = $http_request( $rest_v2_path( $crm_duplicate_path ), $admin_http );
$crm_duplicate_staff_response = $http_request( $rest_v2_path( $crm_duplicate_path ), $sales_b_http );
adc_check( 200 === $crm_duplicate_admin_response[0] && 200 === $crm_duplicate_admin_v2_response[0] && 403 === $crm_duplicate_staff_response[0] && 401 === $http_request( $crm_duplicate_path )[0], 'Customer duplicate REST reads remain administrator-only in v1 and v2 and reject anonymous callers.' );
$crm_merge_path = $rest_path( '/auto-dealership/v1/customers/merge' );
$crm_merge_query = '&source_id=' . $crm_http_pair[0]['customer'] . '&target_id=' . $crm_http_pair[1]['customer'];
adc_check( 401 === $http_request( $crm_merge_path . $crm_merge_query )[0] && 403 === $http_request( $crm_merge_path . $crm_merge_query, $sales_b_http )[0] && 403 === $http_request( $rest_v2_path( $crm_merge_path ) . $crm_merge_query, $sales_b_http )[0], 'HTTP identity preview is restricted to administrators in both API versions.' );
list( $crm_status, $crm_body ) = $http_request( $crm_merge_path . $crm_merge_query, $admin_http );
$crm_http_preview = json_decode( $crm_body, true );
list( $crm_v2_status, $crm_v2_body ) = $http_request( $rest_v2_path( $crm_merge_path ) . $crm_merge_query, $admin_http );
$crm_http_preview_v2 = json_decode( $crm_v2_body, true );
adc_check( 200 === $crm_status && isset( $crm_http_preview['revision'] ) && 200 === $crm_v2_status && isset( $crm_http_preview_v2['data']['revision'] ), 'HTTP administrator receives a reviewable identity consolidation preview in v1 and v2.' );
$crm_merge_payload = array( 'source_id'=>$crm_http_pair[0]['customer'], 'target_id'=>$crm_http_pair[1]['customer'], 'revision'=>$crm_http_preview['revision'], 'evidence'=>'HTTP-IDENTITY-REVIEW', 'verified'=>false );
adc_check( 400 === $http_request( $crm_merge_path, $admin_http, 'POST', $crm_merge_payload )[0], 'HTTP consolidation requires explicit confirmation of independent identity review.' );
$crm_merge_payload['verified'] = true;
adc_check( 403 === $http_request( $crm_merge_path, array_replace( $admin_http, array( 'nonce'=>'bad' ) ), 'POST', $crm_merge_payload )[0] && 403 === $http_request( $crm_merge_path, $sales_b_http, 'POST', $crm_merge_payload )[0], 'HTTP consolidation rejects invalid nonce and staff privilege escalation.' );
adc_check( 200 === $http_request( $crm_merge_path, $admin_http, 'POST', $crm_merge_payload )[0] && 409 === $http_request( $crm_merge_path, $admin_http, 'POST', $crm_merge_payload )[0], 'Reviewed HTTP consolidation commits once; a repeated merge conflicts.' );

wp_set_current_user( $admin );
$crm_adminpost_pair = $crm_pair();
$crm_adminpost_preview = CustomerIdentity::preview( $crm_adminpost_pair[0]['customer'], $crm_adminpost_pair[1]['customer'] );
if ( is_wp_error( $crm_adminpost_preview ) ) { throw new RuntimeException( 'Admin-post identity merge preview fixture failed.' ); }
$crm_adminpost_form = array(
	'action'=>'adc_merge_customers',
	'source_id'=>$crm_adminpost_pair[0]['customer'],
	'target_id'=>$crm_adminpost_pair[1]['customer'],
	'revision'=>$crm_adminpost_preview['revision'],
	'evidence'=>'HTTP-ADMINPOST-IDENTITY-REVIEW',
	'verified'=>'1',
);
list( $crm_adminpost_nonce_status, $crm_adminpost_nonce ) = $http_request( '/adc-test-nonce?action=adc_merge_customers_' . $crm_adminpost_pair[0]['customer'] . '_' . $crm_adminpost_pair[1]['customer'], $admin_http );
$crm_adminpost_form['_wpnonce'] = trim( $crm_adminpost_nonce );
list( $crm_staff_nonce_status, $crm_staff_nonce ) = $http_request( '/adc-test-nonce?action=adc_merge_customers_' . $crm_adminpost_pair[0]['customer'] . '_' . $crm_adminpost_pair[1]['customer'], $sales_b_http );
$crm_adminpost_forbidden = $http_request( '/wp-admin/admin-post.php', array( 'cookie'=>$sales_b_http['cookie'], 'nonce'=>$sales_b_http['nonce'] ), 'POST', array_merge( $crm_adminpost_form, array( '_wpnonce'=>trim( $crm_staff_nonce ) ) ), true );
adc_check( 200 === $crm_adminpost_nonce_status && 200 === $crm_staff_nonce_status && 403 === $crm_adminpost_forbidden[0] && null === $wpdb->get_var( $wpdb->prepare( 'SELECT merged_into_id FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d', $crm_adminpost_pair[0]['customer'] ) ), 'Customer merge admin-post enforces service-level administrator access with a valid staff nonce.' );
adc_check( 403 === $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', array_merge( $crm_adminpost_form, array( '_wpnonce'=>'invalid' ) ), true )[0] && null === $wpdb->get_var( $wpdb->prepare( 'SELECT merged_into_id FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d', $crm_adminpost_pair[0]['customer'] ) ), 'Customer merge admin-post rejects an invalid action nonce without mutation.' );
$crm_adminpost_form['verified'] = '0';
adc_check( 400 === $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', $crm_adminpost_form, true )[0] && null === $wpdb->get_var( $wpdb->prepare( 'SELECT merged_into_id FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d', $crm_adminpost_pair[0]['customer'] ) ), 'Customer merge admin-post refuses to proceed without explicit identity review.' );
$crm_adminpost_form['verified'] = '1';
$crm_adminpost_result = $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', $crm_adminpost_form, true );
adc_check(
	302 === $crm_adminpost_result[0]
	&& (int) $crm_adminpost_pair[1]['customer'] === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT merged_into_id FROM ' . Schema::table( 'customers' ) . ' WHERE id=%d', $crm_adminpost_pair[0]['customer'] ) )
	&& (int) $crm_adminpost_pair[1]['customer'] === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM ' . Schema::table( 'leads' ) . ' WHERE id=%d', $crm_adminpost_pair[0]['lead'] ) ),
	'Reviewed customer merge admin-post moves the lead, retires the source identity and redirects.'
);
adc_check( 409 === $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', $crm_adminpost_form, true )[0], 'Customer merge admin-post rejects replay after the source identity has been retired.' );

// Render actual admin forms and verify output escaping, without claiming a full-theme visual test.
$crm_render_pair = $crm_pair();
$_GET = array( 'source_id'=>$crm_render_pair[0]['customer'], 'target_id'=>$crm_render_pair[1]['customer'] );
$wpdb->update( Schema::table( 'customers' ), array( 'full_name'=>'<img src=x onerror=alert(1)>' ), array( 'id'=>$crm_render_pair[0]['customer'] ) );
ob_start(); AutoDealership\Admin\CustomerIdentityPage::render(); $crm_admin_html = ob_get_clean();
adc_check( str_contains( $crm_admin_html, 'name="revision"' ) && str_contains( $crm_admin_html, 'name="verified"' ) && str_contains( $crm_admin_html, '&lt;img' ) && ! str_contains( $crm_admin_html, '<img src=x' ), 'Identity admin preview escapes customer content and includes revision, nonce and review controls.' );
$_GET = array();
ob_start(); AutoDealership\Admin\RequestPage::render( $crm_http_lead ); $crm_admin_html = ob_get_clean();
adc_check( str_contains( $crm_admin_html, 'name="revision"' ) && str_contains( $crm_admin_html, 'name="_wpnonce"' ) && str_contains( $crm_admin_html, 'HTTP visible reply' ), 'Request admin form renders the current reply and protected revision controls.' );
