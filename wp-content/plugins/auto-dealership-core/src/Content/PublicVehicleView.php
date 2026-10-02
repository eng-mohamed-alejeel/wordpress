<?php
namespace AutoDealership\Content;

use AutoDealership\Inventory\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Read-only display data for a vehicle card or detail page. */
final class PublicVehicleView {
	/** @return array<string,mixed>|null */
	public static function for_post( int $post_id, bool $preview = false ): ?array {
		if ( $post_id < 1 || 'car' !== get_post_type( $post_id ) ) {
			return null;
		}
		if ( $preview ) {
			if ( ! is_preview() || ! current_user_can( 'edit_post', $post_id ) ) {
				return null;
			}
		} elseif ( ! PublicCatalog::is_post_publicly_eligible( $post_id ) ) {
			return null;
		}

		$vehicle = PublicCatalog::vehicle_for_post( $post_id );
		$legacy = static function ( string $key ) use ( $post_id ) {
			$value = get_post_meta( $post_id, $key, true );
			return is_scalar( $value ) ? $value : '';
		};
		$terms = static function ( string $taxonomy ) use ( $post_id ): string {
			$values = get_the_terms( $post_id, $taxonomy );
			return ! is_wp_error( $values ) && ! empty( $values ) ? (string) $values[0]->name : '';
		};
		$categories = get_the_terms( $post_id, 'car_category' );
		$features = array();
		if ( $vehicle ) {
			foreach ( array( 'interior_features', 'exterior_features', 'safety_features' ) as $key ) {
				$features = array_merge( $features, preg_split( '/\r\n|\r|\n/', (string) ( $vehicle[ $key ] ?? '' ) ) ?: array() );
			}
		} else {
			$raw_features = get_post_meta( $post_id, '_car_features', true );
			$features = is_array( $raw_features ) ? $raw_features : explode( "\n", (string) $raw_features );
		}
		$features = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', array_filter( $features, 'is_scalar' ) ) ) ) ) );
		return array(
			'post_id'      => $post_id,
			'vehicle'      => $vehicle,
			'price'        => $vehicle ? ( (int) $vehicle['retail_price'] / 100 ) : $legacy( '_car_price' ),
			'monthly'      => $legacy( '_car_monthly_payment' ),
			'year'         => $vehicle ? $vehicle['model_year'] : $legacy( '_car_year' ),
			'model'        => $vehicle ? $vehicle['model'] : $legacy( '_car_model' ),
			'color'        => $vehicle ? $vehicle['exterior_color'] : $legacy( '_car_color' ),
			'kilometers'   => $vehicle ? $vehicle['mileage'] : $legacy( '_car_kilometers' ),
			'transmission' => $vehicle ? $vehicle['transmission'] : $legacy( '_car_transmission' ),
			'fuel'         => $vehicle ? $vehicle['fuel_type'] : $legacy( '_car_fuel_type' ),
			'condition'    => $vehicle ? $vehicle['condition_key'] : $legacy( '_car_condition' ),
			'status'       => $vehicle ? $vehicle['status'] : ( $legacy( '_car_inventory_status' ) ?: 'available' ),
			'featured'     => (bool) $legacy( '_car_featured' ),
			'features'     => $features,
			'brand_name'   => $vehicle ? $vehicle['brand'] : $terms( 'car_brand' ),
			'category'     => $vehicle ? $vehicle['body_type'] : ( ! is_wp_error( $categories ) && ! empty( $categories ) ? $categories[0]->slug : '' ),
		);
	}
}
