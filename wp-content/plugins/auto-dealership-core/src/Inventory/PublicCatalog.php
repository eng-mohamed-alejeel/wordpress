<?php
namespace AutoDealership\Inventory;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Keeps publicly queried legacy car posts aligned with operational inventory. */
final class PublicCatalog {
	public static function boot(): void {
		add_filter( 'posts_clauses', array( self::class, 'filter_car_queries' ), 20, 2 );
	}

	public static function filter_car_queries( array $clauses, \WP_Query $query ): array {
		if ( is_admin() || $query->is_preview() ) {
			return $clauses;
		}
		$post_type = $query->get( 'post_type' );
		if ( 'car' !== $post_type && ( ! is_array( $post_type ) || ! in_array( 'car', $post_type, true ) ) ) {
			return $clauses;
		}
		global $wpdb;
		$vehicles = Schema::table( 'vehicles' );
		$branches = Schema::table( 'branches' );
		$posts    = $wpdb->posts;
		$clauses['where'] .= " AND NOT EXISTS (
			SELECT 1 FROM $vehicles adc_vehicle
			WHERE adc_vehicle.public_post_id = $posts.ID
			AND ( adc_vehicle.status <> 'available' OR NOT EXISTS (
				SELECT 1 FROM $branches adc_branch
				WHERE adc_branch.id = adc_vehicle.branch_id AND adc_branch.active = 1
			) )
		)";
		return $clauses;
	}
}
