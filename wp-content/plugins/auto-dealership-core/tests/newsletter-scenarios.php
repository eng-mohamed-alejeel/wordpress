<?php
/** Database integration scenarios; never send email or use source subscriber data. */
use AutoDealership\Accounts\CustomerAccount;
use AutoDealership\Accounts\EmailVerification;
use AutoDealership\Core\Capabilities;
use AutoDealership\Leads\LegacyEngagementStore;
use AutoDealership\Leads\MarketingSubscription;
use AutoDealership\Leads\NewsletterService as Newsletter;
use AutoDealership\Operations\OutboxService;

if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
$checks = 0;
$check = static function ( $ok, string $label ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS $label\n"; };
LegacyEngagementStore::boot(); Newsletter::boot();
$admin = get_current_user_id();
$captured = array(); $reject_mail = false;
remove_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_wp_mail', static function ( $result, $mail ) use ( &$captured, &$reject_mail ) { if ( $reject_mail ) { return false; } $captured[] = $mail; return true; }, 10, 2 );
$check( '5' === get_option( 'adc_newsletter_schema' ), 'Newsletter confirmation provenance schema installed' );
$verification_token = static function ( array $mail ): string { preg_match( '/#token=([a-f0-9]{64}\.[A-Za-z0-9_-]{43})/', html_entity_decode( $mail['message'], ENT_QUOTES, 'UTF-8' ), $matches ); return $matches[1] ?? ''; };
$check( in_array( 'adc_manage_newsletter', Capabilities::all_capabilities(), true ), 'Permission is available in role management' );
wp_set_current_user( 0 );

$_POST = array( 'display_name' => 'عميل تجريبي', 'email' => 'welcome@example.invalid', 'password' => 'StrongPass123!test', 'password_confirm' => 'StrongPass123!test', 'privacy_consent' => '1', 'phone' => '+966505555551' );
$register = new ReflectionMethod( CustomerAccount::class, 'register' ); $register->setAccessible( true );
$customer = $register->invoke( null );
$check( count( $captured ) === 0, 'Registration queues verification without blocking on SMTP delivery' );
OutboxService::process_due( 100, array( 'email.verify' ) );
$check( is_int( $customer ), 'Public customer registration succeeds with queued verification' );
$check( get_user_meta( $customer, 'adc_preferred_language', true ) === 'ar', 'Registration saves the customer email language' );
$check( count( $captured ) === 1 && $captured[0]['to'] === 'welcome@example.invalid' && EmailVerification::blocked( $customer ), 'Only verification is sent and new account remains blocked' );
$account_token = $verification_token( $captured[0] );
$login = wp_authenticate( get_userdata( $customer )->user_login, 'StrongPass123!test' );
$check( is_wp_error( $login ) && 'adc_email_pending' === $login->get_error_code(), 'Pending account cannot authenticate through WordPress' );
$check( true === Newsletter::welcome( array( 'subject_id'=>$customer ) ) && count( $captured ) === 1, 'Early welcome worker cannot welcome an unverified account' );
$check( is_wp_error( EmailVerification::confirm( $account_token . 'x' ) ) && EmailVerification::blocked( $customer ), 'Invalid activation proof preserves pending state' );
$check( ! is_wp_error( EmailVerification::confirm( $account_token ) ) && ! EmailVerification::blocked( $customer ), 'Email possession alone activates the account' );
$check( ! is_user_logged_in(), 'Email activation does not create an authenticated session' );
$check( is_wp_error( EmailVerification::confirm( $account_token, 'StrongPass123!test' ) ), 'Consumed activation proof cannot be reused' );
$check( count( $captured ) === 2 && str_contains( $captured[1]['message'], 'عميل تجريبي' ) && ! str_contains( $captured[1]['message'], 'StrongPass' ) && ! str_contains( $captured[1]['message'], 'customer_' ), 'Post-activation welcome is branded and hides credentials and technical login' );
$check( true === Newsletter::welcome( array( 'subject_id' => $customer, 'locale' => 'ar' ) ) && count( $captured ) === 2, 'Welcome retry does not duplicate accepted mail' );

if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new RuntimeException( 'ajax-response' ); } );
$subscribe = static function ( string $email, string $lang = 'ar', bool $confirm = true ) use ( &$captured, $verification_token ): array {
	$_POST = array( 'email' => $email, 'consent_marketing' => '1', 'lang' => $lang, 'nonce' => wp_create_nonce( 'car_dealer_frontend' ) ); $_REQUEST = $_POST;
	ob_start();
	try { MarketingSubscription::handle_theme(); } catch ( RuntimeException $error ) { if ( 'ajax-response' !== $error->getMessage() ) { ob_end_clean(); throw $error; } }
	$result = json_decode( ob_get_clean(), true );
	OutboxService::process_due( 100, array( 'email.verify', 'newsletter.subscription' ) );
	if ( $confirm && ! empty( $result['success'] ) ) {
		global $wpdb; $actual_email = is_user_logged_in() ? wp_get_current_user()->user_email : $email;
		if ( 'pending' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Newsletter::subscribers() . ' WHERE email=%s', $actual_email ) ) ) {
			$confirmed = EmailVerification::confirm( $verification_token( end( $captured ) ) );
			if ( is_wp_error( $confirmed ) ) { throw new RuntimeException( 'Fixture subscription confirmation failed' ); }
		}
	}
	return $result;
};
wp_set_current_user( 0 );
$check( $subscribe( 'first@example.invalid', 'ar', false )['success'], 'Newsletter form creates a pending consented subscription' );
$check( 'pending' === $wpdb->get_var( "SELECT status FROM " . Newsletter::subscribers() . " WHERE email='first@example.invalid'" ), 'New footer subscription awaits confirmation' );
$check( ! is_wp_error( EmailVerification::confirm( $verification_token( end( $captured ) ) ) ), 'Footer email confirmation activates subscription' );
$before = count( $captured );
$check( $subscribe( 'first@example.invalid' )['success'] && count( $captured ) === $before, 'Repeated active subscription does not duplicate welcome' );
$check( $subscribe( 'second@example.invalid', 'en' )['success'], 'English subscription accepted' );
$check( str_contains( end( $captured )['message'], 'Welcome to the ' . \AutoDealership\Core\ProductSetup::brand( 'en' ) . ' newsletter' ), 'Subscription welcome uses requested English language' );
$first = $wpdb->get_row( "SELECT * FROM " . Newsletter::subscribers() . " WHERE email='first@example.invalid'", ARRAY_A );
parse_str( wp_parse_url( Newsletter::unsubscribe_url( $first ), PHP_URL_QUERY ), $unsubscribe );
$check( is_wp_error( Newsletter::unsubscribe( (int) $first['id'], 'invalid' ) ), 'Forged unsubscribe token rejected' );

