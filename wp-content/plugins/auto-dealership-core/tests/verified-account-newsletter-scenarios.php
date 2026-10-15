<?php
/** Reuse only an authenticated owner's positive proof of the current email address. */
use AutoDealership\Accounts\EmailVerification as AccountProof;
use AutoDealership\Leads\NewsletterService as AccountNewsletter;
use AutoDealership\Leads\CustomerIdentity as AccountPreferences;
use AutoDealership\Operations\OutboxService as AccountOutbox;

if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
global $wpdb;
$wpdb->query( 'DELETE FROM ' . \AutoDealership\Security\PublicRequestGuard::table() . " WHERE policy_key='intake'" );
wp_set_current_user( 0 );
$_POST = array( 'display_name'=>'Verified newsletter owner', 'email'=>'verified-newsletter-owner@example.invalid', 'password'=>'StrongPass123!test', 'password_confirm'=>'StrongPass123!test', 'privacy_consent'=>'1', 'phone'=>'+966505555557' );
$owner = $register->invoke( null ); AccountOutbox::process_due( 100, array( 'email.verify' ) );
$owner_token = $verification_token( end( $captured ) );
$check( ! is_wp_error( AccountProof::confirm( $owner_token, 'StrongPass123!test' ) ), 'Owner activates account before requesting marketing subscription' );
$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AccountNewsletter::subscribers() . " WHERE email='verified-newsletter-owner@example.invalid'" ), 'Account activation alone does not subscribe the owner' );

// Typing a verified account's address while anonymous is not proof of ownership.
$before = count( $captured );
$check( $subscribe( 'verified-newsletter-owner@example.invalid', 'en', false )['success'], 'Anonymous request for an existing account address is accepted uniformly' );
$guest_token = $verification_token( end( $captured ) );
$guest_row = $wpdb->get_row( "SELECT * FROM " . AccountNewsletter::subscribers() . " WHERE email='verified-newsletter-owner@example.invalid'", ARRAY_A );
$old_challenge = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AccountProof::table() . " WHERE purpose='newsletter' AND subject_id=%d", $guest_row['id'] ), ARRAY_A );
$check( 'pending' === $guest_row['status'] && count( $captured ) === $before + 1 && $guest_token, 'Guest must confirm even when the address belongs to a verified account' );

// Authenticated footer uses the locked account email, not the submitted email.
wp_set_current_user( $owner );
update_user_meta( $owner, 'adc_preferred_language', 'en' );
$check( AccountProof::current_account_verified_for( 'verified-newsletter-owner@example.invalid' ) && ! AccountProof::current_account_verified_for( 'other-owner@example.invalid' ), 'Positive account proof is restricted to the signed-in owner and current address' );
$before = count( $captured );
$result = $subscribe( 'spoofed-address@example.invalid', 'ar', false );
$active = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AccountNewsletter::subscribers() . ' WHERE id=%d', $guest_row['id'] ), ARRAY_A );
$check( $result['success'] && 'active' === $active['status'] && 'verified_account' === $active['confirmation_source'] && (int) $active['confirmation_user_id'] === $owner && $active['confirmed_at'], 'Authenticated footer upgrades pending consent using the current account proof' );
$check( count( $captured ) === $before + 1 && str_contains( end( $captured )['subject'], 'Welcome to the ' . \AutoDealership\Core\ProductSetup::brand( 'en' ) . ' newsletter' ) && 'en' === $active['locale'], 'Footer sends only one welcome in the saved account language' );
$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AccountNewsletter::subscribers() . " WHERE email='spoofed-address@example.invalid'" ), 'Footer cannot subscribe a different submitted address for the owner' );
$check( $active['consent_key'] !== $guest_row['consent_key'] && is_wp_error( AccountProof::confirm( $guest_token ) ), 'Account-based activation rotates consent and invalidates the old guest link' );
$before = count( $captured );
$check( true === AccountProof::send( array( 'subject_id'=>$old_challenge['id'], 'correlation_id'=>$old_challenge['generation'] ) ) && count( $captured ) === $before, 'Previously queued guest confirmation cannot send after account-based activation' );
$subscribe( 'ignored@example.invalid', 'en', false ); AccountPreferences::update_preferences( true, 'en' );
AccountOutbox::process_due( 100, array( 'email.verify', 'newsletter.subscription' ) );
$check( count( $captured ) === $before, 'Repeated footer and account saves do not send duplicate welcomes or confirmations' );

