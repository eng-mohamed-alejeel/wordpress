<?php
namespace AutoDealership\Leads;

use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/** One public boundary for REST enquiries and the theme's contact/test-drive forms. */
final class PublicIntake {
	public static function enabled(): bool { return (bool) apply_filters( 'adc_core_public_intake_enabled', true ); }

	public static function enqueue(): void {
		if ( self::enabled() ) {
			wp_enqueue_script( 'adc-public-intake', plugins_url( 'assets/js/public-intake.js', ADC_FILE ), array(), ADC_VERSION, true );
		}
	}

	public static function submit( array $input, string $compatibility_type = '' ) {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return self::error( 'adc_schema_unavailable', 503 ); }
		foreach ( array( 'website'=>200, 'message'=>4000, 'request_kind'=>32, 'date'=>10, 'time'=>5, 'idempotency_key'=>36 ) as $key => $limit ) {
			if ( isset( $input[$key] ) && ( ! is_string( $input[$key] ) || mb_strlen( $input[$key] ) > $limit ) ) { return self::error( 'adc_invalid_lead', 400 ); }
		}
		if ( ! empty( $input['website'] ) || ! in_array( $compatibility_type, array( '', 'message','booking' ), true ) ) { return self::error( 'adc_invalid_lead', 400 ); }
		$identity = ContactIdentity::normalize( $input );
		if ( is_wp_error( $identity ) ) { return $identity; }
		if ( $compatibility_type && ( mb_strlen( $identity['name'] ) > 120 || '' === $identity['email'] ) ) { return self::error( 'adc_invalid_lead', 400 ); }
		$branch = Money::parse( $input['branch_id'] ?? 0 );
		$post_id = Money::parse( $input['car_id'] ?? 0 );
		$key = strtolower( $input['idempotency_key'] ?? '' );
		$kind = 'booking' === $compatibility_type ? 'test_drive' : ( $input['request_kind'] ?? 'contact' );
		if ( null === $branch || null === $post_id || ! in_array( $kind, array( 'contact','finance','finance_request','price_request','offer_request','test_drive','purchase' ), true ) || ( '' !== $key && ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $key ) ) ) { return self::error( 'adc_invalid_lead', 400 ); }
		if ( $post_id ) {
			if ( 'car' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) { return self::error( 'adc_intake_vehicle_unavailable', 400 ); }
			$vehicle = $wpdb->get_row( $wpdb->prepare( 'SELECT branch_id,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id=%d LIMIT 1', $post_id ), ARRAY_A );
			if ( $wpdb->last_error ) { return self::error( 'adc_intake_unavailable', 503 ); }
			if ( $vehicle ) {
				if ( 'available' !== $vehicle['status'] || ! VehicleService::branch_exists( (int) $vehicle['branch_id'] ) || ( $branch && $branch !== (int) $vehicle['branch_id'] ) ) { return self::error( 'adc_intake_vehicle_unavailable', 400 ); }
				$branch = (int) $vehicle['branch_id'];
			} elseif ( 'available' !== get_post_meta( $post_id, '_car_inventory_status', true ) ) { return self::error( 'adc_intake_vehicle_unavailable', 400 ); }
		}
		if ( ! $branch ) { $branch = (int) get_option( 'adc_default_branch_id', 0 ); }
		if ( $branch < 0 || ( $branch && ! VehicleService::branch_exists( $branch ) ) ) { return self::error( 'adc_invalid_lead', 400 ); }
		$date = $input['date'] ?? ''; $time = $input['time'] ?? '';
		if ( 'test_drive' === $kind ) {
			if ( ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date ) || ! preg_match( '/\A[0-9]{2}:[0-9]{2}\z/', $time ) ) { return self::error( 'adc_invalid_booking_time', 400 ); }
			$when = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
			if ( ! $post_id || ! $when || $when->format( 'Y-m-d H:i' ) !== $date . ' ' . $time || $when->getTimestamp() <= time() ) { return self::error( 'adc_invalid_booking_time', 400 ); }
		} elseif ( '' !== $date || '' !== $time ) { return self::error( 'adc_invalid_lead', 400 ); }
		$message = sanitize_textarea_field( $input['message'] ?? '' );
		if ( 'message' === $compatibility_type && 'contact' === $kind && '' === trim( $message ) ) { return self::error( 'adc_invalid_lead', 400 ); }
		$context = 'Request: ' . $kind;
		if ( $post_id ) { $context .= "\nVehicle post: " . $post_id; }
		if ( $date ) { $context .= "\nRequested appointment: $date $time (" . wp_timezone_string() . ')'; }
		if ( '' !== $message ) { $context .= "\n" . $message; }
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		$rate_key = 'adc_lead_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$count = (int) get_transient( $rate_key );
		if ( $count >= 8 ) { return self::error( 'adc_rate_limited', 429 ); }
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
		return LeadService::create_public( array_merge( $identity, array( 'branch_id'=>$branch, 'idempotency_key'=>$key, 'request_kind'=>$kind, 'car_id'=>$post_id, 'date'=>$date, 'time'=>$time, 'message'=>$message ) ), null, $context, $compatibility_type );
	}

	/** Theme adapter retains nonce, logged-in identity and existing response shape. */
	public static function handle_theme( string $type ): void {
		check_ajax_referer( 'car_dealer_frontend', 'nonce' );
		$input = wp_unslash( $_POST );
		$language = isset( $input['lang'] ) && is_scalar( $input['lang'] ) && 'en' === sanitize_key( (string) $input['lang'] ) ? 'en' : 'ar';
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$input['name'] = $user->display_name; $input['email'] = $user->user_email;
			$input['mobile'] = get_user_meta( $user->ID, 'car_dealer_phone', true );
		} else { $input['mobile'] = $input['phone'] ?? ''; }
		$input['request_kind'] = $input['lead_type'] ?? 'contact';
		$result = self::submit( $input, $type );
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			if ( 'en' === $language ) {
				$message = array(
					'adc_rate_limited' => 'Too many requests. Please try again later.',
					'adc_invalid_booking_time' => 'Choose a vehicle and a future date and time in the site timezone.',
					'adc_intake_vehicle_unavailable' => 'This vehicle is unavailable for this request.',
				)[ $result->get_error_code() ] ?? 'The request could not be saved. Review the details and try again.';
			}
			wp_send_json_error( array( 'message'=>$message ), (int) ( $result->get_error_data()['status'] ?? 400 ) );
		}
		$data = array( 'message'=>'en' === $language ? 'Your request has been received successfully.' : __( 'تم استلام طلبك بنجاح.', 'auto-dealership-core' ) );
		if ( 'booking' === $type ) {
			$data['booking_id'] = $result['legacy_request_id'];
			$data['account_url'] = is_user_logged_in() && function_exists( 'car_dealer_account_url' ) ? car_dealer_account_url() . '#customer-bookings' : '';
		}
		wp_send_json_success( $data );
	}

	public static function owns_legacy_request( string $type, int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'leads' ) . ' WHERE legacy_request_type=%s AND legacy_request_id=%d LIMIT 1', $type, $id ) );
	}

	private static function error( string $code, int $status ): \WP_Error {
		$messages = array( 'adc_rate_limited'=>'تم تجاوز عدد الطلبات المسموح. حاول لاحقًا.', 'adc_invalid_booking_time'=>'اختر سيارة وتاريخًا ووقتًا في المستقبل حسب توقيت الموقع.', 'adc_intake_vehicle_unavailable'=>'السيارة غير متاحة لهذا الطلب.' );
		return new \WP_Error( $code, __( $messages[$code] ?? 'تعذر حفظ الطلب. راجع البيانات أو حاول لاحقًا.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
