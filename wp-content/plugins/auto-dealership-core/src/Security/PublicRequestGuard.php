<?php
namespace AutoDealership\Security;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Atomic fixed-window limits for public application entry points. */
final class PublicRequestGuard {
	public const POLICIES = array(
		'intake'      => array( 'limit'=>8, 'window'=>3600 ),
		'public_read' => array( 'limit'=>120, 'window'=>60 ),
	);

	public static function table(): string { return Schema::table( 'request_limits' ); }

	/** Returns rate metadata or WP_Error. No raw address is persisted or returned. */
	public static function consume( string $policy_key ) {
		global $wpdb;
		if ( ! isset( self::POLICIES[ $policy_key ] ) ) { return self::error( 'adc_rate_policy_invalid', 500 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_rate_unavailable', 503 ); }
		$policy = self::POLICIES[ $policy_key ];
		$now = time();
		$window_started = intdiv( $now, $policy['window'] ) * $policy['window'];
		$identity = get_current_user_id() > 0 ? 'user:' . get_current_user_id() : 'address:' . ClientAddress::resolve();
		$bucket_key = hash_hmac( 'sha256', $policy_key . '|' . $window_started . '|' . $identity, wp_salt( 'auth' ) );
		$expires = $window_started + $policy['window'] + DAY_IN_SECONDS;
		$inserted = $wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . self::table() . ' (bucket_key,policy_key,attempts,window_started,expires_at) VALUES (%s,%s,1,%s,%s) ON DUPLICATE KEY UPDATE attempts=LAST_INSERT_ID(attempts+1)',
			$bucket_key, $policy_key, gmdate( 'Y-m-d H:i:s', $window_started ), gmdate( 'Y-m-d H:i:s', $expires )
		) );
		if ( false === $inserted ) { return self::error( 'adc_rate_unavailable', 503 ); }
		// LAST_INSERT_ID(expr) is connection-local, so this is the exact attempt assigned
		// by the atomic statement even when other requests update the bucket immediately.
		$attempts = 1 === $inserted ? 1 : (int) $wpdb->insert_id;
		if ( $attempts < 1 ) { return self::error( 'adc_rate_unavailable', 503 ); }
		$retry_after = max( 1, $window_started + $policy['window'] - $now );
		if ( (int) $attempts > $policy['limit'] ) {
			do_action( 'adc_public_rate_limited', array( 'policy'=>$policy_key, 'retry_after'=>$retry_after ) );
			return new \WP_Error( 'adc_rate_limited', __( 'Too many requests. Please try again later.', 'auto-dealership-core' ), array( 'status'=>429, 'retry_after'=>$retry_after ) );
		}
		return array( 'limit'=>$policy['limit'], 'remaining'=>max( 0, $policy['limit'] - (int) $attempts ), 'retry_after'=>$retry_after );
	}

	public static function permission( string $policy_key ) {
		$result = self::consume( $policy_key );
		return is_wp_error( $result ) ? $result : true;
	}

	/** Bounded scheduled cleanup. */
	public static function prune(): void {
		global $wpdb;
		if ( ! Schema::is_ready() ) { return; }
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at<%s ORDER BY expires_at ASC LIMIT 5000', current_time( 'mysql', true ) ) );
		update_option( 'adc_rate_limit_health', array( 'finished_at'=>current_time( 'mysql', true ), 'deleted'=>false === $deleted ? 0 : (int) $deleted, 'error'=>false === $deleted ? 'adc_rate_prune_failed' : '' ), false );
	}

	/** Aggregate operational metadata only. */
	public static function summary() {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_audit' ) ) { return self::error( 'adc_rate_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return self::error( 'adc_rate_unavailable', 503 ); }
		$counts = array_fill_keys( array_keys( self::POLICIES ), 0 );
		$now = time();
		foreach ( self::POLICIES as $policy_key => $policy ) {
			$window_started = intdiv( $now, $policy['window'] ) * $policy['window'];
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE policy_key=%s AND window_started=%s', $policy_key, gmdate( 'Y-m-d H:i:s', $window_started ) ) );
			if ( $wpdb->last_error ) { return self::error( 'adc_rate_unavailable', 503 ); }
			$counts[ $policy_key ] = (int) $count;
		}
		return array( 'policies'=>self::POLICIES, 'active_buckets'=>$counts, 'trusted_proxy_rules'=>ClientAddress::trusted_rule_count(), 'health'=>(array) get_option( 'adc_rate_limit_health', array() ) );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Public request protection is unavailable.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