wp_set_current_user( $customer );
$check( is_wp_error( Newsletter::save( array( 'subject' => 'Forbidden', 'body' => 'No' ) ) ), 'Customer cannot create campaigns' );
$marketing = wp_insert_user( array( 'user_login' => 'marketing_test', 'user_email' => 'marketing@example.invalid', 'user_pass' => wp_generate_password( 24 ), 'role' => 'dealership_marketing' ) );
wp_set_current_user( $marketing );
$check( Newsletter::can_manage(), 'Marketing employee can manage campaigns' );
$campaign = Newsletter::save( array( 'subject' => 'اختبار الحملة', 'body' => '<p>عرض جديد</p><script>alert(1)</script>', 'button_text' => 'عرض السيارات', 'button_url' => 'https://example.invalid/cars', 'locale' => 'ar' ) );
$check( is_int( $campaign ), 'Marketing employee saves a campaign draft' );
$draft = Newsletter::campaign( $campaign );
$check( ! str_contains( $draft['body'], '<script>' ) && str_contains( Newsletter::html( $draft ), 'dir="rtl"' ), 'Rich content sanitized and Arabic preview rendered' );
$check( is_wp_error( Newsletter::save( array( 'subject' => 'bad', 'body' => 'test', 'button_text' => 'bad', 'button_url' => 'javascript:alert(1)' ) ) ), 'Unsafe button URL rejected' );
$check( true === Newsletter::queue( $campaign ), 'Campaign snapshots active subscribers and queues expansion' );
$check( is_wp_error( Newsletter::queue( $campaign ) ), 'Duplicate campaign send rejected' );
$check( is_wp_error( Newsletter::save( array( 'subject' => 'changed', 'body' => 'changed' ), $campaign ) ), 'Queued content cannot be edited' );
$check( true === Newsletter::unsubscribe( (int) $first['id'], $unsubscribe['token'] ), 'Signed unsubscribe accepted before queued delivery' );
$before = count( $captured );
for ( $i = 0; $i < 4; ++$i ) { OutboxService::process_due( 100, array( 'customer.welcome', 'newsletter.subscription', 'newsletter.expand', 'newsletter.deliver' ) ); }
$stats = Newsletter::stats( $campaign );
$check( 2 === $stats['total'] && 1 === $stats['sent'] && 1 === $stats['skipped'], 'Campaign skips unsubscribed recipient and sends to remaining subscriber' );
$check( count( $captured ) === $before + 1, 'Only one campaign message accepted' );
$mail = end( $captured );
$check( $mail['to'] === 'second@example.invalid' && ! str_contains( $mail['message'], 'first@example.invalid' ), 'Recipient addresses remain private' );
$check( str_contains( implode( "\n", $mail['headers'] ), 'List-Unsubscribe-Post' ) && str_contains( $mail['message'], 'adc_unsubscribe' ), 'Campaign includes signed unsubscribe link and one-click headers' );
$delivery = $wpdb->get_row( 'SELECT * FROM ' . Newsletter::table( 'deliveries' ) . " WHERE campaign_id=$campaign AND status='sent'", ARRAY_A );
$check( true === Newsletter::deliver( array( 'subject_id' => $delivery['id'] ) ) && count( $captured ) === $before + 1, 'Repeated delivery callback does not duplicate successful send' );

