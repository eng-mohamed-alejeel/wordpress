<?php
namespace AutoDealership\Leads;

use AutoDealership\Accounts\BrandedMail;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;
use AutoDealership\Operations\OutboxService;

defined( 'ABSPATH' ) || exit;

/** Consent-aware newsletter campaigns using the existing durable outbox. */
final class NewsletterService {
	public static function table( string $name ): string { global $wpdb; return $wpdb->prefix . 'adc_newsletter_' . $name; }
	public static function subscribers(): string { global $wpdb; return $wpdb->prefix . 'car_dealer_subscribers'; }
	public static function language( string $locale ): string { return str_starts_with( strtolower( $locale ), 'en' ) ? 'en' : 'ar'; }
	private static function t( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }
	private static function error( string $code, string $message = '' ): \WP_Error {
		$translations = array(
			'ليست لديك صلاحية إدارة الحملات.' => 'You do not have permission to manage newsletter campaigns.',
			'رابط إلغاء الاشتراك غير صالح.' => 'This unsubscribe link is invalid.',
			'لا يمكن تعديل حملة بعد بدء إرسالها.' => 'A campaign cannot be edited after sending has started.',
			'هذه الحملة أُرسلت أو أُلغيت بالفعل.' => 'This campaign has already been sent or cancelled.',
			'لا يوجد مشتركون نشطون لإرسال الحملة.' => 'There are no active subscribers for this campaign.',
			'أدخل عنوانًا ومحتوى صالحين، ورابطًا صحيحًا للزر إن وُجد.' => 'Enter a valid subject and message, and a valid button URL if provided.',
		);
		$message = $message ? self::t( $message, $translations[$message] ?? $message ) : self::t( 'تعذر تنفيذ العملية. حاول مرة أخرى.', 'The operation could not be completed. Please try again.' );
		return new \WP_Error( $code, $message );
	}
	public static function can_manage(): bool { return current_user_can( 'adc_manage_newsletter' ); }
	public static function boot(): void {
		self::install();
		BrandedMail::boot();
		\AutoDealership\Accounts\EmailVerification::boot();
		OutboxService::register_handler( 'customer.welcome', array( self::class, 'welcome' ) );
		OutboxService::register_handler( 'newsletter.subscription', array( self::class, 'subscription_email' ) );
		OutboxService::register_handler( 'newsletter.expand', array( self::class, 'expand' ) );
		OutboxService::register_handler( 'newsletter.deliver', array( self::class, 'deliver' ) );
		add_action( 'adc_outbox_failed', array( self::class, 'failed' ) );
		add_action( 'template_redirect', array( self::class, 'unsubscribe_page' ), 1 );
	}

