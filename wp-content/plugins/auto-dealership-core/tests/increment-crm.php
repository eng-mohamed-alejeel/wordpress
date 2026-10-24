<?php
/** Synthetic CRM 1.15–1.17 fixtures; never bootstrap the source configuration. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Database\Schema;
use AutoDealership\Core\Capabilities;
use AutoDealership\Admin\UserPermissions;
use AutoDealership\Leads\ContactIdentity;
use AutoDealership\Leads\PublicIntake;
use AutoDealership\Leads\LeadService;
use AutoDealership\Leads\LegacyEngagementStore;
use AutoDealership\Leads\RequestWorkflow;
use AutoDealership\Leads\CustomerIdentity;
use AutoDealership\Privacy\PrivacyTools;
use AutoDealership\Privacy\RetentionService;
use AutoDealership\Security\CustomerScope;

$crm_role_matrix = Capabilities::role_matrix();
adc_check( in_array( 'adc_assign_marketing_leads', $crm_role_matrix['dealership_sales_manager'], true ) && in_array( 'adc_assign_marketing_leads', $crm_role_matrix['dealership_general_manager'], true ), 'Default sales and general managers receive the dedicated marketing-assignment capability.' );
adc_check( ! in_array( 'adc_assign_marketing_leads', $crm_role_matrix['dealership_marketing'], true ), 'Marketing cannot assign leads by default.' );
adc_check( in_array( 'adc_assign_marketing_leads', Capabilities::assignable_capabilities(), true ), 'Dedicated assignment is available in explicit role and user grants.' );
adc_check( isset( UserPermissions::catalog()['adc_assign_marketing_leads'] ), 'Dedicated assignment appears in the existing permission editor catalog.' );
$crm_assignable_caps = Capabilities::assignable_capabilities();
$crm_permission_catalog = array_keys( UserPermissions::catalog() );
sort( $crm_assignable_caps ); sort( $crm_permission_catalog );
adc_check( $crm_assignable_caps === $crm_permission_catalog, 'Permission editor catalog covers every assignable capability exactly.' );
$crm_roles_option = wp_roles()->role_key;
$crm_roles_before_migration = get_option( $crm_roles_option );
$crm_definitions_before_migration = get_option( 'adc_role_definitions', null );
$crm_marketing_version_before = get_option( 'adc_marketing_permissions_version', null );
$crm_sales_version_before = get_option( 'adc_sales_permissions_version', null );
$crm_migration_failures_before = get_option( 'adc_permission_migration_failures', null );
$crm_marketing_role = get_role( 'dealership_marketing' );
if ( ! $crm_marketing_role ) { throw new RuntimeException( 'Marketing role fixture is unavailable.' ); }
try {
	$crm_custom_definitions = get_option( 'adc_role_definitions', array() );
	$crm_custom_definitions['dealership_marketing'] = array( 'caps'=>array_values( array_diff( array_keys( array_filter( $crm_marketing_role->capabilities ) ), array( 'adc_view_campaign_metrics' ) ) ) );
	update_option( 'adc_role_definitions', $crm_custom_definitions, false );
	$crm_marketing_role->remove_cap( 'adc_view_campaign_metrics' );
	delete_option( 'adc_marketing_permissions_version' );
	Capabilities::migrate_marketing_permissions();
	adc_check( ! get_role( 'dealership_marketing' )->has_cap( 'adc_view_campaign_metrics' ), 'Marketing permission migration preserves an administrator-customized role denial.' );

	update_option( 'adc_role_definitions', $crm_definitions_before_migration ?? array(), false );
	$crm_marketing_role = get_role( 'dealership_marketing' );
	$crm_marketing_role->remove_cap( 'adc_view_campaign_metrics' );
	delete_option( 'adc_marketing_permissions_version' );
	$crm_role_write_filter = static function ( $value, $previous ) { return $previous; };
	add_filter( 'pre_update_option_' . $crm_roles_option, $crm_role_write_filter, 10, 2 );
	Capabilities::migrate_marketing_permissions();
	remove_filter( 'pre_update_option_' . $crm_roles_option, $crm_role_write_filter, 10 );
	$crm_marketing_failure_state = get_option( 'adc_permission_migration_failures', array() );
	adc_check( ! get_role( 'dealership_marketing' )->has_cap( 'adc_view_campaign_metrics' ) && '1' !== get_option( 'adc_marketing_permissions_version' ) && isset( $crm_marketing_failure_state['marketing'] ), 'Failed role-capability persistence leaves the migration incomplete, retryable and visible in status.' );
	$crm_current_user = get_current_user_id();
	wp_set_current_user( 0 );
	ob_start(); Capabilities::migration_notice(); $crm_migration_notice = ob_get_clean();
	wp_set_current_user( $admin );
	ob_start(); Capabilities::migration_notice(); $crm_migration_admin_notice = ob_get_clean();
	adc_check( false !== has_action( 'admin_notices', array( Capabilities::class, 'migration_notice' ) ) && '' === $crm_migration_notice && str_contains( $crm_migration_admin_notice, 'notice-error' ) && str_contains( $crm_migration_admin_notice, 'التسويق' ) && str_contains( $crm_migration_admin_notice, 'سجل أخطاء PHP' ), 'Migration warning is wired to the admin screen, actionable for administrators and hidden from non-administrators.' );
	wp_set_current_user( $crm_current_user );
	Capabilities::migrate_marketing_permissions();
	$crm_marketing_failure_state = get_option( 'adc_permission_migration_failures', array() );
	adc_check( get_role( 'dealership_marketing' )->has_cap( 'adc_view_campaign_metrics' ) && '1' === get_option( 'adc_marketing_permissions_version' ) && ! isset( $crm_marketing_failure_state['marketing'] ), 'Retry completes a partially applied permission migration and clears its warning.' );

	$crm_marketing_role->remove_cap( 'adc_view_campaign_metrics' );
	delete_option( 'adc_marketing_permissions_version' );
	$crm_failure_status_filter = static function ( $value, $previous ) { return $previous; };
	add_filter( 'pre_update_option_adc_permission_migration_failures', $crm_failure_status_filter, 10, 2 );
	add_filter( 'pre_update_option_' . $crm_roles_option, $crm_role_write_filter, 10, 2 );
	Capabilities::migrate_marketing_permissions();
	remove_filter( 'pre_update_option_' . $crm_roles_option, $crm_role_write_filter, 10 );
	remove_filter( 'pre_update_option_adc_permission_migration_failures', $crm_failure_status_filter, 10 );
	ob_start(); Capabilities::migration_notice(); $crm_unpersisted_migration_notice = ob_get_clean();
	$crm_marketing_failure_state = get_option( 'adc_permission_migration_failures', array() );
	adc_check( '1' !== get_option( 'adc_marketing_permissions_version' ) && ! isset( $crm_marketing_failure_state['marketing'] ) && str_contains( $crm_unpersisted_migration_notice, 'التسويق' ), 'Migration failure remains visible during the request when its status option cannot be persisted.' );
	Capabilities::migrate_marketing_permissions();

	delete_option( 'adc_marketing_permissions_version' );
	$crm_version_write_filter = static function ( $value, $previous ) { return $previous; };
	add_filter( 'pre_update_option_adc_marketing_permissions_version', $crm_version_write_filter, 10, 2 );
	Capabilities::migrate_marketing_permissions();
	remove_filter( 'pre_update_option_adc_marketing_permissions_version', $crm_version_write_filter, 10 );
	adc_check( '1' !== get_option( 'adc_marketing_permissions_version' ), 'Failed migration-version persistence is not reported as complete.' );
	Capabilities::migrate_marketing_permissions();
	adc_check( '1' === get_option( 'adc_marketing_permissions_version' ), 'Migration can be completed after the version-store failure is removed.' );

	$crm_migration_user = wp_insert_user( array( 'user_login'=>'crm_migration_retry_' . wp_generate_password( 8, false ), 'user_pass'=>wp_generate_password( 40 ), 'role'=>'subscriber' ) );
	if ( is_wp_error( $crm_migration_user ) ) { throw new RuntimeException( $crm_migration_user->get_error_message() ); }
	(new WP_User( $crm_migration_user ))->add_cap( 'adc_create_reservations' );
	delete_option( 'adc_sales_permissions_version' );
	$usermeta_table = $wpdb->usermeta;
	$with_sql_failure(
		static fn( $query ) => str_starts_with( $query, 'SELECT user_id FROM ' . $usermeta_table . ' ' ),
		static fn() => Capabilities::migrate_sales_permissions()
	);
	adc_check( '1' !== get_option( 'adc_sales_permissions_version' ), 'Failed legacy-user discovery query leaves the sales migration incomplete.' );
	$with_sql_failure(
		static fn( $query ) => str_starts_with( $query, "UPDATE `$usermeta_table`" ) && str_contains( $query, (string) $crm_migration_user ),
		static fn() => Capabilities::migrate_sales_permissions()
	);
	adc_check( '1' !== get_option( 'adc_sales_permissions_version' ) && ! user_can( $crm_migration_user, 'adc_create_quotes' ), 'Failed direct-user grant persistence leaves the sales migration incomplete.' );
	Capabilities::migrate_sales_permissions();
	adc_check( '1' === get_option( 'adc_sales_permissions_version' ) && user_can( $crm_migration_user, 'adc_create_quotes' ), 'Sales migration retry completes legacy direct-user grants.' );
} finally {
	if ( isset( $crm_role_write_filter ) ) { remove_filter( 'pre_update_option_' . $crm_roles_option, $crm_role_write_filter, 10 ); }
	if ( isset( $crm_version_write_filter ) ) { remove_filter( 'pre_update_option_adc_marketing_permissions_version', $crm_version_write_filter, 10 ); }
	update_option( $crm_roles_option, $crm_roles_before_migration );
	if ( null === $crm_definitions_before_migration ) { delete_option( 'adc_role_definitions' ); } else { update_option( 'adc_role_definitions', $crm_definitions_before_migration, false ); }
	if ( null === $crm_marketing_version_before ) { delete_option( 'adc_marketing_permissions_version' ); } else { update_option( 'adc_marketing_permissions_version', $crm_marketing_version_before, false ); }
	if ( null === $crm_migration_failures_before ) { delete_option( 'adc_permission_migration_failures' ); } else { update_option( 'adc_permission_migration_failures', $crm_migration_failures_before, false ); }
	if ( isset( $crm_migration_user ) && ! is_wp_error( $crm_migration_user ) ) { wp_delete_user( $crm_migration_user ); }
	if ( null === $crm_sales_version_before ) { delete_option( 'adc_sales_permissions_version' ); } else { update_option( 'adc_sales_permissions_version', $crm_sales_version_before, false ); }
	wp_roles()->for_site();
}

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
$crm_stage_history = Schema::table( 'lead_stage_history' );
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
adc_check( 'new' === $wpdb->get_var( $wpdb->prepare( "SELECT to_stage FROM `$crm_stage_history` WHERE lead_id=%d ORDER BY id ASC LIMIT 1", (int) $crm_message['id'] ) ), 'New leads receive an initial stage-history baseline in the intake transaction.' );
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
$crm_admin_user = new WP_User( $admin );
$crm_admin_user->add_cap( 'adc_create_marketing_leads' );
$crm_marketing_input = array( 'name'=>'Synthetic Marketing Lead', 'mobile'=>'+966500000976', 'email'=>'marketing-crm@example.invalid', 'city'=>'Riyadh', 'branch_id'=>(int) $branch_b['id'], 'consent_marketing'=>true, 'idempotency_key'=>wp_generate_uuid4() );
$crm_marketing_data = array( 'source'=>'marketing', 'campaign'=>'Autumn campaign', 'platform'=>'Google', 'ad_url'=>'https://example.invalid/ad', 'notes'=>'Synthetic campaign lead', 'staff_id'=>$sales_b );
$crm_before_marketing = $crm_counts();
$crm_marketing_result = LeadService::create_marketing( $crm_marketing_input, $crm_marketing_data );
$crm_marketing_row = is_array( $crm_marketing_result ) ? $wpdb->get_row( $wpdb->prepare( "SELECT source,campaign,platform,ad_url,consent_marketing,created_by,referred_at,owner_user_id,stage FROM $crm_leads WHERE id=%d", (int) $crm_marketing_result['id'] ), ARRAY_A ) : null;
if ( ! is_array( $crm_marketing_result ) || ! is_array( $crm_marketing_row ) ) { throw new RuntimeException( 'CRM marketing lead fixture creation failed.' ); }
adc_check( is_array( $crm_marketing_result ) && is_array( $crm_marketing_row ) && 'marketing' === $crm_marketing_row['source'] && 'Autumn campaign' === $crm_marketing_row['campaign'] && 'Google' === $crm_marketing_row['platform'] && 'https://example.invalid/ad' === $crm_marketing_row['ad_url'] && '1' === (string) $crm_marketing_row['consent_marketing'] && (int) $admin === (int) $crm_marketing_row['created_by'] && ! empty( $crm_marketing_row['referred_at'] ) && (int) $sales_b === (int) $crm_marketing_row['owner_user_id'] && 'assigned' === $crm_marketing_row['stage'], 'Marketing lead, campaign attribution, consent and requested branch assignment are created together.' );
adc_check( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $crm_stage_history WHERE lead_id=%d", (int) $crm_marketing_result['id'] ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $crm_activities WHERE lead_id=%d AND type='assignment'", (int) $crm_marketing_result['id'] ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $audit WHERE subject_type='lead' AND subject_id=%d AND event_key='lead.created'", (int) $crm_marketing_result['id'] ) ), 'Marketing assignment activities, stage transitions and creation audit are transactionally recorded.' );
$crm_marketer = $make_user( 'crm_marketer', 'dealership_marketing', (int) $branch_b['id'] );
wp_set_current_user( $crm_marketer );
$crm_marketer_input = array_replace( $crm_marketing_input, array( 'name'=>'Marketing staff lead', 'mobile'=>'+966500000977', 'email'=>'marketing-staff@example.invalid', 'idempotency_key'=>wp_generate_uuid4() ) );
$crm_marketer_result = LeadService::create_marketing( $crm_marketer_input, array_replace( $crm_marketing_data, array( 'staff_id'=>0 ) ) );
if ( ! is_array( $crm_marketer_result ) ) { throw new RuntimeException( 'CRM marketing staff lead fixture creation failed.' ); }
adc_check( current_user_can( 'adc_add_marketing_activity' ) && is_array( LeadService::add_activity( (int) $crm_marketer_result['id'], 'note', 'Marketing follow-up recorded by creator.' ) ), 'Marketing activity capability permits notes on the creator’s own marketing lead.' );
adc_check( $error_is( LeadService::add_activity( (int) $crm_marketing_result['id'], 'note', 'Must not reach another creator’s lead.' ), 'adc_lead_not_found' ), 'Marketing activity capability cannot modify another user’s marketing lead.' );
adc_check( $error_is( LeadService::add_activity( (int) $crm_marketer_result['id'], 'sale', 'Must not record sales events.' ), 'adc_activity_forbidden' ), 'Marketing activity capability cannot record sales workflow events.' );
wp_set_current_user( $admin );
$crm_after_marketing = $crm_counts();
$crm_marketing_retry = LeadService::create_marketing( $crm_marketing_input, $crm_marketing_data );
adc_check( $crm_marketing_result === $crm_marketing_retry && $crm_after_marketing === $crm_counts(), 'Marketing intake retries use the idempotency key rather than creating a second customer or lead.' );
$crm_before_marketing_failure = $crm_counts();
$crm_failed_marketing = $with_sql_failure( static fn( $query ) => str_starts_with( $query, "INSERT INTO `$audit`" ), static fn() => LeadService::create_marketing( array_replace( $crm_marketing_input, array( 'mobile'=>'+966500000975', 'idempotency_key'=>wp_generate_uuid4() ) ), $crm_marketing_data ) );
adc_check( is_wp_error( $crm_failed_marketing ) && $crm_before_marketing_failure === $crm_counts(), 'Marketing lead creation rolls back all records if its audit event cannot be saved.' );
adc_check( $error_is( LeadService::create_marketing( array_replace( $crm_marketing_input, array( 'mobile'=>'+966500000974', 'idempotency_key'=>wp_generate_uuid4() ) ), array_replace( $crm_marketing_data, array( 'staff_id'=>$sales_a ) ) ), 'adc_invalid_marketing_assignment' ), 'Marketing intake rejects staff assigned to a different branch without creating records.' );
$crm_admin_user->remove_cap( 'adc_create_marketing_leads' );
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
			$worker_scenario = $input['_scenario'] ?? $scenario;
			$worker_actor = (int) ( $input['_actor'] ?? $actor );
			unset( $input['_scenario'], $input['_actor'] );
			fwrite( $pipes[0], wp_json_encode( array( 'database'=>DB_NAME, 'source_database'=>'', 'host'=>DB_HOST, 'user'=>DB_USER, 'password'=>DB_PASSWORD, 'scenario'=>$worker_scenario, 'actor'=>$worker_actor, 'input'=>$input, 'barrier'=>$gate ) ) );
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
$crm_claim_lead = $crm_submit( array_replace( $crm_input, array( 'idempotency_key'=>wp_generate_uuid4() ) ), '' );
if ( ! is_array( $crm_claim_lead ) ) { throw new RuntimeException( 'CRM claim fixture creation failed.' ); }
$crm_claim_lead_id = (int) $crm_claim_lead['id'];
$crm_second_sales = $make_user( 'crm_sales_branch_b2', 'dealership_sales', (int) $branch_b['id'] );
$crm_expiry_manager = $make_user( 'crm_expiry_manager_b', 'dealership_sales_manager', (int) $branch_b['id'] );
$crm_assignment_lead = $crm_submit( array_replace( $crm_input, array( 'mobile'=>'+966500000986', 'idempotency_key'=>wp_generate_uuid4() ) ), '' );
if ( ! is_array( $crm_assignment_lead ) || is_wp_error( LeadService::assign( (int) $crm_assignment_lead['id'], $sales_b ) ) ) { throw new RuntimeException( 'CRM reassignment fixture failed.' ); }
adc_check( $error_is( LeadService::assign( (int) $crm_assignment_lead['id'], $crm_second_sales ), 'adc_invalid_assignment' ), 'Manager cannot reassign an owned lead without a reason.' );
adc_check( is_array( LeadService::assign( (int) $crm_assignment_lead['id'], $crm_second_sales, 'Branch workload balancing' ) ) && $crm_second_sales === (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $crm_leads WHERE id=%d", (int) $crm_assignment_lead['id'] ) ), 'Manager reassignment with a reason records the new owner.' );
adc_check( $error_is( LeadService::release_assignment( (int) $crm_assignment_lead['id'], '' ), 'adc_assignment_reason_required' ) && is_array( LeadService::release_assignment( (int) $crm_assignment_lead['id'], 'No first contact was made' ) ) && 'new' === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $crm_leads WHERE id=%d", (int) $crm_assignment_lead['id'] ) ), 'Manager can return an uncontacted lead to the queue only with a reason.' );
$crm_assignment_user = new WP_User( $crm_second_sales );
$crm_assignment_user->add_cap( 'adc_assign_marketing_leads' );
wp_set_current_user( $crm_second_sales );
adc_check( is_wp_error( LeadService::assign( (int) $crm_assignment_lead['id'], $sales_b, 'Must remain denied' ) ) && 'new' === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $crm_leads WHERE id=%d", (int) $crm_assignment_lead['id'] ) ), 'Dedicated marketing assignment capability cannot assign a non-marketing lead.' );
adc_check( is_wp_error( LeadService::release_assignment( (int) $crm_assignment_lead['id'], 'Must remain denied' ) ) && 'new' === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $crm_leads WHERE id=%d", (int) $crm_assignment_lead['id'] ) ), 'Dedicated marketing assignment capability cannot release a non-marketing assignment.' );
adc_check( is_array( LeadService::release_assignment( (int) $crm_marketing_result['id'], 'Return marketing lead to assignment queue' ) ), 'Scoped assignment permission can release a marketing assignment.' );
$dedicated_assignment = LeadService::assign( (int) $crm_marketing_result['id'], $sales_b, 'Dedicated assignment capability' );
adc_check( is_array( $dedicated_assignment ) && current_user_can( 'adc_assign_marketing_leads' ) && ! current_user_can( 'adc_manage_branch_leads' ) && $sales_b === (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $crm_leads WHERE id=%d", (int) $crm_marketing_result['id'] ) ), 'Dedicated assignment capability permits same-branch marketing assignment without branch-wide lead management.' );
wp_set_current_user( $sales_b );
adc_check( in_array( $crm_claim_lead_id, array_map( 'intval', array_column( LeadService::list_for_current_user( 1, 100 ), 'id' ) ), true ), 'Sales employee sees an unassigned lead only within the assigned branch queue.' );
wp_set_current_user( $sales_a );
adc_check( ! in_array( $crm_claim_lead_id, array_map( 'intval', array_column( LeadService::list_for_current_user( 1, 100 ), 'id' ) ), true ), 'Sales employee in a different branch cannot see the shared lead.' );
$crm_claim_race = $crm_parallel( 'crm_claim', 0, array(
	array( '_actor'=>$sales_b, 'lead_id'=>$crm_claim_lead_id ),
	array( '_actor'=>$crm_second_sales, 'lead_id'=>$crm_claim_lead_id ),
) );
adc_check( 1 === count( array_filter( $crm_claim_race, static fn( $r ) => isset( $r['stage'] ) && 'assigned' === $r['stage'] ) ) && 1 === count( array_filter( $crm_claim_race, static fn( $r ) => 'adc_claim_unavailable' === ( $r['error'] ?? '' ) ) ), 'Concurrent claim requests assign a shared lead to one salesperson only.' );
$crm_claim_owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $crm_leads WHERE id=%d", $crm_claim_lead_id ) );
wp_set_current_user( $crm_claim_owner );
adc_check( $error_is( LeadService::update_stage( $crm_claim_lead_id, 'contacted' ), 'adc_first_contact_required' ), 'Stage update cannot mark a claimed lead contacted without first-contact evidence.' );
adc_check( is_array( LeadService::add_activity( $crm_claim_lead_id, 'call', 'A call note alone is not first-contact evidence.' ) ) && $error_is( LeadService::update_stage( $crm_claim_lead_id, 'contacted' ), 'adc_first_contact_required' ), 'A generic call note does not satisfy first-contact requirements.' );
adc_check( is_wp_error( $with_sql_failure( static fn( $q ) => str_contains( $q, 'INSERT INTO' ) && str_contains( $q, $crm_stage_history ), static fn() => LeadService::record_first_contact( $crm_claim_lead_id, 'call', current_time( 'Y-m-d\TH:i' ), 'Injected history failure', 'This transaction must roll back' ) ) ) && 'assigned' === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM `$crm_leads` WHERE id=%d", $crm_claim_lead_id ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT first_contact_at FROM `$crm_leads` WHERE id=%d", $crm_claim_lead_id ) ), 'A failed stage-history write rolls back the lead stage and first-contact evidence.' );
$crm_first_contact = LeadService::record_first_contact( $crm_claim_lead_id, 'call', current_time( 'Y-m-d\TH:i' ), 'Reached the customer', 'Discussed vehicle requirements' );
adc_check( is_array( $crm_first_contact ) && 'contacted' === $wpdb->get_var( $wpdb->prepare( "SELECT stage FROM $crm_leads WHERE id=%d", $crm_claim_lead_id ) ) && $crm_first_contact['first_contact_at'] === $wpdb->get_var( $wpdb->prepare( "SELECT first_contact_at FROM $crm_leads WHERE id=%d", $crm_claim_lead_id ) ), 'First-contact record stores its typed time and atomically advances stage.' );
adc_check( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$crm_stage_history` WHERE lead_id=%d AND ((from_stage='new' AND to_stage='assigned') OR (from_stage='assigned' AND to_stage='contacted'))", $crm_claim_lead_id ) ), 'Claim and first-contact stage changes are captured as ordered historical transitions.' );
adc_check( 'Reached the customer' === $wpdb->get_var( $wpdb->prepare( "SELECT result FROM " . Schema::table( 'activities' ) . " WHERE id=%d", (int) $crm_first_contact['activity_id'] ) ), 'First-contact outcome is retained as structured activity evidence.' );
adc_check( $error_is( LeadService::set_priority( $crm_claim_lead_id, 'critical' ), 'adc_invalid_lead_priority' ) && is_array( LeadService::set_priority( $crm_claim_lead_id, 'urgent' ) ) && 'urgent' === $wpdb->get_var( $wpdb->prepare( "SELECT priority FROM $crm_leads WHERE id=%d", $crm_claim_lead_id ) ), 'Lead priority is validated, persisted and ordered using the configured four levels.' );
wp_set_current_user( $admin );
$crm_policy_fields = \AutoDealership\Admin\SettingsPage::sections()['crm'][1];
$crm_policy_previous_get = $_GET;
$_GET['section'] = 'crm';
ob_start();
\AutoDealership\Admin\SettingsPage::render();
$crm_policy_html = ob_get_clean();
$_GET = $crm_policy_previous_get;
adc_check( str_contains( $crm_policy_html, 'name="lead_assignment_mode"' ) && str_contains( $crm_policy_html, 'name="lead_claim_timeout_minutes"' ) && str_contains( $crm_policy_html, 'round_robin' ), 'CRM settings render both assignment mode choices and the bounded claim timeout.' );
$crm_policy = \AutoDealership\Core\ConfigurationService::update_section( array( 'lead_assignment_mode'=>'round_robin', 'lead_claim_timeout_minutes'=>'25' ), $crm_policy_fields );
adc_check( is_array( $crm_policy ) && 'round_robin' === get_option( 'adc_lead_assignment_mode' ) && 25 === (int) get_option( 'adc_lead_claim_timeout_minutes' ), 'Administrator can save the round-robin policy and claim timeout through the CRM settings section.' );
$crm_invalid_policy = \AutoDealership\Core\ConfigurationService::update_section( array( 'lead_assignment_mode'=>'random', 'lead_claim_timeout_minutes'=>'4' ), $crm_policy_fields );
adc_check( is_wp_error( $crm_invalid_policy ) && 'round_robin' === get_option( 'adc_lead_assignment_mode' ) && 25 === (int) get_option( 'adc_lead_claim_timeout_minutes' ), 'Invalid CRM assignment modes and out-of-range timeouts are rejected without changing saved policy.' );
$crm_rr_staff = array_values( array_filter( array_map( 'absint', get_users( array( 'role'=>'dealership_sales', 'fields'=>'ID', 'number'=>-1, 'orderby'=>'ID', 'order'=>'ASC' ) ) ), static fn( $user_id ) => user_can( $user_id, 'adc_manage_own_leads' ) && ! user_can( $user_id, 'manage_options' ) && \AutoDealership\Security\BranchScope::allows( (int) $branch_b['id'], $user_id ) ) );
sort( $crm_rr_staff, SORT_NUMERIC );
$crm_rr_success = count( $crm_rr_staff ) > 1;
if ( $crm_rr_success ) {
	$crm_rr_placeholders = implode( ',', array_fill( 0, count( $crm_rr_staff ), '%d' ) );
	$crm_rr_last_owner = $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $crm_leads WHERE branch_id=%d AND owner_user_id IN ($crm_rr_placeholders) AND claimed_at IS NOT NULL ORDER BY claimed_at DESC,id DESC LIMIT 1", array_merge( array( (int) $branch_b['id'] ), $crm_rr_staff ) ) );
	$crm_rr_last_index = false === $crm_rr_last_owner ? false : array_search( (int) $crm_rr_last_owner, $crm_rr_staff, true );
	$crm_rr_expected_index = false === $crm_rr_last_index ? 0 : ( ( $crm_rr_last_index + 1 ) % count( $crm_rr_staff ) );
	for ( $crm_rr_index = 0; $crm_rr_index < 4; ++$crm_rr_index ) {
		$crm_rr_result = $crm_submit( array_replace( $crm_input, array( 'mobile'=>'+966500001' . str_pad( (string) $crm_rr_index, 3, '0', STR_PAD_LEFT ), 'idempotency_key'=>wp_generate_uuid4() ) ), '' );
		if ( ! is_array( $crm_rr_result ) ) { $crm_rr_success = false; break; }
		$crm_rr_expected_owner = $crm_rr_staff[$crm_rr_expected_index];
		$crm_rr_row = $wpdb->get_row( $wpdb->prepare( "SELECT owner_user_id,stage,claimed_at FROM $crm_leads WHERE id=%d", (int) $crm_rr_result['id'] ), ARRAY_A );
		if ( ! $crm_rr_row || (int) $crm_rr_row['owner_user_id'] !== $crm_rr_expected_owner || 'assigned' !== $crm_rr_row['stage'] || empty( $crm_rr_row['claimed_at'] ) ) { $crm_rr_success = false; break; }
		$crm_rr_expected_index = ( $crm_rr_expected_index + 1 ) % count( $crm_rr_staff );
	}
}
adc_check( $crm_rr_success, 'Round robin assigns only active branch sales staff and rotates fairly across branch intake order.' );
if ( count( $crm_rr_staff ) > 1 ) {
	$crm_clear_rate();
	$crm_parallel_rr = $crm_parallel( 'crm_intake', 0, array(
		array_replace( $crm_input, array( 'mobile'=>'+966500001101', 'idempotency_key'=>wp_generate_uuid4() ) ),
		array_replace( $crm_input, array( 'mobile'=>'+966500001102', 'idempotency_key'=>wp_generate_uuid4() ) ),
	) );
	$crm_parallel_owners = array();
	foreach ( $crm_parallel_rr as $crm_parallel_result ) {
		if ( empty( $crm_parallel_result['id'] ) ) { continue; }
		$crm_parallel_owners[] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_user_id FROM $crm_leads WHERE id=%d AND stage='assigned' AND branch_id=%d", (int) $crm_parallel_result['id'], (int) $branch_b['id'] ) );
	}
	$crm_parallel_success = 2 === count( array_unique( $crm_parallel_owners ) ) && 2 === count( array_intersect( $crm_parallel_owners, $crm_rr_staff ) );
	$crm_parallel_detail = $crm_parallel_success ? '' : ' ' . wp_json_encode( array( 'results'=>$crm_parallel_rr, 'owners'=>$crm_parallel_owners, 'staff'=>$crm_rr_staff ) );
	adc_check( $crm_parallel_success, 'Concurrent distinct enquiries serialize on the branch and advance the round-robin cursor exactly once each.' . $crm_parallel_detail );
}
$crm_filtered_leads = LeadService::list_for_current_user( 1, 20, array( 'priority'=>'urgent', 'branch_id'=>(int) $branch_b['id'], 'owner'=>$crm_claim_owner ) );
adc_check( 1 === LeadService::count_for_current_user( array( 'priority'=>'urgent', 'branch_id'=>(int) $branch_b['id'], 'owner'=>$crm_claim_owner ) ) && 1 === count( $crm_filtered_leads ) && $crm_claim_lead_id === (int) $crm_filtered_leads[0]['id'], 'CRM list and count apply priority, employee and authorized branch filters consistently.' );
wp_set_current_user( $manager_a );
adc_check( 0 === LeadService::count_for_current_user( array( 'branch_id'=>(int) $branch_b['id'] ) ), 'CRM filters cannot expand a manager query beyond assigned branches.' );
$crm_report_from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
$crm_report_to = gmdate( 'Y-m-d' );
wp_set_current_user( $sales_a );
adc_check( $error_is( \AutoDealership\Reports\LeadPerformanceReport::summary( $crm_report_from, $crm_report_to ), 'adc_lead_report_forbidden' ), 'Sales employees without report capability cannot open CRM performance aggregates.' );
$crm_report_manager = $make_user( 'crm_report_manager_b', 'dealership_sales_manager', (int) $branch_b['id'] );
wp_set_current_user( $crm_report_manager );
$crm_branch_report = \AutoDealership\Reports\LeadPerformanceReport::summary( $crm_report_from, $crm_report_to );
adc_check( is_array( $crm_branch_report ) && ! array_filter( $crm_branch_report['branches'], static fn( $row ) => (int) $row['branch_id'] !== (int) $branch_b['id'] ) && ! array_filter( $crm_branch_report['pipeline'], static fn( $row ) => (int) $row['branch_id'] !== (int) $branch_b['id'] ) && ! array_filter( $crm_branch_report['transitions'], static fn( $row ) => (int) $row['branch_id'] !== (int) $branch_b['id'] ) && ! isset( $crm_branch_report['employees'] ) && ! array_filter( array_merge( $crm_branch_report['pipeline'], $crm_branch_report['transitions'] ), static fn( $row ) => array_key_exists( 'owner_user_id', $row ) ), 'CRM branch-level reports stay within the active branch and omit employee identifiers from the report shape.' );
wp_set_current_user( $admin );
$crm_global_report = \AutoDealership\Reports\LeadPerformanceReport::summary( $crm_report_from, $crm_report_to );
adc_check( is_array( $crm_global_report ) && array_key_exists( 'avg_first_contact_seconds', $crm_global_report['totals'] ) && isset( $crm_global_report['pipeline'], $crm_global_report['transitions'] ) && array_filter( $crm_global_report['transitions'], static fn( $row ) => 'assigned' === $row['from_stage'] && 'contacted' === $row['to_stage'] && null !== $row['avg_duration_seconds'] && is_numeric( $row['destination_share_percent'] ) && $row['destination_share_percent'] > 0 && $row['transition_rate_percent'] === $row['destination_share_percent'] ) && array_filter( $crm_global_report['branches'], static fn( $row ) => (int) $row['branch_id'] === (int) $branch_b['id'] ) && ! isset( $crm_global_report['employees'] ) && ! array_filter( array_merge( $crm_global_report['pipeline'], $crm_global_report['transitions'] ), static fn( $row ) => array_key_exists( 'owner_user_id', $row ) ), 'CRM report preserves aggregate transition shares and timings without exposing employee identity.' );
adc_check( $error_is( \AutoDealership\Reports\LeadPerformanceReport::summary( $crm_report_from, gmdate( 'Y-m-d', time() + 400 * DAY_IN_SECONDS ) ), 'adc_lead_report_dates' ), 'CRM performance report rejects invalid or excessive date ranges.' );
$crm_previous_timezone = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Asia/Riyadh' );
$crm_timezone_day = current_time( 'Y-m-d' );
$crm_timezone_lead = LeadService::create_public( array( 'name'=>'Timezone boundary fixture', 'mobile'=>'+966500000973', 'branch_id'=>(int) $branch_b['id'] ) );
if ( ! is_array( $crm_timezone_lead ) ) { throw new RuntimeException( 'CRM report timezone fixture creation failed.' ); }
$crm_timezone_start = new DateTimeImmutable( $crm_timezone_day . ' 00:00:00', wp_timezone() );
$crm_timezone_end = $crm_timezone_start->modify( '+1 day' );
$crm_timezone_created = $crm_timezone_start->modify( '+30 minutes' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$wpdb->update( $crm_leads, array( 'created_at'=>$crm_timezone_created ), array( 'id'=>(int) $crm_timezone_lead['id'] ), array( '%s' ), array( '%d' ) );
$crm_timezone_report = \AutoDealership\Reports\LeadPerformanceReport::summary( $crm_timezone_day, $crm_timezone_day );
$crm_timezone_expected = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $crm_leads WHERE branch_id=%d AND created_at>=%s AND created_at<%s", (int) $branch_b['id'], $crm_timezone_start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $crm_timezone_end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) ) );
$crm_timezone_actual = is_array( $crm_timezone_report ) ? array_sum( array_column( array_values( array_filter( $crm_timezone_report['branches'], static fn( $row ) => (int) $row['branch_id'] === (int) $branch_b['id'] ) ), 'total' ) ) : -1;
update_option( 'timezone_string', $crm_previous_timezone );
adc_check( is_array( $crm_timezone_report ) && $crm_timezone_expected === $crm_timezone_actual && $crm_timezone_expected > 0, 'CRM report date filters follow the WordPress timezone while querying UTC timestamps.' );
$crm_history_order_lead = LeadService::create_public( array( 'name'=>'Stage duration order fixture', 'mobile'=>'+966500000972', 'branch_id'=>(int) $branch_b['id'] ) );
if ( ! is_array( $crm_history_order_lead ) ) { throw new RuntimeException( 'CRM stage-duration fixture creation failed.' ); }
$wpdb->delete( $crm_stage_history, array( 'lead_id'=>(int) $crm_history_order_lead['id'] ), array( '%d' ) );
$crm_recent_stage_timestamp = time() - 2 * DAY_IN_SECONDS;
$crm_recent_stage_date = gmdate( 'Y-m-d H:i:s', $crm_recent_stage_timestamp );
$crm_older_stage_date = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
$crm_history_order_newer = $wpdb->insert( $crm_stage_history, array( 'lead_id'=>(int) $crm_history_order_lead['id'], 'branch_id'=>(int) $branch_b['id'], 'from_stage'=>'new', 'to_stage'=>'assigned', 'changed_at'=>$crm_recent_stage_date ), array( '%d', '%d', '%s', '%s', '%s' ) );
$crm_history_order_older = $wpdb->insert( $crm_stage_history, array( 'lead_id'=>(int) $crm_history_order_lead['id'], 'branch_id'=>(int) $branch_b['id'], 'from_stage'=>'', 'to_stage'=>'new', 'changed_at'=>$crm_older_stage_date ), array( '%d', '%d', '%s', '%s', '%s' ) );
if ( 1 !== $crm_history_order_newer || 1 !== $crm_history_order_older ) { throw new RuntimeException( 'CRM stage-duration history fixtures failed.' ); }
$crm_history_order_update = LeadService::update_stage( (int) $crm_history_order_lead['id'], 'lost', 'Synthetic loss for duration test' );
$crm_history_order_row_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $crm_stage_history WHERE lead_id=%d ORDER BY id DESC LIMIT 1", (int) $crm_history_order_lead['id'] ) );
$crm_expected_duration = time() - $crm_recent_stage_timestamp;
$crm_live_duration = (int) $wpdb->get_var( $wpdb->prepare( "SELECT duration_seconds FROM $crm_stage_history WHERE id=%d", $crm_history_order_row_id ) );
$wpdb->update( $crm_stage_history, array( 'duration_seconds'=>1 ), array( 'id'=>$crm_history_order_row_id ), array( '%d' ), array( '%d' ) );
update_option( 'adc_lead_stage_history_backfill_complete', true, false );
update_option( 'adc_lead_stage_history_duration_complete', false, false );
update_option( 'adc_lead_stage_history_duration_cursor', 0, false );
$crm_duration_recalculation = true;
for ( $crm_duration_batch = 0; $crm_duration_batch < 20 && ! get_option( 'adc_lead_stage_history_duration_complete', false ); ++$crm_duration_batch ) {
	if ( ! \AutoDealership\Leads\LeadStageHistoryBackfill::run_batch() ) { $crm_duration_recalculation = false; break; }
}
$crm_duration_recalculation = $crm_duration_recalculation && get_option( 'adc_lead_stage_history_duration_complete', false );
$crm_history_order_duration = (int) $wpdb->get_var( $wpdb->prepare( "SELECT duration_seconds FROM $crm_stage_history WHERE id=%d", $crm_history_order_row_id ) );
adc_check( is_array( $crm_history_order_update ) && abs( $crm_live_duration - $crm_expected_duration ) <= 5 && $crm_duration_recalculation && abs( $crm_history_order_duration - $crm_expected_duration ) <= 5, 'Live transitions and bounded historical recalculation use chronological event timestamps through completion.' );
$crm_report_previous_get = $_GET;
$_GET = array( 'from'=>$crm_report_from, 'to'=>$crm_report_to );
ob_start();
\AutoDealership\Admin\OperationsPages::render_lead_performance();
$crm_report_html = ob_get_clean();
$_GET = $crm_report_previous_get;
adc_check( str_contains( $crm_report_html, 'مؤشرات الفروع' ) && str_contains( $crm_report_html, 'توزيع المراحل حسب الفرع' ) && str_contains( $crm_report_html, 'التحولات التاريخية بين المراحل حسب الفرع' ) && str_contains( $crm_report_html, 'لا تتضمن هوية الموظف' ) && ! str_contains( $crm_report_html, 'مؤشرات موظفي المبيعات' ) && ! str_contains( $crm_report_html, 'الموظف وقت الانتقال' ) && str_contains( $crm_report_html, 'حصة الوجهة من المغادرين' ) && str_contains( $crm_report_html, 'المنطقة الزمنية للموقع' ) && str_contains( $crm_report_html, 'استعادة سجل المراحل' ), 'CRM performance screen exposes only branch-level aggregates and explains its metrics and history coverage.' );
$crm_marketing_previous_get = $_GET;
$_GET = array( 'page'=>'adc-marketing-reports', 'period'=>'7days' );
wp_set_current_user( $crm_marketer );
ob_start();
\AutoDealership\Admin\OperationsPages::render_marketing_reports();
$crm_marketing_report_html = ob_get_clean();
$_GET = $crm_marketing_previous_get;
wp_set_current_user( $admin );
adc_check( str_contains( $crm_marketing_report_html, 'مقاييس الأداء المعروضة مجمعة للحملة' ) && ! str_contains( $crm_marketing_report_html, 'أداء موظفي المبيعات' ) && ! str_contains( $crm_marketing_report_html, 'name="owner_user_id"' ) && ! str_contains( $crm_marketing_report_html, get_userdata( $sales_b )->display_name ), 'Campaign metrics retain campaign-level aggregates without rendering employee names or identifiers.' );
$crm_crm_previous_get = $_GET;
$_GET = array( 'page'=>'adc-crm' );
wp_set_current_user( $sales_b );
ob_start();
\AutoDealership\Admin\OperationsPages::render_leads();
$crm_viewer_without_export_html = ob_get_clean();
$_GET = $crm_crm_previous_get;
adc_check(
	! str_contains( $crm_viewer_without_export_html, 'تصدير النتائج CSV' ),
	'CRM lead viewers do not see the bulk-export action without its separate capability.'
);
wp_set_current_user( $admin );
$crm_admin_user->add_cap( 'adc_view_own_leads' );
$crm_admin_user->add_cap( 'adc_view_branch_leads' );
$crm_admin_user->add_cap( 'adc_manage_branch_leads' );
$crm_admin_user->add_cap( 'adc_view_marketing_leads' );
$crm_admin_user->add_cap( 'adc_create_marketing_leads' );
$crm_admin_user->add_cap( 'adc_export_crm_leads' );
wp_set_current_user( 0 );
wp_set_current_user( $admin );
$crm_crm_previous_get = $_GET;
$_GET = array( 'page'=>'adc-crm', 'stage'=>'assigned', 'priority'=>'normal', 'branch_id'=>(int) $branch_b['id'], 'source'=>'marketing', 'campaign'=>'Autumn campaign', 'platform'=>'Google', 'created_by'=>(int) $admin, 'owner'=>(int) $sales_b );
ob_start();
\AutoDealership\Admin\OperationsPages::render_leads();
$crm_list_html = ob_get_clean();
$_GET = $crm_crm_previous_get;
adc_check( 1 === substr_count( $crm_list_html, 'name="idempotency_key"' ) && ! str_contains( $crm_list_html, 'class="card adc-marketing-intake"' ) && str_contains( $crm_list_html, 'data-crm-board-status' ) && str_contains( $crm_list_html, 'data-lead-stage-form' ) && str_contains( $crm_list_html, 'name="campaign" value="Autumn campaign"' ), 'CRM intake is rendered once and the board offers accessible stage controls while retaining marketing filters.' );
adc_check(
	str_contains( $crm_list_html, '<form method="post"' ) && str_contains( $crm_list_html, 'name="action" value="adc_export_leads"' )
	&& str_contains( $crm_list_html, 'name="campaign" value="Autumn campaign"' )
	&& str_contains( $crm_list_html, 'name="platform" value="Google"' )
	&& str_contains( $crm_list_html, 'name="created_by" value="' . (int) $admin . '"' )
	&& str_contains( $crm_list_html, 'name="purpose" required' ) && str_contains( $crm_list_html, 'name="reason" required' ),
	'CRM export form uses POST, carries active filters, and requires purpose and reason.'
);
$crm_admin_user->remove_cap( 'adc_export_crm_leads' );
clean_user_cache( $admin );
wp_set_current_user( 0 );
wp_set_current_user( $admin );
wp_clear_scheduled_hook( 'adc_backfill_lead_stage_history' );
update_option( 'adc_lead_stage_history_backfill_complete', false, false );
update_option( 'adc_lead_stage_history_duration_complete', false, false );
\AutoDealership\Leads\LeadStageHistoryBackfill::schedule_if_needed();
$crm_backfill_scheduled_at = wp_next_scheduled( 'adc_backfill_lead_stage_history' );
\AutoDealership\Leads\LeadStageHistoryBackfill::schedule_if_needed();
$crm_backfill_scheduled_again = wp_next_scheduled( 'adc_backfill_lead_stage_history' );
wp_clear_scheduled_hook( 'adc_backfill_lead_stage_history' );
adc_check( 20 === has_action( 'plugins_loaded', array( \AutoDealership\Leads\LeadStageHistoryBackfill::class, 'schedule_if_needed' ) ) && $crm_backfill_scheduled_at > time() && $crm_backfill_scheduled_at === $crm_backfill_scheduled_again, 'Schema migration queues the idempotent history backfill after schema installation without running a batch inline.' );
$crm_backfill_lead = $crm_submit( array_replace( $crm_input, array( 'mobile'=>'+966500000989', 'idempotency_key'=>wp_generate_uuid4() ) ), '' );
if ( ! is_array( $crm_backfill_lead ) ) { throw new RuntimeException( 'CRM audit backfill fixture failed.' ); }
if ( ! is_array( LeadService::release_assignment( (int) $crm_backfill_lead['id'], 'Backfill fixture release' ) ) ) { throw new RuntimeException( 'CRM assignment-release backfill fixture failed.' ); }
$wpdb->delete( $crm_stage_history, array( 'lead_id'=>(int) $crm_backfill_lead['id'] ), array( '%d' ) );
update_option( 'adc_lead_stage_history_backfill_cursor', 0, false );
update_option( 'adc_lead_stage_history_backfill_complete', false, false );
for ( $crm_backfill_batch = 0; $crm_backfill_batch < 20 && ! get_option( 'adc_lead_stage_history_backfill_complete', false ); ++$crm_backfill_batch ) {
	if ( ! \AutoDealership\Leads\LeadStageHistoryBackfill::run_batch() ) { break; }
}
$crm_backfilled_rows = $wpdb->get_results( $wpdb->prepare( "SELECT from_stage,to_stage,audit_event_id FROM `$crm_stage_history` WHERE lead_id=%d ORDER BY id", (int) $crm_backfill_lead['id'] ), ARRAY_A );
adc_check( get_option( 'adc_lead_stage_history_backfill_complete', false ) && 3 === count( $crm_backfilled_rows ) && '' === $crm_backfilled_rows[0]['from_stage'] && 'new' === $crm_backfilled_rows[0]['to_stage'] && 'new' === $crm_backfilled_rows[1]['from_stage'] && 'assigned' === $crm_backfilled_rows[1]['to_stage'] && 'assigned' === $crm_backfilled_rows[2]['from_stage'] && 'new' === $crm_backfilled_rows[2]['to_stage'] && null !== $crm_backfilled_rows[0]['audit_event_id'] && null !== $crm_backfilled_rows[1]['audit_event_id'] && null !== $crm_backfilled_rows[2]['audit_event_id'], 'Audit backfill reconstructs creation, assignment and assignment-release transitions without inventing events.' );
$crm_policy_reset = \AutoDealership\Core\ConfigurationService::update_section( array( 'lead_assignment_mode'=>'manual', 'lead_claim_timeout_minutes'=>'30' ), $crm_policy_fields );
adc_check( is_array( $crm_policy_reset ) && 'manual' === get_option( 'adc_lead_assignment_mode' ), 'Manual assignment remains available and can be restored without changing claim handling.' );
$crm_expiry_lead = $crm_submit( array_replace( $crm_input, array( 'mobile'=>'+966500000987', 'idempotency_key'=>wp_generate_uuid4() ) ), '' );
wp_set_current_user( $admin );
if ( ! is_array( $crm_expiry_lead ) || is_wp_error( LeadService::assign( (int) $crm_expiry_lead['id'], $sales_b ) ) ) { throw new RuntimeException( 'CRM expiry fixture assignment failed.' ); }
$wpdb->update( $crm_leads, array( 'claimed_at'=>gmdate( 'Y-m-d H:i:s', time() - 31 * MINUTE_IN_SECONDS ), 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>(int) $crm_expiry_lead['id'] ), array( '%s', '%s' ), array( '%d' ) );
update_option( 'adc_lead_claim_timeout_minutes', 30 );
$crm_expiry_failure = $with_sql_failure( static fn( $q ) => str_contains( $q, 'INSERT IGNORE INTO' ) && str_contains( $q, Schema::table( 'followup_notifications' ) ), static fn() => LeadService::release_stale_claims() );
$crm_expiry_failure_state = $wpdb->get_row( $wpdb->prepare( "SELECT owner_user_id,stage FROM `$crm_leads` WHERE id=%d", (int) $crm_expiry_lead['id'] ), ARRAY_A );
adc_check( 0 === $crm_expiry_failure && (int) $crm_expiry_failure_state['owner_user_id'] === (int) $sales_b && 'assigned' === $crm_expiry_failure_state['stage'], 'Failed claim-expiry notification persistence rolls back returning the lead to the shared queue.' );
$crm_released = LeadService::release_stale_claims();
$crm_expiry_state = $wpdb->get_row( $wpdb->prepare( "SELECT owner_user_id,stage,claimed_at,first_contact_at FROM $crm_leads WHERE id=%d", (int) $crm_expiry_lead['id'] ), ARRAY_A );
adc_check( $crm_released >= 1 && '0' === (string) $crm_expiry_state['owner_user_id'] && 'new' === $crm_expiry_state['stage'] && null === $crm_expiry_state['claimed_at'] && null === $crm_expiry_state['first_contact_at'], 'Expiry uses the claim timestamp and actual first-contact record, not unrelated updated_at changes.' );
$crm_expiry_notifications = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'followup_notifications' ) . " WHERE source_type='claim_expired' AND source_id=%d", (int) $crm_expiry_lead['id'] ), ARRAY_A );
$crm_expiry_owner_notice = array_values( array_filter( $crm_expiry_notifications, static fn( $row ) => (int) $row['recipient_id'] === (int) $sales_b && 0 === (int) $row['level'] ) );
$crm_expiry_manager_notice = array_values( array_filter( $crm_expiry_notifications, static fn( $row ) => (int) $row['recipient_id'] === (int) $crm_expiry_manager && 1 === (int) $row['level'] ) );
adc_check( count( $crm_expiry_owner_notice ) === 1 && count( $crm_expiry_manager_notice ) === 1, 'Expired CRM claim queues durable owner and branch-manager notifications immediately.' );
wp_set_current_user( $sales_b );
adc_check( is_array( \AutoDealership\Operations\FollowUpNotifications::inbox() ) && array_filter( \AutoDealership\Operations\FollowUpNotifications::inbox(), static fn( $row ) => 'claim_expired' === $row['source_type'] && (int) $row['source_id'] === (int) $crm_expiry_lead['id'] ), 'Claim-expiry notification is visible to the former owner in the scoped inbox.' );
wp_set_current_user( $admin );
$crm_expiry_delivered = \AutoDealership\Operations\FollowUpNotifications::deliver( array( 'subject_id'=>(int) $crm_expiry_owner_notice[0]['id'] ) );
adc_check( true === $crm_expiry_delivered && null !== $wpdb->get_var( $wpdb->prepare( 'SELECT delivered_at FROM ' . Schema::table( 'followup_notifications' ) . ' WHERE id=%d', (int) $crm_expiry_owner_notice[0]['id'] ) ), 'Claim-expiry inbox delivery completes through the shared outbox handler.' );
$crm_claim_expiry_mail = null;
$crm_claim_expiry_mail_filter = static function ( $return, $atts ) use ( &$crm_claim_expiry_mail ) { $crm_claim_expiry_mail = $atts; return true; };
add_filter( 'pre_wp_mail', $crm_claim_expiry_mail_filter, 10, 2 );
update_option( 'adc_followup_email_enabled', true );
$crm_claim_expiry_email = \AutoDealership\Operations\FollowUpNotifications::deliver( array( 'subject_id'=>(int) $crm_expiry_manager_notice[0]['id'] ) );
remove_filter( 'pre_wp_mail', $crm_claim_expiry_mail_filter, 10 );
update_option( 'adc_followup_email_enabled', false );
$crm_expiry_manager_user = get_userdata( (int) $crm_expiry_manager );
adc_check( true === $crm_claim_expiry_email && is_array( $crm_claim_expiry_mail ) && $crm_expiry_manager_user && $crm_expiry_manager_user->user_email === $crm_claim_expiry_mail['to'] && str_contains( $crm_claim_expiry_mail['subject'], 'CRM lead claim expired' ) && ! str_contains( $crm_claim_expiry_mail['message'], 'crm@example.invalid' ) && ! str_contains( $crm_claim_expiry_mail['message'], '+966500000987' ), 'Enabled claim-expiry email reaches the branch manager without including customer contact details.' );
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
