<?php
/** Remaining bounded CRM acceptance and mixed concurrency, synthetic database only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Leads\CustomerIdentity;
use AutoDealership\Leads\RequestWorkflow;
use AutoDealership\Leads\LeadService;
use AutoDealership\Leads\EngagementQuery;
use AutoDealership\Database\Schema;

// Mixed operation races must never leave a financial document on a merged tombstone.
foreach ( array( false, true ) as $reverse ) {
	$pair = $crm_pair();
	$preview = CustomerIdentity::preview( $pair[0]['customer'], $pair[1]['customer'] );
	$merge = array( '_scenario'=>'crm_merge', 'source_id'=>$pair[0]['customer'], 'target_id'=>$pair[1]['customer'], 'revision'=>$preview['revision'] );
	$quote = array( '_scenario'=>'crm_quote', 'customer_id'=>$pair[0]['customer'], 'vehicle_id'=>$crm_document_vehicle, 'valid_until'=>gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) );
	$race = $crm_parallel( 'crm_merge', $admin, $reverse ? array( $quote,$merge ) : array( $merge,$quote ) );
	$source_merged = $wpdb->get_var( $wpdb->prepare( "SELECT merged_into_id FROM $customers WHERE id=%d", $pair[0]['customer'] ) );
	$source_quotes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'quotations' ) . ' WHERE customer_id=%d', $pair[0]['customer'] ) );
	adc_check( ( $source_merged && 0 === $source_quotes ) || ( ! $source_merged && 1 === $source_quotes ), 'Mixed quote/merge race preserves operational references for worker order ' . (int) $reverse . '.' );
	adc_check( 1 === count( array_filter( $race, static fn( $r ) => isset( $r['error'] ) ) ), 'Exactly one conflicting quote/merge operation is rejected.' );
}

// Erasure followed by a new explicit enquiry may create a fresh identity; old data stays erased.
$mixed_account = $make_user( 'mixed_privacy', 'subscriber', 0 );
$mixed_user = get_userdata( $mixed_account );
$mixed_input = array_replace( $crm_input, array( 'name'=>$mixed_user->display_name, 'email'=>$mixed_user->user_email, 'idempotency_key'=>wp_generate_uuid4() ) );
foreach ( array( false,true ) as $reverse ) {
	wp_set_current_user( $mixed_account );
	$old = $crm_submit( array_replace( $mixed_input, array( 'idempotency_key'=>wp_generate_uuid4() ) ) );
	$old_id = $crm_customer_id( $old );
	$new = array_replace( $mixed_input, array( '_scenario'=>'crm_intake', 'idempotency_key'=>wp_generate_uuid4() ) );
	$erase = array( '_scenario'=>'crm_erase', 'email'=>$mixed_user->user_email );
	$crm_clear_rate();
	$race = $crm_parallel( 'crm_intake', $mixed_account, $reverse ? array( $erase,$new ) : array( $new,$erase ) );
	$erasure = $race[$reverse ? 0 : 1]; $intake = $race[$reverse ? 1 : 0];
	adc_check( ! empty( $erasure['done'] ) && isset( $intake['id'] ), 'Concurrent account intake and privacy erasure complete under worker order ' . (int) $reverse . ': ' . wp_json_encode( array( 'erasure_done' => ! empty( $erasure['done'] ), 'erasure_error' => $erasure['error'] ?? '', 'intake_has_id' => isset( $intake['id'] ), 'intake_error' => $intake['error'] ?? '' ) ) );
	adc_check( __( 'Erased customer', 'auto-dealership-core' ) === $wpdb->get_var( "SELECT full_name FROM $customers WHERE id=$old_id" ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT public_payload_hash FROM $crm_leads WHERE id=%d", $old['id'] ) ) && '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM $crm_messages WHERE id=%d", $old['legacy_request_id'] ) ), 'Concurrent fresh enquiry never restores erased identity or old compatibility content.' );
	adc_check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $customers WHERE account_user_id=$mixed_account" ) <= 1, 'Mixed erasure/intake retains at most one current account-linked customer.' );
}

// Isolated date/time edits preserve the other appointment field; mapped branch changes block writes.
wp_set_current_user( 0 );
$appointment_input = array_replace( $crm_input, array( 'car_id'=>$crm_car, 'date'=>wp_date( 'Y-m-d', time() + 8 * DAY_IN_SECONDS ), 'time'=>'10:30', 'idempotency_key'=>wp_generate_uuid4() ) );
$appointment = $crm_submit( $appointment_input, 'booking' );
wp_set_current_user( $admin );
$state = RequestWorkflow::read( $appointment['id'] );
$next_date = wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS );
adc_check( is_array( RequestWorkflow::update( $appointment['id'], array( 'revision'=>$state['revision'], 'requested_date'=>$next_date ) ) ) && '10:30' === RequestWorkflow::read( $appointment['id'] )['requested_time'], 'Date-only rescheduling preserves the prior time.' );
$state = RequestWorkflow::read( $appointment['id'] );
adc_check( is_array( RequestWorkflow::update( $appointment['id'], array( 'revision'=>$state['revision'], 'requested_time'=>'14:15' ) ) ) && $next_date === RequestWorkflow::read( $appointment['id'] )['requested_date'], 'Time-only rescheduling preserves the prior date.' );
$wpdb->update( $vehicles, array( 'public_post_id'=>$crm_car ), array( 'id'=>$crm_document_vehicle ) );
$state = RequestWorkflow::read( $appointment['id'] );
adc_check( $error_is( RequestWorkflow::update( $appointment['id'], array( 'revision'=>$state['revision'], 'status'=>'confirmed' ) ), 'adc_request_vehicle' ), 'Vehicle mapped to another branch blocks appointment confirmation.' );
$wpdb->update( $vehicles, array( 'public_post_id'=>null ), array( 'id'=>$crm_document_vehicle ) );

// Use the current account and staff adapters without loading the full theme in CLI.
require_once ABSPATH . 'wp-content/themes/car-dealer/inc/accounts.php';
require_once ABSPATH . 'docs/archive/car-dealer/inc/crm.php';
require_once ABSPATH . 'docs/archive/car-dealer/inc/customer-workflow.php';
$account_actor = $make_user( 'account_paging', 'subscriber', 0 );
$account_user = get_userdata( $account_actor );
wp_set_current_user( $account_actor );
$paging_ids = array();
for ( $i=0; $i<12; ++$i ) {
	$result = $crm_submit( array_replace( $crm_input, array( 'name'=>$account_user->display_name, 'email'=>$account_user->user_email, 'message'=>'PAGING-' . $i, 'idempotency_key'=>wp_generate_uuid4() ) ) );
	$paging_ids[] = $result['legacy_request_id'];
}
$first = adc_customer_request_page( 'messages', 1 ); $second = adc_customer_request_page( 'messages', 2 );
adc_check( ! is_wp_error( $first ) && ! is_wp_error( $second ) && 10 === count( $first['items'] ) && $first['has_more'] && 2 === count( $second['items'] ) && ! $second['has_more'] && ! array_intersect( array_column( $first['items'], 'id' ), array_column( $second['items'], 'id' ) ), 'Customer pagination returns ten owned rows, a next-page indicator, and two distinct rows on page two.' );
wp_set_current_user( $sales_b );
$staff_account_page = adc_customer_request_page( 'messages' );
adc_check( ! is_wp_error( $staff_account_page ) && array() === $staff_account_page['items'], 'Matching branch staff do not inherit customer-account request ownership.' );
// Counts and rows must share the same branch/owner predicate.
wp_set_current_user( $admin );
$admin_user = wp_get_current_user(); $had_theme_cap = $admin_user->has_cap( 'manage_car_dealer' ); $admin_user->add_cap( 'manage_car_dealer' );
foreach ( array( 'message','booking' ) as $type ) { delete_option( 'cd_customer_link_cursor_' . $type ); }
$legacy_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='cd_crm'" );
$with_sql_failure( static fn( $sql ) => str_starts_with( $sql, "SELECT id FROM $crm_leads WHERE legacy_request_type=" ), static function () { car_dealer_reconcile_customer_requests(); } );
adc_check( 0 === (int) get_option( 'cd_customer_link_cursor_message' ), 'Reconciliation lookup failure leaves the cursor unchanged for a safe retry.' );
car_dealer_reconcile_customer_requests();
adc_check( (int) get_option( 'cd_customer_link_cursor_message' ) >= max( $paging_ids ) && $legacy_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='cd_crm'" ), 'Legacy reconciliation advances over core-owned account requests without duplicate profiles.' );
if ( ! $had_theme_cap ) { $admin_user->remove_cap( 'manage_car_dealer' ); }
$sales_user = get_userdata( $sales_b ); $sales_had_cap = $sales_user->has_cap( 'manage_car_dealer' ); $sales_user->add_cap( 'manage_car_dealer' );
LeadService::assign( (int) RequestWorkflow::linked_lead( 'message', $paging_ids[0] ), $sales_b );
wp_set_current_user( $sales_b );
$predicate = RequestWorkflow::staff_predicate( 'message', 'r.id' );
$visible = $wpdb->get_col( "SELECT r.id FROM $crm_messages r WHERE $predicate ORDER BY r.id DESC LIMIT 20" );
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $crm_messages r WHERE $predicate" );
adc_check( in_array( (string) $paging_ids[0], $visible, true ) && ! in_array( (string) $paging_ids[1], $visible, true ) && count( $visible ) === $total, 'Legacy request count and page rows respect assigned ownership in the same branch.' );
// Exercise the actual renderer across its twenty-row boundary.
wp_set_current_user( 0 );
for ( $i=0; $i<23; ++$i ) {
	$owned = $crm_submit( array_replace( $crm_input, array( 'message'=>'STAFF-PAGE-' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ), 'idempotency_key'=>wp_generate_uuid4() ) ) );
	wp_set_current_user( $admin ); LeadService::assign( $owned['id'], $sales_b ); wp_set_current_user( 0 );
}
wp_set_current_user( $sales_b );
$staff_first = EngagementQuery::page( 'message', 1 );
$staff_second = EngagementQuery::page( 'message', 2 );
$_GET = array( 'paged'=>1 ); ob_start(); \AutoDealership\Admin\EngagementPages::render_messages(); $page_one = ob_get_clean();
$_GET = array( 'paged'=>2 ); ob_start(); \AutoDealership\Admin\EngagementPages::render_messages(); $page_two = ob_get_clean(); $_GET = array();
$staff_messages = static fn( array $items ): int => count( array_filter( $items, static fn( array $row ): bool => str_starts_with( (string) $row['message'], 'STAFF-PAGE-' ) ) );
adc_check( ! is_wp_error( $staff_first ) && ! is_wp_error( $staff_second ) && 20 === count( $staff_first['items'] ) && 20 === $staff_messages( $staff_first['items'] ) && 3 === $staff_messages( $staff_second['items'] ) && ! array_intersect( array_column( $staff_first['items'], 'id' ), array_column( $staff_second['items'], 'id' ) ) && str_contains( $page_one, 'STAFF-PAGE-22' ) && str_contains( $page_two, 'STAFF-PAGE-00' ) && str_contains( $page_one, 'page-numbers' ) && ! str_contains( $page_one . $page_two, 'PAGING-1<' ), 'Plugin-owned scoped staff renderer paginates twenty recent rows, exposes the next page and excludes unassigned account messages.' );
if ( ! $sales_had_cap ) { $sales_user->remove_cap( 'manage_car_dealer' ); }
wp_set_current_user( $admin );
