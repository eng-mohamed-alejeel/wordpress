<?php
/** Advanced search and listing shortcodes migrated from legacy modules. */
defined( 'ABSPATH' ) || exit;

function car_dealer_search_shortcode() {
	ob_start();
	get_template_part( 'templates/components/filter-bar' );
	return ob_get_clean();
}
add_shortcode( 'car_dealer_search', 'car_dealer_search_shortcode' );

function car_dealer_cars_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'count' => 6, 'featured' => '', 'status' => '' ), $atts, 'car_dealer_cars' );
	$args = array( 'post_type' => 'car', 'posts_per_page' => absint( $atts['count'] ) );
	if ( function_exists( 'car_dealer_catalog_is_authoritative' ) && car_dealer_catalog_is_authoritative() ) {
		$args['adc_public_catalog'] = true;
		$args['adc_catalog_filters'] = array( 'sort' => 'newest' );
	}
	$meta = array();
	if ( ! function_exists( 'car_dealer_catalog_is_authoritative' ) || ! car_dealer_catalog_is_authoritative() ) {
		if ( '' !== $atts['featured'] ) { $meta[] = array( 'key' => '_car_featured', 'value' => absint( $atts['featured'] ) ); }
		if ( '' !== $atts['status'] ) { $meta[] = array( 'key' => '_car_inventory_status', 'value' => sanitize_key( $atts['status'] ) ); }
	}
	if ( $meta ) { $args['meta_query'] = $meta; }
	$query = new WP_Query( $args );
	ob_start();
	if ( $query->have_posts() ) {
		echo '<div class="car-grid">';
		while ( $query->have_posts() ) { $query->the_post(); get_template_part( 'templates/components/car-card' ); }
		echo '</div>';
	} else {
		echo '<p class="empty-state">' . esc_html__( 'لا توجد سيارات مطابقة.', 'car-dealer' ) . '</p>';
	}
	wp_reset_postdata();
	return ob_get_clean();
}
add_shortcode( 'car_dealer_cars', 'car_dealer_cars_shortcode' );

function car_dealer_extend_archive_filters( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'car' ) ) { return; }
	if ( function_exists( 'car_dealer_catalog_is_authoritative' ) && car_dealer_catalog_is_authoritative() ) { return; }
	$meta_query = (array) $query->get( 'meta_query' );
	if ( isset( $_GET['inventory_status'] ) && '' !== $_GET['inventory_status'] ) {
		$meta_query[] = array( 'key' => '_car_inventory_status', 'value' => sanitize_key( wp_unslash( $_GET['inventory_status'] ) ) );
	}
	if ( isset( $_GET['featured'] ) && '1' === $_GET['featured'] ) {
		$meta_query[] = array( 'key' => '_car_featured', 'value' => '1' );
	}
	if ( $meta_query ) { $query->set( 'meta_query', $meta_query ); }
}
add_action( 'pre_get_posts', 'car_dealer_extend_archive_filters', 15 );