$failed = Newsletter::save( array( 'subject' => 'Retry test', 'body' => 'Retry body' ) ); Newsletter::queue( $failed ); Newsletter::expand( array( 'subject_id' => $failed ) );
$reject_mail = true;
for ( $i = 0; $i < 5; ++$i ) {
	$wpdb->query( 'UPDATE ' . OutboxService::table() . " SET next_attempt_at='2000-01-01 00:00:00' WHERE event_key='newsletter.deliver' AND status IN ('retry','pending')" );
	OutboxService::process_due( 100, array( 'newsletter.deliver' ) );
}
$check( Newsletter::stats( $failed )['failed'] === 1, 'Exhausted retries shown as failed delivery' );
$reject_mail = false;
$check( true === Newsletter::retry( $failed ), 'Marketing employee can retry failed deliveries' );
OutboxService::process_due( 100, array( 'newsletter.deliver' ) );
$check( Newsletter::stats( $failed )['sent'] === 1 && Newsletter::stats( $failed )['failed'] === 0, 'Manual retry recovers failed delivery' );

$cancelled = Newsletter::save( array( 'subject' => 'Cancelled test', 'body' => 'Cancelled body' ) ); Newsletter::queue( $cancelled );
$check( true === Newsletter::cancel( $cancelled ), 'Marketing employee can cancel campaign' );
$before = count( $captured ); Newsletter::expand( array( 'subject_id' => $cancelled ) );
OutboxService::process_due( 100, array( 'newsletter.expand', 'newsletter.deliver' ) );
$check( count( $captured ) === $before, 'Cancelled campaign does not send remaining mail' );

