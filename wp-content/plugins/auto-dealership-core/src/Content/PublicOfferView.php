<?php
namespace AutoDealership\Content;

use AutoDealership\Database\Schema;
use AutoDealership\Inventory\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Read-only public offer model shared by templates and structured data. */
final class PublicOfferView {
	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_public_offer_view_enabled', true );
	}

	public static function boot(): void {
		if ( self::enabled() ) {
			add_filter( 'posts_clauses', array( self::class, 'filter_archive_query' ), 20, 2 );
			add_action( 'template_redirect', array( self::class, 'hide_invalid_single' ), 1 );
		}
	}

	/** Keep the archive's found_posts and pagination aligned with the public offer model. */
	public static function filter_archive_query( array $clauses, \WP_Query $query ): array {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'car_offer' ) || $query->is_preview() ) {
			return $clauses;
		}

		global $wpdb;
		$posts = $wpdb->posts;
		$meta = $wpdb->postmeta;
		$clauses['join'] .= " LEFT JOIN $meta adc_offer_car_meta ON adc_offer_car_meta.post_id = $posts.ID AND adc_offer_car_meta.meta_key = '_offer_car_id' AND adc_offer_car_meta.meta_id = (SELECT MIN(adc_first_car.meta_id) FROM $meta adc_first_car WHERE adc_first_car.post_id = $posts.ID AND adc_first_car.meta_key = '_offer_car_id')";
		$clauses['join'] .= " LEFT JOIN $meta adc_offer_price_meta ON adc_offer_price_meta.post_id = $posts.ID AND adc_offer_price_meta.meta_key = '_offer_new_price' AND adc_offer_price_meta.meta_id = (SELECT MIN(adc_first_price.meta_id) FROM $meta adc_first_price WHERE adc_first_price.post_id = $posts.ID AND adc_first_price.meta_key = '_offer_new_price')";
		$clauses['join'] .= " LEFT JOIN $meta adc_offer_expiry_meta ON adc_offer_expiry_meta.post_id = $posts.ID AND adc_offer_expiry_meta.meta_key = '_offer_expires' AND adc_offer_expiry_meta.meta_id = (SELECT MIN(adc_first_expiry.meta_id) FROM $meta adc_first_expiry WHERE adc_first_expiry.post_id = $posts.ID AND adc_first_expiry.meta_key = '_offer_expires')";

		$car_eligibility = self::car_eligibility_sql( 'adc_offer_car' );
		$car_id = 'adc_offer_car_meta.meta_value';
		$clauses['where'] .= $wpdb->prepare(
			" AND $posts.post_type = 'car_offer' AND $posts.post_status = 'publish'
				AND $car_id REGEXP '^[0-9]{1,20}$' AND $car_id NOT REGEXP '[^0-9]' AND CAST($car_id AS UNSIGNED) > 0
				AND adc_offer_price_meta.meta_value REGEXP '^[0-9]{1,12}$' AND adc_offer_price_meta.meta_value NOT REGEXP '[^0-9]' AND CAST(adc_offer_price_meta.meta_value AS UNSIGNED) > 0
				AND (adc_offer_expiry_meta.meta_id IS NULL OR adc_offer_expiry_meta.meta_value = '' OR (
					CHAR_LENGTH(adc_offer_expiry_meta.meta_value) = 10 AND adc_offer_expiry_meta.meta_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
					AND CAST(SUBSTRING(adc_offer_expiry_meta.meta_value, 1, 4) AS UNSIGNED) BETWEEN 1 AND 9999
					AND CAST(SUBSTRING(adc_offer_expiry_meta.meta_value, 6, 2) AS UNSIGNED) BETWEEN 1 AND 12
					AND CAST(SUBSTRING(adc_offer_expiry_meta.meta_value, 9, 2) AS UNSIGNED) BETWEEN 1 AND DAY(LAST_DAY(CONCAT(SUBSTRING(adc_offer_expiry_meta.meta_value, 1, 7), '-01')))
					AND adc_offer_expiry_meta.meta_value >= %s
				))
				AND EXISTS (SELECT 1 FROM $posts adc_offer_car WHERE adc_offer_car.ID = CAST($car_id AS UNSIGNED)
					AND adc_offer_car.post_type = 'car' AND adc_offer_car.post_status = 'publish' AND $car_eligibility)",
			current_time( 'Y-m-d' )
		);
		return $clauses;
	}

	/** Mirrors PublicCatalog's mapped and unmapped visibility decision. */
	private static function car_eligibility_sql( string $car_alias ): string {
		global $wpdb;
		$legacy_status = "COALESCE((SELECT adc_inventory_meta.meta_value FROM {$wpdb->postmeta} adc_inventory_meta WHERE adc_inventory_meta.post_id = $car_alias.ID AND adc_inventory_meta.meta_key = '_car_inventory_status' ORDER BY adc_inventory_meta.meta_id ASC LIMIT 1), '') IN ('', 'available')";
		if ( get_option( 'adc_db_version' ) !== Schema::VERSION ) {
			return $legacy_status;
		}

		$vehicles = Schema::table( 'vehicles' );
		$branches = Schema::table( 'branches' );
		$mapped = "EXISTS (SELECT 1 FROM $vehicles adc_offer_vehicle INNER JOIN $branches adc_offer_branch ON adc_offer_branch.id = adc_offer_vehicle.branch_id AND adc_offer_branch.active = 1 WHERE adc_offer_vehicle.public_post_id = $car_alias.ID AND adc_offer_vehicle.status = 'available' AND NOT EXISTS (SELECT 1 FROM $vehicles adc_offer_duplicate WHERE adc_offer_duplicate.public_post_id = adc_offer_vehicle.public_post_id AND adc_offer_duplicate.id <> adc_offer_vehicle.id))";
		if ( PublicCatalog::is_authoritative() ) {
			return $mapped;
		}
		return "($mapped OR (NOT EXISTS (SELECT 1 FROM $vehicles adc_offer_mapping WHERE adc_offer_mapping.public_post_id = $car_alias.ID) AND $legacy_status))";
	}

	public static function hide_invalid_single(): void {
		if ( ! is_singular( 'car_offer' ) || is_preview() || self::for_post( get_queried_object_id() ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/** @return array<string,mixed>|null */
	public static function for_post( int $post_id ): ?array {
		if ( $post_id < 1 || 'car_offer' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}
		$raw_car_id = get_post_meta( $post_id, '_offer_car_id', true );
		$car_id = is_scalar( $raw_car_id ) && preg_match( '/\A[0-9]{1,20}\z/', (string) $raw_car_id ) ? absint( $raw_car_id ) : 0;
		if ( ! self::car_available( $car_id ) ) {
			return null;
		}
		$expires = get_post_meta( $post_id, '_offer_expires', true );
		if ( ! is_string( $expires ) || ( '' !== $expires && ( ! self::valid_date( $expires ) || $expires < current_time( 'Y-m-d' ) ) ) ) {
			return null;
		}
		$price = self::price( get_post_meta( $post_id, '_offer_new_price', true ) );
		if ( $price < 1 ) {
			return null;
		}
		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$old = self::price( get_post_meta( $post_id, '_offer_old_price', true ) );
		$monthly = self::price( get_post_meta( $post_id, '_offer_monthly_payment', true ) );
		return array(
			'post_id' => $post_id,
			'car_id' => $car_id,
			'title' => (string) get_the_title( $post_id ),
			'car_title' => (string) get_the_title( $car_id ),
			'url' => $url,
			'price' => $price,
			'old_price' => $old > $price ? $old : 0,
			'monthly_payment' => $monthly,
			'currency' => 'SAR', // Legacy offer price fields are whole SAR, not vehicle minor units.
			'expires' => $expires,
			'image_url' => get_the_post_thumbnail_url( $post_id, 'full' ) ?: get_the_post_thumbnail_url( $car_id, 'full' ) ?: '',
		);
	}

	/** Authorized editorial preview keeps the existing draft fields visible without publishing them. */
	public static function for_preview( int $post_id ): ?array {
		if ( $post_id < 1 || 'car_offer' !== get_post_type( $post_id ) || ! is_preview() || ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}
		return array(
			'post_id'         => $post_id,
			'car_id'          => absint( get_post_meta( $post_id, '_offer_car_id', true ) ),
			'title'           => (string) get_the_title( $post_id ),
			'url'             => (string) get_permalink( $post_id ),
			'price'           => self::price( get_post_meta( $post_id, '_offer_new_price', true ) ),
			'old_price'       => self::price( get_post_meta( $post_id, '_offer_old_price', true ) ),
			'monthly_payment' => self::price( get_post_meta( $post_id, '_offer_monthly_payment', true ) ),
		);
	}

	private static function car_available( int $post_id ): bool {
		if ( ! PublicCatalog::is_post_publicly_eligible( $post_id ) ) {
			return false;
		}
		return (bool) PublicCatalog::vehicle_for_post( $post_id )
			|| in_array( get_post_meta( $post_id, '_car_inventory_status', true ), array( '', 'available' ), true );
	}

	private static function price( $value ): int {
		return is_scalar( $value ) && preg_match( '/\A[0-9]{1,12}\z/', (string) $value ) ? max( 0, (int) $value ) : 0;
	}

	private static function valid_date( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		return (bool) $date && $date->format( 'Y-m-d' ) === $value;
	}
}
