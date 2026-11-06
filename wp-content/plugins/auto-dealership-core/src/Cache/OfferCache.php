<?php
namespace AutoDealership\Cache;

use AutoDealership\Content\PublicOfferView;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps public offer pages coherent with full-page caches.
 *
 * The offer read model remains the source of truth. This class only removes
 * stale cached representations and limits the cache lifetime of an active
 * offer to its local WordPress expiry boundary.
 */
final class OfferCache {
	private const EXPIRY_HOOK = 'adc_offer_cache_expire';
	private const RECONCILE_HOOK = 'adc_offer_cache_reconcile';
	private const LAST_RECONCILE_OPTION = 'adc_offer_cache_last_reconcile_date';
	private const BATCH_SIZE = 250;

	/** @var array<int,bool> */
	private static array $queued_offer_ids = array();
	/** @var array<int,bool> */
	private static array $queued_vehicle_ids = array();
	/** @var array<int,bool> */
	private static array $queued_expiry_sync_ids = array();
	/** @var array<string,bool> */
	private static array $queued_urls = array();
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		add_action( 'init', array( self::class, 'schedule_reconcile' ), 20 );
		add_action( 'save_post_car_offer', array( self::class, 'queue_saved_offer' ), 99, 3 );
		add_action( 'save_post_car', array( self::class, 'queue_saved_vehicle' ), 99, 3 );
		add_action( 'transition_post_status', array( self::class, 'queue_status_change' ), 99, 3 );
		add_action( 'before_delete_post', array( self::class, 'queue_deleted_post' ), 99 );
		add_action( 'added_post_meta', array( self::class, 'queue_meta_change' ), 99, 4 );
		add_action( 'updated_post_meta', array( self::class, 'queue_meta_change' ), 99, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'queue_meta_change' ), 99, 4 );
		add_action( self::EXPIRY_HOOK, array( self::class, 'expire_offer' ), 10, 2 );
		add_action( self::RECONCILE_HOOK, array( self::class, 'reconcile' ) );
		add_action( 'send_headers', array( self::class, 'send_offer_cache_headers' ), 20 );
		add_action( 'shutdown', array( self::class, 'flush_queue' ), 100 );
	}

	/** Schedule one local-midnight safety pass without replacing a host cron. */
	public static function schedule_reconcile(): void {
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_single_event( self::next_reconcile_timestamp(), self::RECONCILE_HOOK );
		}
	}

	/** @return array{scheduled_at:int,last_date:string,wp_cron_disabled:bool} */
	public static function reconcile_status(): array {
		$last = get_option( self::LAST_RECONCILE_OPTION, '' );
		return array(
			'scheduled_at'     => (int) wp_next_scheduled( self::RECONCILE_HOOK ),
			'last_date'        => self::valid_date( (string) $last ) ? (string) $last : '',
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	/** Queue an offer after native or custom editorial saves. */
	public static function queue_saved_offer( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $post, $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::queue_offer( $post_id );
	}

	/** Queue offers and the vehicle page when public vehicle data changes. */
	public static function queue_saved_vehicle( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $post, $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::queue_vehicle( $post_id );
	}

	/** Handle status transitions, including trashing and restoring an offer. */
	public static function queue_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status || ! in_array( $post->post_type, array( 'car', 'car_offer' ), true ) ) {
			return;
		}
		if ( 'car_offer' === $post->post_type ) {
			self::queue_offer( (int) $post->ID );
			return;
		}
		self::queue_vehicle( (int) $post->ID );
	}

	/** Preserve a deleted public URL long enough for the cache adapter to purge it. */
	public static function queue_deleted_post( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'car', 'car_offer' ), true ) ) {
			return;
		}
		$url = get_permalink( $post_id );
		if ( is_string( $url ) && '' !== $url ) {
			self::$queued_urls[ $url ] = true;
		}
		if ( 'car_offer' === $post_type ) {
			self::queue_offer( $post_id );
			return;
		}
		self::queue_vehicle( $post_id );
	}

	/** Invalidate when offer or vehicle metadata is changed outside the editor. */
	public static function queue_meta_change( $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		$post_type = get_post_type( $post_id );
		if ( 'car_offer' === $post_type && in_array( $meta_key, array( '_offer_car_id', '_offer_old_price', '_offer_new_price', '_offer_monthly_payment', '_offer_expires', '_thumbnail_id' ), true ) ) {
			self::queue_offer( $post_id );
			return;
		}
		if ( 'car' === $post_type ) {
			self::queue_vehicle( $post_id );
		}
	}

	/** Purge a single offer when its local expiry boundary is reached. */
	public static function expire_offer( int $post_id, string $expected_expires = '' ): void {
		if ( 'car_offer' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$current_expires = get_post_meta( $post_id, '_offer_expires', true );
		$current_expires = is_string( $current_expires ) ? $current_expires : '';
		if ( '' === $current_expires || ( '' !== $expected_expires && $current_expires !== $expected_expires ) ) {
			self::sync_expiry_schedule( $post_id );
			return;
		}
		if ( ! self::valid_date( $current_expires ) || $current_expires >= current_time( 'Y-m-d' ) ) {
			self::sync_expiry_schedule( $post_id );
			return;
		}
		self::queue_offer( $post_id, false );
		self::flush_queue();
	}

	/**
	 * Safety net for missed single events. It only examines dates since the last
	 * completed pass, so normal traffic does not repeatedly scan all offers.
	 */
	public static function reconcile(): void {
		$today = current_time( 'Y-m-d' );
		$to_date = self::offset_date( $today, -1 );
		$last = get_option( self::LAST_RECONCILE_OPTION, '' );
		$from_date = self::valid_date( (string) $last ) ? self::offset_date( (string) $last, 1 ) : $to_date;

		if ( $from_date <= $to_date ) {
			$page = 1;
			do {
				$ids = get_posts( array(
					'post_type'      => 'car_offer',
					'post_status'    => 'publish',
					'posts_per_page' => self::BATCH_SIZE,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array(
						array(
							'key'     => '_offer_expires',
							'value'   => array( $from_date, $to_date ),
							'compare' => 'BETWEEN',
							'type'    => 'DATE',
						),
					),
				) );
				foreach ( $ids as $id ) {
				self::queue_offer( (int) $id, false );
				}
				$page++;
			} while ( count( $ids ) === self::BATCH_SIZE );
			self::flush_queue();
		}

		update_option( self::LAST_RECONCILE_OPTION, $to_date, false );
		self::schedule_reconcile();
	}

	/** Limit active offer cache lifetime to the local expiry boundary. */
	public static function send_offer_cache_headers(): void {
		if ( is_admin() || is_preview() || ! is_singular( 'car_offer' ) ) {
			return;
		}
		$offer = PublicOfferView::for_display( get_queried_object_id() );
		if ( ! is_array( $offer ) ) {
			return;
		}
		if ( ! empty( $offer['is_expired'] ) ) {
			$max_age = 300;
		} elseif ( ! empty( $offer['expires'] ) && is_string( $offer['expires'] ) && self::valid_date( $offer['expires'] ) ) {
			$boundary = self::date_timestamp( $offer['expires'] );
			$max_age = max( 60, $boundary - current_time( 'timestamp' ) );
		} else {
			$max_age = 300;
		}
		$max_age = min( $max_age, 7 * DAY_IN_SECONDS );
		header( 'Cache-Control: public, max-age=' . $max_age . ', s-maxage=' . $max_age . ', must-revalidate', true );
	}

	/** Flush queued URLs after post/meta writes have completed. */
	public static function flush_queue(): void {
		if ( ! self::$queued_offer_ids && ! self::$queued_vehicle_ids && ! self::$queued_urls ) {
			return;
		}

		$offer_ids  = array_map( 'absint', array_keys( self::$queued_offer_ids ) );
		$vehicle_ids = array_map( 'absint', array_keys( self::$queued_vehicle_ids ) );
		$expiry_ids  = array_map( 'absint', array_keys( self::$queued_expiry_sync_ids ) );
		$urls       = array_keys( self::$queued_urls );
		$known_offer_ids = array_fill_keys( $offer_ids, true );
		self::$queued_offer_ids = array();
		self::$queued_vehicle_ids = array();
		self::$queued_expiry_sync_ids = array();
		self::$queued_urls = array();

		foreach ( $expiry_ids as $offer_id ) {
			self::sync_expiry_schedule( $offer_id );
		}
		foreach ( $offer_ids as $offer_id ) {
			$url = get_permalink( $offer_id );
			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
		}
		foreach ( $vehicle_ids as $vehicle_id ) {
			$url = get_permalink( $vehicle_id );
			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
			$related_page = 1;
			do {
				$related = get_posts( array(
					'post_type'      => 'car_offer',
					'post_status'    => 'any',
					'posts_per_page' => self::BATCH_SIZE,
					'paged'          => $related_page,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => '_offer_car_id',
					'meta_value'     => (string) $vehicle_id,
				) );
				foreach ( $related as $offer_id ) {
					$offer_id = absint( $offer_id );
					if ( ! isset( $known_offer_ids[ $offer_id ] ) ) {
						$offer_ids[] = $offer_id;
						$known_offer_ids[ $offer_id ] = true;
					}
					$url = get_permalink( $offer_id );
					if ( is_string( $url ) && '' !== $url ) {
						$urls[] = $url;
					}
				}
				$related_page++;
			} while ( count( $related ) === self::BATCH_SIZE );
		}

		$urls[] = home_url( '/' );
		$archive = get_post_type_archive_link( 'car_offer' );
		if ( is_string( $archive ) && '' !== $archive ) {
			$urls[] = $archive;
		}
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		$urls = array_merge( $urls, self::sitemap_urls() );
		$urls = array_values( array_unique( $urls ) );
		CacheManager::purge_posts( $offer_ids, 'offer_change' );
		CacheManager::purge_urls( $urls, $offer_ids, 'offer', 'offer_change' );
	}

	private static function queue_offer( int $post_id, bool $sync_expiry = true ): void {
		if ( $post_id > 0 && 'car_offer' === get_post_type( $post_id ) ) {
			self::$queued_offer_ids[ $post_id ] = true;
			if ( $sync_expiry ) {
				self::$queued_expiry_sync_ids[ $post_id ] = true;
			}
		}
	}

	private static function queue_vehicle( int $post_id ): void {
		if ( $post_id > 0 && 'car' === get_post_type( $post_id ) ) {
			self::$queued_vehicle_ids[ $post_id ] = true;
		}
	}

	private static function sync_expiry_schedule( int $post_id ): void {
		self::unschedule_offer_events( $post_id );
		if ( 'car_offer' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$expires = get_post_meta( $post_id, '_offer_expires', true );
		if ( ! is_string( $expires ) || '' === $expires || ! self::valid_date( $expires ) ) {
			return;
		}
		$timestamp = self::date_timestamp( $expires );
		if ( $timestamp <= time() ) {
			$timestamp = time() + 5;
		}
		wp_schedule_single_event( $timestamp, self::EXPIRY_HOOK, array( $post_id, $expires ) );
	}

	private static function unschedule_offer_events( int $post_id ): void {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return;
		}
		$cron = _get_cron_array();
		foreach ( (array) $cron as $timestamp => $hooks ) {
			foreach ( (array) ( $hooks[ self::EXPIRY_HOOK ] ?? array() ) as $event ) {
				$args = (array) ( $event['args'] ?? array() );
				if ( absint( $args[0] ?? 0 ) === $post_id ) {
					wp_unschedule_event( (int) $timestamp, self::EXPIRY_HOOK, $args );
				}
			}
		}
	}

	/** @return string[] */
	private static function sitemap_urls(): array {
		$urls = array( home_url( '/wp-sitemap.xml' ) );
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$server   = wp_sitemaps_get_server();
			$provider = $server && isset( $server->registry ) ? $server->registry->get_provider( 'posts' ) : null;
			if ( $provider && method_exists( $provider, 'get_max_num_pages' ) && method_exists( $provider, 'get_sitemap_url' ) ) {
				$pages = max( 1, (int) $provider->get_max_num_pages( 'car_offer' ) );
				for ( $page = 1; $page <= $pages; $page++ ) {
					$urls[] = $provider->get_sitemap_url( 'car_offer', $page );
				}
			}
		} else {
			$urls[] = home_url( '/wp-sitemap-posts-car_offer-1.xml' );
		}
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	private static function next_reconcile_timestamp(): int {
		return ( new \DateTimeImmutable( 'tomorrow 00:00:05', wp_timezone() ) )->getTimestamp();
	}

	private static function date_timestamp( string $date ): int {
		return \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() )->modify( '+1 day' )->setTime( 0, 0, 5 )->getTimestamp();
	}

	private static function offset_date( string $date, int $days ): string {
		return \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	private static function valid_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