wp_set_current_user( 0 );
$wpdb->query( "UPDATE " . EmailVerification::table() . " SET created_at='2000-01-01 00:00:00' WHERE purpose='newsletter'" );
$check( $subscribe( 'first@example.invalid' )['success'], 'User can explicitly resubscribe' );
$check( is_wp_error( Newsletter::unsubscribe( (int) $first['id'], $unsubscribe['token'] ) ), 'Old unsubscribe token invalid after renewed consent' );
wp_set_current_user( $admin );
ob_start(); AutoDealership\Admin\NewsletterPage::render(); $ui = ob_get_clean();
$check( str_contains( $ui, 'إنشاء حملة جديدة' ) && str_contains( $ui, 'إدارة المشتركين' ), 'Dashboard exposes campaigns and subscriber management' );

$single_run = Newsletter::save( array( 'subject' => 'Single worker invocation', 'body' => 'Prepare and send immediately' ) ); Newsletter::queue( $single_run );
$check( wp_next_scheduled( 'adc_mail_wake' ) <= time(), 'Mail wake is immediately eligible for cron' );
Newsletter::run_mail();
$check( Newsletter::stats( $single_run )['sent'] === 2 && Newsletter::stats( $single_run )['pending'] === 0, 'One worker invocation prepares and sends a small campaign' );

$bilingual_input = array( 'subject_ar'=>'عروض مختارة لك', 'subject_en'=>'Offers chosen for you', 'body_ar'=>'<p>اكتشف سياراتنا الجديدة</p>', 'body_en'=>'<p>Explore our latest cars</p>', 'button_text_ar'=>'عرض السيارات', 'button_text_en'=>'Explore cars', 'button_url_ar'=>'https://example.invalid/?lang=ar', 'button_url_en'=>'https://example.invalid/?lang=en', 'creation_key'=>wp_generate_uuid4() );
$bilingual = Newsletter::save( $bilingual_input );
$check( is_int( $bilingual ) && Newsletter::campaign( $bilingual )['bilingual'], 'Both campaign language versions are stored separately' );
$check( Newsletter::save( $bilingual_input ) === $bilingual, 'Repeated creation key updates the same draft instead of duplicating it' );
$partial = Newsletter::save( array( 'subject_ar'=>'مسودة جزئية', 'body_ar'=>'<p>نص عربي فقط</p>', 'subject_en'=>'', 'body_en'=>'' ) );
$check( is_int( $partial ) && is_wp_error( Newsletter::queue( $partial ) ), 'Partial translations can be saved but cannot be sent' );
$row_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::table( 'campaigns' ) ); $before = count( $captured );
$check( str_contains( Newsletter::preview( $bilingual_input, 'ar' ), 'dir="rtl"' ) && str_contains( Newsletter::preview( $bilingual_input, 'en' ), 'Offers chosen for you' ), 'Unsaved previews render the requested version' );
$check( $row_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::table( 'campaigns' ) ) && count( $captured ) === $before, 'Live preview neither saves campaigns nor sends email' );
Newsletter::queue( $bilingual ); Newsletter::run_mail();
$bilingual_mails = array_slice( $captured, $before );
$check( count( $bilingual_mails ) === 2 && $bilingual_mails[0]['subject'] === 'عروض مختارة لك' && $bilingual_mails[1]['subject'] === 'Offers chosen for you', 'Arabic and English subscribers receive different subjects' );
$check( str_contains( $bilingual_mails[0]['message'], 'اكتشف سياراتنا الجديدة' ) && ! str_contains( $bilingual_mails[0]['message'], 'Explore our latest cars' ) && str_contains( $bilingual_mails[1]['message'], 'Explore our latest cars' ) && ! str_contains( $bilingual_mails[1]['message'], 'اكتشف سياراتنا الجديدة' ), 'Each recipient receives only their preferred content' );
$check( str_contains( $bilingual_mails[0]['message'], 'dir="rtl"' ) && str_contains( $bilingual_mails[1]['message'], 'dir="ltr"' ) && str_contains( $bilingual_mails[1]['message'], 'Unsubscribe' ), 'Directions and unsubscribe copy follow recipient language' );
$locales = $wpdb->get_col( 'SELECT locale FROM ' . Newsletter::table( 'deliveries' ) . " WHERE campaign_id=$bilingual ORDER BY id" );
$check( $locales === array( 'ar', 'en' ), 'Delivery results record which language was sent' );

