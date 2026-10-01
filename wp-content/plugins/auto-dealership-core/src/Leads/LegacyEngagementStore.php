<?php
namespace AutoDealership\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the temporary compatibility tables used by account request views.
 *
 * New writes enter through plugin services only. The tables remain until the
 * account and request workflow no longer require legacy request identifiers.
 */
final class LegacyEngagementStore {
	public const VERSION = '1.3.0';
	public const OPTION_VERSION = 'adc_legacy_engagement_version';

	public static function owns_schema(): bool {
		return true;
	}

	public static function boot(): void {
		if ( self::VERSION !== get_option( self::OPTION_VERSION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		$lock = 'adc_legacy_engagement_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return;
		}

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$collate = $wpdb->get_charset_collate();

			dbDelta( "CREATE TABLE {$wpdb->prefix}car_dealer_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lead_type varchar(40) NOT NULL DEFAULT 'contact',
			car_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(120) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(60) NOT NULL DEFAULT '',
			subject varchar(190) NOT NULL DEFAULT '',
			message longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			customer_reply text NOT NULL,
			updated_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status)
		) $collate ENGINE=InnoDB;" );

			dbDelta( "CREATE TABLE {$wpdb->prefix}car_dealer_bookings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			car_id bigint(20) unsigned NOT NULL,
			name varchar(120) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(60) NOT NULL DEFAULT '',
			requested_date date NULL,
			requested_time varchar(30) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			customer_reply text NOT NULL,
			updated_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY car_id (car_id),
			KEY status (status)
		) $collate ENGINE=InnoDB;" );

			dbDelta( "CREATE TABLE {$wpdb->prefix}car_dealer_subscribers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) $collate ENGINE=InnoDB;" );

			if ( self::is_ready() ) {
				update_option( self::OPTION_VERSION, self::VERSION, false );
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function is_ready(): bool {
		global $wpdb;
		foreach ( array( 'car_dealer_messages', 'car_dealer_bookings', 'car_dealer_subscribers' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$engine = $wpdb->get_var( $wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
				$table
			) );
			if ( 'InnoDB' !== $engine ) {
				return false;
			}
		}
		return true;
	}
}
