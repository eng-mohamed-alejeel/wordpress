<?php
namespace AutoDealership\Database;

defined( 'ABSPATH' ) || exit;

/** Commit a service-owned transaction only when its required audit writes succeed. */
final class Transaction {
	public static function begin(): bool {
		global $wpdb;
		if ( ! Schema::is_ready() ) {
			return false;
		}
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	public static function commit( callable $audit ): bool {
		global $wpdb;
		try {
			if ( true === $audit() && false !== $wpdb->query( 'COMMIT' ) ) {
				return true;
			}
		} catch ( \Throwable ) {
			error_log( 'Auto Dealership Core: transaction audit failed.' );
		}
		$wpdb->query( 'ROLLBACK' );
		return false;
	}
}
