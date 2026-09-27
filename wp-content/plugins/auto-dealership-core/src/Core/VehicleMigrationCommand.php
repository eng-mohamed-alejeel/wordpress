<?php
namespace AutoDealership\Core;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Migration\LegacyVehicleMapper;

defined( 'ABSPATH' ) || exit;

/**
 * Copies legacy car posts into the operational inventory without deleting or editing source posts.
 *
 * ## OPTIONS
 *
 * [--branch=<id>]
 * : Existing branch ID to use when no legacy location matches.
 *
 * [--dry-run]
 * : Count eligible, already copied, and invalid posts without inserting rows.
 *
 * [--post-id=<id>]
 * : Limit the operation to one legacy vehicle post for a controlled pilot.
 *
 * [--batch-size=<number>]
 * : Number of legacy posts per query. Default 100; maximum 500.
 *
 * ## EXAMPLES
 *
 *     wp adc migrate-vehicles --dry-run --branch=1
 *     wp adc migrate-vehicles --branch=1 --batch-size=100
 */
final class VehicleMigrationCommand {
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;
		if ( ! Schema::is_ready() ) { \WP_CLI::error( 'Verified dealership schema is required.' ); }
		$dry_run = isset( $assoc_args['dry-run'] );
		$fallback_branch = absint( $assoc_args['branch'] ?? 0 );
		$target_post = absint( $assoc_args['post-id'] ?? 0 );
		$batch_size = min( 500, max( 1, absint( $assoc_args['batch-size'] ?? 100 ) ) );
		if ( $fallback_branch && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE id = %d AND active = 1', $fallback_branch ) ) ) {
			\WP_CLI::error( 'The fallback branch does not exist or is inactive.' );
		}
		$counts = array( 'eligible' => 0, 'imported' => 0, 'already_imported' => 0, 'skipped_invalid' => 0, 'skipped_branch' => 0, 'skipped_workflow' => 0, 'conflict' => 0 );
		$cursor = 0;
		while ( true ) {
			if ( $target_post ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'car' AND post_status NOT IN ('trash','auto-draft') AND ID = %d", $target_post ) );
			} else {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'car' AND post_status NOT IN ('trash','auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d", $cursor, $batch_size ) );
			}
			if ( $wpdb->last_error ) { \WP_CLI::error( 'Source inventory could not be read.' ); }
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $post_id ) {
				$cursor = (int) $post_id;
				$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id = %d LIMIT 1', $cursor ) );
				if ( $wpdb->last_error ) { ++$counts['conflict']; continue; }
				if ( $existing ) {
					++$counts['already_imported'];
					continue;
				}
				$row = LegacyVehicleMapper::map( $cursor );
				if ( is_wp_error( $row ) ) {
					++$counts['skipped_invalid'];
					continue;
				}
				$status = LegacyVehicleMapper::status( $cursor );
				if ( null === $status ) {
					++$counts['skipped_workflow'];
					continue;
				}
				$location = sanitize_text_field( (string) get_post_meta( $cursor, '_car_location', true ) );
				$branch_id = $fallback_branch;
				if ( ! $branch_id && $location ) {
					$matches = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE active = 1 AND name = %s ORDER BY id LIMIT 2', $location ) );
					if ( 1 === count( $matches ) ) { $branch_id = (int) $matches[0]; }
				}
				if ( ! $branch_id ) {
					++$counts['skipped_branch'];
					continue;
				}
				$collision = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . ' WHERE vin=%s OR stock_number=%s LIMIT 1', $row['vin'], $row['stock_number'] ) );
				if ( $wpdb->last_error || $collision ) { ++$counts['conflict']; continue; }
				++$counts['eligible'];
				if ( $dry_run ) {
					continue;
				}
				$now = current_time( 'mysql', true );
				if ( ! Transaction::begin() ) { ++$counts['conflict']; continue; }
				// Lock the source post to serialize overlapping imports, then recheck mapping.
				$source = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d AND post_type='car' AND post_status NOT IN ('trash','auto-draft') FOR UPDATE", $cursor ) );
				if ( ! $source ) { $wpdb->query( 'ROLLBACK' ); ++$counts['conflict']; continue; }
				$mapped = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . ' WHERE public_post_id=%d LIMIT 1 FOR UPDATE', $cursor ) );
				if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); ++$counts['conflict']; continue; }
				if ( $mapped ) { $wpdb->query( 'ROLLBACK' ); ++$counts['already_imported']; continue; }
				$active_branch = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'branches' ) . ' WHERE id=%d AND active=1', $branch_id ) );
				if ( ! $active_branch ) { $wpdb->query( 'ROLLBACK' ); ++$counts['skipped_branch']; continue; }
				$ok = $wpdb->insert( Schema::table( 'vehicles' ), array_merge( $row, array(
					'branch_id' => $branch_id,
					'status' => $status,
					'public_post_id' => $cursor,
					'created_at' => $now,
					'updated_at' => $now,
				) ) );
				if ( false === $ok ) {
					$wpdb->query( 'ROLLBACK' );
					++$counts['conflict'];
					continue;
				}
				$vehicle_id = (int) $wpdb->insert_id;
				$movement = $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id'=>$vehicle_id, 'from_branch_id'=>0, 'to_branch_id'=>$branch_id, 'from_status'=>'', 'to_status'=>$status, 'actor_user_id'=>get_current_user_id(), 'reason'=>'Legacy inventory import; physical receipt not inferred', 'created_at'=>$now ) );
				if ( 1 !== $movement ) { $wpdb->query( 'ROLLBACK' ); ++$counts['conflict']; continue; }
				if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.legacy_imported', 'vehicle', $vehicle_id, 'Legacy post migration', null, array( 'public_post_id' => $cursor ) ) ) ) {
					++$counts['conflict'];
					continue;
				}
				++$counts['imported'];
			}
			if ( $target_post ) { break; }
		}
		\WP_CLI::log( ( $dry_run ? 'Dry run' : 'Migration' ) . ' results: ' . wp_json_encode( $counts ) );
		if ( $counts['conflict'] > 0 || $counts['skipped_invalid'] > 0 || $counts['skipped_branch'] > 0 || $counts['skipped_workflow'] > 0 ) {
			\WP_CLI::warning( 'Resolve skipped records and duplicate VIN/stock conflicts, reconcile legacy reservations/sales, then rerun; imported rows are idempotently skipped.' );
		}
	}
}
