<?php
/** Structured data migrated from the legacy SEO module. */
defined( 'ABSPATH' ) || exit;

function car_dealer_schema_markup() {
	$data = array(
		'@context' => 'https://schema.org',
		'@type' => 'Organization',
		'name' => get_bloginfo( 'name' ),
		'url' => home_url( '/' ),
	);
	if ( is_singular( 'car' ) ) {
		$car_id = get_the_ID();
		$vehicle = function_exists( 'car_dealer_public_vehicle' ) ? car_dealer_public_vehicle( $car_id ) : null;
		$price = $vehicle && function_exists( 'car_dealer_catalog_price' ) ? car_dealer_catalog_price( $vehicle ) : (float) get_post_meta( $car_id, '_car_price', true );
		$public_url = function_exists( 'car_dealer_catalog_localized_url' ) ? car_dealer_catalog_localized_url( get_permalink( $car_id ) ) : get_permalink( $car_id );
		$year = $vehicle ? $vehicle['model_year'] : get_post_meta( $car_id, '_car_year', true );
		$mileage = $vehicle ? $vehicle['mileage'] : (int) get_post_meta( $car_id, '_car_kilometers', true );
		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'Vehicle',
			'name' => get_the_title(),
			'url' => $public_url,
			'offers' => array(
				'@type' => 'Offer',
				'price' => $price,
				'priceCurrency' => $vehicle['currency'] ?? 'SAR',
				'availability' => 'https://schema.org/InStock',
				'url' => $public_url,
			),
			'vehicleModelDate' => $year,
			'mileageFromOdometer' => array(
				'@type' => 'QuantitativeValue',
				'value' => $mileage,
				'unitCode' => 'KMT',
			),
		);
		if ( $vehicle ) {
			$data['brand'] = array( '@type' => 'Brand', 'name' => $vehicle['brand'] );
			$data['model'] = $vehicle['model'];
			$data['vehicleConfiguration'] = $vehicle['trim_name'];
			$data['sku'] = $vehicle['stock_number'];
			$data['fuelType'] = $vehicle['fuel_type'];
			$data['vehicleTransmission'] = $vehicle['transmission'];
			$data['color'] = $vehicle['exterior_color'];
			$data['itemCondition'] = 'new' === $vehicle['condition_key'] ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition';
		}
		if ( has_post_thumbnail( $car_id ) ) {
			$data['image'] = get_the_post_thumbnail_url( $car_id, 'full' );
		}
	}
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', 'car_dealer_schema_markup' );
