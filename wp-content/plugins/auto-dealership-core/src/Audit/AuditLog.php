<?php
namespace AutoDealership\Audit;

defined( 'ABSPATH' ) || exit;

/** Append-only application audit event writer. */
final class AuditLog {
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'adc_audit_events';
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::definition() );
	}

	public static function definition(): string {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_key varchar(100) NOT NULL,
			subject_type varchar(60) NOT NULL,
			subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reason text NOT NULL,
			before_data longtext NULL,
			after_data longtext NULL,
			correlation_id char(36) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY subject (subject_type,subject_id),
			KEY actor_created (actor_user_id,created_at),
			KEY event_created (event_key,created_at),
			KEY correlation_id (correlation_id)
		) {$charset_collate} ENGINE=InnoDB;";
	}

	/**
	 * Record a state change or security relevant action. Callers must provide only
	 * business data that is safe to retain; credentials and payment data are forbidden.
	 *
	 * @param array<string,mixed>|null $before Previous state.
	 * @param array<string,mixed>|null $after New state.
	 */
	public static function record( string $event_key, string $subject_type, int $subject_id, string $reason = '', ?array $before = null, ?array $after = null, string $correlation_id = '' ): bool {
		global $wpdb;
		if ( ! preg_match( '/^[a-z][a-z0-9_.-]{1,99}$/', $event_key ) || ! preg_match( '/^[a-z][a-z0-9_.-]{1,59}$/', $subject_type ) ) {
			return false;
		}
		$json = static function ( ?array $data ): ?string {
			if ( null === $data ) {
				return null;
			}
			$encoded = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return false === $encoded ? null : $encoded;
		};
		$before_json = $json( $before );
		$after_json = $json( $after );
		if ( ( null !== $before && null === $before_json ) || ( null !== $after && null === $after_json ) ) {
			return false;
		}
		$correlation_id = preg_match( '/^[a-f0-9-]{36}$/i', $correlation_id ) ? $correlation_id : wp_generate_uuid4();
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'actor_user_id' => get_current_user_id(),
				'event_key' => $event_key,
				'subject_type' => sanitize_key( $subject_type ),
				'subject_id' => max( 0, $subject_id ),
				'reason' => sanitize_textarea_field( $reason ),
				'before_data' => $before_json,
				'after_data' => $after_json,
				'correlation_id' => $correlation_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return 1 === $result;
	}
}
