<?php
namespace AutoDealership\Content;

use AutoDealership\Inventory\CatalogPresentation;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Core\BranchLocations;
use AutoDealership\Core\SocialChannels;

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
		if ( (string) ( $data['@id'] ?? '' ) !== home_url( '/' ) . '#organization' ) {
			self::print_entity( self::organization() );
		}
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
				'publisher' => array( '@id' => home_url( '/' ) . '#organization' ),
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
				'publisher' => array( '@id' => home_url( '/' ) . '#organization' ),
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
		return self::organization();
	}

	/** Build business identity schema only from public settings in this installation. */
	/** @return array<string,mixed> */
	private static function organization(): array {
		$language = \AutoDealership\Core\Localization::language();
		$settings = (array) get_option( 'car_dealer_theme_settings', array() );
		$home     = home_url( '/' );
		$data     = array(
			'@context' => 'https://schema.org',
			// Keep the existing Organization contract for consumers that expect a
			// scalar @type, while exposing the dealership specialization safely.
			'@type'    => 'Organization',
			'additionalType' => 'https://schema.org/AutoDealer',
			'@id'      => $home . '#organization',
			'name'     => \AutoDealership\Core\ProductSetup::brand( $language ),
			'url'      => $home,
		);

		$legal_name = \AutoDealership\Core\ProductSetup::legal_name( $language );
		if ( $legal_name && $legal_name !== $data['name'] ) {
			$data['legalName'] = $legal_name;
		}

		$logo_id = absint( get_theme_mod( 'custom_logo' ) );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		if ( is_string( $logo ) && '' !== $logo ) {
			$data['logo'] = array( '@type' => 'ImageObject', 'url' => $logo );
		}

		$phone = trim( (string) ( $settings['phone'] ?? '' ) );
		$email = sanitize_email( (string) ( $settings['email'] ?? '' ) );
		if ( $phone ) {
			$data['telephone'] = $phone;
		}
		if ( $email ) {
			$data['email'] = $email;
		}

		$address = \AutoDealership\Core\ProductSetup::contact_address( $language );
		if ( $address ) {
			$data['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $address );
		}
		$country = \AutoDealership\Core\ProductSetup::country( $language );
		if ( $country ) {
			$data['areaServed'] = $country;
			if ( isset( $data['address'] ) ) {
				$data['address']['addressCountry'] = $country;
			}
		}

		$same_as = array();
		foreach ( SocialChannels::links( 'contact' ) as $link ) {
			if ( ! empty( $link['url'] ) ) {
				$same_as[] = (string) $link['url'];
			}
		}
		if ( $same_as ) {
			$data['sameAs'] = array_values( array_unique( $same_as ) );
		}

		$locations = array();
		foreach ( BranchLocations::all() as $row ) {
			if ( ! is_array( $row ) || empty( $row['active'] ) ) {
				continue;
			}
			$name = trim( (string) ( $row[ 'name_' . $language ] ?? '' ) ) ?: trim( (string) ( $row['name_ar'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$location = array( '@type' => 'LocalBusiness', 'name' => $name );
			$location_address = trim( (string) ( $row[ 'address_' . $language ] ?? '' ) ) ?: trim( (string) ( $row['address_ar'] ?? '' ) );
			if ( $location_address ) {
				$location['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $location_address );
			}
			$location_phone = trim( (string) ( $row['phone'] ?? '' ) );
			if ( $location_phone ) {
				$location['telephone'] = $location_phone;
			}
			if ( ! empty( $row['directions_url'] ) ) {
				$location['hasMap'] = (string) $row['directions_url'];
			}
			$locations[] = $location;
		}
		if ( $locations ) {
			$data['location'] = $locations;
		}

		return $data;
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
				$offers[] = array( '@type'=>'Offer', 'name'=>implode( ' ', array( $option['brand'], $option['model'], $option['trim_name'], $option['exterior_color'], $option['model_year'] ) ), 'price'=>\AutoDealership\Pricing\Money::decimal( (string)$option['public_price'] ), 'priceCurrency'=>self::currency_code( 'SAR', $post_id, 'grouped_vehicle' ), 'availability'=>self::availability_url( 'available', $post_id, 'grouped_vehicle' ), 'url'=>$grouped_url );
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
			'priceCurrency' => self::currency_code( $currency, 0, 'vehicle_offer' ),
			'availability' => self::availability_url( 'available', 0, 'vehicle_offer' ),
			'url' => $url,
		);
	}

	private static function currency_code( string $currency, int $post_id, string $context ): string {
		$value = strtoupper( trim( (string) apply_filters( 'adc_structured_data_currency', $currency, $post_id, $context ) ) );
		return preg_match( '/\A[A-Z]{3}\z/', $value ) ? $value : 'SAR';
	}

	private static function availability_url( string $status, int $post_id, string $context ): string {
		$map = array(
			'available'  => 'https://schema.org/InStock',
			'sold'       => 'https://schema.org/SoldOut',
			'pending'    => 'https://schema.org/LimitedAvailability',
			'outofstock' => 'https://schema.org/OutOfStock',
		);
		$value = (string) apply_filters( 'adc_structured_data_availability', $map[ $status ] ?? $map['available'], $status, $post_id, $context );
		return in_array( $value, array_values( $map ), true ) ? $value : $map['available'];
	}
}