// Opt-out persists through login and proof checks until another explicit consent.
AccountPreferences::update_preferences( false, 'en' );
do_action( 'wp_login', get_userdata( $owner )->user_login, get_userdata( $owner ) );
$check( 'unsubscribed' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . AccountNewsletter::subscribers() . ' WHERE id=%d', $active['id'] ) ), 'Logging in does not revive an unsubscribed owner' );
$before = count( $captured ); $renewed = AccountPreferences::update_preferences( true, 'ar' );
AccountOutbox::process_due( 100, array( 'email.verify', 'newsletter.subscription' ) );
$check( ! is_wp_error( $renewed ) && $renewed['newsletter_active'] && count( $captured ) === $before + 1 && str_contains( end( $captured )['subject'], 'أهلًا بك' ), 'Explicit account re-opt-in activates and sends an Arabic welcome without another challenge' );

// Change the database email without refreshing the user cache to test a stale session.
$wpdb->update( $wpdb->users, array( 'user_email'=>'changed-proof-owner@example.invalid' ), array( 'ID'=>$owner ) );
$check( ! AccountProof::current_account_verified_for( 'changed-proof-owner@example.invalid' ), 'Proof of an old email cannot authorize the newly changed address' );
$before = count( $captured );
$response = $subscribe( 'verified-newsletter-owner@example.invalid', 'ar', false );
$changed = $wpdb->get_row( "SELECT * FROM " . AccountNewsletter::subscribers() . " WHERE email='changed-proof-owner@example.invalid'", ARRAY_A );
$check( $response['success'] && $changed && 'pending' === $changed['status'] && count( $captured ) === $before + 1 && str_contains( end( $captured )['subject'], 'تأكيد الاشتراك' ), 'Footer rereads the account address and requires proof after a change, even with a cached old user object' );
$wpdb->update( $wpdb->users, array( 'user_email'=>'verified-newsletter-owner@example.invalid' ), array( 'ID'=>$owner ) ); clean_user_cache( $owner );
wp_set_current_user( 0 ); wp_set_current_user( $owner );

// Fail enqueue after the active-state write: every state change must roll back.
AccountPreferences::update_preferences( false, 'ar' );
$before_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AccountNewsletter::subscribers() . ' WHERE id=%d', $active['id'] ), ARRAY_A );
$suppressed_errors = $wpdb->suppress_errors( true );
$trigger = 'test_verified_newsletter_enqueue_failure';
try {
	$created = $wpdb->query( "CREATE TRIGGER $trigger BEFORE INSERT ON " . AccountOutbox::table() . " FOR EACH ROW BEGIN IF NEW.event_key='newsletter.subscription' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated newsletter queue failure'; END IF; END" );
	$check( false !== $created, 'Isolated enqueue failure fixture installed' );
	$failed = AccountPreferences::update_preferences( true, 'en' );
	$after_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AccountNewsletter::subscribers() . ' WHERE id=%d', $active['id'] ), ARRAY_A );
	$check( is_wp_error( $failed ) && $after_row === $before_row && ! AccountPreferences::current_preferences()['consent_marketing'], 'Failed welcome enqueue rolls back activation, proof metadata, language and account consent' );
} finally { $wpdb->query( "DROP TRIGGER IF EXISTS $trigger" ); $wpdb->suppress_errors( $suppressed_errors ); }

// The account exemption for legacy/pending users must never be treated as positive proof.
wp_set_current_user( 0 );
$_POST = array( 'display_name'=>'Pending newsletter owner', 'email'=>'pending-account-newsletter@example.invalid', 'password'=>'StrongPass123!test', 'password_confirm'=>'StrongPass123!test', 'privacy_consent'=>'1', 'phone'=>'+966505555558' );
$pending_owner = $register->invoke( null ); AccountOutbox::process_due( 100, array( 'email.verify' ) );
wp_set_current_user( $pending_owner );
$prefs = AccountPreferences::update_preferences( true, 'en' );
$check( ! is_wp_error( $prefs ) && 'pending' === $prefs['subscription_status'] && ! AccountProof::current_account_verified_for( 'pending-account-newsletter@example.invalid' ), 'Incomplete account registration does not grant direct newsletter activation' );
wp_set_current_user( $legacy_account );
$check( ! AccountProof::blocked( $legacy_account ) && ! AccountProof::current_account_verified_for( 'legacy-verification@example.invalid' ), 'Legacy login exemption is never mistaken for verified email ownership' );
wp_set_current_user( $admin ); $_REQUEST['lang']='en';
ob_start(); \AutoDealership\Admin\NewsletterPage::render(); $proof_ui = ob_get_clean();
$check( str_contains( $proof_ui, 'Verified account email' ) && str_contains( $proof_ui, 'Newsletter confirmation link' ), 'Dashboard distinguishes account proof from a newsletter link' );
wp_set_current_user( 0 );
