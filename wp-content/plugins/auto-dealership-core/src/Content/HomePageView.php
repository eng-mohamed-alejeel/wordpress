<?php
namespace AutoDealership\Content;

defined( 'ABSPATH' ) || exit;

/** Bounded public selections for a replaceable home-page layout. */
final class HomePageView {
	/** @return array<string,mixed> */
	public static function view(): array {
		$settings = SiteContent::data()['home'];
		$featured = self::cars( (int)$settings['featured']['count'], array( 'key' => '_car_featured', 'value' => '1' ) );
		$latest = self::cars( (int)$settings['latest']['count'] );
		$demand = self::cars( (int)$settings['demand']['count'], array( 'key' => '_car_demand', 'value' => 'yes' ) );
		$offer_ids = array();
		if ( PublicOfferView::enabled() ) {
			$offers = new \WP_Query( array(
				'post_type' => 'car_offer', 'post_status' => 'publish', 'posts_per_page' => 48,
				'fields' => 'ids', 'no_found_rows' => true, 'ignore_sticky_posts' => true,
			) );
			foreach ( $offers->posts as $post_id ) {
				if ( PublicOfferView::for_post( (int) $post_id ) ) { $offer_ids[] = (int) $post_id; }
				if ( (int)$settings['offers']['count'] === count( $offer_ids ) ) { break; }
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
			'available_car_count' => self::available_car_count(),
		);
	}

	private static function available_car_count(): int {
		global $wpdb;
		if ( get_option( 'adc_db_version' ) === \AutoDealership\Database\Schema::VERSION ) {
			$table = \AutoDealership\Database\Schema::table( 'onyx_stock' );
			$total = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' s WHERE ' . \AutoDealership\Inventory\GroupedModelCatalog::availability() );
			return $wpdb->last_error ? 0 : (int) $total;
		}
		return 0;
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
