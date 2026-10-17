<?php
namespace AutoDealership\Accounts;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;
use AutoDealership\Leads\NewsletterService;
use AutoDealership\Operations\OutboxService;
use AutoDealership\Security\PublicRequestGuard;

defined( 'ABSPATH' ) || exit;

/** Purpose-bound email ownership challenges. Raw proofs never enter the database/outbox. */
final class EmailVerification {
	private static bool $booted = false;
	private static bool $protected = false;
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'adc_email_verifications'; }
	public static function t( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }
	public static function email_hash( string $email ): string { return hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) ); }
	private static function account_email_hash( string $email ): string { return hash( 'sha256', strtolower( $email ) ); }
	private static function error( string $code = 'adc_verification_invalid' ): \WP_Error {
		return new \WP_Error( $code, self::t( 'تعذر التأكيد. قد يكون الرابط منتهيًا أو مستخدمًا، أو البيانات غير صحيحة. اطلب رابطًا جديدًا عند الحاجة.', 'Verification could not be completed. The link may be expired or used, or the details may be incorrect. Request a new link if needed.' ) );
	}
	public static function boot(): void {
		if ( (isset($_GET['adc_verify']) || isset($_GET['cd_account'])) && ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if(isset($_GET['cd_account'])){add_action('send_headers','nocache_headers');}
		self::install();
		if ( self::$booted ) { return; } self::$booted = true;
		VerificationExperience::boot();
		OutboxService::register_handler( 'email.verify', array( self::class, 'send' ) );
		self::protect_accounts();
		add_action( 'template_redirect', array( self::class, 'page' ), 0 );
		add_action( 'adc_verification_prune', array( self::class, 'prune' ) );
		if ( ! wp_next_scheduled( 'adc_verification_prune' ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'adc_verification_prune' ); }
	}
	/** Register guards before plugins_loaded callbacks can resolve a cached current user. */
	public static function protect_accounts(): void {
		if ( self::$protected ) { return; } self::$protected = true;
		add_filter( 'authenticate', array( self::class, 'authenticate' ), PHP_INT_MAX, 3 );
		add_filter( 'determine_current_user', array( self::class, 'current_user' ), PHP_INT_MAX );
		add_filter( 'send_auth_cookies', static fn( $send, $expire, $expiration, $id ) => $send && ! self::blocked( (int) $id ), PHP_INT_MAX, 4 );
		add_filter( 'map_meta_cap', static fn( $caps, $cap, $id ) => self::blocked( (int) $id ) ? array( 'do_not_allow' ) : $caps, PHP_INT_MAX, 3 );
		add_filter( 'registration_errors', static function ( $errors ) {
			if ( CustomerAccount::owns_theme_actions() && is_wp_error( $errors ) ) {
				$url = add_query_arg( 'cd_account', 'register', apply_filters( 'adc_customer_account_url', home_url( '/' ), '' ) );
				$errors->add( 'adc_registration_route', self::t( 'يرجى إنشاء حساب العميل من صفحة التسجيل في الموقع لتأكيد البريد بأمان.', 'Please create your customer account using the website registration page to verify your email securely.' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html( self::t( 'إنشاء حساب', 'Create account' ) ) . '</a>' );
			}
			return $errors;
		}, PHP_INT_MAX );
		add_action( 'plugins_loaded', static function (): void { if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User && self::blocked( (int) $GLOBALS['current_user']->ID ) ) { wp_set_current_user( 0 ); wp_clear_auth_cookie(); } }, 0 );
		add_action( 'profile_update', array( self::class, 'email_changed' ), 30, 2 );
	}
	public static function install(): void {
		if ( '1' === get_option( 'adc_email_verification_schema' ) ) { return; }
		global $wpdb;
		$lock = 'adc_verify_schema_' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return; }
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$table = self::table(); $collate = $wpdb->get_charset_collate();
			dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			purpose varchar(20) NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			selector varchar(64) NOT NULL,
			generation varchar(36) NOT NULL,
			email_hash varchar(64) NOT NULL,
			token_hash varchar(64) NOT NULL,
			consent_key varchar(36) NOT NULL DEFAULT '',
			locale varchar(2) NOT NULL DEFAULT 'ar',
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			sent_at datetime NULL,
			used_at datetime NULL,
			window_started datetime NOT NULL,
			requests smallint(5) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY purpose_subject (purpose,subject_id),
			UNIQUE KEY selector (selector),
			KEY expires_at (expires_at)
			) $collate ENGINE=InnoDB;" );
			if ( ! $wpdb->last_error && 'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) { update_option( 'adc_email_verification_schema', '1', false ); }
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	/** Legacy accounts have no marker. Only newly enrolled customers require verification. */
	public static function blocked( int $id ): bool {
		if ( ! $id ) { return false; }
		$status = (string) get_user_meta( $id, 'adc_email_verification_status', true );
		if ( '' === $status ) { return false; }
		$user = get_userdata( $id );
		return 'verified' !== $status || ! $user || ! hash_equals( (string) get_user_meta( $id, 'adc_verified_email_hash', true ), self::account_email_hash( $user->user_email ) );
	}
	public static function authenticate( $user, $login = '', $password = '' ) {
		return $user instanceof \WP_User && self::blocked( (int) $user->ID ) ? new \WP_Error( 'adc_email_pending', self::t( 'يرجى تأكيد بريدك الإلكتروني قبل تسجيل الدخول. يمكنك طلب رابط جديد من صفحة التأكيد.', 'Please verify your email before signing in. You can request a new link on the verification page.' ) . ' <a href="' . esc_url( self::page_url( 'account' ) ) . '">' . esc_html( self::t( 'تأكيد البريد', 'Verify email' ) ) . '</a>' ) : $user;
	}
	public static function current_user( $id ) { return self::blocked( (int) $id ) ? 0 : $id; }
	/** Positive proof only: legacy exemption from login blocking is not email verification. */
	public static function current_account_verified_for( string $email ): bool {
		global $wpdb;
		$id = get_current_user_id();
		if ( ! $id ) { return false; }
		$current_email = $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM {$wpdb->users} WHERE ID=%d", $id ) );
		if ( ! $current_email || $wpdb->last_error || strtolower( $current_email ) !== strtolower( $email ) ) { return false; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key IN ('adc_email_verification_status','adc_verified_email_hash','adc_email_verified_at') ORDER BY umeta_id", $id ), ARRAY_A );
		if ( $wpdb->last_error ) { return false; }
		$proof = array();
		foreach ( $rows ?: array() as $row ) { if ( ! array_key_exists( $row['meta_key'], $proof ) ) { $proof[$row['meta_key']] = $row['meta_value']; } }
		return 'verified' === ( $proof['adc_email_verification_status'] ?? '' ) && ! empty( $proof['adc_email_verified_at'] ) && hash_equals( self::account_email_hash( $current_email ), (string) ( $proof['adc_verified_email_hash'] ?? '' ) );
	}
	/** Called inside the caller's transaction, with the account/subscriber locked first. */
	public static function issue( string $purpose, int $subject_id, string $email, string $locale, string $consent_key = '' ) {
		global $wpdb;
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) && 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) { return self::error( 'adc_verification_https' ); }
		if ( ! in_array( $purpose, array( 'account', 'newsletter' ), true ) || $subject_id < 1 || ! is_email( $email ) || '1' !== get_option( 'adc_email_verification_schema' ) ) { return self::error( 'adc_verification_storage' ); }
		$table = self::table();
		$previous = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE purpose=%s AND subject_id=%d FOR UPDATE", $purpose, $subject_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error( 'adc_verification_storage' ); }
		$now = time();
		$window = $previous ? strtotime( $previous['window_started'] . ' UTC' ) : $now;
		$count = $previous && $window > $now - HOUR_IN_SECONDS ? (int) $previous['requests'] : 0;
		if ( $previous && ( strtotime( $previous['created_at'] . ' UTC' ) > $now - MINUTE_IN_SECONDS || $count >= 5 ) ) { return self::error( 'adc_verification_throttled' ); }
		$rate = PublicRequestGuard::consume( 'email_send', self::email_hash( $email ) );
		if ( is_wp_error( $rate ) ) { return self::error( 'adc_rate_limited' === $rate->get_error_code() ? 'adc_verification_throttled' : 'adc_verification_storage' ); }
		$data = array( 'purpose'=>$purpose, 'subject_id'=>$subject_id, 'selector'=>bin2hex( random_bytes( 32 ) ), 'generation'=>wp_generate_uuid4(), 'email_hash'=>self::email_hash( $email ), 'consent_key'=>$consent_key, 'locale'=>NewsletterService::language( $locale ), 'created_at'=>gmdate( 'Y-m-d H:i:s', $now ), 'expires_at'=>gmdate( 'Y-m-d H:i:s', $now + ( 'account' === $purpose ? DAY_IN_SECONDS : 2 * DAY_IN_SECONDS ) ), 'sent_at'=>null, 'used_at'=>null, 'window_started'=>gmdate( 'Y-m-d H:i:s', $count ? $window : $now ), 'requests'=>$count + 1 );
		$data['token_hash'] = hash( 'sha256', self::proof( $data ) );
		$ok = $previous ? $wpdb->update( $table, $data, array( 'id'=>$previous['id'] ) ) : $wpdb->insert( $table, $data );
		if ( false === $ok ) { return self::error( 'adc_verification_storage' ); }
		$id = $previous ? (int) $previous['id'] : (int) $wpdb->insert_id;
		$payload = array( 'subject_type'=>'email_verification', 'subject_id'=>$id, 'correlation_id'=>$data['generation'] );
		$event = OutboxService::enqueue( 'email.verify', $payload, 'email-verify:' . $id . ':' . $data['generation'] );
		return is_wp_error( $event ) ? $event : $payload;
	}
	/** A keyed PRF permits safe retry without retaining the raw 256-bit proof anywhere. */
	public static function deliver_committed(array $payload): void {
		// Only called after a successful commit. CLI tests/workers keep explicit dispatch control.
		if('cli'===PHP_SAPI) { return; }
		add_action('shutdown',static function()use($payload): void {
			if(function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
			$previous=ignore_user_abort(true);
			try { self::dispatch_committed($payload); } finally { ignore_user_abort($previous); }
		},20);
	}
	public static function dispatch_committed(array $payload) {
		global $wpdb;
		$id=(int)($payload['subject_id']??0);$generation=$payload['correlation_id']??'';
		if($id<1 || !is_string($generation) || !wp_is_uuid($generation)) { return self::error('adc_verification_invalid'); }
		$key=hash('sha256','email.verify|email-verify:'.$id.':'.$generation);
		$event=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.OutboxService::table()." WHERE event_key='email.verify' AND idempotency_key=%s",$key));
		return $event?OutboxService::process_due(1,array('email.verify'),array($event)):true;
	}
	/** A keyed PRF permits safe retry without retaining the raw 256-bit proof anywhere. */
	private static function proof( array $row ): string {
		$context = implode( '|', array( 'adc-email-v1', $row['purpose'], $row['subject_id'], $row['email_hash'], $row['selector'], $row['generation'], $row['expires_at'], $row['consent_key'] ) );
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', $context, wp_salt( 'secure_auth' ), true ) ), '+/', '-_' ), '=' );
	}
	public static function page_url( string $purpose = 'account', string $language = '' ): string {
		return add_query_arg( array( 'adc_verify'=>1, 'purpose'=>'newsletter' === $purpose ? 'newsletter' : 'account', 'lang'=>NewsletterService::language( $language ?: \AutoDealership\Core\Localization::language() ) ), home_url( '/' ) );
	}
	private static function link( array $row ): string {
		// Fragments are never sent in HTTP requests or access logs; POST carries the proof.
		return self::page_url( $row['purpose'], $row['locale'] ) . '#token=' . $row['selector'] . '.' . self::proof( $row );
	}
	private static function target( array $row, bool $lock = false ): ?array {
		global $wpdb; $suffix = $lock ? ' FOR UPDATE' : '';
		if ( 'account' === $row['purpose'] ) {
			$user = $wpdb->get_row( $wpdb->prepare( "SELECT ID,user_email,user_pass FROM {$wpdb->users} WHERE ID=%d" . $suffix, $row['subject_id'] ), ARRAY_A );
			if ( ! $user || ! self::blocked( (int) $user['ID'] ) ) { return null; }
			return array( 'email'=>$user['user_email'], 'password'=>$user['user_pass'], 'locale'=>get_user_meta( (int) $user['ID'], 'adc_preferred_language', true ) ?: $row['locale'] );
		}
		$subscriber = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NewsletterService::subscribers() . ' WHERE id=%d' . $suffix, $row['subject_id'] ), ARRAY_A );
		if ( ! $subscriber || 'pending' !== $subscriber['status'] || ! hash_equals( $row['consent_key'], $subscriber['consent_key'] ) ) { return null; }
		return array( 'email'=>$subscriber['email'], 'locale'=>$subscriber['locale'] );
	}
	public static function send( array $payload ) {
		global $wpdb;
		$id = (int) ( $payload['subject_id'] ?? 0 );
		$lock = 'adc_verification_mail_' . md5( DB_NAME . $wpdb->prefix . $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return self::error( 'adc_verification_busy' ); }
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ), ARRAY_A );
			if ( $wpdb->last_error ) { return self::error( 'adc_verification_storage' ); }
			if ( ! $row || $row['generation'] !== ( $payload['correlation_id'] ?? '' ) || $row['used_at'] || $row['sent_at'] || strtotime( $row['expires_at'] . ' UTC' ) <= time() ) { return true; }
			$target = self::target( $row );
			if ( $wpdb->last_error ) { return self::error( 'adc_verification_storage' ); }
			if ( ! $target || ! hash_equals( $row['email_hash'], self::email_hash( $target['email'] ) ) ) { return true; }
			$row['locale'] = NewsletterService::language( $target['locale'] );
			$ar = 'ar' === $row['locale']; $account = 'account' === $row['purpose'];
			$title = $account ? ( $ar ? 'تأكيد بريدك وتفعيل حسابك' : 'Verify your email and activate your account' ) : ( $ar ? \AutoDealership\Core\ProductSetup::copy( 'تأكيد الاشتراك في نشرة أوتو براندز', $ar ? 'ar' : 'en' ) : \AutoDealership\Core\ProductSetup::copy( 'Confirm your AutoBrands newsletter subscription', $ar ? 'ar' : 'en' ) );
			$body = '<p>' . esc_html( $account ? ( $ar ? \AutoDealership\Core\ProductSetup::copy( 'شكرًا لإنشاء حسابك في أوتو براندز. لتأكيد ملكية بريدك وتفعيل حسابك، افتح الرابط لتأكيد بريدك وتفعيل حسابك تلقائيًا.', $ar ? 'ar' : 'en' ) : \AutoDealership\Core\ProductSetup::copy( 'Thank you for creating your AutoBrands account. Open the link to verify your email and activate your account automatically.', $ar ? 'ar' : 'en' ) ) : ( $ar ? \AutoDealership\Core\ProductSetup::copy( 'وصلنا طلب اشتراكك في النشرة البريدية. أكد الاشتراك لتصلك عروض وأخبار أوتو براندز باللغة التي اخترتها.', $ar ? 'ar' : 'en' ) : \AutoDealership\Core\ProductSetup::copy( 'We received your newsletter subscription request. Confirm to receive AutoBrands news and offers in your preferred language.', $ar ? 'ar' : 'en' ) ) ) . '</p><p>' . esc_html( $ar ? ( $account ? 'الرابط صالح لمدة 24 ساعة، ويُستخدم مرة واحدة.' : 'الرابط صالح لمدة 48 ساعة، ويُستخدم مرة واحدة.' ) : ( $account ? 'This single-use link is valid for 24 hours.' : 'This single-use link is valid for 48 hours.' ) ) . '</p><p>' . esc_html( $ar ? 'إذا لم تطلب ذلك، تجاهل الرسالة. لن يتم التفعيل ولن تشترك في الحملات.' : 'If you did not request this, ignore this email. No activation or campaign subscription will take place.' ) . '</p>';
			$url = self::link( $row );
			$body .= '<p>' . esc_html( $ar ? 'إذا لم يعمل الزر، انسخ الرابط التالي كاملًا وافتحه في المتصفح:' : 'If the button does not work, copy this entire link into your browser:' ) . '</p><p dir="ltr" style="word-break:break-all"><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
			$html = BrandedMail::render( $title, $body, $account ? ( $ar ? 'تفعيل حسابي' : 'Activate my account' ) : ( $ar ? 'تأكيد اشتراكي' : 'Confirm subscription' ), $url, esc_html( $ar ? 'لن نطلب منك إرسال كلمة المرور عبر البريد الإلكتروني.' : 'We never ask you to send your password by email.' ), $row['locale'] );
			if ( ! BrandedMail::send( $target['email'], $title, $html ) ) { return self::error( 'adc_verification_mail' ); }
			return false !== $wpdb->update( self::table(), array( 'sent_at'=>current_time( 'mysql', true ), 'locale'=>$row['locale'] ), array( 'id'=>$id, 'generation'=>$row['generation'] ) ) ? true : self::error( 'adc_verification_storage' );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	/** Atomic consume. Confirmation never grants a login session. */
	public static function confirm( string $token, string $password = '', string $expected_purpose = '' ) {
		global $wpdb;
		if ( ! preg_match( '/\A([a-f0-9]{64})\.([A-Za-z0-9_-]{43})\z/', $token, $parts ) ) { return self::error(); }
		// Resolve references first, then use the same target -> challenge lock order as issue().
		$reference = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE selector=%s', $parts[1] ), ARRAY_A );
		if ( $reference && ( ! in_array( $reference['purpose'], array( 'account', 'newsletter' ), true ) || ( $expected_purpose && $expected_purpose !== $reference['purpose'] ) ) ) { return self::error(); }
		if ( ! $reference || ! Transaction::begin() ) { return self::error(); }
		$target = self::target( $reference, true );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d FOR UPDATE', $reference['id'] ), ARRAY_A );
		if ( ! $row || ! $target || $wpdb->last_error || $row['used_at'] || ! hash_equals( $row['selector'], $parts[1] ) || ! hash_equals( $row['token_hash'], hash( 'sha256', $parts[2] ) ) || ! hash_equals( self::proof( $row ), $parts[2] ) || ! hash_equals( $row['email_hash'], self::email_hash( $target['email'] ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error(); }
		if(strtotime($row['expires_at'].' UTC')<=time()){$wpdb->query('ROLLBACK');return new \WP_Error('adc_verification_expired',self::t('انتهت صلاحية رابط التأكيد. اطلب رابطًا جديدًا أدناه دون إعادة التسجيل.','This verification link has expired. Request a new link below; you do not need to register again.'));}
		$now = current_time( 'mysql', true ); $id = (int) $row['subject_id'];
		if ( 'account' === $row['purpose'] ) {
			update_user_meta( $id, 'adc_email_verification_status', 'verified' );
			$verified_email_hash = self::account_email_hash( $target['email'] );
			update_user_meta( $id, 'adc_verified_email_hash', $verified_email_hash );
			update_user_meta( $id, 'adc_email_verified_at', $now );
			$ok = get_user_meta( $id, 'adc_email_verification_status', true ) === 'verified' && get_user_meta( $id, 'adc_verified_email_hash', true ) === $verified_email_hash && get_user_meta( $id, 'adc_email_verified_at', true ) === $now;
			$payload = array( 'subject_type'=>'user', 'subject_id'=>$id );
			$event = OutboxService::enqueue( 'customer.welcome', $payload, 'customer-welcome:' . $id );
		} else {
			$ok = 1 === $wpdb->update( NewsletterService::subscribers(), array( 'status'=>'active', 'confirmed_at'=>$now, 'confirmation_source'=>'newsletter_link', 'confirmation_user_id'=>0 ), array( 'id'=>$id, 'status'=>'pending', 'consent_key'=>$row['consent_key'] ) );
			$payload = array( 'subject_type'=>'subscriber', 'subject_id'=>$id, 'correlation_id'=>$row['consent_key'] );
			$event = OutboxService::enqueue( 'newsletter.subscription', $payload, 'subscription:' . $id . ':' . $row['consent_key'] );
		}
		$consumed = $wpdb->update( self::table(), array( 'used_at'=>$now ), array( 'id'=>$row['id'], 'generation'=>$row['generation'] ) );
		if ( ! $ok || 1 !== $consumed || is_wp_error( $event ) || ! Transaction::commit( static fn() => AuditLog::record( 'email.' . $row['purpose'] . '_confirmed', 'email_verification', (int) $row['id'], 'Email ownership confirmed', null, array( 'purpose'=>$row['purpose'], 'subject_id'=>$id ) ) ) ) {
			$wpdb->query( 'ROLLBACK' ); if ( 'account' === $row['purpose'] ) { clean_user_cache( $id ); } return self::error( 'adc_verification_storage' );
		}
		if ( 'account' === $row['purpose'] ) { clean_user_cache( $id ); NewsletterService::welcome( $payload ); } else { NewsletterService::subscription_email( $payload ); }
		NewsletterService::wake();
		return array( 'purpose'=>$row['purpose'], 'locale'=>$row['locale'] );
	}
	/** Public resend: a uniform response, regardless of account/address existence. */
	public static function request( string $purpose, string $email, bool $report_state = false ) {
		global $wpdb;
		if ( ! in_array( $purpose, array( 'account', 'newsletter' ), true ) || ! is_email( $email ) ) { return true; }
		if ( ! Transaction::begin() ) { return self::error( 'adc_verification_storage' ); }
		$email = strtolower( $email );
		if ( 'account' === $purpose ) {
			$user = $wpdb->get_row( $wpdb->prepare( "SELECT ID,user_email FROM {$wpdb->users} WHERE user_email=%s LIMIT 1 FOR UPDATE", $email ), ARRAY_A );
			$eligible = $user && get_user_meta( $user['ID'], 'adc_email_verification_status', true ) && self::blocked( (int) $user['ID'] );
			$id = (int) ( $user['ID'] ?? 0 ); $locale = (string) get_user_meta( $id, 'adc_preferred_language', true ); $key = '';
			if($report_state && $user && !self::blocked($id)) { $wpdb->query('ROLLBACK'); return array('status'=>'already_verified','locale'=>$locale); }
		} else {
			$subscriber = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NewsletterService::subscribers() . ' WHERE email=%s FOR UPDATE', $email ), ARRAY_A );
			$eligible = $subscriber && 'pending' === $subscriber['status'];
			$id = (int) ( $subscriber['id'] ?? 0 ); $locale = $subscriber['locale'] ?? 'ar'; $key = $subscriber['consent_key'] ?? '';
		}
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_verification_storage' ); }
		if ( ! $eligible ) { $wpdb->query( 'ROLLBACK' ); return true; }
		$payload = self::issue( $purpose, $id, $email, $locale, $key );
		if ( is_wp_error( $payload ) ) { $wpdb->query( 'ROLLBACK' ); return 'adc_verification_throttled' === $payload->get_error_code() ? true : $payload; }
		if ( 'account' === $purpose ) { update_user_meta( $id, 'adc_email_verification_status', 'pending' ); }
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'email.verification_requested', 'email_verification', (int) $payload['subject_id'], 'Verification link requested', null, array( 'purpose'=>$purpose ) ) ) ) { clean_user_cache( $id ); return self::error( 'adc_verification_storage' ); }
		clean_user_cache( $id ); self::deliver_committed($payload); NewsletterService::wake(); return true;
	}
	public static function email_changed( int $id, $old ): void {
		$user = get_userdata( $id );
		if ( ! $user || ! $old || strtolower( $old->user_email ) === strtolower( $user->user_email ) || ! get_user_meta( $id, 'adc_email_verification_status', true ) ) { return; }
		update_user_meta( $id, 'adc_email_verification_status', 'pending' );
		\WP_Session_Tokens::get_instance( $id )->destroy_all();
		self::request( 'account', $user->user_email );
	}
	public static function prune(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at<%s ORDER BY expires_at LIMIT 500', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
	}
	private static function field( string $name ): string { return isset( $_POST[$name] ) && is_string( $_POST[$name] ) ? wp_unslash( $_POST[$name] ) : ''; }
	public static function confirmed_account_token(string $token): bool {
		if(!preg_match('/\A([a-f0-9]{64})\.([A-Za-z0-9_-]{43})\z/',$token,$parts)) { return false; }
		global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table()." WHERE selector=%s AND purpose='account'",$parts[1]),ARRAY_A);
		if(!$row || empty($row['used_at']) || !hash_equals($row['token_hash'],hash('sha256',$parts[2])) || !hash_equals(self::proof($row),$parts[2]) || self::blocked((int)$row['subject_id'])) { return false; }
		$user=get_userdata((int)$row['subject_id']);return $user && hash_equals($row['email_hash'],self::email_hash($user->user_email));
	}
	public static function login_redirect(string $status,string $locale=''): void {
		$status='already_verified'===$status?'already_verified':'verified';$token=bin2hex(random_bytes(24));
		set_transient('adc_login_notice_'.hash('sha256',$token),$status,10*MINUTE_IN_SECONDS);
		setcookie('adc_account_notice',$token,array('expires'=>time()+600,'path'=>defined('COOKIEPATH')?COOKIEPATH:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
		$url=add_query_arg('cd_account','login',apply_filters('adc_customer_account_url',home_url('/'),''));
		wp_safe_redirect(\AutoDealership\Inventory\CatalogPresentation::localized_url($url,NewsletterService::language($locale?:\AutoDealership\Core\Localization::language())),303);exit;
	}
	public static function consume_login_notice(): string {
		$token=$_COOKIE['adc_account_notice']??'';if(!is_string($token) || !preg_match('/\A[a-f0-9]{48}\z/',$token)){return '';}
		$key='adc_login_notice_'.hash('sha256',$token);$status=get_transient($key);delete_transient($key);
		setcookie('adc_account_notice','',array('expires'=>time()-3600,'path'=>defined('COOKIEPATH')?COOKIEPATH:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
		return in_array($status,array('verified','already_verified'),true)?$status:'';
	}
	public static function page(): void {
		if(!isset($_GET['adc_verify'])){return;}
		$purpose=isset($_GET['purpose'])&&'newsletter'===$_GET['purpose']?'newsletter':'account';
		if(!in_array(wp_get_environment_type(),array('local','development'),true)&&!is_ssl()){wp_safe_redirect(set_url_scheme(self::page_url($purpose),'https'),302);exit;}
		nocache_headers();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
		// Link previews/prefetches never render an auto-activation document.
		$fetch=strtolower((string)($_SERVER['HTTP_SEC_PURPOSE']??$_SERVER['HTTP_PURPOSE']??''));
		if(str_contains($fetch,'prefetch')||str_contains($fetch,'prerender')){status_header(204);exit;}
		$nonce=base64_encode(random_bytes(18));header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
		$title=self::t('تأكيد البريد الإلكتروني','Verify your email');$success=false;$posted='POST'===($_SERVER['REQUEST_METHOD']??'');$retry_token='';
		$body='<p>'.esc_html(self::t('افتح أحدث رابط وصل إلى بريدك لإتمام التأكيد بأمان.','Open the latest link sent to your email to complete verification securely.')).'</p>';
		$generic=self::t('تم استلام طلبك. إذا كان البريد ينتظر التأكيد وضمن حدود الإرسال، فسيصلك رابط جديد. تحقق من صندوق الوارد والبريد غير المرغوب فيه. إذا كان حسابك مفعّلًا، يمكنك تسجيل الدخول مباشرة.','Your request has been received. If the email is awaiting verification and within sending limits, a new link will be sent. Check your inbox and spam folder. If your account is already active, you can sign in directly.');
		if(isset($_GET['requested'])){$title=self::t('تحقق من بريدك الإلكتروني','Check your email');$body='<p role="status">'.esc_html($generic).'</p>';}
		if($posted){
			$rate=PublicRequestGuard::consume('email_verification');$valid=wp_verify_nonce(self::field('_wpnonce'),'adc_email_verify');$operation=self::field('operation');
			if(!$valid||is_wp_error($rate)){
				status_header(is_wp_error($rate)?(int)($rate->get_error_data()['status']??503):403);
				if(is_wp_error($rate)&&'adc_rate_limited'===$rate->get_error_code()){header('Retry-After: '.(int)($rate->get_error_data()['retry_after']??60));}
				$body='<p role="alert">'.esc_html(self::t('تعذر إتمام الطلب الآن. حدّث الصفحة أو حاول لاحقًا.','Unable to complete the request now. Refresh the page or try again later.')).'</p>';
			}elseif(in_array($operation,array('resend','change_email'),true)){
				$email=trim(self::field('email'));
				$result=is_email($email)&&strlen($email)<=100?VerificationExperience::reserve_resend($purpose):new \WP_Error('adc_email_invalid');
				if(!is_wp_error($result)){$result='change_email'===$operation&&'account'===$purpose?VerificationExperience::change_email($email):self::request($purpose,$email);}
				if(is_wp_error($result)){
					$code=$result->get_error_code();$wait=in_array($code,array('adc_verification_throttled','adc_rate_limited'),true);
					status_header($wait?429:400);if($wait){header('Retry-After: '.max(1,VerificationExperience::remaining($purpose)));}
					$message=$wait?self::t('انتظر حتى انتهاء مهلة إعادة الإرسال ثم حاول مجددًا.','Wait for the resend cooldown to finish, then try again.'):('adc_email_invalid'===$code?self::t('أدخل بريدًا إلكترونيًا صحيحًا.','Enter a valid email address.'):self::t('تعذر تنفيذ الطلب الآن. تحقق من البيانات وحاول لاحقًا. إذا استمرت المشكلة، تواصل معنا.','Unable to process this request now. Check the details and try again later. Contact us if the problem persists.'));
					$body='<p role="alert">'.esc_html($message).'</p>';
				}else{
					// Post/Redirect/Get: refresh never sends another message.
					wp_safe_redirect(add_query_arg('requested',1,self::page_url($purpose)),303);exit;
				}
			}elseif('confirm'===$operation){
				$token=self::field('token');
				$result=VerificationExperience::valid_browser_stamp(self::field('browser_stamp'),$purpose)?self::confirm($token,'',$purpose):new \WP_Error('adc_browser_required',self::t('تعذر تأكيد الطلب. أعد فتح الرابط من البريد وانتظر لحظة لاكتمال التحقق.','Unable to confirm this request. Reopen the email link and wait a moment for verification.'));
				if('account'===$purpose&&is_wp_error($result)&&'adc_browser_required'!==$result->get_error_code()&&self::confirmed_account_token($token)){self::login_redirect('already_verified');}
				if(!is_wp_error($result)&&'account'===$result['purpose']){self::login_redirect('verified',$result['locale']);}
				if(is_wp_error($result)){
					status_header(400);$body='<p role="alert">'.esc_html($result->get_error_message()).'</p>';
					if('adc_browser_required'===$result->get_error_code()&&preg_match('/\A[a-f0-9]{64}\.[A-Za-z0-9_-]{43}\z/',$token)){$retry_token=$token;}
				}else{$success=true;$title=self::t('تم تأكيد اشتراكك','Your subscription is confirmed');$body='<p role="status">'.esc_html(self::t('ستصلك الحملات القادمة باللغة المفضلة لديك. يمكنك إلغاء الاشتراك في أي وقت.','You will receive future campaigns in your preferred language. You can unsubscribe at any time.')).'</p>';}
			}else{status_header(400);}
		}
		if(!$success){
			$stamp=VerificationExperience::browser_stamp($purpose);
			$body.='<form id="adc-verify-form" hidden method="post" action="'.esc_url(self::page_url($purpose)).'">'.str_replace('id="_wpnonce"','id="adc-confirm-nonce"',wp_nonce_field('adc_email_verify','_wpnonce',false,false)).'<input type="hidden" name="browser_stamp" value="'.esc_attr($stamp).'"><input type="hidden" name="token" id="adc-verify-token" value="'.esc_attr($retry_token).'"><input type="hidden" name="operation" value="confirm"><p id="adc-confirm-help" role="status">'.esc_html(self::t('جارٍ تجهيز التأكيد الآمن…','Preparing secure verification…')).'</p><button id="adc-verify-submit" disabled type="submit">'.esc_html('account'===$purpose?self::t('تأكيد وتفعيل الحساب','Confirm and activate account'):self::t('تأكيد الاشتراك','Confirm subscription')).'</button></form><noscript><p>'.esc_html(self::t('فعّل JavaScript ثم افتح رابط البريد مجددًا لإتمام التأكيد.','Enable JavaScript and reopen the email link to complete verification.')).'</p></noscript>';
			$remaining=VerificationExperience::remaining($purpose);
			$body.='<section class="adc-resend"><h2>'.esc_html(self::t('طلب رابط تأكيد جديد','Request a new verification link')).'</h2><form data-resend-form method="post" action="'.esc_url(self::page_url($purpose)).'">'.str_replace('id="_wpnonce"','id="adc-resend-nonce"',wp_nonce_field('adc_email_verify','_wpnonce',false,false)).'<input type="hidden" name="operation" value="resend"><label for="adc-resend-email">'.esc_html(self::t('البريد الإلكتروني','Email address')).'</label><input id="adc-resend-email" name="email" type="email" autocomplete="email" maxlength="100" dir="ltr" required><button data-resend-button type="submit" '.($remaining?'disabled':'').'>'.esc_html(self::t('إرسال رابط جديد','Send a new link')).'</button></form><p id="adc-resend-wait" role="status" aria-live="polite" data-remaining="'.(int)$remaining.'"></p></section>';
			if('account'===$purpose&&VerificationExperience::enrollment_id()){
				$body.='<details class="adc-correction"><summary>'.esc_html(self::t('هل كتبت بريدك بشكل غير صحيح؟','Entered the wrong email?')).'</summary><p>'.esc_html(self::t('يمكنك تصحيح البريد من المتصفح الذي أنشأت الحساب منه خلال 30 دقيقة. سيُلغى الرابط السابق ولن يتغير اختيارك للتسويق.','You can correct your email in the browser used for registration within 30 minutes. The previous link will be invalidated; your marketing choice is unchanged.')).'</p><form data-resend-form method="post" action="'.esc_url(self::page_url('account')).'">'.str_replace('id="_wpnonce"','id="adc-correction-nonce"',wp_nonce_field('adc_email_verify','_wpnonce',false,false)).'<input type="hidden" name="operation" value="change_email"><label for="adc-correction-email">'.esc_html(self::t('البريد الإلكتروني الصحيح','Correct email address')).'</label><input id="adc-correction-email" type="email" name="email" autocomplete="email" maxlength="100" dir="ltr" required><button data-resend-button type="submit" '.($remaining?'disabled':'').'>'.esc_html(self::t('حفظ وإرسال رابط جديد','Save and send a new link')).'</button></form></details>';
			}
		}
		$url='account'===$purpose?add_query_arg('cd_account','login',apply_filters('adc_customer_account_url',home_url('/'),'')):home_url('/');$url=\AutoDealership\Inventory\CatalogPresentation::localized_url($url,\AutoDealership\Core\Localization::language());
		$html=BrandedMail::render($title,'','','','',\AutoDealership\Core\Localization::language());
		$html=str_replace('</h1>','</h1>'.$body.'<p><a href="'.esc_url($url).'">'.esc_html('account'===$purpose?self::t('الذهاب إلى تسجيل الدخول','Go to sign in'):self::t('العودة إلى الموقع','Back to the website')).'</a></p>',$html);
		$css='<style>form input[type=email]{display:block;width:100%;box-sizing:border-box;padding:13px;margin:8px 0 16px;border:1px solid #94a3b8;border-radius:9px;font:inherit;background:#fff;color:#0f172a}button{padding:12px 22px;border:0;border-radius:9px;background:#1d4ed8;color:#fff;font:inherit;cursor:pointer;max-width:100%}button:disabled{opacity:.55;cursor:wait}button:focus-visible,input:focus-visible,summary:focus-visible{outline:3px solid #60a5fa;outline-offset:3px}.adc-resend{border-top:1px solid #cbd5e1;margin-top:24px;padding-top:12px}.adc-resend h2{font-size:18px}.adc-correction{margin:20px 0;padding:14px;background:#f1f5f9;border-radius:10px;color:#0f172a}.adc-correction summary{cursor:pointer;font-weight:600}#adc-resend-wait{font-size:14px;color:#475569;min-height:22px}form[hidden]{display:none}</style>';
		$html=str_replace('</head>',$css.'</head>',$html);
		if(!$success){
			$config=wp_json_encode(array('account'=>'account'===$purpose,'waiting'=>self::t('يمكنك طلب رابط جديد بعد %s ثانية.','You can request a new link in %s seconds.'),'ready'=>self::t('يمكنك الآن طلب رابط جديد.','You can now request a new link.'),'confirming'=>self::t('جارٍ تأكيد الحساب…','Confirming your account…'),'manual'=>self::t('اضغط الزر لإتمام التأكيد.','Press the button to complete verification.')),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
			$script=<<<'JS'
(()=>{const cfg=CONFIG;const f=document.getElementById('adc-verify-form'),b=document.getElementById('adc-verify-submit'),input=document.getElementById('adc-verify-token');const original=new URLSearchParams(location.hash.slice(1)).get('token');const token=original||input.value;history.replaceState(null,'',location.pathname+location.search);let started=false;if(token&&/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(token)){input.value=token;f.hidden=false;const prepare=()=>{if(started||document.visibilityState!=='visible'||document.prerendering)return;started=true;setTimeout(()=>{if(document.visibilityState!=='visible'){started=false;return;}b.disabled=false;document.getElementById('adc-confirm-help').textContent=cfg.manual;if(original&&cfg.account){document.getElementById('adc-confirm-help').textContent=cfg.confirming;f.requestSubmit();}},1600);};document.addEventListener('visibilitychange',prepare);document.addEventListener('prerenderingchange',prepare);prepare();}const wait=document.getElementById('adc-resend-wait');let end=Date.now()+Number(wait.dataset.remaining)*1000;let previous=-1;const tick=()=>{const n=Math.max(0,Math.ceil((end-Date.now())/1000));document.querySelectorAll('[data-resend-button]').forEach(x=>x.disabled=n>0);if(n!==previous){wait.textContent=n?cfg.waiting.replace('%s',n):cfg.ready;previous=n;}};tick();setInterval(tick,1000);document.querySelectorAll('[data-resend-form]').forEach(form=>form.addEventListener('submit',()=>{end=Date.now()+60000;tick();}));})();
JS;
			$html=str_replace('</body>','<script nonce="'.esc_attr($nonce).'">'.str_replace('CONFIG',$config,$script).'</script></body>',$html);
		}
		header('Content-Type: text/html; charset=UTF-8');echo $html;exit;
	}

}
