<?php
namespace AutoDealership\Content;

use AutoDealership\Inventory\CatalogPresentation;
use AutoDealership\Inventory\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Public JSON-LD values come from the same visibility rules as the catalog. */
final class PublicStructuredData {
	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_structured_data_enabled', true );
	}

	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_head', array( self::class, 'print_json_ld' ) );
	}

	public static function print_json_ld(): void {
		$data = self::current_page();
		if ( ! $data ) {
			return;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( is_string( $json ) ) {
			echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
		}
	}

	/** @return array<string,mixed> */
	public static function current_page(): array {
		if ( is_singular( 'car' ) ) {
			return self::vehicle( get_queried_object_id() );
		}
		if ( is_singular( 'car_offer' ) ) {
			return self::offer( get_queried_object_id() );
		}
		return array(
			'@context' => 'https://schema.org',
			'@type' => 'Organization',
			'name' => get_bloginfo( 'name' ),
			'url' => home_url( '/' ),
		);
	}

	/** @return array<string,mixed> */
	public static function vehicle( int $post_id ): array {
		if ( ! self::visible_car( $post_id ) ) {
			return array();
		}
		$record = PublicCatalog::vehicle_for_post( $post_id );
		$url = CatalogPresentation::localized_url( (string) get_permalink( $post_id ) );
		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'Vehicle',
			'name' => get_the_title( $post_id ),
			'url' => $url,
		);
		$price = $record ? ( (int) ( $record['retail_price'] ?? 0 ) / 100 ) : self::positive_price( get_post_meta( $post_id, '_car_price', true ) );
		if ( $price > 0 ) {
			$data['offers'] = self::offer_value( $price, $record['currency'] ?? 'SAR', $url );
		}
		$year = $record['model_year'] ?? get_post_meta( $post_id, '_car_year', true );
		if ( is_scalar( $year ) && preg_match( '/\A[0-9]{4}\z/', (string) $year ) ) {
			$data['vehicleModelDate'] = (string) $year;
		}
		$mileage = $record['mileage'] ?? get_post_meta( $post_id, '_car_kilometers', true );
		if ( is_scalar( $mileage ) && preg_match( '/\A[0-9]+\z/', (string) $mileage ) ) {
			$data['mileageFromOdometer'] = array( '@type' => 'QuantitativeValue', 'value' => (int) $mileage, 'unitCode' => 'KMT' );
		}
		if ( $record ) {
			foreach ( array(
				'brand' => 'brand', 'model' => 'model', 'trim_name' => 'vehicleConfiguration',
				'stock_number' => 'sku', 'fuel_type' => 'fuelType',
				'transmission' => 'vehicleTransmission', 'exterior_color' => 'color',
			) as $source => $target ) {
				if ( isset( $record[ $source ] ) && is_scalar( $record[ $source ] ) && '' !== trim( (string) $record[ $source ] ) ) {
					$data[ $target ] = 'brand' === $source ? array( '@type' => 'Brand', 'name' => (string) $record[ $source ] ) : (string) $record[ $source ];
				}
			}
			if ( in_array( $record['condition_key'] ?? '', array( 'new', 'used' ), true ) ) {
				$data['itemCondition'] = 'https://schema.org/' . ( 'new' === $record['condition_key'] ? 'NewCondition' : 'UsedCondition' );
			}
		}
		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $image ) {
			$data['image'] = $image;
		}
		return $data;
	}

	/** @return array<string,mixed> */
	public static function offer( int $post_id ): array {
		$model = PublicOfferView::for_post( $post_id );
		if ( ! $model ) {
			return array();
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'Vehicle',
			'name' => $model['car_title'],
			'url' => $model['url'],
			'offers' => self::offer_value( $model['price'], $model['currency'], $model['url'] ),
		);
		if ( '' !== $model['expires'] ) {
			$data['offers']['priceValidUntil'] = $model['expires'];
		}
		if ( '' !== $model['image_url'] ) {
			$data['image'] = $model['image_url'];
		}
		return $data;
	}

	private static function visible_car( int $post_id ): bool {
		if ( ! PublicCatalog::is_post_publicly_eligible( $post_id ) ) {
			return false;
		}
		$record = PublicCatalog::vehicle_for_post( $post_id );
		return $record || in_array( get_post_meta( $post_id, '_car_inventory_status', true ), array( '', 'available' ), true );
	}

	private static function positive_price( $value ): int {
		return is_scalar( $value ) && preg_match( '/\A[0-9]{1,12}\z/', (string) $value ) ? max( 0, (int) $value ) : 0;
	}

	/** @return array<string,mixed> */
	private static function offer_value( float $price, string $currency, string $url ): array {
		return array(
			'@type' => 'Offer',
			'price' => $price,
			'priceCurrency' => preg_match( '/\A[A-Z]{3}\z/', $currency ) ? $currency : 'SAR',
			'availability' => 'https://schema.org/InStock',
			'url' => $url,
		);
	}
}
