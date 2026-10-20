<?php
/** Additional security scenarios in the disposable newsletter database. */
use AutoDealership\Accounts\EmailVerification as Verification;
use AutoDealership\Accounts\CustomerAccount;
use AutoDealership\Leads\NewsletterService as VerificationNewsletter;
use AutoDealership\Database\Transaction;

if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
global $wpdb;
wp_set_current_user( 0 );
$before = count( $captured );
$pending_response = $subscribe( 'pending-security@example.invalid', 'en', false );
$pending_mail = end( $captured ); $pending_token = $verification_token( $pending_mail );
$pending = $wpdb->get_row( "SELECT * FROM " . VerificationNewsletter::subscribers() . " WHERE email='pending-security@example.invalid'", ARRAY_A );
wp_clear_scheduled_hook( 'adc_mail_wake' ); wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'adc_mail_wake' );
VerificationNewsletter::wake();
$check( wp_next_scheduled( 'adc_mail_wake' ) <= time(), 'A new verification can wake mail processing ahead of an older backoff timer' );
$challenge = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Verification::table() . " WHERE purpose='newsletter' AND subject_id=%d", $pending['id'] ), ARRAY_A );
$check( ! empty( $pending_response['success'] ) && $pending['status'] === 'pending' && ! $pending['confirmed_at'] && ! $pending['welcome_sent_at'], 'Pending subscriber has neither confirmation nor a welcome marker' );
$check( $pending_token && ! str_contains( wp_json_encode( $challenge ), explode( '.', $pending_token )[1] ), 'Database stores only a proof hash and reconstructible references' );
$outbox_payload = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . \AutoDealership\Operations\OutboxService::table() . " WHERE event_key='email.verify' AND JSON_EXTRACT(payload,'$.subject_id')=%d ORDER BY id DESC LIMIT 1", $challenge['id'] ) );
$check( ! str_contains( $outbox_payload, $pending_token ) && ! str_contains( $outbox_payload, 'pending-security@' ) && ! str_contains( $outbox_payload, 'http' ), 'Verification outbox contains no proof, recipient or link' );
$check( strtotime( $challenge['expires_at'] . ' UTC' ) - strtotime( $challenge['created_at'] . ' UTC' ) === 2 * DAY_IN_SECONDS, 'Newsletter proof expires after 48 hours' );
$check( str_contains( $pending_mail['message'], 'lang="en"' ) && str_contains( $pending_mail['message'], '#token=' ), 'English verification email uses a browser fragment for its proof' );
$check( is_wp_error( Verification::confirm( 'invalid' ) ) && is_wp_error( Verification::confirm( $pending_token . 'x' ) ), 'Malformed and oversized proofs are rejected' );
$check( is_wp_error( Verification::confirm( $pending_token, '', 'account' ) ), 'Newsletter proof cannot be used on an account activation endpoint' );
$forged = substr( $pending_token, 0, -1 ) . ( str_ends_with( $pending_token, 'A' ) ? 'B' : 'A' );
$check( is_wp_error( Verification::confirm( $forged ) ) && 'pending' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . VerificationNewsletter::subscribers() . ' WHERE id=%d', $pending['id'] ) ), 'A forged proof cannot activate the recipient' );
$wpdb->update( Verification::table(), array( 'generation'=>wp_generate_uuid4() ), array( 'id'=>$challenge['id'] ) );
$check( is_wp_error( Verification::confirm( $pending_token ) ), 'Proof is cryptographically bound to the current challenge generation' );
$wpdb->update( Verification::table(), array( 'generation'=>$challenge['generation'] ), array( 'id'=>$challenge['id'] ) );
wp_set_current_user( $admin );
$security_campaign = VerificationNewsletter::save( array( 'subject'=>'Verification audience', 'body'=>'Confirmed audience only' ) );
VerificationNewsletter::queue( $security_campaign );
$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VerificationNewsletter::table( 'deliveries' ) . ' WHERE campaign_id=%d AND subscriber_id=%d', $security_campaign, $pending['id'] ) ), 'Pending address is excluded from the campaign snapshot' );
VerificationNewsletter::cancel( $security_campaign );
wp_set_current_user( 0 );
$before = count( $captured );
$check( true === Verification::request( 'newsletter', 'pending-security@example.invalid' ) && count( $captured ) === $before, 'Resend cooldown returns uniform success without another email' );
$check( true === Verification::request( 'newsletter', 'missing-security@example.invalid' ) && count( $captured ) === $before, 'Unknown address produces the same public response without mail' );
$wpdb->update( Verification::table(), array( 'created_at'=>'2000-01-01 00:00:00' ), array( 'id'=>$challenge['id'] ) );
Verification::request( 'newsletter', 'pending-security@example.invalid' );
\AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) );
$replacement_token = $verification_token( end( $captured ) );
$check( $replacement_token !== $pending_token && count( $captured ) === $before + 1, 'Resend creates a fresh proof and sends one message' );
$check( is_wp_error( Verification::confirm( $pending_token ) ), 'Resend invalidates the preceding link' );
$before = count( $captured );
\AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) );
$check( count( $captured ) === $before, 'Stale queued challenges and accepted confirmation mails are not resent' );
$wpdb->update( Verification::table(), array( 'expires_at'=>'2000-01-01 00:00:00' ), array( 'id'=>$challenge['id'] ) );
$check( is_wp_error( Verification::confirm( $replacement_token ) ), 'An expired newsletter link is rejected' );
$wpdb->update( Verification::table(), array( 'created_at'=>'2000-01-01 00:00:00', 'requests'=>5, 'window_started'=>current_time( 'mysql', true ) ), array( 'id'=>$challenge['id'] ) );
$before = count( $captured ); Verification::request( 'newsletter', 'pending-security@example.invalid' );
$check( count( $captured ) === $before, 'The per-address hourly resend limit is enforced' );
$wpdb->update( Verification::table(), array( 'window_started'=>'2000-01-01 00:00:00' ), array( 'id'=>$challenge['id'] ) );
Verification::request( 'newsletter', 'pending-security@example.invalid' ); \AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) ); $replacement_token = $verification_token( end( $captured ) );
$check( ! is_wp_error( Verification::confirm( $replacement_token ) ), 'Expired request can be recovered using a newly issued link' );
$verified = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VerificationNewsletter::subscribers() . ' WHERE id=%d', $pending['id'] ), ARRAY_A );
$check( 'active' === $verified['status'] && $verified['confirmed_at'] && $verified['welcome_sent_at'], 'Confirmation records its timestamp and sends the welcome' );
$check( is_wp_error( Verification::confirm( $replacement_token ) ), 'Newsletter confirmation is single use' );