	public static function install(): void {
		if ( '5' === get_option( 'adc_newsletter_schema' ) ) { return; }
		global $wpdb;
		$lock = 'adc_newsletter_schema_' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return; }
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$collate = $wpdb->get_charset_collate(); $campaigns = self::table( 'campaigns' ); $deliveries = self::table( 'deliveries' );
			dbDelta( "CREATE TABLE $campaigns (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subject varchar(190) NOT NULL,
			body longtext NOT NULL,
			button_text varchar(100) NOT NULL DEFAULT '',
			button_url text NOT NULL,
			locale varchar(20) NOT NULL DEFAULT 'ar',
			bilingual tinyint(1) NOT NULL DEFAULT 0,
			subject_ar varchar(190) NOT NULL DEFAULT '',
			subject_en varchar(190) NOT NULL DEFAULT '',
			body_ar longtext NOT NULL,
			body_en longtext NOT NULL,
			button_text_ar varchar(100) NOT NULL DEFAULT '',
			button_text_en varchar(100) NOT NULL DEFAULT '',
			button_url_ar text NOT NULL,
			button_url_en text NOT NULL,
			creation_key varchar(36) DEFAULT NULL,
			copied_from bigint(20) unsigned NOT NULL DEFAULT 0,
			archived_at datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			expanded tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			queued_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY creation_key (creation_key),
			KEY status (status),
			KEY archived_at (archived_at)
			) $collate ENGINE=InnoDB;" );
			dbDelta( "CREATE TABLE $deliveries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			subscriber_id bigint(20) unsigned NOT NULL,
			consent_key varchar(36) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			outbox_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sent_at datetime NULL,
			last_error varchar(100) NOT NULL DEFAULT '',
			locale varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_subscriber (campaign_id,subscriber_id),
			KEY campaign_status (campaign_id,status)
			) $collate ENGINE=InnoDB;" );
			$ready = true;
			$subscribers = self::subscribers();
			if ( LegacyEngagementStore::is_ready() && ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $subscribers LIKE %s", 'confirmed_at' ) ) ) {
				$wpdb->query( "ALTER TABLE $subscribers ADD confirmed_at datetime NULL" );
			}
			$ready = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $subscribers LIKE %s", 'confirmed_at' ) );
			foreach ( array( 'confirmation_source'=>"varchar(20) NOT NULL DEFAULT ''", 'confirmation_user_id'=>'bigint(20) unsigned NOT NULL DEFAULT 0' ) as $column=>$definition ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $subscribers LIKE %s", $column ) ) ) { $wpdb->query( "ALTER TABLE $subscribers ADD $column $definition" ); }
				$ready = $ready && (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $subscribers LIKE %s", $column ) );
			}
			foreach ( array( $campaigns, $deliveries ) as $table ) {
				$ready = $ready && 'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			}
			if ( $ready && LegacyEngagementStore::is_ready() ) {
				$ar = $wpdb->query( "UPDATE $campaigns SET subject_ar=subject,body_ar=body,button_text_ar=button_text,button_url_ar=button_url WHERE bilingual=0 AND locale NOT LIKE 'en%' AND subject_ar=''" );
				$en = $wpdb->query( "UPDATE $campaigns SET subject_en=subject,body_en=body,button_text_en=button_text,button_url_en=button_url WHERE bilingual=0 AND locale LIKE 'en%' AND subject_en=''" );
				if ( false !== $ar && false !== $en ) { update_option( 'adc_newsletter_schema', '5', false ); }
			}
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	public static function wake(): void {
		$scheduled = wp_next_scheduled( 'adc_mail_wake' );
		if ( ! $scheduled || $scheduled > time() ) {
			if ( $scheduled ) { wp_clear_scheduled_hook( 'adc_mail_wake' ); }
			wp_schedule_single_event( time(), 'adc_mail_wake' );
		}
		// Start a non-blocking worker after committed intake rather than exposing SMTP latency.
		if ( 'cli' !== PHP_SAPI && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) { spawn_cron(); }
	}

	/** Caller holds account -> subscriber locks and has obtained explicit marketing consent. */
	public static function activate_for_current_account( int $subscriber_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::subscribers() . ' WHERE id=%d FOR UPDATE', $subscriber_id ), ARRAY_A );
		if ( $wpdb->last_error || ! $row ) { return self::error( 'adc_subscription_storage' ); }
		if ( ! \AutoDealership\Accounts\EmailVerification::current_account_verified_for( $row['email'] ) ) { return false; }
		if ( '5' !== get_option( 'adc_newsletter_schema' ) ) { return self::error( 'adc_subscription_storage' ); }
		if ( 'active' === $row['status'] ) { return true; }
		if ( ! in_array( $row['status'], array( 'pending', 'unsubscribed' ), true ) ) { return self::error( 'adc_subscription_status' ); }
		$now = current_time( 'mysql', true ); $user_id = get_current_user_id(); $key = wp_generate_uuid4();
		if ( 1 !== $wpdb->update( self::subscribers(), array( 'status'=>'active', 'consent_key'=>$key, 'created_at'=>$now, 'confirmed_at'=>$now, 'confirmation_source'=>'verified_account', 'confirmation_user_id'=>$user_id, 'welcome_sent_at'=>null ), array( 'id'=>$subscriber_id ) ) ) { return self::error( 'adc_subscription_storage' ); }
		if ( false === $wpdb->update( \AutoDealership\Accounts\EmailVerification::table(), array( 'used_at'=>$now ), array( 'purpose'=>'newsletter', 'subject_id'=>$subscriber_id ) ) ) { return self::error( 'adc_subscription_storage' ); }
		$payload = array( 'subject_type'=>'subscriber', 'subject_id'=>$subscriber_id, 'correlation_id'=>$key );
		$event = OutboxService::enqueue( 'newsletter.subscription', $payload, 'subscription:' . $subscriber_id . ':' . $key );
		if ( is_wp_error( $event ) || ! AuditLog::record( 'marketing.subscription_confirmed_by_account', 'subscriber', $subscriber_id, 'Explicit opt-in with verified account email', array( 'status'=>$row['status'] ), array( 'status'=>'active', 'confirmation_source'=>'verified_account', 'account_user_id'=>$user_id ) ) ) { return self::error( 'adc_subscription_storage' ); }
		return $payload;
	}

	/** Prepare recipients and deliver a bounded batch during the same worker invocation. */
	public static function run_mail() {
		$keys = array( 'email.verify', 'customer.welcome', 'newsletter.subscription', 'newsletter.expand', 'newsletter.deliver' );
		$priority = OutboxService::process_due( 5, array( 'email.verify' ) );
		if ( is_wp_error( $priority ) ) { return $priority; }
		$first = OutboxService::process_due( 7, $keys );
		if ( is_wp_error( $first ) ) { return $first; }
		$second = OutboxService::process_due( 8, $keys );
		if ( is_wp_error( $second ) ) { return $second; }
		global $wpdb;
		$next = $wpdb->get_var( "SELECT MIN(next_attempt_at) FROM " . OutboxService::table() . " WHERE event_key IN ('email.verify','customer.welcome','newsletter.subscription','newsletter.expand','newsletter.deliver') AND status IN ('pending','retry')" );
		if ( $next ) {
			$when = max( time() + MINUTE_IN_SECONDS, strtotime( $next . ' UTC' ) );
			$scheduled = wp_next_scheduled( 'adc_mail_wake' );
			if ( ! $scheduled || $scheduled !== $when ) {
				wp_clear_scheduled_hook( 'adc_mail_wake' );
				wp_schedule_single_event( $when, 'adc_mail_wake' );
			}
		}
		foreach ( $second as $key => $value ) { $first[$key] += $value + $priority[$key]; }
		return $first;
	}

