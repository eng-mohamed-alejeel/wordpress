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
		self::print_entity( $data );
		foreach ( self::additional_entities() as $entity ) {
			self::print_entity( $entity );
		}
	}

	/** @param array<string,mixed> $entity */
	private static function print_entity( array $entity ): void {
		$json = wp_json_encode( $entity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( is_string( $json ) ) {
			echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
		}
	}

	/** Additional page entities remain separate so existing Vehicle consumers keep their contract. */
	/** @return array<int,array<string,mixed>> */
	private static function additional_entities(): array {
		if ( is_admin() || isset( $_GET['cd_account'] ) || is_search() || is_404() ) {
			return array();
		}
		$language = CatalogPresentation::language();
		$home = CatalogPresentation::localized_url( home_url( '/' ), $language );
		$base = $home;
		$name = \AutoDealership\Core\ProductSetup::brand( $language );
		$items = array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'en' === $language ? 'Home' : 'الرئيسية', 'item' => $home ) );
		if ( is_post_type_archive( 'car' ) ) {
			$base = CatalogPresentation::localized_url( (string) get_post_type_archive_link( 'car' ), $language );
			$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => 'en' === $language ? 'Cars' : 'السيارات', 'item' => $base );
		} elseif ( is_post_type_archive( 'car_offer' ) ) {
			$base = CatalogPresentation::localized_url( (string) get_post_type_archive_link( 'car_offer' ), $language );
			$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => 'en' === $language ? 'Offers' : 'العروض', 'item' => $base );
		} elseif ( is_singular( 'car' ) ) {
			$base = CatalogPresentation::localized_url( (string) get_permalink(), $language );
			$archive = CatalogPresentation::localized_url( (string) get_post_type_archive_link( 'car' ), $language );
			$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => 'en' === $language ? 'Cars' : 'السيارات', 'item' => $archive );
			$items[] = array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title(), 'item' => $base );
		} elseif ( is_singular( 'car_offer' ) ) {
			$base = CatalogPresentation::localized_url( (string) get_permalink(), $language );
			$archive = CatalogPresentation::localized_url( (string) get_post_type_archive_link( 'car_offer' ), $language );
			$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => 'en' === $language ? 'Offers' : 'العروض', 'item' => $archive );
			$items[] = array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title(), 'item' => $base );
		} elseif ( is_singular( array( 'page', 'post' ) ) ) {
			$page_url = CatalogPresentation::localized_url( (string) get_permalink(), $language );
			$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => $page_url );
			$base = $page_url;
		}
		$entities = array(
			array(
				'@context' => 'https://schema.org',
				'@type' => 'WebPage',
				'@id' => $base . '#webpage',
				'url' => $base,
				'name' => wp_get_document_title(),
				'isPartOf' => array( '@id' => $home . '#website' ),
			),
			array(
				'@context' => 'https://schema.org',
				'@type' => 'BreadcrumbList',
				'itemListElement' => $items,
			),
		);
		if ( is_front_page() || is_home() ) {
			$entities[] = array(
				'@context' => 'https://schema.org',
				'@type' => 'WebSite',
				'@id' => $home . '#website',
				'url' => $home,
				'name' => $name,
			);
		}
		return $entities;
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
			'name' => \AutoDealership\Core\ProductSetup::brand( \AutoDealership\Core\Localization::language() ),
			'url' => home_url( '/' ),
		);
	}

	/** @return array<string,mixed> */
	public static function vehicle( int $post_id ): array {
		if ( ! self::visible_car( $post_id ) ) {
			return array();
		}
		if ( \AutoDealership\Inventory\GroupedModelCatalog::is_mapped( $post_id ) ) {
			$view = \AutoDealership\Inventory\GroupedModelCatalog::view( $post_id );
			if ( ! $view ) { return array(); }
			$grouped_url = CatalogPresentation::localized_url( (string) get_permalink( $post_id ) );
			$data = array( '@context'=>'https://schema.org', '@type'=>'Vehicle', 'name'=>get_the_title( $post_id ), 'url'=>$grouped_url, 'brand'=>array( '@type'=>'Brand', 'name'=>$view['brand_name'] ), 'model'=>$view['model'], 'color'=>$view['catalog_model']['colors'] );
			$offers = array();
			foreach ( $view['catalog_model']['options'] as $option ) {
				if ( (int)$option['listed_price'] < 1 ) { continue; }
				$offers[] = array( '@type'=>'Offer', 'name'=>implode( ' ', array( $option['brand'], $option['model'], $option['trim_name'], $option['exterior_color'], $option['model_year'] ) ), 'price'=>\AutoDealership\Pricing\Money::decimal( (string)$option['public_price'] ), 'priceCurrency'=>'SAR', 'availability'=>'https://schema.org/InStock', 'url'=>$grouped_url );
			}
			if ( $offers ) { $data['offers'] = $offers; }
			$image = get_the_post_thumbnail_url( $post_id, 'full' );
			if ( $image ) { $data['image'] = $image; }
			return $data;
		}
		$record = PublicCatalog::vehicle_for_post( $post_id );
		$url = CatalogPresentation::localized_url( (string) get_permalink( $post_id ) );
		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'Vehicle',
			'name' => get_the_title( $post_id ),
			'url' => $url,
		);
		$view = PublicVehicleView::for_post($post_id);
		$price = (float)($view['price'] ?? 0);
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
		$url = CatalogPresentation::localized_url( (string) $model['url'] );
		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'Vehicle',
			'name' => $model['car_title'],
			'url' => $url,
			'offers' => self::offer_value( $model['price'], $model['currency'], $url ),
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
		if ( \AutoDealership\Inventory\GroupedModelCatalog::is_mapped( $post_id ) ) { return true; }
		$record = PublicCatalog::vehicle_for_post( $post_id );
		return $record || in_array( get_post_meta( $post_id, '_car_inventory_status', true ), array( '', 'available' ), true );
	}

	/**
	 * @param mixed $value
	 */
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