// A newly registered account cannot bypass verification via password reset or cookies.
$_POST = array( 'display_name'=>'Security account', 'email'=>'account-security@example.invalid', 'password'=>'StrongPass123!test', 'password_confirm'=>'StrongPass123!test', 'privacy_consent'=>'1', 'phone'=>'+966505555553' );
$security_account = $register->invoke( null ); \AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) ); $activation_token = $verification_token( end( $captured ) );
$account_challenge = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Verification::table() . " WHERE purpose='account' AND subject_id=%d", $security_account ), ARRAY_A );
$check( strtotime( $account_challenge['expires_at'] . ' UTC' ) - strtotime( $account_challenge['created_at'] . ' UTC' ) === DAY_IN_SECONDS, 'Account proof expires after 24 hours' );
$check( 0 === Verification::current_user( $security_account ) && ! apply_filters( 'send_auth_cookies', true, 0, 0, $security_account ), 'Pending account cannot be restored from a cookie or receive authentication cookies' );
$check( ! user_can( $security_account, 'read' ), 'Pending accounts have no protected capabilities even if a user object is cached' );
$account_user = get_userdata( $security_account );
reset_password( $account_user, 'NewStrongPass123!' );
$check( Verification::blocked( $security_account ) && is_wp_error( wp_authenticate( $account_user->user_login, 'NewStrongPass123!' ) ), 'Password reset does not activate a pending account' );
$check( ! is_wp_error( Verification::confirm( $activation_token ) ), 'Valid activation proof works without a password after a reset' );
$check( ! is_wp_error( wp_authenticate( $account_user->user_login, 'NewStrongPass123!' ) ), 'Verified customer can authenticate normally' );
$check( ! Verification::blocked( $admin ), 'Legacy administrator remains unaffected by verification policy' );
$default_registration = apply_filters( 'registration_errors', new WP_Error(), 'default_route_test', 'default-route@example.invalid' );
$check( is_wp_error( $default_registration ) && 'adc_registration_route' === $default_registration->get_error_code(), 'WordPress default public registration cannot bypass the verified customer signup route' );
$before = count( $captured );
$_POST = array( 'display_name'=>'Duplicate request', 'email'=>'account-security@example.invalid', 'password'=>'StrongPass123!test', 'password_confirm'=>'StrongPass123!test', 'privacy_consent'=>'1', 'phone'=>'+966505555553' );
$duplicate = $register->invoke( null );
$check( is_wp_error($duplicate) && 'adc_account_exists'===$duplicate->get_error_code() && CustomerAccount::account_exists() && count( $captured ) === $before && wp_check_password( 'NewStrongPass123!', get_userdata( $security_account )->user_pass ), 'Repeated registration reports an existing account without sending mail or overwriting credentials' );
$wpdb->update( Verification::table(), array( 'created_at'=>'2000-01-01 00:00:00' ), array( 'id'=>$account_challenge['id'] ) );
add_filter( 'send_email_change_email', '__return_false' );
wp_update_user( array( 'ID'=>$security_account, 'user_email'=>'account-security-new@example.invalid' ) );
remove_filter( 'send_email_change_email', '__return_false' );
\AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) );
$changed_email_token = $verification_token( end( $captured ) );
$check( Verification::blocked( $security_account ) && $changed_email_token !== $activation_token, 'Changing a managed account email requires a new confirmation' );
$check( is_wp_error( Verification::confirm( $activation_token, 'NewStrongPass123!' ) ) && ! is_wp_error( Verification::confirm( $changed_email_token, 'NewStrongPass123!' ) ), 'Old email proof cannot activate the new address' );
$check( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VerificationNewsletter::subscribers() . ' WHERE email=%s', 'account-security-new@example.invalid' ) ) === 0, 'Account verification never grants marketing consent' );