$_GET['new'] = '1';
ob_start(); AutoDealership\Admin\NewsletterPage::render(); $composer = ob_get_clean(); unset( $_GET['new'] );
$check( str_contains( $composer, 'name="subject_ar"' ) && str_contains( $composer, 'name="subject_en"' ) && str_contains( $composer, 'data-newsletter-send' ), 'New campaign form exposes both versions and send button immediately' );
$check( ! str_contains( $composer, 'name="test_email"' ) && ! str_contains( $composer, 'value="preview"' ), 'Test email and save-preview steps removed from composer' );
$_POST = $bilingual_input + array( 'preview_language'=>'en', 'nonce'=>wp_create_nonce( 'adc_newsletter_preview' ) ); $_REQUEST = $_POST;
ob_start();
try { AutoDealership\Admin\NewsletterPage::preview(); } catch ( RuntimeException $error ) { if ( 'ajax-response' !== $error->getMessage() ) { throw $error; } }
$preview_response = json_decode( ob_get_clean(), true );
$check( $preview_response['success'] && str_contains( $preview_response['data']['html'], 'Offers chosen for you' ), 'Authenticated live preview endpoint returns the English version' );
$check( $row_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::table( 'campaigns' ) ), 'AJAX preview leaves stored campaigns unchanged' );
wp_set_current_user( $customer ); ob_start();
try { AutoDealership\Admin\NewsletterPage::preview(); } catch ( RuntimeException $error ) { if ( 'ajax-response' !== $error->getMessage() ) { throw $error; } }
$forbidden = json_decode( ob_get_clean(), true );
$check( ! $forbidden['success'], 'Customers cannot invoke the staff preview endpoint' );
wp_set_current_user( $admin ); $_REQUEST['lang']='ar';

$redirected = '';
$redirect = static function ( $location ) use ( &$redirected ) { $redirected=$location; throw new RuntimeException( 'newsletter-submit-redirect' ); };
add_filter( 'wp_redirect', $redirect );
$_POST = $bilingual_input; $_POST['creation_key']=wp_generate_uuid4(); $_POST['subject_ar']='آخر تعديل قبل الإرسال'; $_POST['subject_en']='Latest changes before send';
$_POST['operation']='send'; $_POST['campaign']='0'; $_POST['_wpnonce']=wp_create_nonce( 'adc_newsletter' ); $_REQUEST=$_POST;
try { AutoDealership\Admin\NewsletterPage::submit(); } catch ( RuntimeException $error ) { if ( 'newsletter-submit-redirect' !== $error->getMessage() ) { throw $error; } }
remove_filter( 'wp_redirect', $redirect );
parse_str( wp_parse_url( $redirected, PHP_URL_QUERY ), $sent_request );
$submitted_campaign = Newsletter::campaign( (int) $sent_request['campaign'] );
$check( $sent_request['notice']==='queued' && $submitted_campaign['subject_en']==='Latest changes before send', 'Send button saves and queues current fields without a preliminary save' );
$before=count($captured); Newsletter::run_mail();
$check( count($captured)===$before+2 && Newsletter::stats((int)$submitted_campaign['id'])['sent']===2, 'Direct bilingual submission sends exactly one preferred version per subscriber' );

