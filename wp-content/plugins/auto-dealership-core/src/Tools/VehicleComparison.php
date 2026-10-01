<?php
namespace AutoDealership\Tools;

use AutoDealership\Inventory\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Validates the browser-owned comparison selection against the public catalog. */
final class VehicleComparison {
	public const COOKIE = 'car_dealer_comparison';
	public const LEGACY_COOKIE = 'car_dealer_compare';
	public const LIMIT = 4;
	private static ?array $current = null;

	/** @return int[] */
	public static function current(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$raw = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		$ids = self::decode( $raw );
		if ( ! $ids ) {
			$legacy = isset( $_COOKIE[ self::LEGACY_COOKIE ] ) && is_string( $_COOKIE[ self::LEGACY_COOKIE ] ) ? wp_unslash( $_COOKIE[ self::LEGACY_COOKIE ] ) : '';
			$ids = self::decode( $legacy );
		}
		self::$current = array_values( array_filter( self::normalize( $ids ), static fn( int $id ): bool => PublicCatalog::is_post_publicly_eligible( $id ) ) );
		return self::$current;
	}

	public static function contains( int $post_id ): bool {
		return in_array( $post_id, self::current(), true );
	}

	/** @return array{ids:int[],count:int,active:bool}|\WP_Error */
	public static function change( int $post_id, string $action ) {
		if ( $post_id < 1 || ! in_array( $action, array( 'add', 'remove', 'toggle' ), true ) ) {
			return new \WP_Error( 'adc_comparison_invalid', __( 'طلب المقارنة غير صالح.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		$ids = self::current();
		$exists = in_array( $post_id, $ids, true );
		$add = 'add' === $action || ( 'toggle' === $action && ! $exists );
		if ( $add && ! PublicCatalog::is_post_publicly_eligible( $post_id ) ) {
			return new \WP_Error( 'adc_comparison_vehicle_unavailable', __( 'السيارة غير متاحة للمقارنة.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		if ( $add && ! $exists && count( $ids ) >= self::LIMIT ) {
			return new \WP_Error( 'adc_comparison_full', sprintf( __( 'يمكن مقارنة %d سيارات كحد أقصى.', 'auto-dealership-core' ), self::LIMIT ), array( 'status'=>409 ) );
		}
		$ids = $add ? array_merge( $ids, array( $post_id ) ) : array_values( array_diff( $ids, array( $post_id ) ) );
		self::store( $ids );
		return array( 'ids'=>self::$current, 'count'=>count( self::$current ), 'active'=>in_array( $post_id, self::$current, true ) );
	}

	/** @return int[] */
	private static function decode( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : explode( ',', sanitize_text_field( $raw ) );
	}

	/** @return int[] */
	private static function normalize( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return array_slice( $ids, 0, self::LIMIT );
	}

	private static function store( array $ids ): void {
		self::$current = self::normalize( $ids );
		$expires = time() + MONTH_IN_SECONDS;
		$options = array(
			'expires' => $expires,
			'path' => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain' => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure' => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		$canonical = wp_json_encode( self::$current );
		$legacy = implode( ',', self::$current );
		if ( false !== $canonical ) {
			setcookie( self::COOKIE, $canonical, $options );
			$_COOKIE[ self::COOKIE ] = $canonical;
		}
		setcookie( self::LEGACY_COOKIE, $legacy, $options );
		$_COOKIE[ self::LEGACY_COOKIE ] = $legacy;
	}
}