// A cancelled pending opt-in revokes its proof through the consent/state check.
$legacy_account = wp_insert_user( array( 'user_login'=>'legacy_verification_test', 'user_email'=>'legacy-verification@example.invalid', 'user_pass'=>'StrongPass123!test', 'role'=>'car_dealer_customer' ) );
wp_set_current_user( $legacy_account );
$prefs = \AutoDealership\Leads\CustomerIdentity::update_preferences( true, 'ar' );
\AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) );
$withdrawn_token = $verification_token( end( $captured ) );
$check( ! is_wp_error( $prefs ) && 'pending' === $prefs['subscription_status'], 'Legacy account without email proof still needs newsletter confirmation' );
\AutoDealership\Leads\CustomerIdentity::update_preferences( false, 'ar' );
$check( is_wp_error( Verification::confirm( $withdrawn_token ) ), 'Withdrawing consent invalidates the pending subscription link' );
wp_set_current_user( 0 );
$reject_mail = true;
$check( $subscribe( 'mail-failure-security@example.invalid', 'ar', false )['success'], 'SMTP failure does not discard a valid subscription request' );
$failed_row = $wpdb->get_row( "SELECT * FROM " . VerificationNewsletter::subscribers() . " WHERE email='mail-failure-security@example.invalid'", ARRAY_A );
$failed_challenge = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Verification::table() . " WHERE purpose='newsletter' AND subject_id=%d", $failed_row['id'] ), ARRAY_A );
$check( 'pending' === $failed_row['status'] && ! $failed_challenge['sent_at'], 'SMTP failure keeps subscription pending and proof unsent' );
$reject_mail = false;
$wpdb->update( VerificationNewsletter::subscribers(), array( 'locale'=>'en' ), array( 'id'=>$failed_row['id'] ) );
$wpdb->query( 'UPDATE ' . \AutoDealership\Operations\OutboxService::table() . " SET next_attempt_at='2000-01-01 00:00:00' WHERE event_key='email.verify' AND status='retry'" );
\AutoDealership\Operations\OutboxService::process_due( 100, array( 'email.verify' ) );
$check( $wpdb->get_var( $wpdb->prepare( 'SELECT sent_at FROM ' . Verification::table() . ' WHERE id=%d', $failed_challenge['id'] ) ) && 'pending' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . VerificationNewsletter::subscribers() . ' WHERE id=%d', $failed_row['id'] ) ), 'Mail worker recovers confirmation delivery without activating the address' );
$check( str_contains( end( $captured )['subject'], 'Confirm your ' . \AutoDealership\Core\ProductSetup::brand( 'en' ) . ' newsletter' ), 'Retried verification mail follows the latest saved language preference' );
$erased_token = $verification_token( end( $captured ) );
$erased = \AutoDealership\Privacy\PrivacyTools::erase( 'mail-failure-security@example.invalid' );
$check( $erased['items_removed'] && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Verification::table() . ' WHERE id=%d', $failed_challenge['id'] ) ) && is_wp_error( Verification::confirm( $erased_token ) ), 'Privacy erasure removes pending challenges and invalidates their proofs' );
$before = count( $captured );
$check( true === Verification::send( array( 'subject_id'=>$failed_challenge['id'], 'correlation_id'=>$failed_challenge['generation'] ) ) && count( $captured ) === $before, 'A queued reference cannot email an erased verification target' );
$wpdb->insert( VerificationNewsletter::subscribers(), array( 'email'=>'dashboard-pending@example.invalid', 'status'=>'pending', 'locale'=>'ar', 'consent_key'=>wp_generate_uuid4(), 'created_at'=>current_time( 'mysql', true ) ) );
wp_set_current_user( $admin ); $_GET['subscriber_status']='pending';
ob_start(); \AutoDealership\Admin\NewsletterPage::render(); $pending_ui = ob_get_clean(); unset( $_GET['subscriber_status'] );
$check( str_contains( $pending_ui, 'بانتظار التأكيد' ) && str_contains( $pending_ui, 'resend_confirmation' ) && ! str_contains( $pending_ui, 'name="operation" value="verify_subscriber"' ), 'Dashboard exposes pending subscriptions and resends without a manual verification bypass' );
require __DIR__ . '/verified-account-newsletter-scenarios.php';
require __DIR__ . '/email-verification-browser.php';

