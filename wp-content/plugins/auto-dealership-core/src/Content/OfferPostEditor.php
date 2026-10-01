<?php
namespace AutoDealership\Content;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Owns offer linkage, eligibility and durable offer metadata. */
final class OfferPostEditor {
	private const NONCE_ACTION = 'car_dealer_save_offer';
	private const NONCE_NAME   = 'car_dealer_offer_nonce';
	private const NOTICE_KEY   = 'adc_offer_editor_notice_';
	private static bool $booted = false;
	/** @var array<int,bool> */
	private static array $availability_cache = array();

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_offer_post_editor_enabled', true );
	}

	public static function boot(): void {
		if ( self::$booted || ! self::enabled() ) {
			return;
		}
		self::$booted = true;
		add_filter( 'wp_insert_post_data', array( self::class, 'enforce_publish_eligibility' ), 10, 4 );
		add_action( 'add_meta_boxes_car_offer', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_car_offer', array( self::class, 'save' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_filter( 'manage_car_offer_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_car_offer_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	public static function owns_theme_editor(): bool {
		return self::enabled();
	}

	public static function add_meta_box(): void {
		add_meta_box( 'car-offer-details', __( 'تفاصيل العرض', 'auto-dealership-core' ), array( self::class, 'render' ), 'car_offer', 'normal', 'high' );
	}

	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$selected = absint( get_post_meta( $post->ID, '_offer_car_id', true ) );
		$cars     = self::available_cars( $selected );
		echo '<p><label for="_offer_car_id"><strong>' . esc_html__( 'السيارة المرتبطة', 'auto-dealership-core' ) . '</strong></label><br><select id="_offer_car_id" name="_offer_car_id" class="widefat" required><option value="">' . esc_html__( 'اختر سيارة منشورة ومتوفرة', 'auto-dealership-core' ) . '</option>';
		foreach ( $cars as $car ) {
			$available = self::car_is_available( $car->ID );
			echo '<option value="' . absint( $car->ID ) . '" ' . selected( $selected, $car->ID, false ) . ' ' . disabled( ! $available, true, false ) . '>' . esc_html( get_the_title( $car ) . ( $available ? '' : ' — ' . __( 'غير متاحة حاليًا', 'auto-dealership-core' ) ) ) . '</option>';
		}
		echo '</select></p>';

		$fields = array(
			'_offer_old_price'       => array( __( 'السعر قبل العرض', 'auto-dealership-core' ), 'number' ),
			'_offer_new_price'       => array( __( 'السعر بعد العرض', 'auto-dealership-core' ), 'number' ),
			'_offer_monthly_payment' => array( __( 'القسط الشهري يبدأ من', 'auto-dealership-core' ), 'number' ),
			'_offer_expires'         => array( __( 'تاريخ انتهاء العرض', 'auto-dealership-core' ), 'date' ),
		);
		foreach ( $fields as $key => $field ) {
			echo '<p><label for="' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label><br><input class="widefat" type="' . esc_attr( $field[1] ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( get_post_meta( $post->ID, $key, true ) ) . '"' . ( 'number' === $field[1] ? ' min="0" step="1"' : '' ) . '></p>';
		}
	}

	public static function save( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( ! self::can_save( $post_id, $post ) ) {
			return;
		}

		$car_id = absint( self::raw( '_offer_car_id' ) );
		if ( ! self::car_is_available( $car_id ) ) {
			self::notice( __( 'لم يُحفظ العرض: يجب اختيار سيارة منشورة ومتوفرة في المخزون.', 'auto-dealership-core' ) );
			return;
		}

		$errors = array();
		$values = array(
			'_offer_car_id'          => (string) $car_id,
			'_offer_old_price'       => self::unsigned( '_offer_old_price', $errors ),
			'_offer_new_price'       => self::unsigned( '_offer_new_price', $errors ),
			'_offer_monthly_payment' => self::unsigned( '_offer_monthly_payment', $errors ),
			'_offer_expires'         => self::date( $errors ),
		);
		if ( '' !== $values['_offer_old_price'] && '' !== $values['_offer_new_price'] && (int) $values['_offer_new_price'] > (int) $values['_offer_old_price'] ) {
			$errors[] = __( 'سعر العرض لا يمكن أن يتجاوز السعر السابق.', 'auto-dealership-core' );
		}
		if ( $errors ) {
			self::notice( implode( ' ', array_unique( $errors ) ) );
			return;
		}

		$previous_car = absint( get_post_meta( $post_id, '_offer_car_id', true ) );
		$changes      = array();
		foreach ( $values as $key => $value ) {
			$changes[] = array( 'post_id' => $post_id, 'key' => $key, 'value' => $value );
		}
		$changes[] = array( 'post_id' => $car_id, 'key' => '_car_special_offer', 'value' => '1' );
		$changes[] = array( 'post_id' => $car_id, 'key' => '_car_is_offer', 'value' => '1' );
		if ( $previous_car && $previous_car !== $car_id && ! self::has_other_offer( $previous_car, $post_id ) ) {
			$changes[] = array( 'post_id' => $previous_car, 'key' => '_car_special_offer', 'value' => '' );
			$changes[] = array( 'post_id' => $previous_car, 'key' => '_car_is_offer', 'value' => '' );
		}

		$result = PostMetaStore::apply( $changes, 'content.offer_meta_changed', 'offer_post', $post_id );
		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message() );
		}
	}

	/** Prevents an invalid offer from becoming publicly visible before metadata is saved. */
	public static function enforce_publish_eligibility( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		unset( $postarr, $update );
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( 'car_offer' !== ( $data['post_type'] ?? '' ) || ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return $data;
		}
		$requested_status = sanitize_key( (string) ( $unsanitized_postarr['post_status'] ?? $data['post_status'] ?? '' ) );
		if ( in_array( $requested_status, array( 'publish', 'future', 'private' ), true ) && ! self::car_is_available( absint( self::raw( '_offer_car_id' ) ) ) ) {
			$data['post_status'] = 'draft';
			self::notice( __( 'تم إبقاء العرض كمسودة لأن السيارة المرتبطة غير منشورة أو غير متوفرة.', 'auto-dealership-core' ) );
		}
		return $data;
	}

	public static function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'car_offer' !== $screen->post_type ) {
			return;
		}
		$key     = self::NOTICE_KEY . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	public static function columns( array $columns ): array {
		$updated = array();
		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'title' === $key ) {
				$updated['offer_car'] = __( 'السيارة المرتبطة', 'auto-dealership-core' );
			}
		}
		return $updated;
	}

	public static function column( string $column, int $post_id ): void {
		if ( 'offer_car' !== $column ) {
			return;
		}
		$car_id = absint( get_post_meta( $post_id, '_offer_car_id', true ) );
		if ( ! $car_id || 'car' !== get_post_type( $car_id ) ) {
			echo '<span>' . esc_html__( 'غير محددة', 'auto-dealership-core' ) . '</span>';
			return;
		}
		$title = get_the_title( $car_id );
		if ( current_user_can( 'edit_post', $car_id ) ) {
			echo '<a href="' . esc_url( get_edit_post_link( $car_id ) ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		echo '<br><small>' . esc_html( self::car_is_available( $car_id ) ? __( 'متوفرة', 'auto-dealership-core' ) : __( 'غير متاحة', 'auto-dealership-core' ) ) . '</small>';
	}

	private static function available_cars( int $selected ): array {
		$cars = get_posts( array(
			'post_type'              => 'car',
			'post_status'            => 'publish',
			'posts_per_page'         => 500,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		) );
		self::preload_availability( array_map( static fn( \WP_Post $car ): int => $car->ID, $cars ) );
		$cars = array_values( array_filter( $cars, static fn( \WP_Post $car ): bool => self::car_is_available( $car->ID ) || $car->ID === $selected ) );
		if ( $selected && ! in_array( $selected, wp_list_pluck( $cars, 'ID' ), true ) && 'car' === get_post_type( $selected ) && 'publish' === get_post_status( $selected ) ) {
			array_unshift( $cars, get_post( $selected ) );
		}
		return array_filter( $cars );
	}

	private static function car_is_available( int $car_id ): bool {
		if ( $car_id < 1 || 'car' !== get_post_type( $car_id ) || 'publish' !== get_post_status( $car_id ) ) {
			return false;
		}
		if ( ! array_key_exists( $car_id, self::$availability_cache ) ) {
			self::preload_availability( array( $car_id ) );
		}
		return self::$availability_cache[ $car_id ] ?? false;
	}

	/** @param int[] $car_ids */
	private static function preload_availability( array $car_ids ): void {
		$car_ids = array_values( array_unique( array_filter( array_map( 'absint', $car_ids ) ) ) );
		$missing = array_values( array_filter( $car_ids, static fn( int $id ): bool => ! array_key_exists( $id, self::$availability_cache ) ) );
		if ( ! $missing ) {
			return;
		}
		foreach ( $missing as $car_id ) {
			$status = get_post_meta( $car_id, '_car_inventory_status', true );
			self::$availability_cache[ $car_id ] = '' === $status || 'available' === $status;
		}
		if ( get_option( 'adc_db_version' ) !== Schema::VERSION ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$sql = 'SELECT v.public_post_id,COUNT(*) mapping_count,SUM(CASE WHEN v.status=\'available\' AND b.active=1 THEN 1 ELSE 0 END) eligible_count FROM ' . Schema::table( 'vehicles' ) . ' v LEFT JOIN ' . Schema::table( 'branches' ) . " b ON b.id=v.branch_id WHERE v.public_post_id IN ($placeholders) GROUP BY v.public_post_id";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $missing ), ARRAY_A ) ?: array();
		foreach ( $rows as $row ) {
			self::$availability_cache[ (int) $row['public_post_id'] ] = 1 === (int) $row['mapping_count'] && 1 === (int) $row['eligible_count'];
		}
	}

	private static function has_other_offer( int $car_id, int $excluded_offer_id ): bool {
		$ids = get_posts( array(
			'post_type'      => 'car_offer',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post__not_in'   => array( $excluded_offer_id ),
			'meta_key'       => '_offer_car_id',
			'meta_value'     => (string) $car_id,
			'no_found_rows'  => true,
		) );
		return ! empty( $ids );
	}

	private static function can_save( int $post_id, \WP_Post $post ): bool {
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		return 'car_offer' === $post->post_type
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

	private static function unsigned( string $key, array &$errors ): string {
		$value = str_replace( array( ',', ' ' ), '', self::raw( $key ) );
		if ( '' !== $value && ! preg_match( '/^\d+$/', $value ) ) {
			$errors[] = __( 'يجب أن تكون أسعار العرض أرقامًا صحيحة موجبة.', 'auto-dealership-core' );
			return '';
		}
		return $value;
	}

	private static function date( array &$errors ): string {
		$value = self::raw( '_offer_expires' );
		if ( '' === $value ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			$errors[] = __( 'تاريخ انتهاء العرض غير صالح.', 'auto-dealership-core' );
			return '';
		}
		return $value;
	}

	private static function notice( string $message ): void {
		set_transient( self::NOTICE_KEY . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	}
}
