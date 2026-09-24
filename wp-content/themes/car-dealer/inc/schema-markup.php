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
		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'Vehicle',
			'name' => get_the_title(),
			'url' => get_permalink(),
			'offers' => array(
				'@type' => 'Offer',
				'price' => (float) get_post_meta( $car_id, '_car_price', true ),
				'priceCurrency' => 'SAR',
				'availability' => 'https://schema.org/InStock',
			),
			'vehicleModelDate' => get_post_meta( $car_id, '_car_year', true ),
			'mileageFromOdometer' => array(
				'@type' => 'QuantitativeValue',
				'value' => (int) get_post_meta( $car_id, '_car_kilometers', true ),
				'unitCode' => 'KMT',
			),
		);
	}
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', 'car_dealer_schema_markup' );
