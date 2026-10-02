<?php
namespace AutoDealership\Content;

defined( 'ABSPATH' ) || exit;

/** Bounded public selections for a replaceable home-page layout. */
final class HomePageView {
	/** @return array<string,mixed> */
	public static function view(): array {
		$featured = self::cars( 6, array( 'key' => '_car_featured', 'value' => '1' ) );
		$latest = self::cars( 8 );
		$demand = self::cars( 3, array( 'key' => '_car_demand', 'value' => 'yes' ) );
		$offer_ids = array();
		if ( PublicOfferView::enabled() ) {
			$offers = new \WP_Query( array(
				'post_type' => 'car_offer', 'post_status' => 'publish', 'posts_per_page' => 24,
				'fields' => 'ids', 'no_found_rows' => true, 'ignore_sticky_posts' => true,
			) );
			foreach ( $offers->posts as $post_id ) {
				if ( PublicOfferView::for_post( (int) $post_id ) ) { $offer_ids[] = (int) $post_id; }
				if ( 3 === count( $offer_ids ) ) { break; }
			}
		}
		$count = new \WP_Query( array(
			'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => 1,
			'fields' => 'ids', 'ignore_sticky_posts' => true,
		) );
		return array(
			'featured_ids' => $featured,
			'latest_ids' => $latest,
			'demand_ids' => $demand,
			'offer_ids' => $offer_ids,
			'published_car_count' => (int) $count->found_posts,
		);
	}

	/** @return int[] */
	private static function cars( int $limit, array $meta = array() ): array {
		$args = array(
			'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => $limit,
			'fields' => 'ids', 'no_found_rows' => true, 'ignore_sticky_posts' => true,
		);
		if ( $meta ) { $args['meta_query'] = array( $meta ); }
		$query = new \WP_Query( $args );
		return array_values( array_filter( array_map( 'intval', $query->posts ), static function ( int $post_id ): bool {
			return null !== PublicVehicleView::for_post( $post_id );
		} ) );
	}
}
