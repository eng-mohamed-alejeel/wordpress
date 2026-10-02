<?php
/** Synthetic CRM 1.15–1.17 fixtures; never bootstrap the source configuration. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Database\Schema;
use AutoDealership\Leads\ContactIdentity;
use AutoDealership\Leads\PublicIntake;
use AutoDealership\Leads\LeadService;
use AutoDealership\Leads\LegacyEngagementStore;
use AutoDealership\Leads\RequestWorkflow;
use AutoDealership\Leads\CustomerIdentity;
use AutoDealership\Privacy\PrivacyTools;
use AutoDealership\Privacy\RetentionService;
use AutoDealership\Security\CustomerScope;

// Upgrade a populated pre-intake schema without changing existing business records.
$crm_existing_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $customers" );
$crm_existing_leads = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'leads' ) );
$wpdb->query( "ALTER TABLE $customers DROP COLUMN account_user_id, DROP COLUMN merged_into_id" );
$wpdb->query( 'ALTER TABLE ' . Schema::table( 'leads' ) . ' DROP COLUMN public_request_key, DROP COLUMN public_payload_hash' );
update_option( 'adc_db_version', '1.10.0' );
Schema::install();
adc_check( Schema::is_ready() && array() === Schema::verify() && $crm_existing_customers === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $customers" ) && $crm_existing_leads === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'leads' ) ), 'CRM additive upgrade restores identity and idempotency columns/indexes without losing existing customers or leads.' );

LegacyEngagementStore::install();
$crm_messages = $wpdb->prefix . 'car_dealer_messages';
$crm_bookings = $wpdb->prefix . 'car_dealer_bookings';
$crm_leads = Schema::table( 'leads' );
$crm_activities = Schema::table( 'activities' );
$crm_customer_id = static fn( $lead ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM $crm_leads WHERE id=%d", $lead['id'] ) );
$crm_counts = static function () use ( $wpdb, $customers, $crm_leads, $crm_messages, $crm_bookings, $crm_activities, $audit ): array {
	return array_map( static fn( $table ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), array( $customers, $crm_leads, $crm_messages, $crm_bookings, $crm_activities, $audit ) );
};
$crm_clear_rate = static function (): void {
	global $wpdb;
	$wpdb->delete( Schema::table( 'request_limits' ), array( 'policy_key'=>'intake' ), array( '%s' ) );
};
$crm_submit = static function ( array $input, string $type = 'message' ) use ( $crm_clear_rate ) {
	$crm_clear_rate();
	return PublicIntake::submit( $input, $type );
};
$crm_input = array( 'name'=>'Synthetic CRM', 'mobile'=>'٠٥٠١٢٣٤٥٦٧', 'email'=>'crm@example.invalid', 'branch_id'=>$branch_b['id'], 'message'=>'Synthetic enquiry', 'idempotency_key'=>wp_generate_uuid4() );
wp_set_current_user( 0 );
$crm_before = $crm_counts();
foreach ( array( array( 'name'=>array() ), array( 'mobile'=>'phone' ), array( 'email'=>'invalid@' ), array( 'consent_marketing'=>'yes' ), array( 'website'=>'bot' ), array( 'idempotency_key'=>'bad-key' ), array( 'message'=>str_repeat( 'x', 4001 ) ), array( 'branch_id'=>-1 ) ) as $bad ) {
	adc_check( is_wp_error( $crm_submit( array_replace( $crm_input, $bad ) ) ), 'CRM public intake rejects malformed input.' );
}
adc_check( $crm_before === $crm_counts(), 'Invalid intake leaves all six CRM/audit tables unchanged.' );
foreach ( array( '٠٥٠١٢٣٤٥٦٧', '۰۵۰۱۲۳۴۵۶۷', '00966501234567', '966501234567', '+966 (50) 123-4567' ) as $mobile ) {
	$normalized = ContactIdentity::normalize( array_replace( $crm_input, array( 'mobile'=>$mobile ) ) );
	adc_check( is_array( $normalized ) && '+966501234567' === $normalized['mobile'], 'Arabic, Persian and international mobile input normalizes consistently.' );
}
$crm_message = $crm_submit( $crm_input );
adc_check( is_array( $crm_message ) && isset( $crm_message['legacy_request_id'] ), 'Theme-compatible intake atomically creates a linked message.' );
$crm_after = $crm_counts();
adc_check( array( 1,1,1,0,1,1 ) === array_map( static fn( $a, $b ) => $a - $b, $crm_after, $crm_before ), 'One message creates exactly one customer, lead, activity and audit.' );
adc_check( $crm_message === $crm_submit( $crm_input ) && $crm_after === $crm_counts(), 'Identical public retry returns original IDs without duplicate rows.' );
adc_check( $error_is( $crm_submit( array_replace( $crm_input, array( 'message'=>'Changed content' ) ) ), 'adc_intake_key_conflict' ) && $crm_after === $crm_counts(), 'Reusing the key with changed content returns conflict without writes.' );
foreach ( array( $customers, $crm_leads, $crm_messages, $crm_activities, $audit ) as $table ) {
	$failed = $with_sql_failure( static fn( $q ) => str_starts_with( $q, "INSERT INTO `$table`" ), static fn() => $crm_submit( array_replace( $crm_input, array( 'idempotency_key'=>wp_generate_uuid4() ) ) ) );
	adc_check( is_wp_error( $failed ) && $crm_after === $crm_counts(), 'Intake failure rolls back all rows when insertion fails in ' . $table . '.' );
}
$wpdb->query( "ALTER TABLE $crm_messages ENGINE=MyISAM" );
adc_check( $error_is( $crm_submit( array_replace( $crm_input, array( 'idempotency_key'=>wp_generate_uuid4() ) ) ), 'adc_intake_storage_unavailable' ) && $crm_after === $crm_counts(), 'Intake refuses a nontransactional compatibility table before writing.' );
$wpdb->query( "ALTER TABLE $crm_messages ENGINE=InnoDB" );
$crm_clear_rate();
for ( $crm_attempt = 0; $crm_attempt < 8; ++$crm_attempt ) { $crm_rate_result = PublicIntake::submit( $crm_input, 'message' ); }
adc_check( is_array( $crm_rate_result ) && $error_is( PublicIntake::submit( $crm_input, 'message' ), 'adc_rate_limited' ), 'Rate boundary allows eight submissions and rejects the ninth, including retries.' );
$crm_clear_rate();

// Real account identity is supplied by the theme adapter; contact equality alone never links.
$crm_account = $make_user( 'crm_account', 'subscriber', 0 );
update_user_meta( $crm_account, 'car_dealer_phone', '+966501234567' );
$crm_account_user = get_userdata( $crm_account );
$crm_account_input = array_replace( $crm_input, array( 'name'=>$crm_account_user->display_name, 'email'=>strtolower( $crm_account_user->user_email ), 'idempotency_key'=>wp_generate_uuid4(), 'consent_marketing'=>true ) );
wp_set_current_user( $crm_account );
$crm_linked = $crm_submit( $crm_account_input );
adc_check( is_array( $crm_linked ), 'Authenticated theme enquiry creates an account-linked customer.' );
$crm_linked_id = $crm_customer_id( $crm_linked );
adc_check( $crm_account === (int) $wpdb->get_var( "SELECT account_user_id FROM $customers WHERE id=$crm_linked_id" ), 'Account linkage uses the authenticated WordPress user ID.' );
$crm_second = $crm_submit( array_replace( $crm_account_input, array( 'idempotency_key'=>wp_generate_uuid4(), 'consent_marketing'=>false ) ) );
adc_check( is_array( $crm_second ) && $crm_linked_id === $crm_customer_id( $crm_second ) && '1' === $wpdb->get_var( "SELECT consent_marketing FROM $customers WHERE id=$crm_linked_id" ), 'Further authenticated enquiries reuse the customer without silently withdrawing consent.' );
adc_check( $error_is( $crm_submit( array_replace( $crm_account_input, array( 'name'=>'Forged account', 'idempotency_key'=>wp_generate_uuid4() ) ) ), 'adc_identity_changed' ), 'Account linking rejects identity that differs from the locked WordPress account.' );
$crm_generic = $crm_submit( array_replace( $crm_account_input, array( 'idempotency_key'=>wp_generate_uuid4() ) ), '' );
adc_check( is_array( $crm_generic ) && $crm_customer_id( $crm_generic ) !== $crm_linked_id && null === $wpdb->get_var( $wpdb->prepare( "SELECT account_user_id FROM $customers WHERE id=%d", $crm_customer_id( $crm_generic ) ) ), 'Generic REST-style intake never claims an account even with matching contact data.' );

wp_set_current_user( $admin );
LeadService::assign( $crm_message['id'], $sales_b );
wp_set_current_user( $sales_a );
adc_check( $error_is( RequestWorkflow::read( $crm_message['id'] ), 'adc_request_not_found' ) && is_wp_error( LeadService::activity_history( $crm_message['id'] ) ), 'Foreign branch cannot read request or activity history by ID.' );
wp_set_current_user( $sales_b );
$crm_request = RequestWorkflow::read( $crm_message['id'] );
adc_check( is_array( $crm_request ) && $crm_request['can_edit'], 'Assigned salesperson can read and edit a linked request.' );
$crm_patch = array( 'revision'=>$crm_request['revision'], 'status'=>'read', 'customer_reply'=>'Customer-facing reply' );
adc_check( $error_is( RequestWorkflow::update( $crm_message['id'], array( 'status'=>'read' ) ), 'adc_request_stale' ), 'Staff update requires the current request revision.' );
$crm_before = $crm_counts();
$failed = $with_sql_failure( static fn( $q ) => str_starts_with( $q, "INSERT INTO `$audit`" ), static fn() => RequestWorkflow::update( $crm_message['id'], $crm_patch ) );
adc_check( is_wp_error( $failed ) && 'new' === RequestWorkflow::read( $crm_message['id'] )['status'] && $crm_before === $crm_counts(), 'Failed request audit restores status, reply and activity history.' );
adc_check( is_array( RequestWorkflow::update( $crm_message['id'], $crm_patch ) ), 'Assigned salesperson saves customer-visible reply and status.' );
adc_check( $error_is( RequestWorkflow::update( $crm_message['id'], $crm_patch ), 'adc_request_stale' ), 'A stale staff form cannot overwrite the newer reply.' );
$crm_request = RequestWorkflow::read( $crm_message['id'] );
adc_check( false === RequestWorkflow::update( $crm_message['id'], array( 'revision'=>$crm_request['revision'], 'status'=>'read' ) )['updated'], 'Unchanged request update creates no new history.' );
$crm_audit_row = $wpdb->get_row( "SELECT * FROM $audit ORDER BY id DESC LIMIT 1", ARRAY_A );
adc_check( ! str_contains( wp_json_encode( $crm_audit_row ), 'Customer-facing reply' ), 'Customer reply text is kept out of permanent audit JSON.' );
RequestWorkflow::update( $crm_message['id'], array( 'revision'=>$crm_request['revision'], 'status'=>'completed' ) );
adc_check( $error_is( RequestWorkflow::update( $crm_message['id'], array( 'revision'=>RequestWorkflow::read( $crm_message['id'] )['revision'], 'status'=>'new' ) ), 'adc_request_transition' ), 'Completed message cannot be reopened.' );

// Booking fixture is shared with real HTTP cancellation checks later in the suite.
wp_set_current_user( $admin );
$crm_car = wp_insert_post( array( 'post_type'=>'car', 'post_status'=>'publish', 'post_title'=>'Synthetic CRM car' ) );
update_post_meta( $crm_car, '_car_inventory_status', 'available' );
$crm_booking_input = array_replace( $crm_account_input, array( 'car_id'=>$crm_car, 'date'=>wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ), 'time'=>'12:30', 'idempotency_key'=>wp_generate_uuid4() ) );
wp_set_current_user( $crm_account );
$crm_booking = $crm_submit( $crm_booking_input, 'booking' );
adc_check( is_array( $crm_booking ) && $crm_linked_id === $crm_customer_id( $crm_booking ), 'Account booking reuses its authenticated customer identity.' );
adc_check( $error_is( $crm_submit( array_replace( $crm_booking_input, array( 'date'=>'2020-02-30' ) ), 'booking' ), 'adc_invalid_booking_time' ), 'Invalid/past calendar dates cannot create test-drive bookings.' );
wp_set_current_user( $admin );
$crm_booking_state = RequestWorkflow::read( $crm_booking['id'] );
adc_check( $error_is( RequestWorkflow::update( $crm_booking['id'], array( 'revision'=>$crm_booking_state['revision'], 'status'=>'completed' ) ), 'adc_request_transition' ), 'Pending booking cannot skip confirmation and jump to completed.' );
adc_check( $error_is( RequestWorkflow::update( $crm_booking['id'], array( 'revision'=>$crm_booking_state['revision'], 'requested_date'=>'2020-01-01' ) ), 'adc_request_date' ), 'Staff cannot reschedule a booking into the past.' );
update_post_meta( $crm_car, '_car_inventory_status', 'sold' );
adc_check( $error_is( RequestWorkflow::update( $crm_booking['id'], array( 'revision'=>$crm_booking_state['revision'], 'status'=>'confirmed' ) ), 'adc_request_vehicle' ), 'Unavailable vehicle blocks booking confirmation.' );
update_post_meta( $crm_car, '_car_inventory_status', 'available' );
adc_check( is_array( RequestWorkflow::update( $crm_booking['id'], array( 'revision'=>$crm_booking_state['revision'], 'status'=>'confirmed' ) ) ), 'Available future booking can be confirmed.' );
wp_set_current_user( $sales_b );
adc_check( $error_is( RequestWorkflow::update( $crm_booking['id'], array(), true ), 'adc_request_not_found' ), 'Customer cancellation checks recorded user ownership, even for a staff actor.' );
wp_set_current_user( $crm_account );
adc_check( true === RequestWorkflow::update( $crm_booking['id'], array(), true )['updated'] && false === RequestWorkflow::update( $crm_booking['id'], array(), true )['updated'], 'Account owner cancels booking once; an identical cancellation is harmless.' );

// Consolidation previews and rollback use fresh guest profiles with the exact same scope.
$crm_pair = static function () use ( $crm_input, $crm_submit, $crm_customer_id ): array {
	$actor = get_current_user_id(); wp_set_current_user( 0 ); $pair = array();
	foreach ( array( 1,2 ) as $i ) { $lead = $crm_submit( array_replace( $crm_input, array( 'idempotency_key'=>wp_generate_uuid4() ) ) ); if ( is_wp_error( $lead ) ) { throw new RuntimeException( 'CRM merge fixture failed.' ); } $pair[] = array( 'customer'=>$crm_customer_id( $lead ), 'lead'=>$lead['id'], 'request'=>$lead['legacy_request_id'] ); }
	wp_set_current_user( $actor ); return $pair;
};
$crm_merge_pair = $crm_pair();
list( $crm_source, $crm_target ) = array_column( $crm_merge_pair, 'customer' );
adc_check( $error_is( CustomerIdentity::preview( $crm_source, $crm_target ), 'adc_identity_forbidden' ), 'Customer cannot preview or consolidate CRM identities.' );
wp_set_current_user( $admin );
$crm_preview = CustomerIdentity::preview( $crm_source, $crm_target );
adc_check( is_array( $crm_preview ) && 1 === count( $crm_preview['lead_ids'] ) && count( CustomerIdentity::candidates( $crm_source ) ) >= 1, 'Administrator previews same-contact, same-scope duplicate profiles.' );
adc_check( $error_is( CustomerIdentity::merge( $crm_source, $crm_target, $crm_preview['revision'], 'REVIEW-1', false ), 'adc_identity_invalid' ), 'Consolidation requires explicit independent identity review.' );
adc_check( $error_is( CustomerIdentity::merge( $crm_source, $crm_target, str_repeat( '0', 64 ), 'REVIEW-1', true ), 'adc_identity_stale' ), 'Stale consolidation preview is rejected.' );
$crm_before = $crm_counts();
foreach ( array( $crm_activities, $audit ) as $table ) {
	$failed = $with_sql_failure( static fn( $q ) => str_starts_with( $q, "INSERT INTO `$table`" ), static fn() => CustomerIdentity::merge( $crm_source, $crm_target, $crm_preview['revision'], 'REVIEW-1', true ) );
	adc_check( is_wp_error( $failed ) && $crm_before === $crm_counts() && $crm_source === (int) $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM $crm_leads WHERE id=%d", $crm_merge_pair[0]['lead'] ) ) && null === $wpdb->get_var( "SELECT merged_into_id FROM $customers WHERE id=$crm_source" ), 'Failed merge history/audit restores source identity and all lead references.' );
}
$wpdb->update( $crm_leads, array( 'owner_user_id'=>$sales_b ), array( 'id'=>$crm_merge_pair[0]['lead'] ) );
adc_check( $error_is( CustomerIdentity::preview( $crm_source, $crm_target ), 'adc_identity_scope' ), 'Different salesperson ownership blocks consolidation.' );
$wpdb->update( $crm_leads, array( 'owner_user_id'=>0 ), array( 'id'=>$crm_merge_pair[0]['lead'] ) );
$wpdb->update( $crm_messages, array( 'user_id'=>$crm_account ), array( 'id'=>$crm_merge_pair[0]['request'] ) );
adc_check( $error_is( CustomerIdentity::preview( $crm_source, $crm_target ), 'adc_identity_conflict' ), 'Consolidation cannot transfer another account-owned request to an unlinked customer.' );
$wpdb->update( $crm_messages, array( 'user_id'=>0 ), array( 'id'=>$crm_merge_pair[0]['request'] ) );
$crm_preview = CustomerIdentity::preview( $crm_source, $crm_target );
$crm_merged = CustomerIdentity::merge( $crm_source, $crm_target, $crm_preview['revision'], 'REVIEW-1', true );
if ( is_wp_error( $crm_merged ) ) { throw new RuntimeException( 'Reviewed CRM merge failed: ' . $crm_merged->get_error_code() . '; SQL: ' . $wpdb->last_error ); }
adc_check( is_array( $crm_merged ) && 1 === $crm_merged['moved_leads'] && '' === (string) $wpdb->get_var( "SELECT email FROM $customers WHERE id=$crm_source" ) && $crm_target === (int) $wpdb->get_var( "SELECT merged_into_id FROM $customers WHERE id=$crm_source" ), 'Successful reviewed consolidation moves leads and anonymizes the source tombstone.' );
adc_check( ! CustomerScope::allows( $crm_source, $branch_b['id'] ) && CustomerScope::allows( $crm_target, $branch_b['id'] ), 'Merged source cannot acquire new operational references; destination remains usable.' );
adc_check( $error_is( CustomerIdentity::merge( $crm_source, $crm_target, $crm_preview['revision'], 'REVIEW-1', true ), 'adc_identity_conflict' ), 'Repeated consolidation cannot move or audit the source twice.' );

$crm_document_pair = $crm_pair();
$crm_document_vehicle = $make_vehicle( '117' );
wp_set_current_user( $admin );
$crm_document = AutoDealership\Sales\SalesService::create_quote( $crm_document_pair[0]['customer'], $crm_document_vehicle, gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) );
adc_check( is_array( $crm_document ) && $error_is( CustomerIdentity::preview( $crm_document_pair[0]['customer'], $crm_document_pair[1]['customer'] ), 'adc_identity_operational' ), 'A source with a quotation and immutable revision cannot be consolidated.' );
$crm_consent_pair = $crm_pair();
$wpdb->update( $customers, array( 'consent_marketing'=>1, 'consent_at'=>current_time( 'mysql', true ) ), array( 'id'=>$crm_consent_pair[1]['customer'] ) );
$crm_consent_preview = CustomerIdentity::preview( $crm_consent_pair[0]['customer'], $crm_consent_pair[1]['customer'] );
$crm_consent_merge = CustomerIdentity::merge( $crm_consent_pair[0]['customer'], $crm_consent_pair[1]['customer'], $crm_consent_preview['revision'], 'CONSENT-REVIEW', true );
adc_check( is_array( $crm_consent_merge ) && '0' === $wpdb->get_var( $wpdb->prepare( "SELECT consent_marketing FROM $customers WHERE id=%d", $crm_consent_pair[1]['customer'] ) ), 'Merge retains marketing permission only if both records explicitly consented.' );

// Independent connections exercise uniqueness, account creation and stale-preview locks.
$crm_parallel = static function ( string $scenario, int $actor, array $inputs ) use ( $wpdb ): array {
	$gate = 'adc_gate_' . bin2hex( random_bytes( 8 ) ); $jobs = array();
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $gate ) ) ) { throw new RuntimeException( 'CRM barrier unavailable.' ); }
	try {
		foreach ( $inputs as $input ) {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/database-runner.php', '--worker' ), array( 0=>array( 'pipe','r' ), 1=>array( 'pipe','w' ), 2=>STDERR ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'CRM worker unavailable.' ); }
			$worker_scenario = $input['_scenario'] ?? $scenario; unset( $input['_scenario'] );
			fwrite( $pipes[0], wp_json_encode( array( 'database'=>DB_NAME, 'source_database'=>'', 'host'=>DB_HOST, 'user'=>DB_USER, 'password'=>DB_PASSWORD, 'scenario'=>$worker_scenario, 'actor'=>$actor, 'input'=>$input, 'barrier'=>$gate ) ) );
			fclose( $pipes[0] ); $jobs[] = array( $process, $pipes[1] );
		}
		usleep( 500000 );
	} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $gate ) ); }
	$results = array();
	foreach ( $jobs as list( $process, $output ) ) {
		$value = json_decode( stream_get_contents( $output ), true ); fclose( $output );
		if ( 0 !== proc_close( $process ) || ! is_array( $value ) ) { throw new RuntimeException( 'CRM concurrency failed.' ); }
		$results[] = $value;
	}
	return $results;
};
$crm_clear_rate();
$crm_parallel_input = array_replace( $crm_input, array( 'idempotency_key'=>wp_generate_uuid4() ) );
$crm_before = $crm_counts();
$crm_race = $crm_parallel( 'crm_intake', 0, array( $crm_parallel_input, $crm_parallel_input ) );
adc_check( isset( $crm_race[0]['id'] ) && $crm_race[0] === $crm_race[1] && array( 1,1,1,0,1,1 ) === array_map( static fn( $a, $b ) => $a - $b, $crm_counts(), $crm_before ), 'Concurrent guest retries persist exactly one complete enquiry without orphan rows.' );
$crm_race_account = $make_user( 'crm_race_account', 'subscriber', 0 );
$crm_race_user = get_userdata( $crm_race_account );
$crm_race_identity = array_replace( $crm_input, array( 'name'=>$crm_race_user->display_name, 'email'=>$crm_race_user->user_email ) );
$crm_clear_rate();
$crm_race = $crm_parallel( 'crm_intake', $crm_race_account, array( array_replace( $crm_race_identity, array( 'idempotency_key'=>wp_generate_uuid4() ) ), array_replace( $crm_race_identity, array( 'idempotency_key'=>wp_generate_uuid4() ) ) ) );
adc_check( isset( $crm_race[0]['id'], $crm_race[1]['id'] ) && $crm_race[0]['id'] !== $crm_race[1]['id'] && $crm_customer_id( $crm_race[0] ) === $crm_customer_id( $crm_race[1] ) && '1' === $wpdb->get_var( "SELECT COUNT(*) FROM $customers WHERE account_user_id=$crm_race_account" ), 'Concurrent first enquiries for one authenticated account create one shared customer.' );
$crm_race_revision = RequestWorkflow::read( $crm_race[0]['id'] )['revision'];
$crm_request_race = $crm_parallel( 'crm_request', $admin, array( array( 'lead_id'=>$crm_race[0]['id'], 'revision'=>$crm_race_revision, 'status'=>'read', 'customer_reply'=>'First writer' ), array( 'lead_id'=>$crm_race[0]['id'], 'revision'=>$crm_race_revision, 'status'=>'read', 'customer_reply'=>'Second writer' ) ) );
adc_check( 1 === count( array_filter( $crm_request_race, static fn( $r ) => ! empty( $r['updated'] ) ) ) && 1 === count( array_filter( $crm_request_race, static fn( $r ) => 'adc_request_stale' === ( $r['error'] ?? '' ) ) ), 'Concurrent request edits allow one winner and reject the stale writer.' );
$crm_race_pair = $crm_pair();
$crm_race_preview = CustomerIdentity::preview( $crm_race_pair[0]['customer'], $crm_race_pair[1]['customer'] );
$crm_merge_input = array( 'source_id'=>$crm_race_pair[0]['customer'], 'target_id'=>$crm_race_pair[1]['customer'], 'revision'=>$crm_race_preview['revision'] );
$crm_merge_race = $crm_parallel( 'crm_merge', $admin, array( $crm_merge_input, $crm_merge_input ) );
adc_check( 1 === count( array_filter( $crm_merge_race, static fn( $r ) => isset( $r['moved_leads'] ) ) ) && 1 === count( array_filter( $crm_merge_race, static fn( $r ) => 'adc_identity_conflict' === ( $r['error'] ?? '' ) ) ), 'Concurrent consolidation commits once and rejects the already merged source.' );

// Export/erase follow the authenticated account even after its email changes.
wp_update_user( array( 'ID'=>$crm_account, 'user_email'=>'crm-new@example.invalid' ) );
$crm_export = PrivacyTools::export( 'crm-new@example.invalid' );
adc_check( str_contains( wp_json_encode( $crm_export ), 'crm-new@example.invalid' ) && str_contains( wp_json_encode( $crm_export ), 'Synthetic enquiry' ), 'Privacy export follows account linkage after immediate profile email sync and includes enquiry text.' );
$failed = $with_sql_failure( static fn( $q ) => str_starts_with( $q, "UPDATE `$crm_messages`" ), static fn() => PrivacyTools::erase( 'crm-new@example.invalid' ) );
adc_check( ! $failed['done'] && $crm_account === (int) $wpdb->get_var( "SELECT account_user_id FROM $customers WHERE id=$crm_linked_id" ) && 'crm-new@example.invalid' === $wpdb->get_var( "SELECT email FROM $customers WHERE id=$crm_linked_id" ), 'Legacy erasure failure rolls back canonical identity anonymization.' );
$crm_erased = PrivacyTools::erase( 'crm-new@example.invalid' );
adc_check( $crm_erased['done'] && null === $wpdb->get_var( "SELECT account_user_id FROM $customers WHERE id=$crm_linked_id" ) && '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM $crm_bookings WHERE id=%d", $crm_booking['legacy_request_id'] ) ), 'Erasure clears account linkage and old-email compatibility copies together.' );
adc_check( null === $wpdb->get_var( $wpdb->prepare( "SELECT public_payload_hash FROM $crm_leads WHERE id=%d", $crm_linked['id'] ) ), 'Erasure also clears the enquiry payload fingerprint.' );

// Closed compatibility copies are erased atomically; active copies and tombstones survive.
$crm_retention_setting = get_option( RetentionService::OPTION, 0 );
update_option( RetentionService::OPTION, 365 );
$crm_retention_pair = $crm_pair();
$crm_old = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
foreach ( $crm_retention_pair as $item ) {
	$wpdb->update( $customers, array( 'updated_at'=>$crm_old ), array( 'id'=>$item['customer'] ) );
	$wpdb->update( $crm_leads, array( 'stage'=>'lost', 'updated_at'=>$crm_old ), array( 'id'=>$item['lead'] ) );
	$wpdb->update( $crm_messages, array( 'created_at'=>$crm_old, 'updated_at'=>$crm_old ), array( 'id'=>$item['request'] ) );
}
$wpdb->update( $crm_messages, array( 'status'=>'completed' ), array( 'id'=>$crm_retention_pair[0]['request'] ) );
$wpdb->update( $customers, array( 'updated_at'=>$crm_old ), array( 'id'=>$crm_source ) );
$crm_retention_failed = $with_sql_failure( static fn( $q ) => str_starts_with( $q, "INSERT INTO `$audit`" ), static fn() => RetentionService::run() );
adc_check( 0 === $crm_retention_failed['processed'] && 'Synthetic CRM' === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $crm_retention_pair[0]['customer'] ) ) && 'Synthetic enquiry' === $wpdb->get_var( $wpdb->prepare( "SELECT message FROM $crm_messages WHERE id=%d", $crm_retention_pair[0]['request'] ) ), 'Failed retention audit restores canonical identity and completed compatibility message.' );
$crm_retention = RetentionService::run();
adc_check( 1 === $crm_retention['processed'] && 'Retained customer' === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $crm_retention_pair[0]['customer'] ) ) && '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT message FROM $crm_messages WHERE id=%d", $crm_retention_pair[0]['request'] ) ), 'Retention anonymizes an old closed lead and its completed compatibility request together.' );
adc_check( 'Synthetic CRM' === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $crm_retention_pair[1]['customer'] ) ) && 'Merged customer' === $wpdb->get_var( "SELECT full_name FROM $customers WHERE id=$crm_source" ), 'Active compatibility requests and merged tombstones are excluded from retention.' );
// Keep this protected old fixture out of later baseline retention assertions.
$wpdb->update( $customers, array( 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>$crm_retention_pair[1]['customer'] ) );
update_option( RetentionService::OPTION, $crm_retention_setting );
$crm_clear_rate();
wp_set_current_user( $admin );
