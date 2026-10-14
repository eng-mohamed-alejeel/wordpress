<?php
namespace AutoDealership\Leads;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\PublicRequestGuard;

defined( 'ABSPATH' ) || exit;

/** Explicit newsletter opt-in through a plugin-owned public boundary. */
final class MarketingSubscription {
	private static bool $booted = false;

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_marketing_subscription_enabled', true );
	}

	public static function owns_theme_actions(): bool {
		return self::enabled();
	}

	public static function boot(): void {
		if ( self::$booted || ! self::enabled() ) {
			return;
		}
		self::$booted = true;
		add_action( 'wp_ajax_car_dealer_subscribe', array( self::class, 'handle_theme' ) );
		add_action( 'wp_ajax_nopriv_car_dealer_subscribe', array( self::class, 'handle_theme' ) );
	}

	public static function handle_theme(): void {
		check_ajax_referer( 'car_dealer_frontend', 'nonce' );
		$input = wp_unslash( $_POST );
		$saved_language = is_user_logged_in() ? get_user_meta( get_current_user_id(), 'adc_preferred_language', true ) : 'ar';
		$interface_language = isset( $input['lang'] ) && is_scalar( $input['lang'] ) && in_array( $input['lang'], array( 'ar', 'en' ), true ) ? $input['lang'] : ( 'en' === $saved_language ? 'en' : 'ar' );
		// A saved account preference takes precedence over a hidden page-language field.
		$language = is_user_logged_in() && in_array( $saved_language, array( 'ar', 'en' ), true ) ? $saved_language : $interface_language;
		$message = static fn( string $arabic, string $english ): string => 'en' === $interface_language ? $english : __( $arabic, 'auto-dealership-core' );
		$website = isset( $input['website'] ) && is_string( $input['website'] ) ? trim( $input['website'] ) : '';
		$consent = $input['consent_marketing'] ?? '';
		$email = is_user_logged_in() ? wp_get_current_user()->user_email : ( $input['email'] ?? '' );
		$email = is_string( $email ) ? strtolower( sanitize_email( $email ) ) : '';

		if ( '' !== $website || ! in_array( $consent, array( '1', 1, true ), true ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => $message( 'أدخل بريدًا صحيحًا ووافق صراحة على الاشتراك.', 'Enter a valid email address and explicitly agree to subscribe.' ) ), 400 );
		}
		if ( ! LegacyEngagementStore::is_ready() ) {
			wp_send_json_error( array( 'message' => $message( 'خدمة الاشتراك غير متاحة مؤقتًا.', 'Subscriptions are temporarily unavailable.' ) ), 503 );
		}
		$rate = PublicRequestGuard::consume( 'intake' );
		if ( is_wp_error( $rate ) ) {
			wp_send_json_error( array( 'message' => 'en' === $interface_language ? 'Too many requests. Please try again later.' : $rate->get_error_message() ), (int) ( $rate->get_error_data()['status'] ?? 429 ) );
		}

		global $wpdb;
		if ( ! Transaction::begin() ) {
			wp_send_json_error( array( 'message' => $message( 'تعذر حفظ الاشتراك.', 'Your subscription could not be saved.' ) ), 503 );
		}
		$table = $wpdb->prefix . 'car_dealer_subscribers';
		$previous = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email=%s FOR UPDATE", $email ), ARRAY_A );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); wp_send_json_error( array( 'message' => $message( 'تعذر حفظ الاشتراك.', 'Your subscription could not be saved.' ) ), 503 ); }
		if ( $previous && 'active' === $previous['status'] ) {
			$ok = $wpdb->update( $table, array( 'locale' => $language ), array( 'id' => $previous['id'] ) );
			$language_saved = true;
			if ( is_user_logged_in() ) { update_user_meta( get_current_user_id(), 'adc_preferred_language', $language ); }
			if ( is_user_logged_in() ) { $language_saved = get_user_meta( get_current_user_id(), 'adc_preferred_language', true ) === $language; }
			if ( false === $ok || ! $language_saved || ! Transaction::commit( static fn() => AuditLog::record( 'marketing.language_updated', 'subscriber', (int) $previous['id'], 'Subscription language preference', null, array( 'locale'=>$language ) ) ) ) { $wpdb->query( 'ROLLBACK' ); if ( is_user_logged_in() ) { clean_user_cache( get_current_user_id() ); } wp_send_json_error( array( 'message'=>$message( 'تعذر حفظ الاشتراك.', 'Your subscription could not be saved.' ) ), 503 ); }
			wp_send_json_success( array( 'message' => $message( 'أنت مشترك بالفعل في النشرة البريدية.', 'You are already subscribed.' ) ) );
		}
		$now = current_time( 'mysql', true );
		$consent_key = wp_generate_uuid4();
		$saved = $wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (email,status,created_at,locale,consent_key) VALUES (%s,'active',%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status='active',created_at=VALUES(created_at),locale=VALUES(locale),consent_key=VALUES(consent_key),welcome_sent_at=NULL",
			$email,
			$now, $language, $consent_key
		) );
		$subscriber_id = (int) $wpdb->insert_id;
		$language_saved = true;
		if ( is_user_logged_in() ) { update_user_meta( get_current_user_id(), 'adc_preferred_language', $language ); }
		if ( is_user_logged_in() ) { $language_saved = get_user_meta( get_current_user_id(), 'adc_preferred_language', true ) === $language; }
		$mail_payload = array( 'subject_type' => 'subscriber', 'subject_id' => $subscriber_id, 'correlation_id' => $consent_key );
		$mail_event = false !== $saved && $subscriber_id > 0 ? \AutoDealership\Operations\OutboxService::enqueue( 'newsletter.subscription', $mail_payload, 'subscription:' . $subscriber_id . ':' . $consent_key ) : new \WP_Error( 'adc_subscription_storage' );
		if ( false === $saved || $subscriber_id < 1 || ! $language_saved || is_wp_error( $mail_event ) || ! Transaction::commit( static fn() => AuditLog::record( 'marketing.subscription_activated', 'subscriber', $subscriber_id, 'Explicit website newsletter opt-in', null, array( 'status' => 'active' ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			if ( is_user_logged_in() ) { clean_user_cache( get_current_user_id() ); }
			wp_send_json_error( array( 'message' => $message( 'تعذر حفظ الاشتراك.', 'Your subscription could not be saved.' ) ), 500 );
		}

		NewsletterService::subscription_email( $mail_payload );
		NewsletterService::wake();
		wp_send_json_success( array( 'message' => $message( 'تم الاشتراك بنجاح.', 'You have subscribed successfully.' ) ) );
	}
}