	/** Serialize immediate delivery and a coinciding outbox retry for the same recipient. */
	private static function locked( string $key, callable $callback ) {
		global $wpdb; $lock = 'adc_mail_' . md5( DB_NAME . $wpdb->prefix . $key );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return self::error( 'adc_mail_busy' ); }
		try { return $callback(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	public static function welcome( array $payload ) {
		$id = (int) ( $payload['subject_id'] ?? 0 );
		return self::locked( 'welcome:' . $id, static function () use ( $id, $payload ) {
			$user = get_userdata( $id );
			if ( ! $user || ! in_array( 'car_dealer_customer', (array) $user->roles, true ) ) { return true; }
			if ( \AutoDealership\Accounts\EmailVerification::blocked( $id ) ) { return true; }
			if ( get_user_meta( $id, 'adc_welcome_sent_at', true ) ) { return true; }
			$language = get_user_meta( $id, 'adc_preferred_language', true );
			$language = in_array( $language, array( 'ar', 'en' ), true ) ? $language : self::language( $payload['locale'] ?? 'ar' );
			$ar = 'ar' === $language;
			$brand = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$title = $ar ? 'مرحبًا بك في ' . $brand : 'Welcome to ' . $brand;
			$body = '<p>' . esc_html( $ar ? 'مرحبًا ' . $user->display_name . '،' : 'Hello ' . $user->display_name . ',' ) . '</p><p>' . esc_html( $ar ? 'تم إنشاء حسابك بنجاح. يسعدنا انضمامك إلى أوتو براندز، ونتطلع لمساعدتك في العثور على سيارتك المناسبة.' : 'Your account has been created successfully. We look forward to helping you find your next car.' ) . '</p><p>' . esc_html( $ar ? 'يمكنك الآن الدخول إلى حسابك ومتابعة طلباتك وتحديث بياناتك في أي وقت.' : 'You can now access your account, follow your requests and update your profile.' ) . '</p>';
			$url = apply_filters( 'adc_customer_account_url', add_query_arg( 'cd_account', 'dashboard', home_url( '/' ) ), '' );
			$url = \AutoDealership\Inventory\CatalogPresentation::localized_url( $url, $language );
			$html = BrandedMail::render( $title, $body, $ar ? 'الدخول إلى حسابي' : 'My account', $url, esc_html( $ar ? 'لا نطلب كلمة مرورك عبر البريد. احتفظ ببيانات الدخول الخاصة بك بأمان.' : 'We never ask for your password by email. Keep your login details safe.' ), $language );
			if ( ! BrandedMail::send( $user->user_email, $title, $html ) ) { return self::error( 'adc_welcome_mail' ); }
			return update_user_meta( $id, 'adc_welcome_sent_at', current_time( 'mysql', true ) ) ? true : self::error( 'adc_welcome_status' );
		} );
	}

	public static function subscription_email( array $payload ) {
		$id = (int) ( $payload['subject_id'] ?? 0 );
		return self::locked( 'subscription:' . $id, static function () use ( $id, $payload ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::subscribers() . ' WHERE id=%d', $id ), ARRAY_A );
			if ( $wpdb->last_error ) { return self::error( 'adc_subscription_read' ); }
			if ( ! $row || 'active' !== $row['status'] || $row['consent_key'] !== ( $payload['correlation_id'] ?? '' ) || $row['welcome_sent_at'] ) { return true; }
			$ar = 'ar' === self::language( $row['locale'] ); $locale = $ar ? 'ar' : 'en_US';
			$title = $ar ? 'أهلًا بك في نشرة أوتو براندز' : 'Welcome to the AutoBrands newsletter';
			$body = '<p>' . esc_html( $ar ? 'تم اشتراكك بنجاح، ويسعدنا أن تكون معنا.' : 'You are subscribed. We are glad to have you with us.' ) . '</p><p>' . esc_html( $ar ? 'ستصلك أحدث أخبار السيارات والعروض المختارة من أوتو براندز. يمكنك إلغاء الاشتراك في أي وقت من الرابط أسفل الرسالة.' : 'Look out for our latest car news and selected offers. You can unsubscribe at any time using the link below.' ) . '</p>';
			$url = self::unsubscribe_url( $row );
			$html = BrandedMail::render( $title, $body, $ar ? 'استكشف السيارات' : 'Explore cars', \AutoDealership\Inventory\CatalogPresentation::localized_url( home_url( '/' ), $ar ? 'ar' : 'en' ), self::footer( $url, $locale ), $locale );
			if ( ! BrandedMail::send( $row['email'], $title, $html, self::unsubscribe_headers( $url ) ) ) { return self::error( 'adc_subscription_mail' ); }
			return false !== $wpdb->update( self::subscribers(), array( 'welcome_sent_at' => current_time( 'mysql', true ) ), array( 'id' => $id, 'consent_key' => $row['consent_key'] ) ) ? true : self::error( 'adc_subscription_status' );
		} );
	}

