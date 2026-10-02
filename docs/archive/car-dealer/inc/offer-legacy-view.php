<?php
/** Compatibility and editorial-preview reads for the former theme offer fields. */
defined( 'ABSPATH' ) || exit;

function car_dealer_offer_view_legacy( int $post_id ): array {
	return array(
		'post_id'         => $post_id,
		'car_id'          => absint( get_post_meta( $post_id, '_offer_car_id', true ) ),
		'title'           => (string) get_the_title( $post_id ),
		'url'             => (string) get_permalink( $post_id ),
		'price'           => get_post_meta( $post_id, '_offer_new_price', true ),
		'old_price'       => get_post_meta( $post_id, '_offer_old_price', true ),
		'monthly_payment' => get_post_meta( $post_id, '_offer_monthly_payment', true ),
	);
}
