<?php
namespace AutoDealership\Core;

use AutoDealership\Migration\MigrationInventory;

defined( 'ABSPATH' ) || exit;

/** Produces a read-only, non-PII migration inventory and reconciliation report. */
final class MigrationReportCommand {
	public function __invoke( array $args, array $assoc_args ): void {
		$format = sanitize_key( $assoc_args['format'] ?? 'json' );
		if ( ! in_array( $format, array( 'json','summary' ), true ) ) { \WP_CLI::error( 'Format must be json or summary.' ); }
		$report = MigrationInventory::report( absint( $assoc_args['branch'] ?? 0 ) );
		if ( isset( $report['error'] ) ) { \WP_CLI::error( $report['error'] ); }
		if ( 'json' === $format ) { \WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); }
		else {
			foreach ( array( 'vehicles','crm','offers','compatibility_retirement' ) as $section ) { \WP_CLI::log( strtoupper( $section ) . ': ' . wp_json_encode( $report[ $section ] ) ); }
		}
		$issues = (int) $report['vehicles']['drifted'] + (int) $report['vehicles']['invalid_identity'] + (int) $report['vehicles']['workflow_blocked'] + (int) $report['vehicles']['unresolved_branch'] + (int) $report['vehicles']['vin_conflict'] + (int) $report['vehicles']['stock_conflict'] + (int) $report['offers']['unlinked_or_orphaned'];
		if ( isset( $assoc_args['fail-on-issues'] ) && $issues > 0 ) { \WP_CLI::error( "Migration report found $issues blocking records." ); }
		\WP_CLI::success( 'Read-only migration inventory completed; no source or target rows were changed.' );
	}
}