// More than one preparation batch must remain bounded and complete without duplicate rows.
for ( $i = 0; $i < 105; ++$i ) { $wpdb->insert( Newsletter::subscribers(), array( 'email' => "batch$i@example.invalid", 'status' => 'active', 'created_at' => current_time( 'mysql', true ), 'consent_key' => wp_generate_uuid4() ) ); }
$large = Newsletter::save( array( 'subject' => 'Large campaign test', 'body' => 'Bounded preparation' ) ); Newsletter::queue( $large );
$check( true === Newsletter::expand( array( 'subject_id' => $large ) ), 'First preparation batch succeeds' );
$prepared = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Newsletter::table( 'deliveries' ) . " WHERE campaign_id=$large AND outbox_id>0" );
$check( $prepared === 100 && ! Newsletter::campaign( $large )['expanded'], 'Preparation is limited to 100 recipients per batch' );
for ( $i = 0; $i < 5; ++$i ) { OutboxService::process_due( 100, array( 'newsletter.expand', 'newsletter.deliver' ) ); }
$check( Newsletter::stats( $large )['sent'] === 107 && Newsletter::campaign( $large )['expanded'], 'Multi-batch campaign completes exactly once per active subscriber' );

$blocked = Newsletter::save( array( 'subject' => 'Preparation recovery', 'body' => 'Preparation test' ) ); Newsletter::queue( $blocked );
OutboxService::register_handler( 'newsletter.expand', static fn() => new WP_Error( 'adc_preparation_fixture' ) );
for ( $i = 0; $i < 5; ++$i ) {
	$wpdb->query( 'UPDATE ' . OutboxService::table() . " SET next_attempt_at='2000-01-01 00:00:00' WHERE event_key='newsletter.expand' AND status IN ('pending','retry')" );
	OutboxService::process_due( 100, array( 'newsletter.expand' ) );
}
OutboxService::register_handler( 'newsletter.expand', array( Newsletter::class, 'expand' ) );
$check( true === Newsletter::retry( $blocked ), 'Employee can recover failed campaign preparation' );
for ( $i = 0; $i < 5; ++$i ) { OutboxService::process_due( 100, array( 'newsletter.expand', 'newsletter.deliver' ) ); }
$check( Newsletter::stats( $blocked )['sent'] === 107, 'Recovered preparation sends only one message per subscriber' );
wp_set_current_user( $customer );
$account_mail_count = count( $captured );
$account_preferences = AutoDealership\Leads\CustomerIdentity::update_preferences( true, 'en' );
OutboxService::process_due( 100, array( 'email.verify', 'newsletter.subscription' ) );
$account_subscription = $wpdb->get_row( "SELECT * FROM " . Newsletter::subscribers() . " WHERE email='welcome@example.invalid'", ARRAY_A );
$check( ! is_wp_error( $account_preferences ) && $account_subscription && 'active' === $account_subscription['status'] && 'en' === $account_subscription['locale'] && 'verified_account' === $account_subscription['confirmation_source'], 'Verified account opt-in creates an active subscriber without footer confirmation' );
$check( count( $captured ) === $account_mail_count + 1 && str_contains( end( $captured )['message'], 'Welcome to the ' . \AutoDealership\Core\ProductSetup::brand( 'en' ) . ' newsletter' ), 'Verified account opt-in sends the localized welcome instead of a confirmation' );
AutoDealership\Leads\CustomerIdentity::update_preferences( true, 'en' );
$check( count( $captured ) === $account_mail_count + 1 && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Newsletter::subscribers() . " WHERE email='welcome@example.invalid'" ), 'Repeated account opt-in neither duplicates the subscriber nor sends another welcome' );
$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . EmailVerification::table() . " WHERE purpose='newsletter' AND subject_id=%d", $account_subscription['id'] ) ), 'Verified account opt-in creates no newsletter challenge' );
$check( $subscribe( 'ignored@example.invalid', 'ar' )['success'] && get_user_meta( $customer, 'adc_preferred_language', true ) === 'en', 'Subscription preserves saved email preference even on an Arabic page' );
$saved_locale = $wpdb->get_var( "SELECT locale FROM " . Newsletter::subscribers() . " WHERE email='welcome@example.invalid'" );
$check( $saved_locale === 'en', 'Subscriber language matches the saved account preference' );
update_user_meta($customer,'adc_marketing_consent','0');
$check( AutoDealership\Leads\CustomerIdentity::current_preferences()['consent_marketing'], 'Active newsletter subscription selects consent even when old account metadata disagrees' );
$optout = AutoDealership\Leads\CustomerIdentity::update_preferences(false,'en');
$check( ! $optout['consent_marketing'] && $wpdb->get_var("SELECT status FROM ".Newsletter::subscribers()." WHERE email='welcome@example.invalid'")==='unsubscribed', 'Account opt-out updates newsletter status and selects the second choice' );
$wpdb->query( "UPDATE " . EmailVerification::table() . " SET created_at='2000-01-01 00:00:00' WHERE purpose='newsletter'" );
$preferences = AutoDealership\Leads\CustomerIdentity::update_preferences( true, 'ar' );
OutboxService::process_due( 100, array( 'email.verify', 'newsletter.subscription' ) );
$subscriber_language = $wpdb->get_var( "SELECT locale FROM " . Newsletter::subscribers() . " WHERE email='welcome@example.invalid'" );
$check( ! is_wp_error( $preferences ) && $preferences['newsletter_language'] === 'ar' && $subscriber_language === 'ar', 'Account preferences synchronize user and newsletter language' );
$check( $preferences['consent_marketing'] && $preferences['newsletter_active'] && $preferences['subscription_status'] === 'active', 'Explicit renewed consent activates immediately for the verified owner' );
$check( str_contains( end( $captured )['subject'], 'أهلًا بك' ), 'Renewed verified consent sends the welcome in the newly chosen language' );
$check( is_wp_error( AutoDealership\Leads\CustomerIdentity::update_preferences( true, 'invalid' ) ), 'Invalid language preferences rejected' );
wp_set_current_user( $admin ); $_REQUEST['lang']='en';
$missing_translation = Newsletter::queue( $partial );
$check( is_wp_error( $missing_translation ) && str_contains( $missing_translation->get_error_message(), 'Complete the Arabic and English' ), 'Campaign validation errors are translated for English users' );
$_GET['new']='1'; ob_start(); AutoDealership\Admin\NewsletterPage::render(); $english_composer=ob_get_clean(); unset($_GET['new']);
$check( str_contains($english_composer,'Create a bilingual campaign') && str_contains($english_composer,'Preferred email language') && str_contains($english_composer,'Send to all subscribers'), 'Recent newsletter controls and language labels have English translations' );
update_user_meta($customer,'adc_preferred_language','en'); delete_user_meta($customer,'adc_welcome_sent_at');
Newsletter::welcome(array('subject_id'=>$customer,'locale'=>'ar'));
$check( str_contains(end($captured)['subject'],'Welcome to') && str_contains(end($captured)['message'],'lang=en'), 'Welcome emails use saved preference rather than worker language' );
require_once dirname(__DIR__,3).'/mu-plugins/autobrands-password-email.php';
$reset = apply_filters('retrieve_password_notification_email',array('to'=>'welcome@example.invalid','subject'=>'default','message'=>'default','headers'=>''),'synthetic-reset',get_userdata($customer)->user_login,get_userdata($customer));
$check( str_contains($reset['subject'],'Reset your password') && str_contains($reset['message'],'wp_lang=en_US'), 'Password reset emails also respect the saved email language' );
$source_before=Newsletter::campaign($bilingual);$source_stats=Newsletter::stats($bilingual);$before=count($captured);
$copy_key=wp_generate_uuid4();$copy=Newsletter::duplicate($bilingual,$copy_key);$copy_data=Newsletter::campaign($copy);
$check(is_int($copy)&&$copy!==$bilingual&&$copy_data['status']==='draft'&&(int)$copy_data['copied_from']===$bilingual,'Copy creates an independent draft with source reference');
$check($copy_data['body_ar']===$source_before['body_ar']&&$copy_data['body_en']===$source_before['body_en']&&str_contains($copy_data['subject_en'],'(Copy)'),'Copy retains both language versions and marks draft titles');
$check(Newsletter::stats($copy)['total']===0&&count($captured)===$before&&!$copy_data['queued_at'],'Copy carries no recipients or delivery results and sends no mail');
$check(Newsletter::duplicate($bilingual,$copy_key)===$copy,'Repeated copy action does not create duplicate drafts');
$edited=Newsletter::save(array_replace($bilingual_input,array('subject_ar'=>'عنوان النسخة الجديد','subject_en'=>'Independent copy title')),$copy);
$check($edited===$copy&&Newsletter::campaign($bilingual)===$source_before,'Editing the copy leaves completed source unchanged');
$check(is_wp_error(Newsletter::archive($copy)),'Unsent drafts cannot be archived');
$check(is_wp_error(Newsletter::delete_draft($bilingual))&&is_wp_error(Newsletter::cancel($bilingual)),'Completed campaigns cannot be deleted or marked cancelled');
$check(Newsletter::archive($bilingual)===true,'Completed campaign can be archived');
$archived=Newsletter::campaign($bilingual);
$check($archived['archived_at']&&$archived['status']===$source_before['status']&&$archived['body_ar']===$source_before['body_ar']&&Newsletter::stats($bilingual)===$source_stats,'Archive preserves original content, lifecycle and delivery results');
$check(is_wp_error(Newsletter::retry($bilingual))&&is_wp_error(Newsletter::save($bilingual_input,$bilingual)),'Archived campaign is immutable and cannot be resent');
$_GET['campaign_view']='archived';ob_start();AutoDealership\Admin\NewsletterPage::render();$archive_ui=ob_get_clean();unset($_GET['campaign_view']);
$check(str_contains($archive_ui,'Restore from archive')&&str_contains($archive_ui,'Offers chosen for you'),'Archived campaigns are available with restore controls');
$archived_copy=Newsletter::duplicate($bilingual);
$check(is_int($archived_copy)&&Newsletter::campaign($archived_copy)['status']==='draft','Archived campaign can be copied into a new draft');
$check(Newsletter::archive($bilingual,true)===true&&!Newsletter::campaign($bilingual)['archived_at']&&Newsletter::stats($bilingual)===$source_stats,'Restore returns campaign without changing delivery history');
$check(Newsletter::delete_draft($copy)===true&&!Newsletter::campaign($copy),'Unused draft can be permanently deleted');
$busy=Newsletter::save(array_replace($bilingual_input,array('creation_key'=>wp_generate_uuid4())));Newsletter::queue($busy);
$check(is_wp_error(Newsletter::archive($busy))&&is_wp_error(Newsletter::delete_draft($busy)),'Queued campaign cannot be archived or deleted');
Newsletter::cancel($busy);Newsletter::archive($busy);
$frozen_delivery=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Newsletter::table('deliveries').' WHERE campaign_id=%d LIMIT 1',$busy),ARRAY_A);
$wpdb->update(Newsletter::table('deliveries'),array('status'=>'failed'),array('id'=>$frozen_delivery['id']));
$before=count($captured);
$check(is_wp_error(Newsletter::deliver(array('subject_id'=>$frozen_delivery['id'])))&&count($captured)===$before,'A direct worker retry cannot send an archived campaign');
$check($wpdb->get_var($wpdb->prepare('SELECT status FROM '.Newsletter::table('deliveries').' WHERE id=%d',$frozen_delivery['id']))==='failed','Archived retry rejection preserves historical delivery result');
wp_set_current_user($customer);
$check(is_wp_error(Newsletter::duplicate($bilingual))&&is_wp_error(Newsletter::archive($bilingual))&&is_wp_error(Newsletter::delete_draft($archived_copy)),'Customers cannot copy, archive or delete campaigns');
require __DIR__ . '/email-verification-scenarios.php';
echo "Newsletter integration passed ($checks checks). No real email sent.\n";