// Independent PHP connections exercise the target/challenge row locks concurrently.
wp_set_current_user( 0 );
$subscribe( 'database-race-verification@example.invalid', 'ar', false );
$race_token = $verification_token( end( $captured ) );
$barrier = 'adc_gate_' . bin2hex( random_bytes( 8 ) );
$workers = array(); $results = array();
$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $barrier ) );
try {
	$config = json_decode( base64_decode( ADC_TEST_HTTP_CONFIG ), true );
	$config['source_database'] = 'non_disposable_source'; $config['scenario']='email_confirm'; $config['actor']=0; $config['input']=array( 'token'=>$race_token ); $config['barrier']=$barrier;
	for ( $i = 0; $i < 2; ++$i ) {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/database-runner.php', '--worker' ), array( 0=>array( 'pipe','r' ), 1=>array( 'pipe','w' ), 2=>array( 'pipe','w' ) ), $pipes, ABSPATH, null, array( 'bypass_shell'=>true ) );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Concurrent verification worker could not start' ); }
		fwrite( $pipes[0], wp_json_encode( $config ) ); fclose( $pipes[0] ); $workers[] = array( $process, $pipes );
	}
	usleep( 500000 ); $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $barrier ) );
	foreach ( $workers as list( $process, $pipes ) ) { $output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); $exit = proc_close( $process ); $results[] = 0 === $exit ? json_decode( $output, true ) : null; }
	$workers = array();
	$successful = count( array_filter( $results, static fn( $result ) => is_array( $result ) && ( $result['purpose'] ?? '' ) === 'newsletter' ) );
	$rejected = count( array_filter( $results, static fn( $result ) => is_array( $result ) && ( $result['error'] ?? '' ) === 'adc_verification_invalid' ) );
	$check( 1 === $successful && 1 === $rejected, 'Two independent database connections consume a verification proof exactly once' );
} finally {
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $barrier ) );
	foreach ( $workers as list( $process, $pipes ) ) { if ( is_resource( $process ) ) { proc_terminate( $process ); } }
}
require __DIR__ . '/email-verification-mailpit.php';
require __DIR__ . '/verification-experience-scenarios.php';
