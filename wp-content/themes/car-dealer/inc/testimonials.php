<?php
/** Testimonials feature migrated from legacy dynamic content. */
defined( 'ABSPATH' ) || exit;

function car_dealer_register_testimonials() {
	register_post_type( 'testimonial', array(
		'labels' => array( 'name' => __( 'آراء العملاء', 'car-dealer' ), 'singular_name' => __( 'رأي عميل', 'car-dealer' ) ),
		'public' => false,
		'show_ui' => true,
		'show_in_menu' => 'car-dealer-dashboard',
		'show_in_rest' => true,
		'menu_icon' => 'dashicons-format-quote',
		'supports' => array( 'title', 'editor', 'thumbnail' ),
	) );
}
add_action( 'init', 'car_dealer_register_testimonials' );

function car_dealer_testimonials_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'count' => 3 ), $atts, 'car_dealer_testimonials' );
	$items = get_posts( array( 'post_type' => 'testimonial', 'numberposts' => absint( $atts['count'] ), 'post_status' => 'publish' ) );
	if ( ! $items ) { return ''; }
	ob_start();
	echo '<section class="cd-testimonials"><div class="container"><h2>' . esc_html__( 'آراء العملاء', 'car-dealer' ) . '</h2><div class="cd-testimonial-grid">';
	foreach ( $items as $item ) {
		echo '<article class="cd-testimonial"><div>' . wp_kses_post( wpautop( $item->post_content ) ) . '</div><strong>' . esc_html( get_the_title( $item ) ) . '</strong></article>';
	}
	echo '</div></div></section>';
	return ob_get_clean();
}
add_shortcode( 'car_dealer_testimonials', 'car_dealer_testimonials_shortcode' );
