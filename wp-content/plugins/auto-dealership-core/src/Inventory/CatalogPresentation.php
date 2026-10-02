<?php
namespace AutoDealership\Inventory;

defined( 'ABSPATH' ) || exit;

/** URL-bound catalog language and search-index policy, independent of the active theme. */
final class CatalogPresentation {
	private const FILTER_KEYS = array(
		'search', 'brand', 'model', 'trim', 'min_year', 'max_year', 'min_price', 'max_price',
		'min_mileage', 'max_mileage', 'body_type', 'fuel_type', 'transmission', 'engine_size',
		'drivetrain', 'exterior_color', 'interior_color', 'branch_id', 'condition', 'sort',
	);

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_catalog_presentation_enabled', true );
	}

	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_filter( 'get_canonical_url', array( self::class, 'singular_canonical' ), 10, 2 );
		add_action( 'wp_head', array( self::class, 'print_links' ), 9 );
	}

	public static function language(): string {
		$source = wp_doing_ajax() ? $_REQUEST : $_GET;
		$value = isset( $source['lang'] ) && is_scalar( $source['lang'] ) ? sanitize_key( wp_unslash( (string) $source['lang'] ) ) : '';
		return 'en' === $value ? 'en' : 'ar';
	}

	public static function is_catalog_request(): bool {
		if ( wp_doing_ajax() ) {
			return isset( $_REQUEST['lang'] ) && 'en' === self::language();
		}
		return did_action( 'wp' ) && ( is_post_type_archive( 'car' ) || is_singular( 'car' ) );
	}

	public static function localized_url( string $url, string $language = '' ): string {
		$language = in_array( $language, array( 'ar', 'en' ), true ) ? $language : self::language();
		return 'en' === $language ? add_query_arg( 'lang', 'en', $url ) : remove_query_arg( 'lang', $url );
	}

	/** Preserve only the existing public archive filters when switching language. */
	public static function language_url( string $language ): string {
		$base = is_singular( 'car' ) ? get_permalink() : get_post_type_archive_link( 'car' );
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}
		if ( is_post_type_archive( 'car' ) ) {
			foreach ( array_merge( self::FILTER_KEYS, array( 'paged' ) ) as $key ) {
				if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] ) {
					$base = add_query_arg( $key, sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ), $base );
				}
			}
		}
		return self::localized_url( $base, $language );
	}

	public static function has_active_filters(): bool {
		if ( ! is_post_type_archive( 'car' ) ) {
			return false;
		}
		foreach ( self::FILTER_KEYS as $key ) {
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] && ! ( 'sort' === $key && 'newest' === $_GET[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function robots( array $robots ): array {
		if ( PublicCatalog::is_authoritative() && self::has_active_filters() ) {
			$robots['noindex'] = true;
			$robots['follow'] = true;
		}
		return $robots;
	}

	public static function singular_canonical( string $url, \WP_Post $post ): string {
		return 'car' === $post->post_type ? self::localized_url( $url ) : $url;
	}

	public static function print_links(): void {
		if ( ! self::is_catalog_request() ) {
			return;
		}
		$archive = is_post_type_archive( 'car' );
		$base = $archive ? get_post_type_archive_link( 'car' ) : get_permalink();
		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}
		if ( $archive && PublicCatalog::is_authoritative() && ( self::has_active_filters() || 'en' === self::language() ) ) {
			echo '<link rel="canonical" href="' . esc_url( self::localized_url( $base ) ) . '">' . "\n";
		}
		foreach ( array( 'ar' => 'ar', 'en' => 'en', 'x-default' => 'ar' ) as $hreflang => $language ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( self::localized_url( $base, $language ) ) . '">' . "\n";
		}
	}
}
