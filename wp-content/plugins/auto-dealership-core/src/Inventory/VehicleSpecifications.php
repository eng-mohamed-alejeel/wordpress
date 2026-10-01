<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Public descriptive attributes. Identity, prices and workflow use separate services. */
final class VehicleSpecifications {
	public const TEXT_LIMITS = array( 'exterior_color'=>80, 'interior_color'=>80, 'engine_size'=>40, 'origin_country'=>80, 'drivetrain'=>8, 'video_url'=>500, 'warranty'=>2000, 'interior_features'=>4000, 'exterior_features'=>4000, 'safety_features'=>4000 );
	public const NUMBER_LIMITS = array( 'doors'=>10, 'seats'=>100, 'horsepower'=>5000, 'cylinders'=>32 );
	private const MEDIA_FIELDS = array( 'gallery_media_ids' );

	public static function fields(): array {
		return array_merge( array_keys( self::TEXT_LIMITS ), array_keys( self::NUMBER_LIMITS ), self::MEDIA_FIELDS );
	}

	/** Absent attributes stay unchanged; null clears an optional numeric value. */
	public static function validate( array $input ) {
		$result = array();
		foreach ( self::TEXT_LIMITS as $field => $limit ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			if ( ! is_string( $input[$field] ) || mb_strlen( $input[$field] ) > $limit ) { return self::error( 'adc_specification_invalid', 400 ); }
			$value = $limit > 100 ? sanitize_textarea_field( $input[$field] ) : sanitize_text_field( $input[$field] );
			if ( 'drivetrain' === $field && ! in_array( $value, array( '', 'fwd', 'rwd', 'awd', '4wd' ), true ) ) { return self::error( 'adc_specification_invalid', 400 ); }
			if ( 'video_url' === $field ) {
				$value = esc_url_raw( trim( $input[$field] ), array( 'http', 'https' ) );
				if ( '' !== trim( $input[$field] ) && '' === $value ) { return self::error( 'adc_specification_invalid', 400 ); }
			}
			$result[$field] = $value;
		}
		foreach ( self::NUMBER_LIMITS as $field => $limit ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			$value = $input[$field];
			if ( null === $value || '' === $value ) { $result[$field] = null; continue; }
			if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+\z/', $value ) ) ) { return self::error( 'adc_specification_invalid', 400 ); }
			if ( $value < 1 || $value > $limit ) { return self::error( 'adc_specification_invalid', 400 ); }
			$result[$field] = (int) $value;
		}
		if ( array_key_exists( 'gallery_media_ids', $input ) ) {
			$ids = self::image_ids( $input['gallery_media_ids'], 30 );
			if ( false === $ids ) { return self::error( 'adc_specification_invalid', 400 ); }
			$result['gallery_media_ids'] = wp_json_encode( $ids );
		}
		return $result;
	}

	public static function update( int $id, array $input, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_inventory' ) ) { return self::error( 'adc_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( $id < 1 || '' === trim( $reason ) || mb_strlen( $reason ) > 2000 || array_diff( array_keys( $input ), self::fields() ) ) { return self::error( 'adc_specification_invalid', 400 ); }
		$changes = self::validate( $input );
		if ( is_wp_error( $changes ) ) { return $changes; }
		if ( ! $changes ) { return self::error( 'adc_specification_invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$table = Schema::table( 'vehicles' );
		$columns = implode( ',', self::fields() );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT branch_id,status,$columns FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || ! BranchScope::allows( (int) $row['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_vehicle_not_found', 404 ); }
		if ( ! in_array( $row['status'], array( 'ordered','in_transit','received','inspection','available','hold','maintenance','returned' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_specification_state_locked', 409 ); }
		$before = array();
		foreach ( $changes as $field => $value ) {
			$old = $row[$field];
			if ( isset( self::NUMBER_LIMITS[$field] ) && null !== $old ) { $old = (int) $old; }
			if ( $old === $value ) { unset( $changes[$field] ); } else { $before[$field] = $old; }
		}
		if ( ! $changes ) { $wpdb->query( 'ROLLBACK' ); return array( 'id'=>$id, 'updated'=>false ); }
		$data = $changes;
		$formats = array_map( static fn( $field ) => isset( self::NUMBER_LIMITS[$field] ) ? '%d' : '%s', array_keys( $data ) );
		$data['updated_at'] = current_time( 'mysql', true ); $formats[] = '%s';
		$updated = $wpdb->update( $table, $data, array( 'id'=>$id ), $formats, array( '%d' ) );
		if ( 1 !== $updated || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.specifications_changed', 'vehicle', $id, $reason, $before, $changes ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_specification_save_failed', 500 ); }
		return array( 'id'=>$id, 'updated'=>true );
	}

	private static function image_ids( $value, int $limit ) {
		if ( ! is_array( $value ) || count( $value ) > $limit ) { return false; }
		$ids = array();
		foreach ( $value as $item ) {
			if ( ! is_int( $item ) && ( ! is_string( $item ) || ! preg_match( '/\A[0-9]+\z/', $item ) ) ) { return false; }
			$id = (int) $item;
			if ( $id < 1 || ! current_user_can( 'read_post', $id ) || 'attachment' !== get_post_type( $id ) || ! str_starts_with( (string) get_post_mime_type( $id ), 'image/' ) ) { return false; }
			$path = get_attached_file( $id );
			if ( ! is_string( $path ) || ! is_file( $path ) || filesize( $path ) > 20 * MB_IN_BYTES ) { return false; }
			$ids[$id] = $id;
		}
		return array_values( $ids );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'تعذر حفظ المواصفات. راجع القيم وصلاحية الوصول وحالة المركبة.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
