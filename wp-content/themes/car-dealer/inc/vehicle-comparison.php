<?php
/** Vehicle comparison migrated into a small cookie-backed module. */
defined( 'ABSPATH' ) || exit;

function car_dealer_get_comparison_list() {
	$raw = isset( $_COOKIE['car_dealer_compare'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['car_dealer_compare'] ) ) : '';
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
	return array_slice( array_values( array_unique( $ids ) ), 0, 4 );
}

function car_dealer_set_comparison_list( $ids ) {
	$value = implode( ',', array_slice( array_values( array_unique( array_map( 'absint', $ids ) ) ), 0, 4 ) );
	setcookie( 'car_dealer_compare', $value, time() + MONTH_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	$_COOKIE['car_dealer_compare'] = $value;
}

function car_dealer_comparison_button( $car_id = 0 ) {
	$car_id = $car_id ? absint( $car_id ) : get_the_ID();
	if ( ! $car_id ) { return; }
	printf( '<button class="btn btn-outline cd-compare-button" type="button" data-car-id="%1$d">%2$s</button>', esc_attr( $car_id ), esc_html__( 'أضف للمقارنة', 'car-dealer' ) );
}

function car_dealer_ajax_comparison() {
	check_ajax_referer( 'car_dealer_frontend', 'nonce' );
	$car_id = absint( $_POST['car_id'] ?? 0 );
	$action_type = sanitize_key( wp_unslash( $_POST['compare_action'] ?? 'add' ) );
	if ( ! $car_id || 'car' !== get_post_type( $car_id ) ) { wp_send_json_error( array( 'message' => __( 'السيارة غير صالحة', 'car-dealer' ) ) ); }
	$list = car_dealer_get_comparison_list();
	if ( 'remove' === $action_type ) { $list = array_diff( $list, array( $car_id ) ); }
	else { $list[] = $car_id; }
	car_dealer_set_comparison_list( $list );
	wp_send_json_success( array( 'count' => count( car_dealer_get_comparison_list() ), 'message' => __( 'تم تحديث المقارنة', 'car-dealer' ) ) );
}
add_action( 'wp_ajax_car_dealer_comparison', 'car_dealer_ajax_comparison' );
add_action( 'wp_ajax_nopriv_car_dealer_comparison', 'car_dealer_ajax_comparison' );

function car_dealer_comparison_shortcode() {
	$ids = car_dealer_get_comparison_list();
	if ( ! $ids ) { return '<p class="empty-state">' . esc_html__( 'لم تضف سيارات للمقارنة بعد.', 'car-dealer' ) . '</p>'; }
	$query = new WP_Query( array( 'post_type' => 'car', 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => 4 ) );
	ob_start();
	echo '<div class="car-grid cd-comparison-grid">';
	while ( $query->have_posts() ) { $query->the_post(); get_template_part( 'templates/components/car-card' ); }
	echo '</div>';
	wp_reset_postdata();
	return ob_get_clean();
}
add_shortcode( 'car_dealer_comparison', 'car_dealer_comparison_shortcode' );
