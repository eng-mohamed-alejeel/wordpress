<?php
/** Complete real-theme customer journey, opt in with ADC_BROWSER_JOURNEY=1. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Database\Schema;
wp_set_current_user( $admin );
$journey_password = wp_generate_password( 28, true, false );
$journey_user = wp_insert_user( array( 'user_login'=>'browser_customer', 'user_pass'=>$journey_password, 'user_email'=>'browser@example.invalid', 'display_name'=>'عميل الاختبار', 'role'=>'subscriber' ) );
if ( is_wp_error( $journey_user ) ) { throw new RuntimeException( 'Browser account fixture failed.' ); }
update_user_meta( $journey_user, 'car_dealer_phone', '+966501111111' );
$journey_legacy_profile = wp_insert_post( array( 'post_type'=>'cd_crm', 'post_status'=>'private', 'post_title'=>'Browser legacy profile' ) );
if ( is_wp_error( $journey_legacy_profile ) || $journey_legacy_profile < 1 ) { throw new RuntimeException( 'Browser legacy CRM fixture failed.' ); }
foreach ( array( 'user_id'=>$journey_user, 'email'=>'browser@example.invalid', 'phone'=>'+966501111111', 'source'=>'legacy-browser', 'due'=>'2030-01-01T10:00', 'task'=>'Legacy browser follow-up' ) as $key=>$value ) {
	update_post_meta( $journey_legacy_profile, '_crm_' . $key, $value );
}
$journey_staff_password = wp_generate_password( 28, true, false );
wp_set_password( $journey_staff_password, $admin );
update_option( 'adc_default_branch_id', $branch_b['id'] );
delete_transient( 'adc_lead_rate_' . hash_hmac( 'sha256', '127.0.0.1', wp_salt( 'auth' ) ) );
$journey_socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
$journey_address = stream_socket_get_name( $journey_socket, false ); fclose( $journey_socket );
$journey_port = (int) substr( $journey_address, strrpos( $journey_address, ':' ) + 1 );
$journey_log = tempnam( sys_get_temp_dir(), 'adc-journey-http-' );
$journey_server = null;
try {
	$journey_server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $journey_port, __DIR__ . '/http-router.php' ), array( 0=>array( 'pipe','r' ), 1=>array( 'file',$journey_log,'a' ), 2=>array( 'file',$journey_log,'a' ) ), $journey_pipes, ABSPATH, array_merge( getenv(), array( 'ADC_HTTP_TEST_CONFIG'=>ADC_TEST_HTTP_CONFIG, 'ADC_THEME_TEST'=>'1' ) ) );
	if ( ! is_resource( $journey_server ) ) { throw new RuntimeException( 'Theme test server failed.' ); }
	fclose( $journey_pipes[0] );
	$journey_catalog_mode = get_option( 'adc_public_catalog_mode', 'compatibility' );
	update_option( 'adc_public_catalog_mode', 'authoritative' );
	$catalog_browser = proc_open( array( getenv( 'ADC_NODE' ) ?: 'C:/Program Files/nodejs/node.exe', __DIR__ . '/browser-public-catalog.cjs' ), array( 0=>array( 'pipe','r' ), 1=>STDOUT, 2=>STDERR ), $catalog_browser_pipes );
	if ( ! is_resource( $catalog_browser ) ) { throw new RuntimeException( 'Catalog browser worker failed.' ); }
	fwrite( $catalog_browser_pipes[0], wp_json_encode( array( 'origin'=>'http://127.0.0.1:' . $journey_port, 'car_id'=>(int) $catalog_post_id, 'branch_id'=>(int) $branch_b['id'] ) ) );
	fclose( $catalog_browser_pipes[0] );
	$catalog_browser_status = proc_close( $catalog_browser );
	update_option( 'adc_public_catalog_mode', $journey_catalog_mode );
	if ( 0 !== $catalog_browser_status ) { throw new RuntimeException( 'Catalog browser journey failed. Server log: ' . $journey_log ); }
	// Keep the two browser journeys independent from the shared loopback rate-limit bucket.
	$journey_session_key = hash( 'sha256', ADC_TEST_HTTP_CONFIG . '|session' );
	$journey_rate_reset = @file_get_contents( 'http://127.0.0.1:' . $journey_port . '/adc-test-reset-rate?key=' . rawurlencode( $journey_session_key ) );
	if ( 'ok' !== $journey_rate_reset ) { throw new RuntimeException( 'Browser rate-limit reset failed.' ); }
	$browser = proc_open( array( getenv( 'ADC_NODE' ) ?: 'C:/Program Files/nodejs/node.exe', __DIR__ . '/browser-account-journey.cjs' ), array( 0=>array( 'pipe','r' ), 1=>STDOUT, 2=>STDERR ), $browser_pipes );
	if ( ! is_resource( $browser ) ) { throw new RuntimeException( 'Browser worker failed.' ); }
	fwrite( $browser_pipes[0], wp_json_encode( array( 'origin'=>'http://127.0.0.1:' . $journey_port, 'customer'=>'browser_customer', 'password'=>$journey_password, 'staff'=>'verify_admin', 'staff_password'=>$journey_staff_password, 'car_id'=>$crm_car ) ) ); fclose( $browser_pipes[0] );
	if ( 0 !== proc_close( $browser ) ) { throw new RuntimeException( 'Browser journey failed. Server log: ' . $journey_log ); }
	$journey_contacts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'customers' ) . ' WHERE account_user_id=%d', $journey_user ) );
	adc_check( 1 === $journey_contacts, 'Real theme browser enquiries reuse exactly one canonical customer.' );
	$journey_customer = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'customers' ) . ' WHERE account_user_id=%d', $journey_user ) );
	adc_check( $journey_customer === (int) get_post_meta( $journey_legacy_profile, '_crm_retired_core_customer_id', true ) && ! metadata_exists( 'post', $journey_legacy_profile, '_crm_due' ) && ! metadata_exists( 'post', $journey_legacy_profile, '_crm_task' ), 'Real theme linkage retires the account legacy CRM profile without contact matching.' );
	adc_check( '0' === $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='cd_crm' AND post_title IN ('عميل الاختبار','عميل محدث')" ), 'Real login and profile edit create no duplicate legacy CRM profile.' );
} finally {
	if ( isset( $journey_catalog_mode ) ) { update_option( 'adc_public_catalog_mode', $journey_catalog_mode ); }
	if ( is_resource( $journey_server ) ) { proc_terminate( $journey_server ); proc_close( $journey_server ); }
}
echo "Account journey server stopped. Log: $journey_log\n";
