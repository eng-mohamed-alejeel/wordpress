<?php
namespace AutoDealership\Operations;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Audit\AuditLog;
defined( 'ABSPATH' ) || exit;

/** Human evidence is scoped to one environment and release; approval never activates providers. */
final class ReleaseEvidence {
	public const GATES = array( 'legal_approval', 'business_data_approval', 'provider_acceptance', 'historical_secret_review', 'external_scheduler', 'security_review', 'accessibility_performance', 'recovery_offsite', 'deployment_approval' );
	/** Read-only CLI gate helper also works under SHORTINIT, without loading the plugin. */
	public static function approved( string $environment, string $version ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'adc_release_evidence';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status='approved' AND environment_url=%s AND release_version=%s AND valid_until>=%s AND reviewer_user_id>0 AND reviewer_user_id<>submitter_user_id ORDER BY id DESC", untrailingslashit( $environment ), $version, gmdate( 'Y-m-d' ) ), ARRAY_A ) ?: array();
		$approved = array(); foreach ( $rows as $row ) { if ( ! isset( $approved[$row['gate_key']] ) && in_array( $row['gate_key'], self::GATES, true ) && preg_match( '/\A[a-f0-9]{64}\z/', $row['artifact_hash'] ) && $row['artifact_reference'] && $row['reviewed_at'] ) { $approved[$row['gate_key']] = $row; } }
		return $approved;
	}
	public static function submit( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 403 ); }
		$gate = sanitize_key( $input['gate_key'] ?? '' ); $reference = sanitize_text_field( $input['artifact_reference'] ?? '' ); $hash = strtolower( (string) ( $input['artifact_hash'] ?? '' ) );
		$date = (string) ( $input['valid_until'] ?? '' ); $parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) ); $reason = sanitize_textarea_field( $input['reason'] ?? '' );
		$url = wp_parse_url( $reference );
		if ( is_array( $url ) && isset( $url['scheme'] ) && ( ! in_array( $url['scheme'], array( 'https', 'http' ), true ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) ) ) { return self::error( 'private_reference', 400 ); }
		if ( ! in_array( $gate, self::GATES, true ) || ! $reference || strlen( $reference ) > 255 || ! preg_match( '/\A[a-f0-9]{64}\z/', $hash ) || ! $parsed || $parsed->format( 'Y-m-d' ) !== $date || $date < gmdate( 'Y-m-d' ) || ! trim( $reason ) ) { return self::error( 'invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'database', 500 ); }
		$data = array( 'gate_key'=>$gate, 'environment_url'=>untrailingslashit( site_url() ), 'release_version'=>ADC_VERSION, 'artifact_reference'=>$reference, 'artifact_hash'=>$hash, 'submitter_user_id'=>get_current_user_id(), 'status'=>'pending', 'valid_until'=>$date, 'reason'=>$reason, 'created_at'=>current_time( 'mysql', true ) );
		if ( 1 !== $wpdb->insert( Schema::table( 'release_evidence' ), $data ) ) { return self::rollback(); }
		$id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'release.evidence_submitted', 'release_evidence', $id, $reason, null, $data ) ) ) { return self::error( 'database', 500 ); }
		return array( 'id'=>$id ) + $data;
	}
	public static function review( int $id, bool $approved, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason ); if ( ! trim( $reason ) ) { return self::error( 'invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'database', 500 ); }
		$table = Schema::table( 'release_evidence' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || 'pending' !== $row['status'] || (int) $row['submitter_user_id'] === get_current_user_id() || $row['environment_url'] !== untrailingslashit( site_url() ) || $row['release_version'] !== ADC_VERSION || $row['valid_until'] < gmdate( 'Y-m-d' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'review_conflict', 409 ); }
		$data = array( 'status'=>$approved ? 'approved' : 'rejected', 'reviewer_user_id'=>get_current_user_id(), 'reviewed_at'=>current_time( 'mysql', true ), 'reason'=>$reason );
		if ( 1 !== $wpdb->update( $table, $data, array( 'id'=>$id, 'status'=>'pending' ) ) || ! Transaction::commit( static fn() => AuditLog::record( 'release.evidence_reviewed', 'release_evidence', $id, $reason, $row, array_merge( $row, $data ) ) ) ) { return self::rollback(); }
		return array_merge( $row, $data );
	}
	private static function rollback() { global $wpdb; $wpdb->query( 'ROLLBACK' ); return self::error( 'database', 500 ); }
	private static function error( string $code, int $status ) { return new \WP_Error( 'adc_release_' . $code, $code, array( 'status'=>$status ) ); }
}
