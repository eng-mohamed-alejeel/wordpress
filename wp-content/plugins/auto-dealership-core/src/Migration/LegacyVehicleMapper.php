<?php
namespace AutoDealership\Migration;

use AutoDealership\Inventory\VehicleSpecifications;
use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/** One field map for dry runs, imports and reconciliation. No writes. */
final class LegacyVehicleMapper {
	public static function map( int $id ) {
		$meta = static fn( string $key ) => get_post_meta( $id, $key, true );
		// Serialized arrays/objects must not become literal "Array" identity values.
		foreach ( array( '_car_vin','_car_stock_number','_car_stock','_car_make','_car_model','_car_trim','_car_condition','_car_body_type','_car_fuel_type','_car_transmission' ) as $key ) {
			if ( ! is_string( $meta( $key ) ) ) { return new \WP_Error( 'invalid_identity' ); }
		}
		$stock = $meta( '_car_stock_number' );
		if ( '' === $stock ) { $stock = $meta( '_car_stock' ); }
		$mileage = $meta( '_car_mileage' );
		if ( '' === $mileage ) { $mileage = $meta( '_car_kilometers' ); }
		$price = self::price( $meta( '_car_price' ) );
		$year = Money::parse( $meta( '_car_year' ) );
		$mileage = '' === $mileage ? 0 : Money::parse( $mileage );
		$condition = $meta( '_car_condition' );
		$row = array(
			'vin'=>strtoupper( sanitize_text_field( (string) $meta( '_car_vin' ) ) ),
			'stock_number'=>sanitize_text_field( (string) $stock ),
			'brand'=>sanitize_text_field( (string) $meta( '_car_make' ) ),
			'model'=>sanitize_text_field( (string) $meta( '_car_model' ) ),
			'trim_name'=>sanitize_text_field( (string) $meta( '_car_trim' ) ),
			'model_year'=>$year,
			'condition_key'=>$condition,
			'body_type'=>sanitize_key( (string) $meta( '_car_body_type' ) ),
			'fuel_type'=>sanitize_key( (string) $meta( '_car_fuel_type' ) ),
			'transmission'=>sanitize_key( (string) $meta( '_car_transmission' ) ),
			'mileage'=>$mileage,
			'retail_price'=>$price,
			'currency'=>'SAR',
		);
		if ( ! preg_match( '/\A[A-HJ-NPR-Z0-9]{17}\z/', $row['vin'] ) || '' === $row['stock_number'] || '' === $row['brand'] || '' === $row['model'] || null === $year || $year < 1900 || $year > (int) gmdate( 'Y' ) + 2 || null === $price || $price < 1 || null === $mileage || $mileage > 4294967295 || ! in_array( $condition, array( 'new','used','certified' ), true ) ) { return new \WP_Error( 'invalid_identity' ); }
		foreach ( array( 'stock_number'=>64, 'brand'=>100, 'model'=>120, 'trim_name'=>120, 'body_type'=>40, 'fuel_type'=>40, 'transmission'=>40 ) as $field => $limit ) {
			if ( mb_strlen( $row[$field] ) > $limit ) { return new \WP_Error( 'invalid_identity' ); }
		}
		$specs = array();
		foreach ( VehicleSpecifications::fields() as $field ) {
			$value = $meta( 'exterior_color' === $field ? '_car_color' : '_car_' . $field );
			if ( ! is_string( $value ) && ! is_int( $value ) ) { return new \WP_Error( 'invalid_specifications' ); }
			$specs[$field] = $value;
		}
		$specs = VehicleSpecifications::validate( $specs );
		if ( is_wp_error( $specs ) ) { return new \WP_Error( 'invalid_specifications' ); }
		return array_merge( $row, $specs );
	}

	/** Decimal SAR with at most two fraction digits, without floating point. */
	public static function price( $value ): ?int {
		if ( ! is_string( $value ) || ! preg_match( '/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/', $value, $parts ) ) { return null; }
		return Money::parse( $parts[1] . str_pad( $parts[2] ?? '', 2, '0' ) );
	}

	public static function status( int $id ): ?string {
		$status = get_post_meta( $id, '_car_inventory_status', true );
		if ( ! is_string( $status ) ) { return null; }
		// Missing/unknown status is not evidence that inventory is available.
		return array( 'available'=>'available', 'pending'=>'received' )[$status] ?? null;
	}
}
