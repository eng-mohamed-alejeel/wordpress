<?php
namespace AutoDealership\Database;

defined( 'ABSPATH' ) || exit;

/** Commit a service-owned transaction only when its required audit writes succeed. */
final class Transaction {
	public static function begin( array $required_tables = array() ): bool {
		global $wpdb;
		if ( ! Schema::is_ready() || ! self::tables_support_transactions( $required_tables ) ) {
			return false;
		}
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	private static function tables_support_transactions( array $tables ): bool {
		global $wpdb;
		foreach ( array_unique( $tables ) as $table ) {
			if ( ! is_string( $table ) || '' === $table ) { return false; }
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) { return false; }
		}
		return true;
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
