<?php
/** Compatibility-only catalog URL and SEO policy. */
defined( 'ABSPATH' ) || exit;

function car_dealer_catalog_language_legacy(): string {
	$value = $_REQUEST['lang'] ?? 'ar';
	$value = is_scalar( $value ) ? sanitize_key( wp_unslash( (string) $value ) ) : 'ar';
	return 'en' === $value ? 'en' : 'ar';
}

function car_dealer_catalog_is_request_legacy(): bool {
	if ( wp_doing_ajax() ) {
		return isset( $_REQUEST['lang'] ) && 'en' === car_dealer_catalog_language();
	}
	if ( ! did_action( 'wp' ) ) {
		return false;
	}
	return is_post_type_archive( 'car' ) || is_singular( 'car' );
}

function car_dealer_catalog_localized_url_legacy( string $url, string $language = '' ): string {
	$language = in_array( $language, array( 'ar', 'en' ), true ) ? $language : car_dealer_catalog_language();
	return 'en' === $language ? add_query_arg( 'lang', 'en', $url ) : remove_query_arg( 'lang', $url );
}

function car_dealer_catalog_language_url_legacy( string $language ): string {
	$base = is_singular( 'car' ) ? get_permalink() : get_post_type_archive_link( 'car' );
	if ( is_post_type_archive( 'car' ) ) {
		$allowed = array( 'search', 'brand', 'model', 'trim', 'min_year', 'max_year', 'min_price', 'max_price', 'min_mileage', 'max_mileage', 'body_type', 'fuel_type', 'transmission', 'engine_size', 'drivetrain', 'exterior_color', 'interior_color', 'branch_id', 'condition', 'sort', 'paged' );
		foreach ( $allowed as $key ) {
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] ) {
				$base = add_query_arg( $key, sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ), $base );
			}
		}
	}
	return car_dealer_catalog_localized_url( $base, $language );
}

function car_dealer_catalog_has_active_filters_legacy(): bool {
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
if ( ! function_exists( 'adc_core_owns_catalog_presentation' ) || ! adc_core_owns_catalog_presentation() ) {
	add_filter( 'wp_robots', 'car_dealer_catalog_robots' );
}

function car_dealer_catalog_canonical(): void {
	if ( ! car_dealer_catalog_is_request() ) {
		return;
	}
	if ( is_post_type_archive( 'car' ) && car_dealer_catalog_is_authoritative() && ( car_dealer_catalog_has_active_filters() || 'en' === car_dealer_catalog_language() ) ) {
		echo '<link rel="canonical" href="' . esc_url( car_dealer_catalog_localized_url( get_post_type_archive_link( 'car' ) ) ) . '">' . "\n";
	}
	$base = is_singular( 'car' ) ? get_permalink() : get_post_type_archive_link( 'car' );
	echo '<link rel="alternate" hreflang="ar" href="' . esc_url( car_dealer_catalog_localized_url( $base, 'ar' ) ) . '">' . "\n";
	echo '<link rel="alternate" hreflang="en" href="' . esc_url( car_dealer_catalog_localized_url( $base, 'en' ) ) . '">' . "\n";
	echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( car_dealer_catalog_localized_url( $base, 'ar' ) ) . '">' . "\n";
}
if ( ! function_exists( 'adc_core_owns_catalog_presentation' ) || ! adc_core_owns_catalog_presentation() ) {
	add_action( 'wp_head', 'car_dealer_catalog_canonical', 9 );
}

function car_dealer_catalog_singular_canonical( string $url, WP_Post $post ): string {
	return 'car' === $post->post_type ? car_dealer_catalog_localized_url( $url ) : $url;
}
if ( ! function_exists( 'adc_core_owns_catalog_presentation' ) || ! adc_core_owns_catalog_presentation() ) {
	add_filter( 'get_canonical_url', 'car_dealer_catalog_singular_canonical', 10, 2 );
}
