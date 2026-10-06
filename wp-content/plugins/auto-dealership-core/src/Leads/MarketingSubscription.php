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
		$language = isset( $input['lang'] ) && is_scalar( $input['lang'] ) && 'en' === sanitize_key( (string) $input['lang'] ) ? 'en' : 'ar';
		$message = static fn( string $arabic, string $english ): string => 'en' === $language ? $english : __( $arabic, 'auto-dealership-core' );
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
			wp_send_json_error( array( 'message' => 'en' === $language ? 'Too many requests. Please try again later.' : $rate->get_error_message() ), (int) ( $rate->get_error_data()['status'] ?? 429 ) );
		}

		global $wpdb;
		if ( ! Transaction::begin() ) {
			wp_send_json_error( array( 'message' => $message( 'تعذر حفظ الاشتراك.', 'Your subscription could not be saved.' ) ), 503 );
		}
		$table = $wpdb->prefix . 'car_dealer_subscribers';
		$now = current_time( 'mysql', true );
		$saved = $wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (email,status,created_at) VALUES (%s,'active',%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status='active',created_at=VALUES(created_at)",
			$email,
			$now
		) );
		$subscriber_id = (int) $wpdb->insert_id;
		if ( false === $saved || $subscriber_id < 1 || ! Transaction::commit( static fn() => AuditLog::record( 'marketing.subscription_activated', 'subscriber', $subscriber_id, 'Explicit website newsletter opt-in', null, array( 'status' => 'active' ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => $message( 'تعذر حفظ الاشتراك.', 'Your subscription could not be saved.' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => $message( 'تم الاشتراك بنجاح.', 'You have subscribed successfully.' ) ) );
	}
}
