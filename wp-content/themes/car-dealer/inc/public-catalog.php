<?php
/** Thin presentation adapter for the plugin-owned public catalog. */
defined( 'ABSPATH' ) || exit;

function car_dealer_catalog_is_authoritative(): bool {
	return class_exists( '\AutoDealership\Inventory\PublicCatalog' ) && \AutoDealership\Inventory\PublicCatalog::is_authoritative();
}

function car_dealer_public_vehicle( $post_id = 0 ): ?array {
	$post_id = $post_id ? absint( $post_id ) : get_the_ID();
	if ( ! $post_id || ! class_exists( '\AutoDealership\Inventory\PublicCatalog' ) ) {
		return null;
	}
	return \AutoDealership\Inventory\PublicCatalog::vehicle_for_post( $post_id );
}

function car_dealer_catalog_filter_options(): array {
	if ( ! car_dealer_catalog_is_authoritative() ) {
		return array();
	}
	return \AutoDealership\Inventory\PublicCatalog::filter_options();
}

/** Converts the plugin's integer minor-unit price to the theme's display unit. */
function car_dealer_catalog_price( array $vehicle ): float {
	return isset( $vehicle['retail_price'] ) ? ( (int) $vehicle['retail_price'] / 100 ) : 0.0;
}

function car_dealer_catalog_features( array $vehicle ): array {
	$features = array();
	foreach ( array( 'interior_features', 'exterior_features', 'safety_features' ) as $field ) {
		if ( empty( $vehicle[ $field ] ) ) {
			continue;
		}
		$parts = preg_split( '/\r\n|\r|\n/', (string) $vehicle[ $field ] );
		$features = array_merge( $features, array_filter( array_map( 'trim', $parts ) ) );
	}
	return array_values( array_unique( $features ) );
}

function car_dealer_catalog_has_active_filters(): bool {
	if ( ! is_post_type_archive( 'car' ) ) {
		return false;
	}
	foreach ( array( 'search', 'brand', 'model', 'trim', 'min_year', 'max_year', 'min_price', 'max_price', 'min_mileage', 'max_mileage', 'body_type', 'fuel_type', 'transmission', 'engine_size', 'drivetrain', 'exterior_color', 'interior_color', 'branch_id', 'condition', 'sort' ) as $key ) {
		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] && ! ( 'sort' === $key && 'newest' === $_GET[ $key ] ) ) {
			return true;
		}
	}
	return false;
}

function car_dealer_catalog_robots( array $robots ): array {
	if ( car_dealer_catalog_is_authoritative() && car_dealer_catalog_has_active_filters() ) {
		$robots['noindex'] = true;
		$robots['follow'] = true;
	}
	return $robots;
}
add_filter( 'wp_robots', 'car_dealer_catalog_robots' );

function car_dealer_catalog_canonical(): void {
	if ( car_dealer_catalog_is_authoritative() && car_dealer_catalog_has_active_filters() ) {
		echo '<link rel="canonical" href="' . esc_url( get_post_type_archive_link( 'car' ) ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'car_dealer_catalog_canonical', 9 );