	public static function unsubscribe_url( array $subscriber ): string {
		$token = hash_hmac( 'sha256', $subscriber['id'] . '|' . strtolower( $subscriber['email'] ) . '|' . $subscriber['consent_key'], wp_salt( 'auth' ) );
		return add_query_arg( array( 'adc_unsubscribe' => $subscriber['id'], 'token' => $token, 'lang' => self::language( $subscriber['locale'] ?? 'ar' ) ), home_url( '/' ) );
	}
	private static function unsubscribe_headers( string $url ): array { return array( 'List-Unsubscribe: <' . $url . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ); }
	private static function footer( string $url, string $locale ): string {
		$ar = str_starts_with( $locale, 'ar' );
		return '<p>' . esc_html( $ar ? 'تستلم هذه الرسالة لأنك اشتركت في نشرة أوتو براندز.' : 'You received this email because you subscribed to AutoBrands.' ) . '</p><a href="' . esc_url( $url ) . '" style="color:#2563EB">' . esc_html( $ar ? 'إلغاء الاشتراك' : 'Unsubscribe' ) . '</a>';
	}

	public static function unsubscribe( int $id, string $token ) {
		global $wpdb;
		if ( ! Transaction::begin() ) { return self::error( 'adc_unsubscribe_storage' ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::subscribers() . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( ! $row || $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_unsubscribe_invalid', 'رابط إلغاء الاشتراك غير صالح.' ); }
		$expected = hash_hmac( 'sha256', $row['id'] . '|' . strtolower( $row['email'] ) . '|' . $row['consent_key'], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $token ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_unsubscribe_invalid', 'رابط إلغاء الاشتراك غير صالح.' ); }
		$updated = $wpdb->update( self::subscribers(), array( 'status' => 'unsubscribed' ), array( 'id' => $id, 'consent_key' => $row['consent_key'] ) );
		if ( false === $updated || ! Transaction::commit( static fn() => AuditLog::record( 'marketing.unsubscribed', 'subscriber', $id, 'Signed subscriber request', null, array( 'status' => 'unsubscribed' ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_unsubscribe_storage' ); }
		return true;
	}

	public static function unsubscribe_page(): void {
		if ( ! isset( $_GET['adc_unsubscribe'] ) ) { return; }
		$id = absint( $_GET['adc_unsubscribe'] );
		$token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		nocache_headers(); header( 'Referrer-Policy: no-referrer' );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$result = self::unsubscribe( $id, $token );
			$title = is_wp_error( $result ) ? $result->get_error_message() : self::t( 'تم إلغاء اشتراكك', 'You have unsubscribed' );
			$body = is_wp_error( $result ) ? self::t( 'تحقق من الرابط الموجود في أحدث رسالة بريدية.', 'Please use the link in your most recent newsletter.' ) : self::t( 'لن تصلك حملات النشرة البريدية القادمة. يمكنك الاشتراك مجددًا من الموقع في أي وقت.', 'You will no longer receive newsletter campaigns. You can subscribe again on the website at any time.' );
			status_header( is_wp_error( $result ) ? 400 : 200 );
		} else {
			$title = self::t( 'إلغاء الاشتراك في النشرة البريدية', 'Unsubscribe from the newsletter' );
			$body = self::t( 'هل ترغب في إيقاف رسائل النشرة البريدية؟', 'Would you like to stop receiving newsletter emails?' );
		}
		$language = \AutoDealership\Core\Localization::language();
		$html = BrandedMail::render( $title, '<p>' . esc_html( $body ) . '</p>', '', '', esc_html( get_option( 'blogname' ) ), $language );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$action = add_query_arg( array( 'adc_unsubscribe' => $id, 'token' => $token, 'lang' => $language ), home_url( '/' ) );
			$html = str_replace( '</h1>', '</h1><form method="post" action="' . esc_url( $action ) . '"><button type="submit" style="padding:14px 24px;background:#2563EB;border:0;border-radius:8px;color:white;font:inherit;cursor:pointer">' . esc_html( self::t( 'تأكيد إلغاء الاشتراك', 'Confirm unsubscribe' ) ) . '</button></form>', $html );
		}
		header( 'Content-Type: text/html; charset=UTF-8' ); echo $html; exit;
	}

	public static function campaign( int $id ): ?array { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d', $id ), ARRAY_A ); }
	public static function content_fields( array $input ): array {
		$data = array();
		foreach ( array( 'ar', 'en' ) as $lang ) {
			$data['subject_' . $lang] = sanitize_text_field( $input['subject_' . $lang] ?? '' );
			$data['body_' . $lang] = wp_kses_post( $input['body_' . $lang] ?? '' );
			$data['button_text_' . $lang] = sanitize_text_field( $input['button_text_' . $lang] ?? '' );
			$data['button_url_' . $lang] = esc_url_raw( $input['button_url_' . $lang] ?? '', array( 'http', 'https' ) );
		}
		return $data;
	}
	public static function variant( array $campaign, string $language ): array {
		if ( empty( $campaign['bilingual'] ) ) { return array_intersect_key( $campaign, array_flip( array( 'subject', 'body', 'button_text', 'button_url', 'locale' ) ) ); }
		$lang = self::language( $language ); $variant = array( 'locale' => $lang );
		foreach ( array( 'subject', 'body', 'button_text', 'button_url' ) as $field ) { $variant[$field] = $campaign[$field . '_' . $lang] ?? ''; }
		return $variant;
	}
	public static function preview( array $input, string $language ): string {
		$lang = self::language( $language ); $data = self::content_fields( $input );
		if ( '' === $data['subject_' . $lang] ) { $data['subject_' . $lang] = 'ar' === $lang ? 'عنوان رسالتك' : 'Your email subject'; }
		if ( '' === trim( wp_strip_all_tags( $data['body_' . $lang] ) ) ) { $data['body_' . $lang] = '<p>' . ( 'ar' === $lang ? 'ابدأ بكتابة المحتوى لتظهر معاينة رسالتك هنا مباشرة.' : 'Start writing to see your email preview here.' ) . '</p>'; }
		return self::html( $data + array( 'bilingual' => 1 ), null, $lang );
	}
	public static function save( array $input, int $id = 0 ) {
		if ( ! self::can_manage() ) { return self::error( 'adc_newsletter_forbidden', 'ليست لديك صلاحية إدارة الحملات.' ); }
		$bilingual = array_key_exists( 'subject_ar', $input ) || array_key_exists( 'subject_en', $input );
		$fields = $bilingual ? self::content_fields( $input ) : array();
		if ( $bilingual ) {
			foreach ( array( 'ar', 'en' ) as $lang ) {
				if ( mb_strlen( $fields['subject_' . $lang] ) > 190 || strlen( $fields['body_' . $lang] ) > 100000 || mb_strlen( $fields['button_text_' . $lang] ) > 100 || ( ! empty( $input['button_url_' . $lang] ) && '' === $fields['button_url_' . $lang] ) ) { return self::error( 'adc_newsletter_invalid', 'أدخل عنوانًا ومحتوى صالحين، ورابطًا صحيحًا للزر إن وُجد.' ); }
			}
			$lang = $fields['subject_ar'] ? 'ar' : 'en';
			$input['subject'] = $fields['subject_' . $lang];
			$input['body'] = $fields['body_' . $lang] ?: $fields['body_' . ( 'ar' === $lang ? 'en' : 'ar' )];
			$input['button_text'] = $fields['button_text_' . $lang];
			$input['button_url'] = $fields['button_url_' . $lang];
			$input['locale'] = $lang;
		}
		$subject = sanitize_text_field( $input['subject'] ?? '' );
		$body = wp_kses_post( $input['body'] ?? '' );
		$button = sanitize_text_field( $input['button_text'] ?? '' );
		$url = esc_url_raw( $input['button_url'] ?? '', array( 'http', 'https' ) );
		if ( '' === $subject || mb_strlen( $subject ) > 190 || ( ! $bilingual && '' === trim( wp_strip_all_tags( $body ) ) ) || strlen( $body ) > 100000 || mb_strlen( $button ) > 100 || ( ! $bilingual && '' !== $button && '' === $url ) || ( ! empty( $input['button_url'] ) && '' === $url ) ) { return self::error( 'adc_newsletter_invalid', 'أدخل عنوانًا ومحتوى صالحين، ورابطًا صحيحًا للزر إن وُجد.' ); }
		global $wpdb;
		$creation_key = $input['creation_key'] ?? '';
		if ( ! is_string( $creation_key ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $creation_key ) ) { $creation_key = wp_generate_uuid4(); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		if ( ! $id ) { $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'campaigns' ) . ' WHERE creation_key=%s FOR UPDATE', $creation_key ) ); }
		if ( $id ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
			if ( ! $existing || 'draft' !== $existing['status'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_locked', 'لا يمكن تعديل حملة بعد بدء إرسالها.' ); }
		}
		$data = array( 'subject' => $subject, 'body' => $body, 'button_text' => $button, 'button_url' => $url, 'locale' => self::language( $input['locale'] ?? 'ar' ), 'bilingual' => $bilingual ? 1 : 0 ) + $fields;
		$ok = $id ? $wpdb->update( self::table( 'campaigns' ), $data, array( 'id' => $id ) ) : $wpdb->insert( self::table( 'campaigns' ), $data + array( 'creation_key' => $creation_key, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
		$id = $id ?: (int) $wpdb->insert_id;
		if ( false === $ok || ! Transaction::commit( static fn() => AuditLog::record( 'newsletter.draft_saved', 'newsletter_campaign', $id, 'Campaign draft saved' ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		return $id;
	}

	public static function html( array $campaign, ?array $subscriber = null, string $language = 'ar' ): string {
		$variant = self::variant( $campaign, $subscriber['locale'] ?? $language );
		$footer = $subscriber ? self::footer( self::unsubscribe_url( $subscriber ), $variant['locale'] ) : '<p>' . esc_html( 'ar' === self::language( $variant['locale'] ) ? 'تُرسل هذه النسخة للمشتركين الذين يفضلون العربية. يُضاف رابط إلغاء الاشتراك عند الإرسال.' : 'This version is sent to subscribers who prefer English. An unsubscribe link is added when sending.' ) . '</p>';
		return BrandedMail::render( $variant['subject'], wpautop( $variant['body'] ), $variant['button_text'], $variant['button_url'], $footer, $variant['locale'] );
	}

	public static function queue( int $id ) {
		if ( ! self::can_manage() ) { return self::error( 'adc_newsletter_forbidden' ); }
		global $wpdb;
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( ! $campaign || 'draft' !== $campaign['status'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_locked', 'هذه الحملة أُرسلت أو أُلغيت بالفعل.' ); }
		if ( ! empty( $campaign['bilingual'] ) ) {
			foreach ( array( 'ar', 'en' ) as $lang ) {
				$variant = self::variant( $campaign, $lang );
				if ( '' === trim( $variant['subject'] ) || '' === trim( wp_strip_all_tags( $variant['body'] ) ) || ( $variant['button_text'] && ! $variant['button_url'] ) ) {
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'adc_newsletter_translation_missing', self::t( 'أكمل عنوان ومحتوى النسختين العربية والإنجليزية، ورابط أي زر، قبل الإرسال.', 'Complete the Arabic and English subjects and messages, and each button URL, before sending.' ) );
				}
			}
		}
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table( 'deliveries' ) . " (campaign_id,subscriber_id,consent_key,status) SELECT %d,id,consent_key,'pending' FROM " . self::subscribers() . " WHERE status='active'", $id ) );
		if ( ! $inserted ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_empty', 'لا يوجد مشتركون نشطون لإرسال الحملة.' ); }
		$ok = $wpdb->update( self::table( 'campaigns' ), array( 'status' => 'queued', 'queued_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		$queued = OutboxService::enqueue( 'newsletter.expand', array( 'subject_type' => 'newsletter_campaign', 'subject_id' => $id, 'version' => 0 ), 'newsletter-expand:' . $id . ':0' );
		if ( false === $ok || is_wp_error( $queued ) || ! Transaction::commit( static fn() => AuditLog::record( 'newsletter.queued', 'newsletter_campaign', $id, 'Campaign queued for active subscribers', null, array( 'recipients' => $inserted ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		self::wake(); return true;
	}

	public static function expand( array $payload ) {
		global $wpdb; $id = (int) $payload['subject_id']; $cursor = (int) ( $payload['version'] ?? 0 );
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_read' ); }
		if ( ! $campaign || 'cancelled' === $campaign['status'] || $campaign['expanded'] ) { $wpdb->query( 'ROLLBACK' ); return true; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'deliveries' ) . ' WHERE campaign_id=%d AND id>%d AND outbox_id=0 ORDER BY id LIMIT 100', $id, $cursor ), ARRAY_A );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_read' ); }
		foreach ( $rows as $row ) {
			$event = OutboxService::enqueue( 'newsletter.deliver', array( 'subject_type' => 'newsletter_delivery', 'subject_id' => (int) $row['id'] ), 'newsletter-deliver:' . $row['id'] );
			if ( is_wp_error( $event ) || false === $wpdb->update( self::table( 'deliveries' ), array( 'outbox_id' => $event['id'] ), array( 'id' => $row['id'] ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		}
		$done = count( $rows ) < 100;
		if ( ! $done ) {
			$next = OutboxService::enqueue( 'newsletter.expand', array( 'subject_type' => 'newsletter_campaign', 'subject_id' => $id, 'version' => (int) end( $rows )['id'] ), 'newsletter-expand:' . $id . ':' . end( $rows )['id'] );
			if ( is_wp_error( $next ) ) { $wpdb->query( 'ROLLBACK' ); return $next; }
		}
		$ok = $wpdb->update( self::table( 'campaigns' ), array( 'status' => 'sending', 'expanded' => $done ? 1 : 0 ), array( 'id' => $id ) );
		if ( false === $ok || ! Transaction::commit( static fn() => AuditLog::record( 'newsletter.batch_queued', 'newsletter_campaign', $id, 'Recipient batch queued', null, array( 'count' => count( $rows ) ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		self::wake(); return true;
	}

	public static function deliver( array $payload ) {
		$id = (int) ( $payload['subject_id'] ?? 0 );
		return self::locked( 'delivery:' . $id, static function () use ( $id ) {
			global $wpdb; $table = self::table( 'deliveries' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ), ARRAY_A );
			if ( $wpdb->last_error ) { return self::error( 'adc_newsletter_read' ); }
			if ( ! $row || in_array( $row['status'], array( 'sent', 'skipped' ), true ) ) { return true; }
			$campaign = self::campaign( (int) $row['campaign_id'] );
			if ( $wpdb->last_error ) { return self::error( 'adc_newsletter_read' ); }
			if ( $campaign && $campaign['archived_at'] ) { return self::error( 'adc_newsletter_archived' ); }
			$subscriber = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::subscribers() . ' WHERE id=%d', $row['subscriber_id'] ), ARRAY_A );
			if ( $wpdb->last_error ) { return self::error( 'adc_newsletter_read' ); }
			if ( ! $campaign || 'cancelled' === $campaign['status'] || ! $subscriber || 'active' !== $subscriber['status'] || $subscriber['consent_key'] !== $row['consent_key'] || ! is_email( $subscriber['email'] ) ) {
				return false !== $wpdb->update( $table, array( 'status' => 'skipped' ), array( 'id' => $id ) ) ? true : self::error( 'adc_newsletter_storage' );
			}
			$variant = self::variant( $campaign, $subscriber['locale'] );
			if ( ! BrandedMail::send( $subscriber['email'], $variant['subject'], self::html( $campaign, $subscriber ), self::unsubscribe_headers( self::unsubscribe_url( $subscriber ) ) ) ) { return self::error( 'adc_newsletter_mail' ); }
			return false !== $wpdb->update( $table, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ), 'last_error' => '', 'locale' => self::language( $variant['locale'] ) ), array( 'id' => $id ) ) ? true : self::error( 'adc_newsletter_storage' );
		} );
	}

	public static function failed( array $event ): void {
		if ( 'newsletter.deliver' !== $event['event_key'] ) { return; }
		global $wpdb;
		$wpdb->update( self::table( 'deliveries' ), array( 'status' => 'failed', 'last_error' => $event['error_code'] ), array( 'outbox_id' => $event['id'], 'status' => 'pending' ) );
	}

	public static function stats( int $id ): array {
		global $wpdb; $stats = array( 'pending' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) total FROM ' . self::table( 'deliveries' ) . ' WHERE campaign_id=%d GROUP BY status', $id ), ARRAY_A ) ?: array() as $row ) { $stats[$row['status']] = (int) $row['total']; $stats['total'] += (int) $row['total']; }
		return $stats;
	}

	public static function cancel( int $id ) {
		if ( ! self::can_manage() ) { return self::error( 'adc_newsletter_forbidden' ); }
		global $wpdb;
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		$campaign=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('campaigns').' WHERE id=%d FOR UPDATE',$id),ARRAY_A);
		if (!$campaign || $campaign['archived_at'] || !in_array($campaign['status'],array('queued','sending'),true) || ($campaign['expanded'] && 0===self::stats($id)['pending'])) { $wpdb->query('ROLLBACK'); return self::error('adc_newsletter_locked'); }
		$ok = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'campaigns' ) . " SET status='cancelled' WHERE id=%d AND status IN ('queued','sending')", $id ) );
		$skipped = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'deliveries' ) . " SET status='skipped' WHERE campaign_id=%d AND status='pending'", $id ) );
		if ( 1 !== $ok || false === $skipped || ! Transaction::commit( static fn() => AuditLog::record( 'newsletter.cancelled', 'newsletter_campaign', $id, 'Remaining campaign deliveries cancelled' ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		return true;
	}

	public static function retry( int $id ) {
		if ( ! self::can_manage() ) { return self::error( 'adc_newsletter_forbidden' ); }
		global $wpdb;
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( ! $campaign || $campaign['archived_at'] || ! in_array( $campaign['status'], array( 'queued', 'sending' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_locked' ); }
		if ( ! $campaign['expanded'] ) {
			$cursor = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM ' . self::table( 'deliveries' ) . ' WHERE campaign_id=%d AND outbox_id=0', $id ) );
			$event = OutboxService::enqueue( 'newsletter.expand', array( 'subject_type' => 'newsletter_campaign', 'subject_id' => $id, 'version' => max( 0, $cursor - 1 ) ), 'newsletter-expand-retry:' . $id . ':' . wp_generate_uuid4() );
			if ( is_wp_error( $event ) ) { $wpdb->query( 'ROLLBACK' ); return $event; }
		}
		// Requeue a bounded batch; successful deliveries remain untouched.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'deliveries' ) . " WHERE campaign_id=%d AND status='failed' LIMIT 100 FOR UPDATE", $id ), ARRAY_A );
		foreach ( $rows ?: array() as $row ) {
			$event = OutboxService::enqueue( 'newsletter.deliver', array( 'subject_type' => 'newsletter_delivery', 'subject_id' => (int) $row['id'] ), 'newsletter-retry:' . $row['id'] . ':' . wp_generate_uuid4() );
			if ( is_wp_error( $event ) || false === $wpdb->update( self::table( 'deliveries' ), array( 'outbox_id' => $event['id'], 'status' => 'pending', 'last_error' => '' ), array( 'id' => $row['id'] ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		}
		if ( $wpdb->last_error || ! Transaction::commit( static fn() => AuditLog::record( 'newsletter.retry_requested', 'newsletter_campaign', $id, 'Failed deliveries requeued', null, array( 'count' => count( $rows ?: array() ) ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		self::wake(); return true;
	}

	public static function can_archive( array $campaign, array $stats ): bool {
		return ! $campaign['archived_at'] && 'draft' !== $campaign['status'] && 0 === $stats['pending'] && ( $campaign['expanded'] || 'cancelled' === $campaign['status'] );
	}
	public static function duplicate( int $id, string $creation_key = '' ) {
		if ( ! self::can_manage() ) { return self::error( 'adc_newsletter_forbidden' ); }
		global $wpdb;
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		$source = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( ! $source || $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_missing' ); }
		if ( ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $creation_key ) ) { $creation_key = wp_generate_uuid4(); }
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,copied_from FROM ' . self::table( 'campaigns' ) . ' WHERE creation_key=%s', $creation_key ), ARRAY_A );
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return (int) $existing['copied_from'] === $id ? (int) $existing['id'] : self::error( 'adc_newsletter_locked' ); }
		$data = $source;
		unset( $data['id'] );
		foreach ( array( 'ar'=>' (نسخة)', 'en'=>' (Copy)' ) as $lang=>$suffix ) {
			if ( $data['subject_' . $lang] ) { $data['subject_' . $lang] = mb_substr( $data['subject_' . $lang], 0, 190 - mb_strlen( $suffix ) ) . $suffix; }
		}
		$data['subject'] = $data['subject_ar'] ?: $data['subject_en'];
		$data['status']='draft'; $data['bilingual']=1; $data['expanded']=0;
		$data['creation_key']=$creation_key; $data['copied_from']=$id;
		$data['archived_at']=null; $data['queued_at']=null;
		$data['created_by']=get_current_user_id(); $data['created_at']=current_time( 'mysql', true );
		$ok = $wpdb->insert( self::table( 'campaigns' ), $data ); $new_id=(int)$wpdb->insert_id;
		if ( 1 !== $ok || ! Transaction::commit( static fn() => AuditLog::record( 'newsletter.copied', 'newsletter_campaign', $new_id, 'Campaign copied into an independent draft', null, array( 'source_campaign_id'=>$id ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		return $new_id;
	}
	public static function archive( int $id, bool $restore = false ) {
		if ( ! self::can_manage() ) { return self::error( 'adc_newsletter_forbidden' ); }
		global $wpdb;
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		if ( ! $campaign || ( $restore ? ! $campaign['archived_at'] : ! self::can_archive( $campaign, self::stats( $id ) ) ) ) {
			$wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_newsletter_archive_state', self::t( 'يمكن أرشفة الحملة بعد انتهاء الإرسال فقط.', 'A campaign can only be archived after sending has finished.' ) );
		}
		$processing = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'deliveries' ) . ' d INNER JOIN ' . OutboxService::table() . " o ON o.id=d.outbox_id WHERE d.campaign_id=%d AND o.status='processing'", $id ) );
		if ( $wpdb->last_error || $processing ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_busy' ); }
		$ok=$wpdb->update( self::table( 'campaigns' ), array( 'archived_at'=>$restore ? null : current_time( 'mysql', true ) ), array( 'id'=>$id ) );
		if ( false === $ok || ! Transaction::commit( static fn() => AuditLog::record( $restore ? 'newsletter.restored' : 'newsletter.archived', 'newsletter_campaign', $id, $restore ? 'Campaign restored to the main list' : 'Campaign archived; content and delivery history retained' ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		return true;
	}
	public static function delete_draft( int $id ) {
		if ( ! self::can_manage() ) { return self::error( 'adc_newsletter_forbidden' ); }
		global $wpdb;
		if ( ! Transaction::begin() ) { return self::error( 'adc_newsletter_storage' ); }
		$campaign=$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
		$count=$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'deliveries' ) . ' WHERE campaign_id=%d', $id ) );
		if ( ! $campaign || 'draft' !== $campaign['status'] || $campaign['archived_at'] || $wpdb->last_error || (int)$count > 0 ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_newsletter_delete_state', self::t( 'يمكن حذف المسودات التي لم تُرسل فقط.', 'Only unsent drafts can be deleted.' ) ); }
		$ok=$wpdb->delete( self::table( 'campaigns' ), array( 'id'=>$id ) );
		if ( 1 !== $ok || ! Transaction::commit( static fn() => AuditLog::record( 'newsletter.draft_deleted', 'newsletter_campaign', $id, 'Unsent draft deleted' ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_newsletter_storage' ); }
		return true;
	}
}
