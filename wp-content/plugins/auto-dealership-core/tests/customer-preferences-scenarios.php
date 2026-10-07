<?php
/** Account preferences, immediate profile sync and legacy CRM retirement acceptance. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Database\Schema;
use AutoDealership\Leads\CustomerIdentity;
use AutoDealership\Privacy\PrivacyTools;

if ( ! post_type_exists( 'cd_crm' ) ) {
	register_post_type( 'cd_crm', array( 'public'=>false, 'publicly_queryable'=>false, 'show_ui'=>false, 'supports'=>array( 'title' ) ) );
}

wp_set_current_user( 0 );
$anonymous_preferences = CustomerIdentity::current_preferences();
adc_check( is_wp_error( $anonymous_preferences ) && 403 === $anonymous_preferences->get_error_data()['status'], 'Anonymous callers cannot read account communication preferences.' );

$preference_account = $make_user( 'preference_account', 'subscriber', 0 );
$preference_email = 'preference-account@example.invalid';
wp_update_user( array( 'ID'=>$preference_account, 'display_name'=>'Preference Account', 'user_email'=>$preference_email ) );
update_user_meta( $preference_account, 'car_dealer_phone', '+966505555551' );
wp_set_current_user( $preference_account );
$initial_preferences = CustomerIdentity::current_preferences();
adc_check( is_array( $initial_preferences ) && ! $initial_preferences['consent_marketing'] && 0 === $initial_preferences['linked_customer_id'], 'A new account starts opted out and exposes no unrelated customer identity.' );
$explicit_opt_out = CustomerIdentity::update_preferences( false );
adc_check( is_array( $explicit_opt_out ) && metadata_exists( 'user', $preference_account, 'adc_marketing_consent' ) && '0' === (string) get_user_meta( $preference_account, 'adc_marketing_consent', true ), 'An explicit pre-enquiry opt-out is stored on the WordPress account.' );

$legacy_profile = wp_insert_post( array( 'post_type'=>'cd_crm', 'post_status'=>'private', 'post_title'=>'Legacy Preference Account' ) );
if ( is_wp_error( $legacy_profile ) || $legacy_profile < 1 ) { throw new RuntimeException( 'Legacy CRM preference fixture failed.' ); }
foreach ( array( 'user_id'=>$preference_account, 'email'=>$preference_email, 'phone'=>'+966505555551', 'stage'=>'contacted', 'source'=>'legacy-form', 'due'=>'2030-01-01T10:00', 'task'=>'Legacy follow-up' ) as $key=>$value ) {
	update_post_meta( $legacy_profile, '_crm_' . $key, $value );
}
car_dealer_crm_log( $legacy_profile, 'Legacy personal activity' );

$preference_input = array_replace( $crm_input, array(
	'name'=>'Preference Account', 'mobile'=>'+966505555551', 'email'=>$preference_email,
	'consent_marketing'=>true, 'idempotency_key'=>wp_generate_uuid4(),
) );
$preference_lead = $crm_submit( $preference_input );
if ( is_wp_error( $preference_lead ) ) { throw new RuntimeException( 'Preference account intake failed: ' . $preference_lead->get_error_code() ); }
$preference_customer = $crm_customer_id( $preference_lead );
$preference_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $customers WHERE id=%d", $preference_customer ), ARRAY_A );
adc_check( $preference_account === (int) $preference_row['account_user_id'] && 0 === (int) $preference_row['consent_marketing'] && null === $preference_row['consent_at'], 'Stored account opt-out overrides a later enquiry checkbox while creating the canonical customer.' );
adc_check( $preference_customer === (int) get_post_meta( $legacy_profile, '_crm_retired_core_customer_id', true ) && ! metadata_exists( 'post', $legacy_profile, '_crm_due' ) && ! metadata_exists( 'post', $legacy_profile, '_crm_task' ), 'Canonical account linkage retires its legacy CRM profile and removes actionable follow-up fields.' );

wp_set_current_user( $sales_b );
adc_check( ! user_can( $sales_b, 'read_post', $legacy_profile ) && ! user_can( $sales_b, 'edit_post', $legacy_profile ), 'Non-administrator staff cannot read or edit a retired legacy CRM profile.' );
wp_set_current_user( $admin );
adc_check( user_can( $admin, 'read_post', $legacy_profile ) && ! user_can( $admin, 'edit_post', $legacy_profile ), 'Administrator can read a retired legacy CRM profile while write access remains blocked.' );

wp_set_current_user( $preference_account );
$opt_in = CustomerIdentity::update_preferences( true );
$preference_row = $wpdb->get_row( $wpdb->prepare( "SELECT consent_marketing,consent_at FROM $customers WHERE id=%d", $preference_customer ), ARRAY_A );
adc_check( is_array( $opt_in ) && $opt_in['consent_marketing'] && '1' === (string) get_user_meta( $preference_account, 'adc_marketing_consent', true ) && 1 === (int) $preference_row['consent_marketing'] && '' !== (string) $preference_row['consent_at'], 'Explicit opt-in updates account metadata and the linked canonical customer together.' );

add_filter( 'query', $break_audit );
$failed_opt_out = CustomerIdentity::update_preferences( false );
remove_filter( 'query', $break_audit );
$preference_row = $wpdb->get_row( $wpdb->prepare( "SELECT consent_marketing,consent_at FROM $customers WHERE id=%d", $preference_customer ), ARRAY_A );
adc_check( is_wp_error( $failed_opt_out ) && '1' === (string) get_user_meta( $preference_account, 'adc_marketing_consent', true ) && 1 === (int) $preference_row['consent_marketing'] && '' !== (string) $preference_row['consent_at'], 'Audit failure rolls an account preference change back in user metadata and canonical CRM.' );

$opt_out = CustomerIdentity::update_preferences( false );
$preference_row = $wpdb->get_row( $wpdb->prepare( "SELECT consent_marketing,consent_at FROM $customers WHERE id=%d", $preference_customer ), ARRAY_A );
adc_check( is_array( $opt_out ) && ! $opt_out['consent_marketing'] && '0' === (string) get_user_meta( $preference_account, 'adc_marketing_consent', true ) && 0 === (int) $preference_row['consent_marketing'] && null === $preference_row['consent_at'], 'Explicit opt-out clears marketing consent in both stores atomically.' );
$later_preference_lead = $crm_submit( array_replace( $preference_input, array( 'idempotency_key'=>wp_generate_uuid4(), 'consent_marketing'=>true ) ) );
adc_check( is_array( $later_preference_lead ) && $preference_customer === $crm_customer_id( $later_preference_lead ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT consent_marketing FROM $customers WHERE id=%d", $preference_customer ) ), 'A later authenticated enquiry cannot silently reverse the account opt-out.' );

// Change WordPress identity storage directly so the explicit service call can exercise audit rollback.
$wpdb->update( $wpdb->users, array( 'display_name'=>'Pending Profile', 'user_email'=>'pending-profile@example.invalid' ), array( 'ID'=>$preference_account ) );
$wpdb->update( $wpdb->usermeta, array( 'meta_value'=>'+966505555552' ), array( 'user_id'=>$preference_account, 'meta_key'=>'car_dealer_phone' ) );
clean_user_cache( $preference_account ); wp_cache_delete( $preference_account, 'user_meta' );
add_filter( 'query', $break_audit );
$failed_profile_sync = CustomerIdentity::sync_account_profile( $preference_account );
remove_filter( 'query', $break_audit );
$profile_row = $wpdb->get_row( $wpdb->prepare( "SELECT full_name,email,mobile FROM $customers WHERE id=%d", $preference_customer ), ARRAY_A );
adc_check( is_wp_error( $failed_profile_sync ) && 'Preference Account' === $profile_row['full_name'] && $preference_email === $profile_row['email'] && '+966505555551' === $profile_row['mobile'], 'Audit failure rolls an immediate account profile refresh back without partial CRM identity changes.' );
$profile_sync = CustomerIdentity::sync_account_profile( $preference_account );
$profile_row = $wpdb->get_row( $wpdb->prepare( "SELECT full_name,email,mobile FROM $customers WHERE id=%d", $preference_customer ), ARRAY_A );
adc_check( is_array( $profile_sync ) && array() === array_diff( array( 'full_name','email','mobile' ), $profile_sync['fields'] ) && 'Pending Profile' === $profile_row['full_name'] && 'pending-profile@example.invalid' === $profile_row['email'] && '+966505555552' === $profile_row['mobile'], 'Explicit account profile refresh updates every changed canonical identity field.' );

CustomerIdentity::boot();
wp_update_user( array( 'ID'=>$preference_account, 'display_name'=>'Immediate Profile', 'user_email'=>'immediate-profile@example.invalid' ) );
update_user_meta( $preference_account, 'car_dealer_phone', '+966505555553' );
$profile_row = $wpdb->get_row( $wpdb->prepare( "SELECT full_name,email,mobile FROM $customers WHERE id=%d", $preference_customer ), ARRAY_A );
adc_check( 'Immediate Profile' === $profile_row['full_name'] && 'immediate-profile@example.invalid' === $profile_row['email'] && '+966505555553' === $profile_row['mobile'], 'WordPress profile and phone hooks synchronize the linked customer immediately without another enquiry.' );
adc_check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $audit WHERE event_key='customer.profile_refreshed' AND subject_id=%d", $preference_customer ) ) >= 2, 'Immediate profile refreshes create append-only customer audit events.' );

$privacy_account = $make_user( 'legacy_privacy_account', 'subscriber', 0 );
$privacy_profile_email = 'legacy-privacy@example.invalid';
wp_update_user( array( 'ID'=>$privacy_account, 'display_name'=>'Legacy Privacy Person', 'user_email'=>$privacy_profile_email ) );
update_user_meta( $privacy_account, 'car_dealer_phone', '+966505555559' );
wp_set_current_user( $privacy_account );
CustomerIdentity::update_preferences( true );
$privacy_input = array_replace( $crm_input, array( 'name'=>'Legacy Privacy Person', 'mobile'=>'+966505555559', 'email'=>$privacy_profile_email, 'idempotency_key'=>wp_generate_uuid4() ) );
$privacy_lead = $crm_submit( $privacy_input );
$privacy_customer = $crm_customer_id( $privacy_lead );
$privacy_profile = wp_insert_post( array( 'post_type'=>'cd_crm', 'post_status'=>'private', 'post_title'=>'Legacy Privacy Person' ) );
foreach ( array( 'user_id'=>$privacy_account, 'email'=>$privacy_profile_email, 'phone'=>'+966505555559', 'source'=>'legacy-private', 'task'=>'Call customer', 'due'=>'2030-02-02T11:00' ) as $key=>$value ) { update_post_meta( $privacy_profile, '_crm_' . $key, $value ); }
$privacy_comment = car_dealer_crm_log( $privacy_profile, 'Private legacy note' );
$privacy_export = PrivacyTools::export( $privacy_profile_email );
$privacy_names = array_column( $privacy_export['data'][0]['data'] ?? array(), 'name' );
adc_check( in_array( __( 'Account marketing preference', 'auto-dealership-core' ), $privacy_names, true ) && in_array( __( 'Legacy CRM profile', 'auto-dealership-core' ), $privacy_names, true ) && in_array( __( 'Legacy CRM activity', 'auto-dealership-core' ), $privacy_names, true ), 'Privacy export includes account preference, legacy CRM identity and legacy activity history.' );

$failed_privacy_erase = $with_sql_failure(
	static fn( string $query ): bool => str_contains( $query, "UPDATE {$wpdb->comments} SET comment_content=" ),
	static fn() => PrivacyTools::erase( $privacy_profile_email )
);
clean_post_cache( $privacy_profile ); clean_user_cache( $privacy_account ); wp_cache_delete( $privacy_account, 'user_meta' );
adc_check( ! $failed_privacy_erase['done'] && 'Legacy Privacy Person' === get_post( $privacy_profile )->post_title && 'Legacy Privacy Person' === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $privacy_customer ) ) && '1' === (string) get_user_meta( $privacy_account, 'adc_marketing_consent', true ) && 'Private legacy note' === get_comment( $privacy_comment )->comment_content, 'Legacy privacy write failure rolls back Core, WordPress account and CRM post changes together.' );

$failed_privacy_audit = $with_sql_failure(
	static fn( string $query ): bool => str_starts_with( $query, "INSERT INTO `$audit`" ),
	static fn() => PrivacyTools::erase( $privacy_profile_email )
);
clean_post_cache( $privacy_profile ); clean_user_cache( $privacy_account ); wp_cache_delete( $privacy_account, 'user_meta' );
adc_check( ! $failed_privacy_audit['done'] && 'Legacy Privacy Person' === get_post( $privacy_profile )->post_title && 'Legacy Privacy Person' === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $privacy_customer ) ), 'Privacy erasure rolls back identity changes when its minimized audit event fails.' );

$privacy_erasure = PrivacyTools::erase( $privacy_profile_email );
clean_post_cache( $privacy_profile ); clean_user_cache( $privacy_account ); wp_cache_delete( $privacy_account, 'user_meta' );
adc_check( $privacy_erasure['done'] && 'Erased customer' === get_post( $privacy_profile )->post_title && '1' === (string) get_post_meta( $privacy_profile, '_crm_privacy_erased', true ) && '' === (string) get_post_meta( $privacy_profile, '_crm_email', true ) && '[Personal data erased]' === get_comment( $privacy_comment )->comment_content, 'Successful privacy erasure anonymizes and retires the legacy CRM profile and activity.' );
adc_check( ! metadata_exists( 'user', $privacy_account, 'adc_marketing_consent' ) && __( 'Erased customer', 'auto-dealership-core' ) === $wpdb->get_var( $wpdb->prepare( "SELECT full_name FROM $customers WHERE id=%d", $privacy_customer ) ), 'Successful privacy erasure removes account preference metadata and anonymizes the canonical customer.' );
$privacy_audit_data = (string) $wpdb->get_var( "SELECT after_data FROM $audit WHERE event_key='privacy.personal_data_erased' ORDER BY id DESC LIMIT 1" );
adc_check( str_contains( $privacy_audit_data, 'customer_count' ) && ! str_contains( $privacy_audit_data, $privacy_profile_email ), 'Privacy erasure audit records counts without retaining the erased email address.' );

wp_set_current_user( $admin );
