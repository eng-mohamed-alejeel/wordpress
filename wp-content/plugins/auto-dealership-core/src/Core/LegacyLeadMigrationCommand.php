<?php
namespace AutoDealership\Core;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Idempotently imports historical theme messages and bookings into the CRM. */
final class LegacyLeadMigrationCommand {
	/**
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report eligible, already imported and invalid records without writing.
	 *
	 * [--type=<type>]
	 * : Import messages, bookings, or both. Default: both.
	 *
	 * [--after-id=<id>]
	 * : Resume after a legacy request ID.
	 *
	 * [--batch-size=<number>]
	 * : Records per query. Default 100; maximum 500.
	 *
	 * ## EXAMPLES
	 *
	 *     wp adc migrate-leads --dry-run
	 *     wp adc migrate-leads --type=message --after-id=500 --batch-size=100
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;
		$types = isset( $assoc_args['type'] ) ? array( sanitize_key( $assoc_args['type'] ) ) : array( 'message', 'booking' );
		if ( array_diff( $types, array( 'message', 'booking' ) ) ) {
			\WP_CLI::error( 'Type must be message or booking.' );
		}
		$dry_run    = isset( $assoc_args['dry-run'] );
		$after_id   = absint( $assoc_args['after-id'] ?? 0 );
		$batch_size = min( 500, max( 1, absint( $assoc_args['batch-size'] ?? 100 ) ) );
		$counts     = array( 'eligible' => 0, 'imported' => 0, 'already_imported' => 0, 'invalid' => 0 );
		foreach ( $types as $type ) {
			$table_name = $wpdb->prefix . ( 'booking' === $type ? 'car_dealer_bookings' : 'car_dealer_messages' );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
				\WP_CLI::warning( 'Legacy table is missing; skipped ' . $type . ' requests.' );
				continue;
			}
			while ( true ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,phone FROM $table_name WHERE id > %d ORDER BY id ASC LIMIT %d", $after_id, $batch_size ), ARRAY_A );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					$id       = (int) $row['id'];
					$after_id = max( $after_id, $id );
					$exists   = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'leads' ) . ' WHERE legacy_request_type = %s AND legacy_request_id = %d LIMIT 1', $type, $id ) );
					if ( $exists ) {
						++$counts['already_imported'];
						continue;
					}
					$name   = sanitize_text_field( (string) $row['name'] );
					$mobile = preg_replace( '/[^0-9+]/', '', (string) $row['phone'] );
					if ( '' === $name || ! preg_match( '/^\+?[0-9]{8,15}$/', $mobile ) ) {
						++$counts['invalid'];
						continue;
					}
					++$counts['eligible'];
					if ( $dry_run ) {
						continue;
					}
				\AutoDealership\Leads\LeadService::capture_theme_request( $type, $id );
					$imported = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'leads' ) . ' WHERE legacy_request_type = %s AND legacy_request_id = %d LIMIT 1', $type, $id ) );
					if ( $imported ) {
						++$counts['imported'];
					} else {
						--$counts['eligible'];
						++$counts['invalid'];
					}
				}
				if ( count( $rows ) < $batch_size ) {
					break;
				}
			}
			$after_id = 0;
		}
		\WP_CLI::log( ( $dry_run ? 'Dry run' : 'Migration' ) . ' results: ' . wp_json_encode( $counts ) );
		if ( $counts['invalid'] ) {
			\WP_CLI::warning( 'Review invalid legacy rows before retiring the old CRM tables.' );
		}
	}
}
