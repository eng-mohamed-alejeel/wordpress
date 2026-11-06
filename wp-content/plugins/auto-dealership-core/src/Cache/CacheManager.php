<?php
namespace AutoDealership\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Central cache-provider boundary for public platform data.
 *
 * Callers describe the affected posts or URLs; this class owns provider
 * detection and keeps provider-specific functions out of content and theme
 * code. Global purges are intentionally explicit and are reserved for
 * site-wide settings changes.
 */
final class CacheManager {
	private const LAST_PURGE_OPTION = 'adc_cache_last_purge';

	/** @param int[] $post_ids */
	public static function purge_posts( array $post_ids, string $reason = '' ): void {
		$ids = self::post_ids( $post_ids );
		if ( ! $ids ) {
			return;
		}

		$errors = 0;
		foreach ( $ids as $post_id ) {
			self::call_provider( 'rocket_clean_post', array( $post_id ), $errors );
			self::call_provider( 'wp_cache_post_change', array( $post_id ), $errors );
			self::call_action( 'litespeed_purge_post', array( $post_id ), $errors, true );
		}
		self::call_action( 'adc_cache_purge_posts', array( $ids ), $errors );
		self::record( 'posts', $reason, count( $ids ), 0, $errors );
	}

	/**
	 * Purge exact public URLs through providers that support URL-level invalidation.
	 *
	 * @param string[] $urls
	 * @param int[]    $post_ids
	 */
	public static function purge_urls( array $urls, array $post_ids = array(), string $context = 'public', string $reason = '' ): void {
		$clean_urls = self::urls( $urls );
		$ids        = self::post_ids( $post_ids );
		if ( ! $clean_urls && ! $ids ) {
			return;
		}

		$errors = 0;
		foreach ( $clean_urls as $url ) {
			self::call_provider( 'w3tc_flush_url', array( $url ), $errors );
			self::call_action( 'litespeed_purge_url', array( $url ), $errors, true );
		}

		self::call_action( 'adc_cache_purge_urls', array( $clean_urls, $ids, $context ), $errors );
		// Preserve the original extension point used by the offer invalidator.
		if ( 'offer' === $context ) {
			self::call_action( 'adc_offer_cache_purge_urls', array( $clean_urls, $ids ), $errors );
		}
		self::record( 'urls', $reason, count( $ids ), count( $clean_urls ), $errors, $context );
	}

	/**
	 * Purge every provider's public cache. This is deliberately not used for
	 * individual offers; it is reserved for changes that affect the whole site.
	 */
	public static function purge_all( string $reason = '' ): void {
		$errors = 0;
		self::call_provider( 'wp_cache_clear_cache', array(), $errors );
		self::call_provider( 'w3tc_flush_all', array(), $errors );
		self::call_provider( 'rocket_clean_domain', array(), $errors );
		self::call_action( 'litespeed_purge_all', array(), $errors, true );
		self::call_action( 'adc_cache_purge_all', array( $reason ), $errors );
		self::record( 'all', $reason, 0, 0, $errors );
	}

	/** @return array<string,mixed> */
	public static function last_purge(): array {
		$value = get_option( self::LAST_PURGE_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @return string[] */
	public static function providers(): array {
		$providers = array();
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$providers[] = 'persistent-object-cache';
		}
		if ( function_exists( 'wp_cache_post_change' ) || function_exists( 'wp_cache_clear_cache' ) ) {
			$providers[] = 'wp-super-cache';
		}
		if ( function_exists( 'w3tc_flush_url' ) || function_exists( 'w3tc_flush_all' ) ) {
			$providers[] = 'w3-total-cache';
		}
		if ( function_exists( 'rocket_clean_post' ) || function_exists( 'rocket_clean_domain' ) ) {
			$providers[] = 'wp-rocket';
		}
		if ( has_action( 'litespeed_purge_post' ) || has_action( 'litespeed_purge_all' ) ) {
			$providers[] = 'litespeed-cache';
		}
		if ( has_action( 'adc_cache_purge_urls' ) || has_action( 'adc_cache_purge_all' ) ) {
			$providers[] = 'external-adapter';
		}
		return array_values( array_unique( $providers ) );
	}

	/** @param int[] $post_ids @return int[] */
	private static function post_ids( array $post_ids ): array {
		$ids = array_map( 'absint', $post_ids );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/** @param string[] $urls @return string[] */
	private static function urls( array $urls ): array {
		$clean = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				continue;
			}
			$url = esc_url_raw( $url );
			if ( '' !== $url ) {
				$clean[ $url ] = true;
			}
		}
		return array_keys( $clean );
	}

	/** @param mixed[] $args */
	private static function call_provider( string $function, array $args, int &$errors ): void {
		if ( ! function_exists( $function ) ) {
			return;
		}
		try {
			call_user_func_array( $function, $args );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$errors++;
		}
	}

	/** @param mixed[] $args */
	private static function call_action( string $hook, array $args, int &$errors, bool $only_if_registered = false ): void {
		if ( $only_if_registered && ! has_action( $hook ) ) {
			return;
		}
		try {
			do_action( $hook, ...$args );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$errors++;
		}
	}

	private static function record( string $operation, string $reason, int $post_count, int $url_count, int $errors, string $context = 'public' ): void {
		update_option( self::LAST_PURGE_OPTION, array(
			'operation'  => sanitize_key( $operation ),
			'context'    => sanitize_key( $context ),
			'reason'     => sanitize_key( $reason ),
			'posts'      => max( 0, $post_count ),
			'urls'       => max( 0, $url_count ),
			'errors'     => max( 0, $errors ),
			'timestamp'  => time(),
			'utc'        => current_time( 'mysql', true ),
			'providers'  => self::providers(),
		), false );
	}
}
