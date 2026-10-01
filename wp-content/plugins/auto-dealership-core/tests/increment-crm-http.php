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
adc_check( 403 === $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', array_replace( $crm_http_form, array( 'nonce'=>'invalid' ) ), true )[0] && $crm_before === $crm_counts(), 'Theme AJAX rejects a bad form nonce without creating CRM records.' );
list( $crm_status, $crm_body ) = $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', $crm_http_form, true );
adc_check( 200 === $crm_status && true === ( json_decode( $crm_body, true )['success'] ?? false ), 'Actual theme contact handler accepts an authenticated nonce-protected form.' );
$crm_http_row = $wpdb->get_row( "SELECT * FROM $crm_messages ORDER BY id DESC LIMIT 1", ARRAY_A );
adc_check( 'crm-new@example.invalid' === $crm_http_row['email'] && $crm_account === (int) $crm_http_row['user_id'] && '+966501234567' === $crm_http_row['phone'] && 'Forged form name' !== $crm_http_row['name'], 'Theme AJAX replaces forged contact fields with the authenticated profile.' );
$crm_http_lead = RequestWorkflow::linked_lead( 'message', (int) $crm_http_row['id'] );
$crm_after = $crm_counts();
adc_check( 200 === $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', $crm_http_form, true )[0] && $crm_after === $crm_counts(), 'Repeated theme AJAX form does not duplicate the message or customer.' );
$crm_request_path = $rest_path( '/auto-dealership/v1/leads/' . $crm_http_lead . '/request' );
adc_check( 401 === $http_request( $crm_request_path )[0] && 403 === $http_request( $crm_request_path, $crm_http_auth )[0] && 404 === $http_request( $crm_request_path, $sales_a_http )[0], 'HTTP request details reject anonymous, customer and foreign-branch staff access.' );
list( $crm_status, $crm_body ) = $http_request( $crm_request_path, $admin_http );
$crm_http_state = json_decode( $crm_body, true );
adc_check( 200 === $crm_status && isset( $crm_http_state['revision'] ), 'HTTP administrator reads the linked request revision.' );
$crm_http_patch = array( 'revision'=>$crm_http_state['revision'], 'status'=>'read', 'customer_reply'=>'HTTP visible reply' );
adc_check( 403 === $http_request( $crm_request_path, array_replace( $admin_http, array( 'nonce'=>'bad' ) ), 'PATCH', $crm_http_patch )[0], 'HTTP request PATCH requires a valid REST nonce.' );
adc_check( 200 === $http_request( $crm_request_path, $admin_http, 'PATCH', $crm_http_patch )[0] && 409 === $http_request( $crm_request_path, $admin_http, 'PATCH', $crm_http_patch )[0], 'HTTP request PATCH persists once and rejects the stale revision.' );
$crm_activity_path = $rest_path( '/auto-dealership/v1/leads/' . $crm_http_lead . '/activities' );
adc_check( 200 === $http_request( $crm_activity_path, $admin_http )[0] && str_contains( $http_request( $crm_activity_path, $admin_http )[1], 'HTTP visible reply' ) && 404 === $http_request( $crm_activity_path, $sales_a_http )[0], 'HTTP activity history includes the reply only within authorized scope.' );
$crm_staff_nonce = trim( $http_request( '/adc-test-nonce?action=adc_update_request_' . $crm_http_lead, $admin_http )[1] );
$crm_admin_form = array( 'action'=>'adc_update_request', 'lead_id'=>$crm_http_lead, 'revision'=>RequestWorkflow::read( $crm_http_lead )['revision'], 'status'=>'completed', '_wpnonce'=>'invalid' );
adc_check( 403 === $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', $crm_admin_form, true )[0], 'Admin request form rejects an invalid action nonce.' );
$crm_admin_form['_wpnonce'] = $crm_staff_nonce;
adc_check( 302 === $http_request( '/wp-admin/admin-post.php', $admin_http, 'POST', $crm_admin_form, true )[0] && 'completed' === RequestWorkflow::read( $crm_http_lead )['status'], 'Admin request form saves and redirects with the correct action nonce.' );

$crm_booking_form = array_replace( $crm_http_form, array( 'action'=>'car_dealer_booking', 'car_id'=>$crm_car, 'date'=>wp_date( 'Y-m-d', time() + 4 * DAY_IN_SECONDS ), 'time'=>'11:30', 'idempotency_key'=>wp_generate_uuid4() ) );
list( $crm_status, $crm_body ) = $http_request( '/wp-admin/admin-ajax.php', $crm_http_auth, 'POST', $crm_booking_form, true );
$crm_http_booking = json_decode( $crm_body, true );
adc_check( 200 === $crm_status && ! empty( $crm_http_booking['data']['booking_id'] ), 'Actual theme booking form returns the compatibility booking ID.' );
$crm_cancel_path = $rest_path( '/auto-dealership/v1/bookings/' . $crm_http_booking['data']['booking_id'] . '/cancel' );
adc_check( 401 === $http_request( $crm_cancel_path, array(), 'POST', array() )[0] && 404 === $http_request( $crm_cancel_path, $sales_b_http, 'POST', array() )[0], 'HTTP booking cancellation rejects anonymous and nonowner actors.' );
adc_check( 200 === $http_request( $crm_cancel_path, $crm_http_auth, 'POST', array() )[0] && false === ( json_decode( $http_request( $crm_cancel_path, $crm_http_auth, 'POST', array() )[1], true )['updated'] ?? null ), 'HTTP owner cancellation succeeds once and is safely repeatable.' );

wp_set_current_user( $admin );
$crm_http_pair = $crm_pair();
$crm_merge_path = $rest_path( '/auto-dealership/v1/customers/merge' );
$crm_merge_query = '&source_id=' . $crm_http_pair[0]['customer'] . '&target_id=' . $crm_http_pair[1]['customer'];
adc_check( 401 === $http_request( $crm_merge_path . $crm_merge_query )[0] && 403 === $http_request( $crm_merge_path . $crm_merge_query, $sales_b_http )[0], 'HTTP identity preview is restricted to administrators.' );
list( $crm_status, $crm_body ) = $http_request( $crm_merge_path . $crm_merge_query, $admin_http );
$crm_http_preview = json_decode( $crm_body, true );
adc_check( 200 === $crm_status && isset( $crm_http_preview['revision'] ), 'HTTP administrator receives a reviewable consolidation preview.' );
$crm_merge_payload = array( 'source_id'=>$crm_http_pair[0]['customer'], 'target_id'=>$crm_http_pair[1]['customer'], 'revision'=>$crm_http_preview['revision'], 'evidence'=>'HTTP-IDENTITY-REVIEW', 'verified'=>false );
adc_check( 400 === $http_request( $crm_merge_path, $admin_http, 'POST', $crm_merge_payload )[0], 'HTTP consolidation requires explicit confirmation of independent identity review.' );
$crm_merge_payload['verified'] = true;
adc_check( 403 === $http_request( $crm_merge_path, array_replace( $admin_http, array( 'nonce'=>'bad' ) ), 'POST', $crm_merge_payload )[0] && 403 === $http_request( $crm_merge_path, $sales_b_http, 'POST', $crm_merge_payload )[0], 'HTTP consolidation rejects invalid nonce and staff privilege escalation.' );
adc_check( 200 === $http_request( $crm_merge_path, $admin_http, 'POST', $crm_merge_payload )[0] && 409 === $http_request( $crm_merge_path, $admin_http, 'POST', $crm_merge_payload )[0], 'Reviewed HTTP consolidation commits once; a repeated merge conflicts.' );

// Render actual admin forms and verify output escaping, without claiming a full-theme visual test.
$crm_render_pair = $crm_pair();
$_GET = array( 'source_id'=>$crm_render_pair[0]['customer'], 'target_id'=>$crm_render_pair[1]['customer'] );
$wpdb->update( Schema::table( 'customers' ), array( 'full_name'=>'<img src=x onerror=alert(1)>' ), array( 'id'=>$crm_render_pair[0]['customer'] ) );
ob_start(); AutoDealership\Admin\CustomerIdentityPage::render(); $crm_admin_html = ob_get_clean();
adc_check( str_contains( $crm_admin_html, 'name="revision"' ) && str_contains( $crm_admin_html, 'name="verified"' ) && str_contains( $crm_admin_html, '&lt;img' ) && ! str_contains( $crm_admin_html, '<img src=x' ), 'Identity admin preview escapes customer content and includes revision, nonce and review controls.' );
$_GET = array();
ob_start(); AutoDealership\Admin\RequestPage::render( $crm_http_lead ); $crm_admin_html = ob_get_clean();
adc_check( str_contains( $crm_admin_html, 'name="revision"' ) && str_contains( $crm_admin_html, 'name="_wpnonce"' ) && str_contains( $crm_admin_html, 'HTTP visible reply' ), 'Request admin form renders the current reply and protected revision controls.' );
