<?php
namespace AutoDealership\Content;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Owns the compatibility metadata editor for public vehicle posts. */
final class VehiclePostEditor {
	private const NONCE_ACTION = 'car_dealer_save_car';
	private const NONCE_NAME   = 'car_dealer_car_nonce';
	private const NOTICE_KEY   = 'adc_vehicle_editor_notice_';
	private static bool $booted = false;

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_vehicle_post_editor_enabled', true );
	}

	public static function boot(): void {
		if ( self::$booted || ! self::enabled() ) {
			return;
		}
		self::$booted = true;
		add_action( 'add_meta_boxes_car', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_car', array( self::class, 'save' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	public static function owns_theme_editor(): bool {
		return self::enabled();
	}

	public static function add_meta_box(): void {
		add_meta_box( 'car-details', __( 'تفاصيل السيارة', 'auto-dealership-core' ), array( self::class, 'render' ), 'car', 'normal', 'high' );
	}

	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$fields = array(
			'_car_price'            => __( 'السعر', 'auto-dealership-core' ),
			'_car_monthly_payment'  => __( 'القسط الشهري', 'auto-dealership-core' ),
			'_car_year'             => __( 'سنة الصنع', 'auto-dealership-core' ),
			'_car_model'            => __( 'الموديل', 'auto-dealership-core' ),
			'_car_color'            => __( 'اللون', 'auto-dealership-core' ),
			'_car_kilometers'       => __( 'الممشى (كم)', 'auto-dealership-core' ),
			'_car_transmission'     => __( 'ناقل الحركة', 'auto-dealership-core' ),
			'_car_fuel_type'        => __( 'نوع الوقود', 'auto-dealership-core' ),
			'_car_condition'        => __( 'الحالة', 'auto-dealership-core' ),
			'_car_inventory_status' => __( 'حالة المخزون', 'auto-dealership-core' ),
		);

		$mapped_status = self::mapped_status( $post->ID );
		foreach ( $fields as $key => $label ) {
			$value    = null !== $mapped_status && '_car_inventory_status' === $key ? $mapped_status : get_post_meta( $post->ID, $key, true );
			$readonly = null !== $mapped_status && '_car_inventory_status' === $key;
			echo '<p><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width:100%" ' . ( $readonly ? 'readonly aria-readonly="true"' : '' ) . '></p>';
		}
		if ( null !== $mapped_status ) {
			echo '<p class="description">' . esc_html__( 'حالة السيارة مرتبطة بالمخزون التشغيلي وتُعرض هنا للقراءة فقط.', 'auto-dealership-core' ) . '</p>';
		}

		$features = get_post_meta( $post->ID, '_car_features', true );
		echo '<p><label for="_car_features">' . esc_html__( 'المزايا (سطر لكل ميزة)', 'auto-dealership-core' ) . '</label><br><textarea id="_car_features" name="_car_features" rows="5" style="width:100%">' . esc_textarea( is_array( $features ) ? implode( "\n", $features ) : $features ) . '</textarea></p>';
		echo '<p><label for="_car_featured"><input type="checkbox" id="_car_featured" name="_car_featured" value="1" ' . checked( get_post_meta( $post->ID, '_car_featured', true ), '1', false ) . '> ' . esc_html__( 'سيارة مميزة', 'auto-dealership-core' ) . '</label></p>';
		echo '<p><label for="_car_demand"><input type="checkbox" id="_car_demand" name="_car_demand" value="yes" ' . checked( get_post_meta( $post->ID, '_car_demand', true ), 'yes', false ) . '> ' . esc_html__( 'سيارة مطلوبة', 'auto-dealership-core' ) . '</label></p>';
	}

	public static function save( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( ! self::can_save( $post_id, $post ) ) {
			return;
		}

		$values = array();
		$errors = array();
		$values['_car_price']           = self::decimal( '_car_price', $errors );
		$values['_car_monthly_payment'] = self::decimal( '_car_monthly_payment', $errors );
		$values['_car_year']            = self::year( $errors );
		$values['_car_kilometers']      = self::unsigned( '_car_kilometers', $errors );
		foreach ( array( '_car_model', '_car_color', '_car_transmission', '_car_fuel_type', '_car_condition' ) as $key ) {
			$values[ $key ] = self::text( $key, 120 );
		}

		$mapped_status = self::mapped_status( $post_id );
		$status        = null !== $mapped_status ? $mapped_status : sanitize_key( self::raw( '_car_inventory_status' ) );
		$statuses      = array( '', 'ordered', 'in_transit', 'received', 'inspection', 'available', 'reserved', 'sold', 'ready_for_delivery', 'delivered', 'maintenance', 'hold', 'returned', 'cancelled', 'transferred', 'pending' );
		if ( ! in_array( $status, $statuses, true ) ) {
			$errors[] = __( 'حالة المخزون غير صالحة.', 'auto-dealership-core' );
		}
		$values['_car_inventory_status'] = $status;
		$values['_car_features']         = sanitize_textarea_field( self::raw( '_car_features' ) );
		$values['_car_featured']         = isset( $_POST['_car_featured'] ) ? '1' : '';
		$values['_car_demand']           = isset( $_POST['_car_demand'] ) ? 'yes' : '';

		if ( $errors ) {
			self::notice( implode( ' ', array_unique( $errors ) ) );
			return;
		}

		$changes = array();
		foreach ( $values as $key => $value ) {
			$changes[] = array( 'post_id' => $post_id, 'key' => $key, 'value' => $value );
		}
		$result = PostMetaStore::apply( $changes, 'content.vehicle_meta_changed', 'car_post', $post_id );
		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message() );
		}
	}

	public static function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'car' !== $screen->post_type ) {
			return;
		}
		$key     = self::NOTICE_KEY . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	private static function can_save( int $post_id, \WP_Post $post ): bool {
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		return 'car' === $post->post_type
			&& $nonce
			&& wp_verify_nonce( $nonce, self::NONCE_ACTION )
			&& ! wp_is_post_revision( $post_id )
			&& ! wp_is_post_autosave( $post_id )
			&& ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			&& current_user_can( 'edit_post', $post_id );
	}

	private static function raw( string $key ): string {
		return isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private static function text( string $key, int $length ): string {
		$value = sanitize_text_field( self::raw( $key ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	private static function decimal( string $key, array &$errors ): string {
		$value = str_replace( array( ',', ' ' ), '', self::raw( $key ) );
		if ( '' !== $value && ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $value ) ) {
			$errors[] = __( 'يجب أن تكون الأسعار أرقامًا موجبة وبحد أقصى منزلتين عشريتين.', 'auto-dealership-core' );
			return '';
		}
		return $value;
	}

	private static function unsigned( string $key, array &$errors ): string {
		$value = self::raw( $key );
		if ( '' !== $value && ! preg_match( '/^\d+$/', $value ) ) {
			$errors[] = __( 'يجب أن تكون قراءة العداد رقمًا صحيحًا موجبًا.', 'auto-dealership-core' );
			return '';
		}
		return $value;
	}

	private static function year( array &$errors ): string {
		$value = self::raw( '_car_year' );
		if ( '' !== $value && ( ! preg_match( '/^\d{4}$/', $value ) || (int) $value < 1900 || (int) $value > (int) gmdate( 'Y' ) + 2 ) ) {
			$errors[] = __( 'سنة الصنع غير صالحة.', 'auto-dealership-core' );
			return '';
		}
		return $value;
	}

	/** Returns null when the public post is not mapped to operational inventory. */
	private static function mapped_status( int $post_id ): ?string {
		if ( get_option( 'adc_db_version' ) !== Schema::VERSION ) {
			return null;
		}
		global $wpdb;
		$statuses = $wpdb->get_col( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id=%d ORDER BY id ASC LIMIT 2', $post_id ) );
		return $statuses ? (string) $statuses[0] : null;
	}

	private static function notice( string $message ): void {
		set_transient( self::NOTICE_KEY . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	}
}
